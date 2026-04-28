<?php

declare(strict_types=1);

namespace App\Repository\Tickets;
use PDO;
use App\Repository\Contracts\MealTicketRepositoryInterface;

class MealTicketRepository implements MealTicketRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    // ── CRUD ──────────────────────────────────────

    public function findByWorkerAndDate(string $workerName, string $date): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM bb_meal_tickets WHERE worker_name = :name AND ticket_date = :d LIMIT 1'
        );
        $stmt->execute([':name' => $workerName, ':d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_meal_tickets WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(string $workerName, string $date, int $createdBy): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO bb_meal_tickets (worker_name, ticket_date, printed, created_by)
             VALUES (:name, :d, 0, :cb)'
        );
        $stmt->execute([':name' => $workerName, ':d' => $date, ':cb' => $createdBy]);
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, string $workerName, string $date): void
    {
        $stmt = $this->conn->prepare(
            'UPDATE bb_meal_tickets SET worker_name = :name, ticket_date = :d WHERE id = :id'
        );
        $stmt->execute([':name' => $workerName, ':d' => $date, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_meal_tickets WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    // ── Printing / Progressivo ────────────────────

    public function getMaxProgressivo(int $month, int $year): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COALESCE(MAX(progressivo), 0) FROM bb_meal_tickets
             WHERE MONTH(ticket_date) = :m AND YEAR(ticket_date) = :y'
        );
        $stmt->execute([':m' => $month, ':y' => $year]);
        return (int)$stmt->fetchColumn();
    }

    public function markPrinted(int $id, string $hash, int $progressivo): void
    {
        $stmt = $this->conn->prepare(
            'UPDATE bb_meal_tickets SET printed = 1, hash = :h, progressivo = :p WHERE id = :id'
        );
        $stmt->execute([':h' => $hash, ':p' => $progressivo, ':id' => $id]);
    }

    // ── Listings ──────────────────────────────────

    /**
     * @param array $filters Keys: search, month, year, printed
     */
    public function getAll(array $filters = [], int $limit = 800): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(worker_name LIKE :s1 OR CAST(progressivo AS CHAR) LIKE :s2)';
            $params[':s1'] = '%' . $filters['search'] . '%';
            $params[':s2'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[] = 'MONTH(ticket_date) = :fm AND YEAR(ticket_date) = :fy';
            $params[':fm'] = (int)$filters['month'];
            $params[':fy'] = (int)$filters['year'];
        }
        if (isset($filters['printed']) && $filters['printed'] !== '') {
            $where[] = 'printed = :pr';
            $params[':pr'] = (int)$filters['printed'];
        }

        $sql = 'SELECT * FROM bb_meal_tickets';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY YEAR(ticket_date) DESC, MONTH(ticket_date) DESC, progressivo DESC LIMIT ' . $limit;

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Stats ─────────────────────────────────────

    public function countPrintedByMonth(int $month, int $year): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM bb_meal_tickets
             WHERE MONTH(ticket_date) = :m AND YEAR(ticket_date) = :y AND printed = 1'
        );
        $stmt->execute([':m' => $month, ':y' => $year]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Report: printed tickets between two dates, grouped by worker
     */
    public function getReportByDateRange(string $from, string $to): array
    {
        $stmt = $this->conn->prepare(
            'SELECT worker_name, COUNT(*) as total_tickets
             FROM bb_meal_tickets
             WHERE ticket_date BETWEEN :from AND :to AND printed = 1
             GROUP BY worker_name
             ORDER BY worker_name'
        );
        $stmt->execute([':from' => $from, ':to' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTicketsByDateRange(string $from, string $to): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM bb_meal_tickets
             WHERE ticket_date BETWEEN :from AND :to AND printed = 1
             ORDER BY ticket_date, worker_name'
        );
        $stmt->execute([':from' => $from, ':to' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
