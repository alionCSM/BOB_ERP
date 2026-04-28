<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface SharedLinkRepositoryInterface
{
    public function getAllLinksByUser(int $userId): array;

    public function getAllLinks(): array;

    public function createLink(int $userId, string $title, ?string $expiresAt, ?string $passwordHash): int;

    public function deleteLink(int $userId, int $linkId): bool;

    public function addFileToLink(int $linkId, string $filePath, string $originalName, ?int $workerId, string $source, ?int $companyId): void;

    public function getWorkerDocumentById(int $docId): ?array;

    public function getCompanyDocumentById(int $docId): ?array;

    public function getAllCompanies(): array;

    public function getCompanyById(int $id): ?array;

    public function getCompanyDocuments(int $companyId): array;

    public function getWorkerDocuments(int $workerId): array;

    public function updatePassword(int $linkId, ?string $passwordHash): void;

    public function getLinkByToken(string $token): ?array;

    public function getLinkById(int $id): ?array;

    public function getFilesForLink(int $linkId): array;

    public function updateLink(int $linkId, string $title, ?string $expiresAt): void;

    public function removeFileFromLink(int $fileId, int $linkId): void;

    public function removeFilesBySource(int $linkId, string $source, ?int $entityId = null): void;

    public function deactivateLink(int $linkId): void;

    public function activateLink(int $linkId): void;

    public function toggleActive(int $linkId): bool;

    public function addWorkerToLink(int $linkId, int $workerId): void;

    public function addCompanyToLink(int $linkId, int $companyId): void;

    public function removeWorkerFromLink(int $linkId, int $workerId): void;

    public function removeCompanyFromLink(int $linkId, int $companyId): void;

    public function getLinkedWorkers(int $linkId): array;

    public function getLinkedCompanies(int $linkId): array;

    public function getLiveFilesForLink(int $linkId): array;

    public function getLinkCounts(int $linkId): array;

    public function logDownload(int $linkId, ?int $fileId, string $ip, string $fileName): void;

    public function getWorkerDocumentsByWorkerIds(array $ids): array;
}
