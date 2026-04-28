<?php

declare(strict_types=1);

namespace App\Service\Companies;

use PDO;
use User;
use CompanyService;
use CompanyRepository;
use CompanyValidator;

/**
 * Thin coordinator between HTTP layer and CompanyService.
 * Moved from controllers/companies/ — no behaviour changed.
 */
class CompanyController
{
    private CompanyService $service;

    public function __construct(private PDO $conn)
    {
        $this->service = new CompanyService(new CompanyRepository($conn), new CompanyValidator());
    }

    public function listCompanies(): array
    {
        return $this->service->getAll();
    }

    public function getCompanyById(int $id): ?array
    {
        return $this->service->getById($id);
    }

    public function createFromRequest(array $post): int
    {
        return $this->service->create($post);
    }

    public function updateFromRequest(int $id, array $post): void
    {
        $this->service->update($id, $post);
    }

    public function deleteCompany(User $user, int $id): void
    {
        $this->service->delete($user, $id);
    }

    public function uploadDocumentFromRequest(array $post, array $files, int $userId): void
    {
        $this->service->uploadDocument($post, $files, $userId);
    }

    public function updateDocumentFromRequest(array $post, array $files, int $userId): void
    {
        $this->service->updateDocument($post, $files, $userId);
    }

    public function deleteDocument(int $documentId): void
    {
        $this->service->deleteDocument($documentId);
    }

    public function getDocumentById(int $documentId): ?array
    {
        $repo = new CompanyRepository($this->conn);
        return $repo->getDocumentById($documentId);
    }

    public function getCompanyDetails(User $user, int $companyId): array
    {
        return $this->service->resolveCompanyDetails($user, $companyId);
    }

    public function getMyCompanies(User $user): array
    {
        return $this->service->getMyCompanies($user);
    }

    public function createCompanyUser(int $actingUserId, array $post): array
    {
        return $this->service->createCompanyUser($actingUserId, $post);
    }

    public function assignCompanyAccess(int $companyId, int $userId): void
    {
        $this->service->assignCompanyAccess($companyId, $userId);
    }

    public function getConsorziataExportData(int $companyId, string $startDate, string $endDate): array
    {
        return $this->service->getConsorziataExportData($companyId, $startDate, $endDate);
    }

    public function toggleActive(int $id): array
    {
        return $this->service->toggleActive($id);
    }

    public function deleteWorker(User $user, int $companyId, int $workerId): void
    {
        $this->service->deleteWorker($user, $companyId, $workerId);
    }

    public function getWorkerById(int $workerId): ?array
    {
        $repo = new CompanyRepository($this->conn);
        return $repo->getWorkerById($workerId);
    }
}
