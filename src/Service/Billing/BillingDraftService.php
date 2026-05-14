<?php

declare(strict_types=1);

namespace App\Service\Billing;

use PDO;
use RuntimeException;
use App\Repository\Billing\BillingDraftRepository;
use App\Repository\Billing\BillingRepository;

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
        ];
    }
}
