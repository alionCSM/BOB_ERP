<?php

declare(strict_types=1);

namespace App\Service\Tickets;
use RuntimeException;
use PDO;
use App\Repository\Tickets\MealTicketRepository;

class MealTicketService
{
    private MealTicketRepository $repo;

    public function __construct(PDO $conn)
    {
        $this->repo = new MealTicketRepository($conn);
    }

    // ── Create / Update ──────────────────────────

    /**
     * Create a new ticket. Returns the ticket ID.
     * Throws if a duplicate worker+date already exists.
     */
    public function create(string $workerName, string $date, int $createdBy): int
    {
        $existing = $this->repo->findByWorkerAndDate($workerName, $date);
        if ($existing) {
            throw new RuntimeException('Bigliettino già esistente per questo operaio e data.');
        }
        return $this->repo->insert($workerName, $date, $createdBy);
    }

    public function update(int $id, string $workerName, string $date): void
    {
        $this->repo->update($id, $workerName, $date);
    }

    public function delete(int $id): void
    {
        $this->repo->delete($id);
    }

    // ── Print ─────────────────────────────────────

    /**
     * Mark a ticket as printed, assigning a hash and progressive number.
     * Returns ['hash' => ..., 'progressivo' => ...]
     */
    public function markPrinted(int $id): array
    {
        $ticket = $this->repo->getById($id);
        if (!$ticket) {
            throw new RuntimeException('Bigliettino non trovato.');
        }

        // Already printed? Return existing values
        if ($ticket['printed'] && !empty($ticket['hash'])) {
            return [
                'hash' => $ticket['hash'],
                'progressivo' => (int)$ticket['progressivo'],
            ];
        }

        $hash = bin2hex(random_bytes(7));

        $month = (int)date('m', strtotime($ticket['ticket_date']));
        $year  = (int)date('Y', strtotime($ticket['ticket_date']));
        $nextProg = $this->repo->getMaxProgressivo($month, $year) + 1;

        $this->repo->markPrinted($id, $hash, $nextProg);

        return ['hash' => $hash, 'progressivo' => $nextProg];
    }

    /**
     * Find or create + mark printed for a worker/date combo.
     * Returns the full ticket data needed for PDF.
     */
    public function findOrCreateAndPrint(string $workerName, string $date, int $createdBy): array
    {
        $existing = $this->repo->findByWorkerAndDate($workerName, $date);

        if ($existing) {
            $id = (int)$existing['id'];
        } else {
            $id = $this->repo->insert($workerName, $date, $createdBy);
        }

        $printData = $this->markPrinted($id);

        return [
            'id'          => $id,
            'worker_name' => $workerName,
            'ticket_date' => $date,
            'hash'        => $printData['hash'],
            'progressivo' => $printData['progressivo'],
        ];
    }

    // ── Queries ───────────────────────────────────

    public function getById(int $id): ?array
    {
        return $this->repo->getById($id);
    }

    public function getAll(array $filters = [], int $limit = 800): array
    {
        return $this->repo->getAll($filters, $limit);
    }

    public function countPrintedByMonth(int $month, int $year): int
    {
        return $this->repo->countPrintedByMonth($month, $year);
    }

    public function getReportByDateRange(string $from, string $to): array
    {
        return $this->repo->getReportByDateRange($from, $to);
    }

    public function getTicketsByDateRange(string $from, string $to): array
    {
        return $this->repo->getTicketsByDateRange($from, $to);
    }

    // ── Worker search (for TomSelect) ─────────────

    public function searchWorkers(PDO $conn, string $query): array
    {
        $stmt = $conn->prepare(
            "SELECT CONCAT(first_name, ' ', last_name) AS nome_completo
             FROM bb_workers
             WHERE (first_name LIKE :q1 OR last_name LIKE :q2 OR CONCAT(first_name, ' ', last_name) LIKE :q3)
               AND active = 'Y'
             GROUP BY first_name, last_name
             ORDER BY first_name, last_name
             LIMIT 20"
        );
        $term = '%' . $query . '%';
        $stmt->execute([':q1' => $term, ':q2' => $term, ':q3' => $term]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
