<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

/**
 * Annotazioni BOB-native sui disegni (pin/misure/markup) + calibrazione scala.
 */
final class ZoneAnnotationRepository
{
    public function __construct(private PDO $db) {}

    // ─── Annotazioni ──────────────────────────────────────────────────────────

    /** Tutte le annotazioni di un documento (eventualmente filtrate per pagina). */
    public function allForDocument(int $documentId, ?int $page = null): array
    {
        $sql = "
            SELECT a.*,
                   t.name   AS task_name,
                   t.status AS task_status,
                   t.assignee_name AS task_assignee
            FROM bb_zone_annotations a
            LEFT JOIN bb_zone_tasks t ON t.id = a.task_id
            WHERE a.document_id = :doc
        ";
        $params = [':doc' => $documentId];
        if ($page !== null) {
            $sql .= " AND a.page = :page";
            $params[':page'] = $page;
        }
        $sql .= " ORDER BY a.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_annotations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function create(array $d): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_annotations
                (worksite_id, document_id, page, type, geom, task_id, text, color, created_by)
            VALUES
                (:wid, :doc, :page, :type, :geom, :task, :text, :color, :uid)
        ");
        $stmt->execute([
            ':wid'   => $d['worksite_id'],
            ':doc'   => $d['document_id'],
            ':page'  => $d['page'] ?? 1,
            ':type'  => $d['type'],
            ':geom'  => is_string($d['geom']) ? $d['geom'] : json_encode($d['geom']),
            ':task'  => $d['task_id'] ?? null,
            ':text'  => $d['text'] ?? null,
            ':color' => $d['color'] ?? '#ef4444',
            ':uid'   => $d['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $stmt = $this->db->prepare("
            UPDATE bb_zone_annotations
               SET geom = :geom, text = :text, color = :color, task_id = :task
             WHERE id = :id
        ");
        $stmt->execute([
            ':geom'  => is_string($d['geom']) ? $d['geom'] : json_encode($d['geom']),
            ':text'  => $d['text'] ?? null,
            ':color' => $d['color'] ?? '#ef4444',
            ':task'  => $d['task_id'] ?? null,
            ':id'    => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare("DELETE FROM bb_zone_annotations WHERE id = :id")->execute([':id' => $id]);
    }

    public function setTaskId(int $id, ?int $taskId): void
    {
        $this->db->prepare("UPDATE bb_zone_annotations SET task_id = :t WHERE id = :id")
                 ->execute([':t' => $taskId, ':id' => $id]);
    }

    private function hydrate(array $row): array
    {
        $row['geom'] = json_decode($row['geom'] ?? '{}', true) ?: [];
        $row['id']         = (int)$row['id'];
        $row['page']       = (int)$row['page'];
        $row['task_id']    = $row['task_id'] !== null ? (int)$row['task_id'] : null;
        return $row;
    }

    // ─── Calibrazione ─────────────────────────────────────────────────────────

    public function getCalibration(int $documentId, int $page = 1): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_doc_calibration WHERE document_id = :doc AND page = :page
        ");
        $stmt->execute([':doc' => $documentId, ':page' => $page]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['m_per_wfrac'] = (float)$row['m_per_wfrac'];
        return $row;
    }

    public function setCalibration(int $documentId, int $page, float $mPerWfrac, ?int $userId): void
    {
        $this->db->prepare("
            INSERT INTO bb_zone_doc_calibration (document_id, page, m_per_wfrac, created_by)
            VALUES (:doc, :page, :scale, :uid)
            ON DUPLICATE KEY UPDATE m_per_wfrac = VALUES(m_per_wfrac), created_by = VALUES(created_by)
        ")->execute([
            ':doc'   => $documentId,
            ':page'  => $page,
            ':scale' => $mPerWfrac,
            ':uid'   => $userId,
        ]);
    }
}
