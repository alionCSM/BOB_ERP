<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface WorkerDocumentRepositoryInterface
{
    public function findWorkerUidById(int $workerId): ?string;

    public function createDocument(int $workerId, string $docType, string $dateEmission, ?string $expiryDate, string $relativePath): void;

    public function findDocumentById(int $documentId): ?array;

    public function deleteDocumentById(int $documentId): void;

    public function findDocumentWithWorkerById(int $documentId): ?array;

    public function archiveDocumentVersion(int $documentId, array $current, string $archivedDbPath, int $archivedBy): void;

    public function updateDocumentMetadata(int $documentId, string $docType, string $dateEmission, ?string $expiryDate, string $path): void;

    public function getExpiredCompanyDocuments(string $today, bool $isScoped, array $allowedCompanyNames): array;

    public function getExpiredWorkerDocuments(string $today, bool $isScoped, array $allowedCompanyNames): array;

    public function getExpiringCompanyDocuments(string $today, string $futureDate, bool $isScoped, array $allowedCompanyNames): array;

    public function getExpiringWorkerDocuments(string $today, string $futureDate, bool $isScoped, array $allowedCompanyNames): array;

    public function getWorkerDocumentsExpiringOnDate(string $date, array $companyNames = []): array;

    public function getCompanyDocumentsExpiringOnDate(string $date, array $companyNames = []): array;
}
