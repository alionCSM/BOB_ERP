<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface StrutturaRepositoryInterface
{
    public function search(string $query, ?string $type = null): array;

    public function getById(int $id): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): void;
}
