<?php

declare(strict_types=1);

namespace App\Service\Documents;

use PDO;
use User;
use WorkerDocumentRepository;
use WorkerDocumentService;
use WorkerDocumentUploadValidator;
use WorkerDocumentUpdateValidator;
use RuntimeException;
use InvalidArgumentException;

/**
 * Thin coordinator between HTTP layer and WorkerDocumentService.
 * Moved from controllers/documents/ — no behaviour changed.
 * Relies on assertCompanyScopeWorkerAccess() etc. loaded via bootstrap → company_scope.php.
 */
class WorkerDocumentController
{
    private WorkerDocumentRepository $repository;
    private WorkerDocumentService $service;
    private WorkerDocumentUploadValidator $uploadValidator;
    private WorkerDocumentUpdateValidator $updateValidator;

    public function __construct(private PDO $conn)
    {
        $this->repository     = new WorkerDocumentRepository($conn);
        $this->service        = new WorkerDocumentService($this->repository);
        $this->uploadValidator = new WorkerDocumentUploadValidator();
        $this->updateValidator = new WorkerDocumentUpdateValidator();
    }

    public function uploadFromRequest(User $user, array $post, array $files): void
    {
        $payload = $this->uploadValidator->validate($post, $files);
        assertCompanyScopeWorkerAccess($this->conn, $user, (int)$payload['worker_id']);
        $this->service->upload($payload);
    }

    public function updateFromRequest(User $user, int $userId, array $post, array $files): void
    {
        $payload = $this->updateValidator->validate($post, $files);
        $current = $this->repository->findDocumentWithWorkerById((int)$payload['document_id']);
        if (!$current) {
            throw new RuntimeException('Documento non trovato.');
        }

        assertCompanyScopeWorkerAccess($this->conn, $user, (int)$current['worker_id']);
        $this->service->update($payload, $userId);
    }

    public function deleteById(User $user, int $docId): void
    {
        if ($docId <= 0) {
            throw new InvalidArgumentException('Documento non specificato.');
        }

        $doc = $this->repository->findDocumentById($docId);
        if (!$doc) {
            throw new RuntimeException('Documento non trovato.');
        }

        assertCompanyScopeWorkerAccess($this->conn, $user, (int)$doc['worker_id']);
        $this->service->delete($docId);
    }

    public function resolveSafeRedirect(string $referer, string $fallback = '/users'): string
    {
        $parsed = parse_url($referer);
        $isLocalRef = !empty($parsed['path']) && empty($parsed['host']);
        return $isLocalRef ? (string)$parsed['path'] : $fallback;
    }

    public function getExpiredDocuments(User $user): array
    {
        $today = date('Y-m-d');
        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $user);
        $isScoped = isCompanyScopedUserByContext($this->conn, $user);

        return $this->service->getExpiredDocuments($today, $isScoped, $allowedCompanyNames);
    }

    public function getExpiringDocuments(User $user, int $daysAhead = 30): array
    {
        $today = date('Y-m-d');
        $futureDate = date('Y-m-d', strtotime("+{$daysAhead} days"));
        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $user);
        $isScoped = isCompanyScopedUserByContext($this->conn, $user);

        return $this->service->getExpiringDocuments($today, $futureDate, $isScoped, $allowedCompanyNames);
    }
}
