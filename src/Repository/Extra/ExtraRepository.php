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
        // Aggregate all bb_billing rows linked to this extra so the cantiere
        // page can show partial-billing state. One extra can be invoiced
        // across multiple righe (e.g. partial billing + a separate "saldo"
        // riga to cover the rest).
        //
        // Returned fields:
        //   billing_linked_count : # of bb_billing rows pointing at this extra
        //   billing_total        : SUM of their totale_imponibile
        //   billing_id           : most recent linked riga id (for the chip link)
        //   billing_data         : its date
        $stmt = $this->conn->prepare("
            SELECT
                e.*,
                COALESCE(agg.linked_count, 0) AS billing_linked_count,
                COALESCE(agg.linked_total, 0) AS billing_total,
                b.id   AS billing_id,
                b.data AS billing_data
            FROM bb_extra e
            LEFT JOIN (
                SELECT
                    extra_id,
                    COUNT(*)                 AS linked_count,
                    SUM(totale_imponibile)   AS linked_total,
                    MAX(id)                  AS max_id
                FROM bb_billing
                WHERE extra_id IS NOT NULL
                GROUP BY extra_id
            ) agg ON agg.extra_id = e.id
            LEFT JOIN bb_billing b ON b.id = agg.max_id
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
