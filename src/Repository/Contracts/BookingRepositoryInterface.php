<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface BookingRepositoryInterface
{
    public function getAllBookings(array $filters = []): array;

    public function getById(int $id): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function getPeriods(int $bookingId): array;

    public function addPeriod(int $bookingId, array $period): void;

    public function deletePeriods(int $bookingId): void;

    public function getFatture(int $bookingId): array;

    public function addFattura(int $bookingId, array $data): int;

    public function deleteFattura(int $fatturaId, int $bookingId): ?string;
}
