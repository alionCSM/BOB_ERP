<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use PDO;

/**
 * All bb_ordini SQL in one place.
 * Replaces App\Domain\Ordine.
 */
final class OrderRepository
{
    public function __construct(private PDO $conn) {}

    public function getAll(): array
    {
        $stmt = $this->conn->prepare("
            SELECT o.*, c.name AS company_name
            FROM bb_ordini o
            JOIN bb_companies c ON o.company_id = c.id
            ORDER BY o.order_date DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT o.*, c.name AS company_name
            FROM bb_ordini o
            JOIN bb_companies c ON o.company_id = c.id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByWorksiteId(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT o.*,
                   COALESCE(d.name, c.name) AS company_name
            FROM bb_ordini o
            LEFT JOIN bb_companies c ON o.company_id     = c.id
            LEFT JOIN bb_companies d ON o.destinatario_id = d.id
            WHERE o.worksite_id = :worksite_id
            ORDER BY o.order_date DESC
        ");
        $stmt->execute([':worksite_id' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ordini (worksite_id, order_date, company_id, total, note)
            VALUES (:worksite_id, :order_date, :company_id, :total, :note)
        ");
        $stmt->execute([
            ':worksite_id' => $data['worksite_id'],
            ':order_date'  => $data['order_date'],
            ':company_id'  => $data['company_id'],
            ':total'       => $data['total'],
            ':note'        => $data['note'],
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): int
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_ordini
            SET order_date  = :order_date,
                company_id  = :company_id,
                total       = :total,
                note        = :note
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'          => $id,
            ':order_date'  => $data['order_date'],
            ':company_id'  => $data['company_id'],
            ':total'       => $data['total'],
            ':note'        => $data['note'],
        ]);
        return $stmt->rowCount();
    }

    public function delete(int $id): int
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_ordini WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }
}
