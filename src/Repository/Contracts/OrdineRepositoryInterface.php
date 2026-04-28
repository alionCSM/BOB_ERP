<?php
declare(strict_types=1);
namespace App\Repository\Contracts;

interface OrdineRepositoryInterface
{
    public function getAll(int $userCompanyId): array;
    public function getById(int $id, int $userCompanyId): ?array;
    public function getItems(int $ordineId): array;
    public function getConsorziate(): array;
    public function getWorksites(int $userCompanyId): array;
    public function getNextOrderNumber(int $companyId): int;
    public function create(array $data, int $companyId): int;
    public function replaceItems(int $ordineId, array $items): void;
    public function update(array $data, int $ordineId, int $userCompanyId): bool;
    public function delete(int $id, int $userCompanyId): bool;
    public function updateStatus(int $ordineId, string $status, int $userCompanyId): bool;
}
