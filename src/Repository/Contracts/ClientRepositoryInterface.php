<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface ClientRepositoryInterface
{
    public function getAll(): array;

    public function getAllWithStats(): array;

    public function getById(int $id): ?array;

    public function insert(array $data): int;

    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function getWorksitesByClientId(int $clientId): array;

    public function getLastOfferInfoByClientId(int $clientId): ?array;

    public function countOffersByClientId(int $clientId): int;

    public function countWorksitesByClientId(int $clientId): int;

    public function searchByName(string $query): array;
}
