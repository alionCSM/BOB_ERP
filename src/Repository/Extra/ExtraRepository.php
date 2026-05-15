<?php

declare(strict_types=1);

namespace App\Repository\Extra;

use PDO;

/**
 * All bb_extra SQL in one place.
 * Replaces App\Domain\Extra.
 */
final class ExtraRepository
{
    public function __construct(private PDO $conn) {}

    public function getByWorksiteId(int $worksiteId): array
    {
        // Also surfaces whether this extra has already been turned into a
        // billing row (via the "Fattura" button on the cantiere extras tab).
        // billing_id / billing_data: most recent linked riga, if any.
        $stmt = $this->conn->prepare("
            SELECT
                e.*,
                b.id   AS billing_id,
                b.data AS billing_data,
                b.totale_imponibile AS billing_totale
            FROM bb_extra e
            LEFT JOIN (
                SELECT extra_id, MAX(id) AS max_id
                FROM bb_billing
                WHERE extra_id IS NOT NULL
                GROUP BY extra_id
            ) latest ON latest.extra_id = e.id
            LEFT JOIN bb_billing b ON b.id = latest.max_id
            WHERE e.worksite_id = :wid
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_extra (worksite_id, data, ordine, descrizione, totale)
            VALUES (:worksite_id, :data, :ordine, :descrizione, :totale)
        ");
        $stmt->execute([
            ':worksite_id' => $data['worksite_id'],
            ':data'        => $data['data'],
            ':ordine'      => !empty($data['ordine']) ? $data['ordine'] : null,
            ':descrizione' => $data['descrizione'],
            ':totale'      => $data['totale'],
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): int
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_extra
            SET data        = :data,
                ordine      = :ordine,
                descrizione = :descrizione,
                totale      = :totale
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'          => $id,
            ':data'        => $data['data'],
            ':ordine'      => !empty($data['ordine']) ? $data['ordine'] : null,
            ':descrizione' => $data['descrizione'],
            ':totale'      => $data['totale'],
        ]);
        return $stmt->rowCount();
    }

    public function delete(int $id): int
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }
}
