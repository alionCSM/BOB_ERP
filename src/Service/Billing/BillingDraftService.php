<?php

declare(strict_types=1);

namespace App\Service\Billing;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use App\Repository\Billing\BillingDraftRepository;
use App\Repository\Billing\BillingRepository;
use App\Validator\Documents\DateNormalizer;

/**
 * Orchestrates the editable fatturazione draft.
 *
 * Phase 1 responsibilities:
 *   - Create a new draft for a client by snapshotting all emessa=0 rows
 *     from bb_billing.
 *   - Enforce "one active draft per client" (statuses in ACTIVE_STATUSES).
 *   - Load a draft + lines for the read-only view.
 *
 * Phase 2+ will add inline edit, exclude toggle, Excel from draft, state
 * transitions, and "Fattura ora" propagation (BOB + Yard SQL Server).
 */
final class BillingDraftService
{
    public function __construct(
        private PDO $conn,
        private BillingDraftRepository $drafts,
        private BillingRepository $billing,
    ) {}

    /**
     * Returns the active draft for a client, or null if none.
     */
    public function getActiveDraft(int $clientId): ?array
    {
        return $this->drafts->findActiveByClient($clientId);
    }

    /**
     * Creates a draft for the client snapshotting all current emessa=0
     * bb_billing rows. Throws if an active draft already exists.
     *
     * Wrapped in a DB transaction so partial snapshots can't leak.
     */
    public function createDraftForClient(int $clientId, ?string $periodLabel, int $userId): int
    {
        if ($this->drafts->findActiveByClient($clientId)) {
            throw new RuntimeException('Esiste già una bozza attiva per questo cliente.');
        }

        $unbilled = $this->billing->getDaEmettereByClient($clientId);
        if (empty($unbilled)) {
            throw new RuntimeException('Nessuna riga da fatturare per questo cliente.');
        }

        $this->conn->beginTransaction();
        try {
            $draftId = $this->drafts->createDraft($clientId, $periodLabel, $userId);
            $this->drafts->snapshotLinesFromBilling($draftId, $unbilled);
            $this->conn->commit();
            return $draftId;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel a draft (status → annullata). The bb_billing rows are
     * unaffected and remain available for a fresh draft.
     */
    public function cancelDraft(int $draftId): void
    {
        $draft = $this->drafts->findById($draftId);
        if (!$draft) {
            throw new RuntimeException('Bozza non trovata.');
        }
        if ($draft['status'] === 'fatturata') {
            throw new RuntimeException('Impossibile annullare una bozza già fatturata.');
        }
        $this->drafts->updateStatus($draftId, 'annullata');
    }

    /**
     * Valid status transitions for the draft state machine.
     *
     * The "Invia al cliente" step is intentionally skipped — the client
     * review happens externally (user emails the Excel) so the BOB UI
     * only needs to track approval.
     *
     * 'fatturata' is set only by the Phase 4 "Fattura ora" flow, not via
     * the generic transition endpoint, so it isn't listed as a target here.
     *
     * inviata_cliente / da_modificare remain in the enum for back-compat
     * with any drafts created during Phase 3 testing, but aren't reachable
     * from the new UI.
     */
    private const TRANSITIONS = [
        'bozza'           => ['approvata', 'annullata'],
        'approvata'       => ['bozza', 'annullata'], // → fatturata is reserved for Phase 4
        'inviata_cliente' => ['bozza', 'approvata', 'annullata'], // legacy: route back
        'da_modificare'   => ['bozza', 'annullata'],              // legacy: route back
        'fatturata'       => [],
        'annullata'       => [],
    ];

    /**
     * Move the draft to a new status, enforcing the transition whitelist.
     * Returns the refreshed draft row.
     */
    public function transitionDraft(int $draftId, string $toStatus): array
    {
        $draft = $this->drafts->findById($draftId);
        if (!$draft) {
            throw new RuntimeException('Bozza non trovata.');
        }
        $from    = (string)$draft['status'];
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            throw new InvalidArgumentException(
                "Transizione non permessa: {$from} → {$toStatus}"
            );
        }
        $this->drafts->updateStatus($draftId, $toStatus);
        return $this->drafts->findById($draftId);
    }

    // ── Inline edit (Phase 2) ────────────────────────────────────────────────

    /** Statuses where the draft is still editable. */
    private const EDITABLE_STATUSES = ['bozza'];

    /** Whitelist of columns the inline editor can write. */
    private const EDITABLE_FIELDS = ['data', 'descrizione', 'totale_imponibile', 'aliquota_iva'];

    /**
     * Update a single field on a draft line. Returns the updated line +
     * fresh totals so the client can refresh without a full reload.
     *
     * @return array{line: array, totals: array, modified: bool}
     */
    public function updateLineField(int $lineId, string $field, mixed $rawValue): array
    {
        if (!in_array($field, self::EDITABLE_FIELDS, true)) {
            throw new InvalidArgumentException("Campo non modificabile: {$field}");
        }

        $line = $this->drafts->findLineById($lineId);
        if (!$line) {
            throw new RuntimeException('Riga non trovata.');
        }
        $this->assertEditable($line);

        $value = $this->normalizeValue($field, $rawValue);

        // Compute new merged values for the modified-flag calculation
        $newLine          = $line;
        $newLine[$field]  = $value;
        $modified         = $this->isLineModified($newLine);

        $this->drafts->updateLineFields($lineId, [$field => $value], $modified);

        $totals  = $this->drafts->computeTotals((int)$line['draft_id']);
        $updated = $this->drafts->findLineById($lineId);

        return [
            'line'     => $updated,
            'totals'   => $totals,
            'modified' => $modified,
        ];
    }

    /**
     * Toggle the excluded flag on a line. Returns the updated line + totals.
     *
     * @return array{line: array, totals: array}
     */
    public function setLineExcluded(int $lineId, bool $excluded, ?string $reason): array
    {
        $line = $this->drafts->findLineById($lineId);
        if (!$line) {
            throw new RuntimeException('Riga non trovata.');
        }
        $this->assertEditable($line);

        $this->drafts->setLineExcluded($lineId, $excluded, $reason);

        $totals  = $this->drafts->computeTotals((int)$line['draft_id']);
        $updated = $this->drafts->findLineById($lineId);

        return ['line' => $updated, 'totals' => $totals];
    }

    private function assertEditable(array $line): void
    {
        if (!in_array($line['draft_status'], self::EDITABLE_STATUSES, true)) {
            throw new RuntimeException(
                "Bozza in stato '{$line['draft_status']}': non modificabile."
            );
        }
    }

    /**
     * Sanitize + cast the incoming value based on the field. Throws if
     * unparseable (e.g. a date the user typed wrong).
     */
    private function normalizeValue(string $field, mixed $raw): mixed
    {
        switch ($field) {
            case 'data':
                $s = trim((string)$raw);
                if ($s === '') return null;
                $iso = DateNormalizer::toIso($s);
                if ($iso === null) {
                    throw new InvalidArgumentException(
                        "Data non valida: '{$s}'. Usa formato gg/mm/aaaa."
                    );
                }
                return $iso;

            case 'descrizione':
                return (string)$raw;

            case 'totale_imponibile':
            case 'aliquota_iva':
                // Accept italian comma decimals (e.g. "1.234,56" or "10,5")
                $s = trim((string)$raw);
                if ($s === '') return 0.0;
                // Strip thousand dots, replace decimal comma
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
                if (!is_numeric($s)) {
                    throw new InvalidArgumentException(
                        "Valore numerico non valido: '{$raw}'"
                    );
                }
                $n = (float)$s;
                if ($field === 'aliquota_iva' && ($n < 0 || $n > 100)) {
                    throw new InvalidArgumentException(
                        "IVA fuori intervallo: {$n}% (0-100)"
                    );
                }
                return $n;
        }
        return $raw;
    }

    /**
     * True if any of the editable fields differs from its original_* baseline.
     */
    private function isLineModified(array $line): bool
    {
        // Strings (data is stored as YYYY-MM-DD string)
        if ((string)($line['data'] ?? '') !== (string)($line['original_data'] ?? '')) {
            return true;
        }
        if ((string)($line['descrizione'] ?? '') !== (string)($line['original_descrizione'] ?? '')) {
            return true;
        }
        // Decimals — compare as float to avoid trailing-zero false positives
        $eps = 0.0001;
        if (abs((float)$line['totale_imponibile'] - (float)$line['original_totale_imponibile']) > $eps) {
            return true;
        }
        if (abs((float)$line['aliquota_iva'] - (float)$line['original_aliquota_iva']) > $eps) {
            return true;
        }
        return false;
    }

    /**
     * Hydrates the draft view: header + lines + meta (e.g. new rows added
     * to bb_billing after draft creation). Lines are enriched with the
     * latest "movimentato" month per worksite (from bb_presenze /
     * bb_presenze_consorziate), shown as a read-only hint column in the UI.
     *
     * @return array{draft: array, lines: array<int,array>, totals: array, new_rows_count: int}
     */
    public function getDraftView(int $draftId): array
    {
        $draft = $this->drafts->findById($draftId);
        if (!$draft) {
            throw new RuntimeException('Bozza non trovata.');
        }
        $lines = $this->drafts->getLinesForDraft($draftId);

        // Lookup most-recent presenze month per worksite (display-only)
        $movMap      = $this->loadMovimentatoMap(array_unique(array_column($lines, 'worksite_id')));
        foreach ($lines as &$l) {
            $l['movimentato'] = $movMap[(int)$l['worksite_id']] ?? null;
        }
        unset($l);

        $totImponibile = 0.0;
        $totEscluso    = 0.0;
        foreach ($lines as $l) {
            $val = (float)$l['totale_imponibile'];
            if ((int)$l['excluded'] === 1) {
                $totEscluso += $val;
            } else {
                $totImponibile += $val;
            }
        }

        return [
            'draft'          => $draft,
            'lines'          => $lines,
            'totals'         => [
                'imponibile'     => $totImponibile,
                'escluso'        => $totEscluso,
                'fatturabile'    => $totImponibile, // alias for clarity in the template
            ],
            'new_rows_count' => $this->drafts->countNewBillingRowsForDraft(
                $draftId,
                (int)$draft['client_id']
            ),
        ];
    }

    /**
     * For a list of worksite ids, return [worksite_id => "Mag 2026"] using
     * the latest presenze month (bb_presenze ∪ bb_presenze_consorziate).
     * Returns empty array if input is empty.
     */
    private function loadMovimentatoMap(array $worksiteIds): array
    {
        $worksiteIds = array_values(array_unique(array_filter(array_map('intval', $worksiteIds))));
        if (empty($worksiteIds)) {
            return [];
        }
        $monthNames   = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
        $placeholders = implode(',', array_fill(0, count($worksiteIds), '?'));
        $sql = "
            SELECT worksite_id, yr, mo
            FROM (
                SELECT worksite_id, YEAR(data) AS yr, MONTH(data) AS mo
                FROM bb_presenze
                WHERE worksite_id IN ({$placeholders})
                UNION
                SELECT worksite_id, YEAR(data_presenza) AS yr, MONTH(data_presenza) AS mo
                FROM bb_presenze_consorziate
                WHERE worksite_id IN ({$placeholders})
            ) AS combined
            GROUP BY worksite_id, yr, mo
            ORDER BY worksite_id, yr DESC, mo DESC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array_merge($worksiteIds, $worksiteIds));
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $wid = (int)$m['worksite_id'];
            // First seen wins = most recent month per worksite
            if (!isset($map[$wid])) {
                $map[$wid] = $monthNames[(int)$m['mo'] - 1] . ' ' . $m['yr'];
            }
        }
        return $map;
    }
}
