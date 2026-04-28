<?php

declare(strict_types=1);

namespace App\Service\Share;

use PDO;
use SharedLinkRepository;
use SharedLinkService;
use CreateShareLinkValidator;

/**
 * Thin coordinator between HTTP layer and SharedLinkService.
 * Moved from controllers/share/ — no behaviour changed.
 */
class SharedLinkController
{
    private SharedLinkService $service;
    private CreateShareLinkValidator $validator;

    public function __construct(PDO $conn, int $userId)
    {
        $repository    = new SharedLinkRepository($conn);
        $this->service   = new SharedLinkService($repository, $userId);
        $this->validator = new CreateShareLinkValidator();
    }

    public function getAllLinks(): array
    {
        return $this->service->getAllLinks();
    }

    public function createFromRequest(array $post, array $files): int
    {
        $payload = $this->validator->validate($post);
        return $this->service->createLinkFromPayload($payload, $files);
    }

    public function updateFromRequest(int $linkId, array $post, array $files): void
    {
        $payload = $this->validator->validate($post);

        $payload['removed_files'] = !empty($post['removed_files'])
            ? array_map('intval', (array)json_decode((string)$post['removed_files'], true))
            : [];
        $payload['removed_workers'] = !empty($post['removed_workers'])
            ? array_map('intval', (array)json_decode((string)$post['removed_workers'], true))
            : [];
        $payload['removed_companies'] = !empty($post['removed_companies'])
            ? array_map('intval', (array)json_decode((string)$post['removed_companies'], true))
            : [];

        $this->service->updateLinkFromPayload($linkId, $payload, $files);
    }

    public function getLinkById(int $linkId): ?array
    {
        return $this->service->getLinkById($linkId);
    }

    public function getFilesForLink(int $linkId): array
    {
        return $this->service->getFilesForLink($linkId);
    }

    public function getLiveFilesForLink(int $linkId): array
    {
        return $this->service->getLiveFilesForLink($linkId);
    }

    public function getLinkedWorkers(int $linkId): array
    {
        return $this->service->getLinkedWorkers($linkId);
    }

    public function getLinkedCompanies(int $linkId): array
    {
        return $this->service->getLinkedCompanies($linkId);
    }

    public function toggleActive(int $linkId): bool
    {
        return $this->service->toggleActive($linkId);
    }

    public function deleteById(int $linkId): bool
    {
        return $this->service->deleteLink($linkId);
    }

    public function updatePassword(int $linkId, ?string $password): void
    {
        $hash = ($password !== null && $password !== '')
            ? password_hash($password, PASSWORD_DEFAULT)
            : null;
        $this->service->updatePassword($linkId, $hash);
    }

    public function getAllCompanies(): array
    {
        return $this->service->getAllCompanies();
    }

    public function getCompanyDocumentsForIds(array $companyIds): array
    {
        return $this->service->getCompanyDocumentsForIds($companyIds);
    }

    public function getWorkerDocuments(int $workerId): array
    {
        return $this->service->getWorkerDocuments($workerId);
    }

    public function getWorkerDocumentsMultiple(array $ids): array
    {
        return $this->service->getWorkerDocumentsMultiple($ids);
    }
}
