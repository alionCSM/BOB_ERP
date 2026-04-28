<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface CompanyRepositoryInterface
{
    public function getAll(): array;

    public function getById(int $id): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function getDocuments(int $companyId): array;

    public function getDocumentById(int $id): ?array;

    public function createDocument(int $companyId, string $docType, ?string $dateEmission, ?string $expiryDate, string $relativePath, int $uploadedBy): void;

    public function archiveDocument(int $documentId, array $current, string $archivedDbPath, int $userId): void;

    public function updateDocument(int $documentId, string $docType, ?string $dateEmission, ?string $expiryDate, string $path): void;

    public function deleteDocumentById(int $id): void;

    public function getWorkersByCompanyId(int $companyId): array;

    public function getAllCompanyNames(): array;

    public function hasCompanyAccessMap(): bool;

    public function getAllowedCompanyIdsForUser(int $userId): array;

    public function getAssignableCompanyUsers(int $companyId): array;

    public function getCompaniesByIds(array $ids): array;

    public function countCompanyAccessByUserId(int $userId): int;

    public function findCompanyNameAndConsorziata(int $companyId): ?array;

    public function getConsorziataPresenceDetailRows(int $companyId, string $startDate, string $endDate): array;

    public function getConsorziataPresenceSummaryRows(int $companyId, string $startDate, string $endDate): array;

    public function getInternalPresenceDetailRows(string $companyName, string $startDate, string $endDate): array;

    public function getInternalPresenceSummaryRows(string $companyName, string $startDate, string $endDate): array;

    public function emailAlreadyUsed(string $email): bool;

    public function insertCompanyUser(array $data): int;

    public function clearUserPermissions(int $userId): void;

    public function addUserPermission(int $userId, string $module, int $allowed): void;

    public function addUserCompanyAccess(int $userId, int $companyId): void;

    public function companyExists(int $companyId): bool;

    public function companyUserExists(int $userId): bool;

    public function companyAccessExists(int $userId, int $companyId): bool;

    public function userHasCompaniesViewerPermission(int $userId): bool;

    public function setActive(int $id, int $active): void;

    public function deactivateWorkersByCompany(int $companyId, string $companyName): int;

    public function transaction(callable $fn): mixed;
}
