<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

final class FwBubbleRepository
{
    public function __construct(private PDO $db) {}

    public function allForTask(string $fwTaskId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_fw_bubbles
            WHERE fw_task_id = :tid
            ORDER BY fw_created_at ASC, id ASC
        ");
        $stmt->execute([':tid' => $fwTaskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(int $worksiteId, string $fwTaskId, array $fw): void
    {
        $this->db->prepare("
            INSERT INTO bb_fw_bubbles
                (worksite_id, fw_task_id, fw_id, kind, text, creator_name, creator_email, file_url, fw_created_at, synced_at)
            VALUES
                (:wid, :tid, :fwid, :kind, :text, :cname, :cemail, :furl, :fwc, NOW())
            ON DUPLICATE KEY UPDATE
                text          = VALUES(text),
                file_url      = VALUES(file_url),
                synced_at     = NOW()
        ")->execute([
            ':wid'    => $worksiteId,
            ':tid'    => $fwTaskId,
            ':fwid'   => $fw['id'],
            ':kind'   => $fw['kind'] ?? null,
            ':text'   => $fw['text'] ?? null,
            ':cname'  => $fw['creator_name'] ?? null,
            ':cemail' => $fw['creator_email'] ?? null,
            ':furl'   => $fw['file_url'] ?? null,
            ':fwc'    => $fw['created_at'] ?? null,
        ]);
    }

    public function insert(int $worksiteId, string $fwTaskId, array $fw): void
    {
        $this->upsert($worksiteId, $fwTaskId, $fw);
    }

    public function deleteByFwId(string $fwId): void
    {
        $this->db->prepare("DELETE FROM bb_fw_bubbles WHERE fw_id = :id")
                 ->execute([':id' => $fwId]);
    }
}
