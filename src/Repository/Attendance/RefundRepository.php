<?php
declare(strict_types=1);

namespace App\Repository\Attendance;
use PDO;
use App\Repository\Contracts\RefundRepositoryInterface;

class RefundRepository implements RefundRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getAll(): array
    {
        $stmt = $this->conn->query("
            SELECT r.*,
                   CONCAT(w.first_name, ' ', w.last_name) AS operaio_nome,
                   w.id AS operaio_id
            FROM bb_rimborsi r
            JOIN bb_workers w ON r.operaio_id = w.id
            ORDER BY r.data DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $date, int $workerId, float $amount, string $note): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_rimborsi (data, operaio_id, importo, note)
            VALUES (:data, :worker, :amount, :note)
        ");

        $stmt->execute([
            ':data'   => $date,
            ':worker' => $workerId,
            ':amount' => $amount,
            ':note'   => $note
        ]);
    }

    public function update(int $id, string $date, int $workerId, float $amount, string $note): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_rimborsi
            SET data = :data,
                operaio_id = :worker,
                importo = :amount,
                note = :note
            WHERE id = :id
        ");

        $stmt->execute([
            ':data'   => $date,
            ':worker' => $workerId,
            ':amount' => $amount,
            ':note'   => $note,
            ':id'     => $id
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_rimborsi WHERE id = :id
        ");

        $stmt->execute([':id' => $id]);
    }
}