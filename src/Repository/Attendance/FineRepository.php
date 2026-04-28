<?php
declare(strict_types=1);

namespace App\Repository\Attendance;
use PDO;
use App\Repository\Contracts\FineRepositoryInterface;

class FineRepository implements FineRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getAll(): array
    {
        $stmt = $this->conn->query("
            SELECT m.*,
                   CONCAT(w.first_name, ' ', w.last_name) AS operaio_nome,
                   w.id AS operaio_id
            FROM bb_multe m
            JOIN bb_workers w ON m.operaio_id = w.id
            ORDER BY m.data DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $date, int $workerId, float $amount, string $targa, string $note): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_multe (data, operaio_id, importo, targa, note)
            VALUES (:data, :worker, :amount, :targa, :note)
        ");

        $stmt->execute([
            ':data'   => $date,
            ':worker' => $workerId,
            ':amount' => $amount,
            ':targa'  => $targa,
            ':note'   => $note
        ]);
    }

    public function update(int $id, string $date, int $workerId, float $amount, string $targa, string $note): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_multe
            SET data = :data,
                operaio_id = :worker,
                importo = :amount,
                targa = :targa,
                note = :note
            WHERE id = :id
        ");

        $stmt->execute([
            ':data'   => $date,
            ':worker' => $workerId,
            ':amount' => $amount,
            ':targa'  => $targa,
            ':note'   => $note,
            ':id'     => $id
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_multe WHERE id = :id
        ");

        $stmt->execute([':id' => $id]);
    }
}