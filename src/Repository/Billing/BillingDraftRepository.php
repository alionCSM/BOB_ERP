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

    public function markExcelGenerated(int $draftId): void
    {
        $stmt = $this->conn->prepare(
            'UPDATE bb_billing_drafts SET excel_generated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $draftId]);
    }

    /**
     * Finalize: set status=fatturata + invoice number + invoice date.
     */
    public function finalizeDraftHeader(int $draftId, string $invoiceNumber, string $invoiceDate): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_billing_drafts
                SET status         = 'fatturata',
                    invoice_number = :num,
                    invoice_date   = :dt
              WHERE id = :id"
        );
        $stmt->execute([
            ':num' => $invoiceNumber,
            ':dt'  => $invoiceDate,
            ':id'  => $draftId,
        ]);
    }

    /**
     * Lines + everything needed for the writeback to bb_billing AND Yard.
     * Filtered by excluded so callers can pick what to operate on.
     */
    public function getLinesWithSourceForWriteback(int $draftId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                l.id                AS line_id,
                l.bb_billing_id,
                l.worksite_id,
                l.data,
                l.descrizione,
                l.totale_imponibile,
                l.aliquota_iva,
                l.excluded,
                l.yard_sync_status,
                b.yard_id,
                b.articolo_id       AS bob_articolo_id,
                b.iva_id,
                w.name              AS worksite_name,
                w.yard_worksite_id,
                c.id                AS client_id,
                c.name              AS client_name
            FROM bb_billing_draft_lines l
            JOIN bb_billing   b ON b.id = l.bb_billing_id
            JOIN bb_worksites w ON w.id = l.worksite_id
            JOIN bb_clients   c ON c.id = w.client_id
            WHERE l.draft_id = :id
            ORDER BY l.display_order ASC, l.id ASC
        ");
        $stmt->execute([':id' => $draftId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Write the draft-line values back to bb_billing and set emessa=1.
     */
    public function applyLineToBilling(int $bbBillingId, array $values): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_billing
               SET data              = :data,
                   descrizione       = :descr,
                   totale_imponibile = :imp,
                   aliquota_iva      = :iva,
                   emessa            = 1
             WHERE id = :id
        ");
        $stmt->execute([
            ':data'  => $values['data'] ?: null,
            ':descr' => (string)($values['descrizione'] ?? ''),
            ':imp'   => (float)($values['totale_imponibile'] ?? 0),
            ':iva'   => (float)($values['aliquota_iva'] ?? 0),
            ':id'    => $bbBillingId,
        ]);
    }

    /**
     * Persist the per-line Yard sync outcome.
     */
    public function setLineYardSyncStatus(int $lineId, string $status, ?string $error): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_billing_draft_lines
               SET yard_sync_status       = :s,
                   yard_sync_error        = :e,
                   yard_sync_attempted_at = NOW()
             WHERE id = :id
        ");
        $stmt->execute([
            ':s'  => $status,
            ':e'  => $error,
            ':id' => $lineId,
        ]);
    }

    /**
     * Aggregate Yard sync counters for a draft — used for the fatturata banner.
     */
    public function getYardSyncSummary(int $draftId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                COALESCE(SUM(yard_sync_status = 'synced'),  0) AS synced,
                COALESCE(SUM(yard_sync_status = 'failed'),  0) AS failed,
                COALESCE(SUM(yard_sync_status = 'na'),      0) AS na,
                COALESCE(SUM(yard_sync_status = 'pending' OR yard_sync_status IS NULL), 0) AS pending
            FROM bb_billing_draft_lines
            WHERE draft_id = :id AND excluded = 0
        ");
        $stmt->execute([':id' => $draftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'synced'  => (int)($row['synced']  ?? 0),
            'failed'  => (int)($row['failed']  ?? 0),
            'na'      => (int)($row['na']      ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
        ];
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
     * Fetch a single draft line by id, joined to its parent draft (so callers
     * can verify status / ownership without a second query).
     */
    public function findLineById(int $lineId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                l.*,
                d.client_id AS draft_client_id,
                d.status    AS draft_status,
                w.name      AS cantiere
            FROM bb_billing_draft_lines l
            JOIN bb_billing_drafts d ON d.id = l.draft_id
            JOIN bb_worksites w      ON w.id = l.worksite_id
            WHERE l.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $lineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update editable fields on a line. Caller passes only the columns it
     * wants to change. Always also sets the modified flag based on the
     * computed value vs original_*.
     */
    public function updateLineFields(int $lineId, array $fields, bool $modified): void
    {
        if (empty($fields)) {
            return;
        }
        $allowed = ['data', 'descrizione', 'totale_imponibile', 'aliquota_iva'];
        $sets    = [];
        $params  = [':id' => $lineId, ':mod' => $modified ? 1 : 0];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $sets[]               = "$col = :$col";
            $params[":$col"]      = $val;
        }
        if (empty($sets)) return;

        $sql = 'UPDATE bb_billing_draft_lines SET ' . implode(', ', $sets) . ', modified = :mod WHERE id = :id';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Toggle the excluded flag (and an optional reason).
     */
    public function setLineExcluded(int $lineId, bool $excluded, ?string $reason): void
    {
        $stmt = $this->conn->prepare(
            'UPDATE bb_billing_draft_lines
                SET excluded = :ex, excluded_reason = :rs
              WHERE id = :id'
        );
        $stmt->execute([
            ':ex' => $excluded ? 1 : 0,
            ':rs' => $excluded ? $reason : null,
            ':id' => $lineId,
        ]);
    }

    /**
     * Compute totals for a draft (sum of imponibile, split by excluded state).
     */
    public function computeTotals(int $draftId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN excluded = 0 THEN totale_imponibile END), 0) AS imponibile,
                COALESCE(SUM(CASE WHEN excluded = 1 THEN totale_imponibile END), 0) AS escluso,
                COUNT(*) AS lines_count
            FROM bb_billing_draft_lines
            WHERE draft_id = :id
        ");
        $stmt->execute([':id' => $draftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'imponibile'  => (float)($row['imponibile'] ?? 0),
            'escluso'     => (float)($row['escluso']    ?? 0),
            'lines_count' => (int)($row['lines_count']  ?? 0),
        ];
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
