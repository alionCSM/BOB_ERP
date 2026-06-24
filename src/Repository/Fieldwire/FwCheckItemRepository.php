<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

final class FwCheckItemRepository
{
    public function __construct(private PDO $db) {}

    public function allForTask(string $fwTaskId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_fw_check_items WHERE fw_task_id = :tid ORDER BY id ASC
        ");
        $stmt->execute([':tid' => $fwTaskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(int $worksiteId, string $fwTaskId, array $fw): void
    {
        $this->db->prepare("
            INSERT INTO bb_fw_check_items (worksite_id, fw_task_id, fw_id, name, completed, synced_at)
            VALUES (:wid, :tid, :fwid, :name, :done, NOW())
            ON DUPLICATE KEY UPDATE
                name      = VALUES(name),
                completed = VALUES(completed),
                synced_at = NOW()
        ")->execute([
            ':wid'  => $worksiteId,
            ':tid'  => $fwTaskId,
            ':fwid' => $fw['id'],
            ':name' => $fw['name'] ?? '',
            ':done' => (int) ($fw['completed'] ?? false),
        ]);
    }

    public function markComplete(string $fwId): void
    {
        $this->db->prepare("
            UPDATE bb_fw_check_items SET completed = 1, synced_at = NOW() WHERE fw_id = :id
        ")->execute([':id' => $fwId]);
    }

    public function deleteByFwId(string $fwId): void
    {
        $this->db->prepare("DELETE FROM bb_fw_check_items WHERE fw_id = :id")
                 ->execute([':id' => $fwId]);
    }
}
