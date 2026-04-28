<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface WorkerRepositoryInterface
{
    public function createWorker(array $data, int $createdBy, ?string $photoPath): array;

    public function getUidById(int $workerId): ?string;

    public function updatePhoto(int $workerId, string $photoPath): bool;

    public function updateInfo(int $workerId, array $data): void;

    public function setActiveStatus(int $workerId, string $status): bool;

    public function loadByFiscalCode(string $fiscalCode): ?array;

    public function insertCompanyHistory(array $data): void;

    public function updateCompanyByFiscalCode(string $fiscalCode, string $company, string $startDate, string $role): bool;
}
