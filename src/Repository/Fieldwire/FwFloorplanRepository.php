<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

final class FwFloorplanRepository
{
    public function __construct(private PDO $db) {}

    public function allForWorksite(int $worksiteId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_fw_floorplans WHERE worksite_id = :wid ORDER BY name ASC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsert(int $worksiteId, array $fw): void
    {
        $this->db->prepare("
            INSERT INTO bb_fw_floorplans (worksite_id, fw_id, name, sheets_count, fw_updated_at, synced_at)
            VALUES (:wid, :fwid, :name, :sheets, :fwu, NOW())
            ON DUPLICATE KEY UPDATE
                name         = VALUES(name),
                sheets_count = VALUES(sheets_count),
                fw_updated_at = VALUES(fw_updated_at),
                synced_at    = NOW()
        ")->execute([
            ':wid'    => $worksiteId,
            ':fwid'   => $fw['id'],
            ':name'   => $fw['name'] ?? '',
            ':sheets' => $fw['sheets_count'] ?? null,
            ':fwu'    => $fw['updated_at'] ?? null,
        ]);
    }

    public function deleteByFwId(string $fwId): void
    {
        $this->db->prepare("DELETE FROM bb_fw_floorplans WHERE fw_id = :id")
                 ->execute([':id' => $fwId]);
    }
}
