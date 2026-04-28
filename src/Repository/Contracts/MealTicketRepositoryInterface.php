<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface MealTicketRepositoryInterface
{
    public function findByWorkerAndDate(string $workerName, string $date): ?array;

    public function getById(int $id): ?array;

    public function insert(string $workerName, string $date, int $createdBy): int;

    public function update(int $id, string $workerName, string $date): void;

    public function delete(int $id): void;

    public function getMaxProgressivo(int $month, int $year): int;

    public function markPrinted(int $id, string $hash, int $progressivo): void;

    public function getAll(array $filters = [], int $limit = 800): array;

    public function countPrintedByMonth(int $month, int $year): int;

    public function getReportByDateRange(string $from, string $to): array;

    public function getTicketsByDateRange(string $from, string $to): array;
}
