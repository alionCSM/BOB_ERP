<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface RefundRepositoryInterface
{
    public function getAll(): array;

    public function insert(string $date, int $workerId, float $amount, string $note): void;

    public function update(int $id, string $date, int $workerId, float $amount, string $note): void;

    public function delete(int $id): void;
}
