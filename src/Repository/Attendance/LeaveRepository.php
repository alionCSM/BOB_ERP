<?php
declare(strict_types=1);

namespace App\Repository\Attendance;

use PDO;

/**
 * Ferie e permessi (bb_ferie_permessi).
 * Stesso pattern di AdvanceRepository/FineRepository.
 */
class LeaveRepository
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getAll(): array
    {
        $stmt = $this->conn->query("
            SELECT fp.*,
                   CONCAT(w.first_name, ' ', w.last_name) AS operaio_nome,
                   w.company AS operaio_azienda,
                   w.id AS operaio_id
            FROM bb_ferie_permessi fp
            JOIN bb_workers w ON fp.worker_id = w.id
            ORDER BY fp.data_inizio DESC, fp.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByWorker(int $workerId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM bb_ferie_permessi
            WHERE worker_id = :wid
            ORDER BY data_inizio DESC, id DESC
        ");
        $stmt->execute([':wid' => $workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(int $workerId, string $tipo, string $from, string $to, ?float $ore, string $note, int $createdBy): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ferie_permessi (worker_id, tipo, data_inizio, data_fine, ore, note, created_by)
            VALUES (:wid, :tipo, :dal, :al, :ore, :note, :uid)
        ");
        $stmt->execute([
            ':wid'  => $workerId,
            ':tipo' => $tipo,
            ':dal'  => $from,
            ':al'   => $to,
            ':ore'  => $ore,
            ':note' => $note !== '' ? $note : null,
            ':uid'  => $createdBy ?: null,
        ]);
    }

    public function update(int $id, int $workerId, string $tipo, string $from, string $to, ?float $ore, string $note): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_ferie_permessi
            SET worker_id = :wid, tipo = :tipo, data_inizio = :dal,
                data_fine = :al, ore = :ore, note = :note
            WHERE id = :id
        ");
        $stmt->execute([
            ':wid'  => $workerId,
            ':tipo' => $tipo,
            ':dal'  => $from,
            ':al'   => $to,
            ':ore'  => $ore,
            ':note' => $note !== '' ? $note : null,
            ':id'   => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->conn->prepare("DELETE FROM bb_ferie_permessi WHERE id = :id")
                   ->execute([':id' => $id]);
    }
}
