<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

final class FwTaskRepository
{
    public function __construct(private PDO $db) {}

    public function allForWorksite(int $worksiteId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_fw_tasks
            WHERE worksite_id = :wid
            ORDER BY fw_created_at DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByFwId(string $fwId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_fw_tasks WHERE fw_id = :id LIMIT 1");
        $stmt->execute([':id' => $fwId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function upsert(int $worksiteId, array $fw): void
    {
        $this->db->prepare("
            INSERT INTO bb_fw_tasks
                (worksite_id, fw_id, name, description, status, category_name, assignee_name, due_date, fw_created_at, fw_updated_at, synced_at)
            VALUES
                (:wid, :fwid, :name, :desc, :status, :cat, :assignee, :due, :fwc, :fwu, NOW())
            ON DUPLICATE KEY UPDATE
                name          = VALUES(name),
                description   = VALUES(description),
                status        = VALUES(status),
                category_name = VALUES(category_name),
                assignee_name = VALUES(assignee_name),
                due_date      = VALUES(due_date),
                fw_updated_at = VALUES(fw_updated_at),
                synced_at     = NOW()
        ")->execute([
            ':wid'      => $worksiteId,
            ':fwid'     => $fw['id'],
            ':name'     => $fw['name'] ?? '',
            ':desc'     => $fw['description'] ?? null,
            ':status'   => $fw['status'] ?? null,
            ':cat'      => $fw['category_name'] ?? null,
            ':assignee' => $fw['assignee_name'] ?? null,
            ':due'      => $fw['due_date'] ?? null,
            ':fwc'      => $fw['created_at'] ?? null,
            ':fwu'      => $fw['updated_at'] ?? null,
        ]);
    }

    public function updateStatus(string $fwId, string $status): void
    {
        $this->db->prepare("
            UPDATE bb_fw_tasks SET status = :s, synced_at = NOW() WHERE fw_id = :id
        ")->execute([':s' => $status, ':id' => $fwId]);
    }

    public function deleteByFwId(string $fwId): void
    {
        $this->db->prepare("DELETE FROM bb_fw_tasks WHERE fw_id = :id")
                 ->execute([':id' => $fwId]);
    }
}
