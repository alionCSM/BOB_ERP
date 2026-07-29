<?php

declare(strict_types=1);

namespace App\Service\Billing;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use App\Repository\Billing\BillingDraftRepository;
use App\Repository\Billing\BillingRepository;
use App\Validator\Documents\DateNormalizer;
use App\Domain\YardWorksiteBilling;
use App\Infrastructure\Config;
use App\Infrastructure\SqlServerConnection;

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

    // ── Fattura ora (Phase 4) ────────────────────────────────────────────────

    /**
     * Apply the draft's edits back to the cantieri (bb_billing + Yard).
     *
     * This is NOT invoice generation — the actual fattura is emitted
     * downstream by accounting on Yard. We only propagate the user's edits
     * (data, descrizione, importo, iva). The emessa flag is intentionally
     * left untouched: it will become 1 when accounting actually invoices
     * the row on Yard, and `syncEmessaForClient` will pick that up.
     *
     * What we do here:
     *   1. Inside a BOB MySQL transaction:
     *        - UPDATE bb_billing for each non-excluded line (values only)
     *        - UPDATE bb_billing_drafts → status=fatturata (internal label for
     *          "applied"), invoice_date = today (audit only). invoice_number
     *          stays NULL — can be filled in later if needed.
     *   2. After commit, attempt Yard SQL Server sync per line. Failures are
     *      tracked on the line so the user can retry.
     *
     * Returns a summary the controller can surface to the UI.
     *
     * @return array{
     *   applied_at: string,
     *   yard: array{synced:int, failed:int, na:int, failures: array<int, array<string,mixed>>}
     * }
     */
    public function commitInvoice(int $draftId): array
    {
        $draft = $this->drafts->findById($draftId);
        if (!$draft) {
            throw new RuntimeException('Bozza non trovata.');
        }
        if (!in_array($draft['status'], ['bozza', 'approvata'], true)) {
            throw new InvalidArgumentException(
                "Per applicare le modifiche la bozza deve essere in stato 'bozza' o 'approvata' (attuale: {$draft['status']})."
            );
        }
        $appliedDate = date('Y-m-d');

        $lines = $this->drafts->getLinesWithSourceForWriteback($draftId);
        $toCommit = array_values(array_filter($lines, static fn ($l) => (int)$l['excluded'] === 0));

        // ── BOB MySQL transaction ────────────────────────────────────────────
        $this->conn->beginTransaction();
        try {
            foreach ($toCommit as $l) {
                $this->drafts->applyLineToBilling((int)$l['bb_billing_id'], [
                    'data'              => $l['data'],
                    'descrizione'       => $l['descrizione'],
                    'totale_imponibile' => $l['totale_imponibile'],
                    'aliquota_iva'      => $l['aliquota_iva'],
                ]);
                // Tag rows without yard_id as 'na' so they don't show as pending
                if (empty($l['yard_id'])) {
                    $this->drafts->setLineYardSyncStatus((int)$l['line_id'], 'na', null);
                }
            }
            $this->drafts->finalizeDraftHeader($draftId, null, $appliedDate);
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw new RuntimeException(
                'Scrittura su bb_billing fallita: ' . $e->getMessage(), 0, $e
            );
        }

        // ── Yard SQL Server sync (post-commit, per-row) ─────────────────────
        $yardLines  = array_values(array_filter($toCommit, static fn ($l) => !empty($l['yard_id'])));
        $yardResult = $this->syncLinesToYard($yardLines);

        return [
            'applied_at' => $appliedDate,
            'yard'       => $yardResult,
        ];
    }

    /**
     * Re-attempt Yard sync for lines whose status is 'failed' (or NULL).
     * Available on a fatturata draft so the user can retry after a Yard
     * connection issue without re-running the whole commit.
     */
    public function retryYardSync(int $draftId): array
    {
        $draft = $this->drafts->findById($draftId);
        if (!$draft) {
            throw new RuntimeException('Bozza non trovata.');
        }
        if ($draft['status'] !== 'fatturata') {
            throw new RuntimeException(
                "Retry Yard disponibile solo per bozze fatturate (attuale: {$draft['status']})."
            );
        }
        $all = $this->drafts->getLinesWithSourceForWriteback($draftId);
        $toRetry = array_values(array_filter(
            $all,
            static fn ($l) => (int)$l['excluded'] === 0
                          && !empty($l['yard_id'])
                          && in_array($l['yard_sync_status'], [null, 'failed', 'pending'], true)
        ));
        return $this->syncLinesToYard($toRetry);
    }

    /**
     * Per-line Yard updateBrogliaccio with persisted status. Never throws —
     * collects failures so the caller can show them in bulk.
     *
     * @param array<int, array<string,mixed>> $lines
     */
    private function syncLinesToYard(array $lines): array
    {
        if (empty($lines)) {
            return ['synced' => 0, 'failed' => 0, 'na' => 0, 'failures' => []];
        }

        // Lazy-build the Yard client (avoid opening SqlServer connection until
        // we know we need it — e.g. if no row has yard_id, we skip entirely)
        try {
            $yardBilling = new YardWorksiteBilling(new SqlServerConnection(new Config()));
        } catch (\Throwable $e) {
            // Yard unreachable at all — mark every line failed with the same reason
            $err = 'Yard non raggiungibile: ' . $e->getMessage();
            foreach ($lines as $l) {
                $this->drafts->setLineYardSyncStatus((int)$l['line_id'], 'failed', $err);
            }
            return [
                'synced'   => 0,
                'failed'   => count($lines),
                'na'       => 0,
                'failures' => array_map(fn ($l) => [
                    'line_id'  => (int)$l['line_id'],
                    'cantiere' => $l['worksite_name'] ?? '',
                    'error'    => $err,
                ], $lines),
            ];
        }

        $synced   = 0;
        $failed   = 0;
        $failures = [];

        foreach ($lines as $l) {
            $yardArticleId = $this->billing->getArticleYardId((int)($l['bob_articolo_id'] ?? 0));
            if (!$yardArticleId) {
                $err = 'articolo_id senza yard_id corrispondente';
                $this->drafts->setLineYardSyncStatus((int)$l['line_id'], 'failed', $err);
                $failed++;
                $failures[] = [
                    'line_id'  => (int)$l['line_id'],
                    'cantiere' => (string)($l['worksite_name'] ?? ''),
                    'error'    => $err,
                ];
                continue;
            }

            $dataY = [
                'data'              => $l['data'] ?: null,
                'nome_cantiere'     => (string)($l['worksite_name'] ?? ''),
                'nome_cliente'      => (string)($l['client_name'] ?? ''),
                'descrizione'       => (string)($l['descrizione'] ?? ''),
                'aliquota_iva'      => (float)($l['aliquota_iva'] ?? 0),
                'totale_imponibile' => (float)($l['totale_imponibile'] ?? 0),
                'articolo_id'       => (int)$yardArticleId,
                'cantiere_id'       => $l['yard_worksite_id'] ?? null,
                'iva_id'            => $l['iva_id'] ?? null,
            ];

            try {
                $yardBilling->updateBrogliaccio((int)$l['yard_id'], $dataY);
                $this->drafts->setLineYardSyncStatus((int)$l['line_id'], 'synced', null);
                $synced++;
            } catch (\Throwable $e) {
                $err = $e->getMessage();
                $this->drafts->setLineYardSyncStatus((int)$l['line_id'], 'failed', $err);
                $failed++;
                $failures[] = [
                    'line_id'  => (int)$l['line_id'],
                    'cantiere' => (string)($l['worksite_name'] ?? ''),
                    'error'    => $err,
                ];
            }
        }

        return ['synced' => $synced, 'failed' => $failed, 'na' => 0, 'failures' => $failures];
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
        'bozza'           => ['annullata'], // → fatturata happens via commitInvoice (Applica modifiche)
        'approvata'       => ['bozza', 'annullata'], // legacy escape
        'inviata_cliente' => ['bozza', 'annullata'], // legacy escape
        'da_modificare'   => ['bozza', 'annullata'], // legacy escape
        'fatturata'       => ['bozza'], // riapri: allows edit + re-apply
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
    private const EDITABLE_FIELDS = ['data', 'descrizione', 'totale_imponibile', 'aliquota_iva', 'iva_id'];

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

        // When the user picks a new iva_id from the dropdown, derive the
        // corresponding aliquota_iva from bb_billing_vat_codes so the two
        // stay in sync (mirrors what the cantiere saveBilling does).
        $fieldsToWrite = [$field => $value];
        if ($field === 'iva_id' && $value !== null) {
            $pct = $this->billing->getVatPercentageById((int)$value);
            if ($pct === null) {
                throw new InvalidArgumentException("Codice IVA non trovato (id={$value}).");
            }
            $fieldsToWrite['aliquota_iva'] = $pct;
        }

        // Compute new merged values for the modified-flag calculation
        $newLine = $line;
        foreach ($fieldsToWrite as $k => $v) {
            $newLine[$k] = $v;
        }
        $modified = $this->isLineModified($newLine);

        $this->drafts->updateLineFields($lineId, $fieldsToWrite, $modified);

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
                // Il campo e' modificato con una textarea (auto-espandibile),
                // ma il dato deve restare su una riga sola: viene riscritto su
                // bb_billing, esportato in Excel e spinto su Yard, dove un a
                // capo romperebbe la descrizione della fattura.
                $s = preg_replace('/[\r\n\t]+/u', ' ', (string)$raw) ?? (string)$raw;
                $s = preg_replace('/ {2,}/u', ' ', $s) ?? $s;
                return trim($s);

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

            case 'iva_id':
                $s = trim((string)$raw);
                if ($s === '') return null;
                if (!ctype_digit($s)) {
                    throw new InvalidArgumentException("ID IVA non valido: '{$raw}'");
                }
                return (int)$s;
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
        if ((int)($line['iva_id'] ?? 0) !== (int)($line['original_iva_id'] ?? 0)) {
            return true;
        }
        return false;
    }

    /**
     * Hydrates the draft view: header + lines + meta (e.g. new rows added
     * to bb_billing after draft creation).
     *
     * @return array{draft: array, lines: array<int,array>, totals: array, new_rows_count: int}
     */
    public function getDraftView(int $draftId): array
    {
        $draft = $this->drafts->findById($draftId);
        if (!$draft) {
            throw new RuntimeException('Bozza non trovata.');
        }

        // Auto-snapshot any bb_billing rows that have appeared on the
        // client's cantieri after the bozza was created — but only when
        // the bozza is still being worked on (editable). On fatturata /
        // annullata bozze we leave the list frozen as it was.
        if (in_array($draft['status'], self::EDITABLE_STATUSES, true)) {
            $this->drafts->snapshotMissingLines($draftId, (int)$draft['client_id']);
        }

        $lines = $this->drafts->getLinesForDraft($draftId);

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
            'yard_summary' => $draft['status'] === 'fatturata'
                ? $this->drafts->getYardSyncSummary($draftId)
                : null,
            'vat_codes' => $this->billing->getVatCodes(),
        ];
    }

}
