<?php

declare(strict_types=1);

namespace App\Repository\Billing;

use PDO;

/**
 * SQL for the editable fatturazione draft (bb_billing_drafts +
 * bb_billing_draft_lines). Kept separate from BillingRepository to keep
 * concerns isolated.
 */
final class BillingDraftRepository
{
    public function __construct(private PDO $conn) {}

    // ── Draft header ─────────────────────────────────────────────────────────

    public const ACTIVE_STATUSES = ['bozza', 'inviata_cliente', 'da_modificare', 'approvata'];

    public function findActiveByClient(int $clientId): ?array
    {
        $in  = "'" . implode("','", self::ACTIVE_STATUSES) . "'";
        $stmt = $this->conn->prepare(
            "SELECT * FROM bb_billing_drafts
              WHERE client_id = :cid AND status IN ($in)
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':cid' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(int $draftId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_billing_drafts WHERE id = :id');
        $stmt->execute([':id' => $draftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createDraft(int $clientId, ?string $periodLabel, int $userId): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO bb_billing_drafts (client_id, period_label, status, created_by)
                  VALUES (:cid, :label, :status, :uid)'
        );
        $stmt->execute([
            ':cid'    => $clientId,
            ':label'  => $periodLabel,
            ':status' => 'bozza',
            ':uid'    => $userId,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateStatus(int $draftId, string $status): void
    {
        $stmt = $this->conn->prepare(
            'UPDATE bb_billing_drafts SET status = :s WHERE id = :id'
        );
        $stmt->execute([':s' => $status, ':id' => $draftId]);
    }

    // ── Draft lines ──────────────────────────────────────────────────────────

    /**
     * Bulk-insert draft lines, snapshotting the current bb_billing rows.
     * One row per bb_billing.id. original_* values mirror the current ones
     * so we have a baseline for the diff display.
     */
    public function snapshotLinesFromBilling(int $draftId, array $billingRows): int
    {
        if (empty($billingRows)) {
            return 0;
        }

        $sql = 'INSERT INTO bb_billing_draft_lines (
                    draft_id, bb_billing_id, worksite_id,
                    data, descrizione, totale_imponibile, aliquota_iva,
                    original_data, original_descrizione, original_totale_imponibile, original_aliquota_iva,
                    display_order
                ) VALUES (
                    :draft_id, :bb_id, :worksite_id,
                    :data, :descr, :imp, :iva,
                    :odata, :odescr, :oimp, :oiva,
                    :ord
                )';
        $stmt = $this->conn->prepare($sql);

        $count = 0;
        foreach ($billingRows as $i => $r) {
            $stmt->execute([
                ':draft_id'     => $draftId,
                ':bb_id'        => (int)$r['id'],
                ':worksite_id'  => (int)$r['worksite_id'],
                ':data'         => $r['data'] ?: null,
                ':descr'        => $r['descrizione'] ?? '',
                ':imp'          => (float)($r['totale_imponibile'] ?? 0),
                ':iva'          => (float)($r['aliquota_iva'] ?? 0),
                ':odata'        => $r['data'] ?: null,
                ':odescr'       => $r['descrizione'] ?? '',
                ':oimp'         => (float)($r['totale_imponibile'] ?? 0),
                ':oiva'         => (float)($r['aliquota_iva'] ?? 0),
                ':ord'          => $i,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Fetch all draft lines, joined to worksite + client info for display.
     */
    public function getLinesForDraft(int $draftId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                l.*,
                w.name         AS cantiere,
                w.order_number,
                b.yard_id      AS source_yard_id,
                b.emessa       AS source_emessa
            FROM bb_billing_draft_lines l
            JOIN bb_billing   b ON b.id = l.bb_billing_id
            JOIN bb_worksites w ON w.id = l.worksite_id
            WHERE l.draft_id = :id
            ORDER BY l.display_order ASC, l.id ASC
        ");
        $stmt->execute([':id' => $draftId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count of bb_billing rows for this client (emessa=0) that are NOT yet
     * tracked by the draft — i.e. added after draft creation.
     * Used for the "Nuove righe disponibili" banner.
     */
    public function countNewBillingRowsForDraft(int $draftId, int $clientId): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid
              AND b.emessa = 0
              AND b.id NOT IN (
                  SELECT bb_billing_id FROM bb_billing_draft_lines WHERE draft_id = :did
              )
        ");
        $stmt->execute([':cid' => $clientId, ':did' => $draftId]);
        return (int)$stmt->fetchColumn();
    }
}
