<?php

declare(strict_types=1);

namespace App\Service\Share;
use App\Repository\Share\SharedLinkRepository;

class SharedLinkService
{
    private SharedLinkRepository $repository;
    private int $userId;

    public function __construct(SharedLinkRepository $repository, int $userId)
    {
        $this->repository = $repository;
        $this->userId = $userId;
    }

    public function getAllLinks(): array
    {
        return $this->repository->getAllLinks();
    }

    public function deleteLink(int $linkId): bool
    {
        return $this->repository->deleteLink($this->userId, $linkId);
    }

    public function updatePassword(int $linkId, ?string $passwordHash): void
    {
        $this->repository->updatePassword($linkId, $passwordHash);
    }

    public function createLinkFromPayload(array $payload, array $files): int
    {
        $passwordHash = !empty($payload['password'])
            ? password_hash($payload['password'], PASSWORD_DEFAULT)
            : null;
        $linkId = $this->repository->createLink($this->userId, $payload['title'], $payload['expires_at'], $passwordHash);

        // Add worker associations (dynamic — all their docs shared live)
        $workerIds = $payload['workers'] ?? [];
        foreach ($workerIds as $wid) {
            $this->repository->addWorkerToLink($linkId, (int)$wid);
        }

        // Add company associations (dynamic — all their docs shared live)
        $companyIds = $payload['companies'] ?? [];
        foreach ($companyIds as $cid) {
            $this->repository->addCompanyToLink($linkId, (int)$cid);
        }

        // Handle manual doc uploads (static files)
        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
        $uploadDir = rtrim($cloudBase, '/\\') . '/shared_uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        foreach (($payload['manual_docs'] ?? []) as $i => $manualDoc) {
            $companyId = (int)($manualDoc['company_id'] ?? 0);
            $docName = trim((string)($manualDoc['name'] ?? ''));
            if ($companyId <= 0 || $docName === '') {
                continue;
            }

            $uploadedToken = basename((string)($manualDoc['uploaded_token'] ?? ''));
            if ($uploadedToken !== '' && str_starts_with($uploadedToken, "u{$this->userId}_")) {
                $tmpPath = rtrim($cloudBase, '/\\') . '/shared_uploads_tmp/' . $uploadedToken;
                if (is_file($tmpPath)) {
                    $orig = preg_replace('/^u\d+_[a-zA-Z0-9_-]+_/', '', $uploadedToken) ?: 'manual_upload.bin';
                    $filename = "upload_{$linkId}_" . uniqid('', true) . "_{$orig}";
                    $destPath = $uploadDir . $filename;

                    if (@rename($tmpPath, $destPath) || (@copy($tmpPath, $destPath) && @unlink($tmpPath))) {
                        $relativePath = 'shared_uploads/' . $filename;
                        $this->repository->addFileToLink($linkId, $relativePath, $docName, null, 'manual', $companyId);
                        continue;
                    }
                }
            }

            $legacyName = $files['manual_docs']['name'][$i]['file'] ?? '';
            if ($legacyName === '') {
                continue;
            }

            $tmp = $files['manual_docs']['tmp_name'][$i]['file'] ?? '';
            if ($tmp === '') {
                continue;
            }

            // Validate file size (20 MB max)
            $legacySize = (int)($files['manual_docs']['size'][$i]['file'] ?? 0);
            if ($legacySize > 20 * 1024 * 1024) {
                continue;
            }

            // Validate MIME type server-side
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file((string)$tmp);
            $allowed  = [
                'application/pdf',
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ];
            if (!in_array($mimeType, $allowed, true)) {
                continue;
            }

            $ext      = strtolower(pathinfo((string)$legacyName, PATHINFO_EXTENSION));
            $safeName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
            $filename = "upload_{$linkId}_" . $safeName;
            $destPath = $uploadDir . $filename;

            if (!move_uploaded_file($tmp, $destPath)) {
                continue;
            }

            $relativePath = 'shared_uploads/' . $filename;
            $this->repository->addFileToLink($linkId, $relativePath, $docName, null, 'manual', $companyId);
        }

        return $linkId;
    }

    public function updateLinkFromPayload(int $linkId, array $payload, array $files): void
    {
        // Update title and expiry (password untouched)
        $this->repository->updateLink(
            $linkId,
            $payload['title'],
            $payload['expires_at']
        );

        // Remove manual files
        $removedFileIds = $payload['removed_files'] ?? [];
        foreach ($removedFileIds as $fileId) {
            $this->repository->removeFileFromLink((int)$fileId, $linkId);
        }

        // Remove worker associations
        $removedWorkers = $payload['removed_workers'] ?? [];
        foreach ($removedWorkers as $wid) {
            $this->repository->removeWorkerFromLink($linkId, (int)$wid);
        }

        // Remove company associations
        $removedCompanies = $payload['removed_companies'] ?? [];
        foreach ($removedCompanies as $cid) {
            $this->repository->removeCompanyFromLink($linkId, (int)$cid);
        }

        // Add new worker associations
        $newWorkerIds = $payload['workers'] ?? [];
        foreach ($newWorkerIds as $wid) {
            $this->repository->addWorkerToLink($linkId, (int)$wid);
        }

        // Add new company associations
        $newCompanyIds = $payload['companies'] ?? [];
        foreach ($newCompanyIds as $cid) {
            $this->repository->addCompanyToLink($linkId, (int)$cid);
        }

        // Handle manual doc uploads
        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
        $uploadDir = rtrim($cloudBase, '/\\') . '/shared_uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        foreach (($payload['manual_docs'] ?? []) as $i => $manualDoc) {
            $companyId = (int)($manualDoc['company_id'] ?? 0);
            $docName = trim((string)($manualDoc['name'] ?? ''));
            if ($companyId <= 0 || $docName === '') {
                continue;
            }

            $uploadedToken = basename((string)($manualDoc['uploaded_token'] ?? ''));
            if ($uploadedToken !== '' && str_starts_with($uploadedToken, "u{$this->userId}_")) {
                $tmpPath = rtrim($cloudBase, '/\\') . '/shared_uploads_tmp/' . $uploadedToken;
                if (is_file($tmpPath)) {
                    $orig = preg_replace('/^u\d+_[a-zA-Z0-9_-]+_/', '', $uploadedToken) ?: 'manual_upload.bin';
                    $filename = "upload_{$linkId}_" . uniqid('', true) . "_{$orig}";
                    $destPath = $uploadDir . $filename;

                    if (@rename($tmpPath, $destPath) || (@copy($tmpPath, $destPath) && @unlink($tmpPath))) {
                        $relativePath = 'shared_uploads/' . $filename;
                        $this->repository->addFileToLink($linkId, $relativePath, $docName, null, 'manual', $companyId);
                    }
                }
            }
        }
    }

    public function getLinkById(int $linkId): ?array
    {
        return $this->repository->getLinkById($linkId);
    }

    public function getFilesForLink(int $linkId): array
    {
        return $this->repository->getFilesForLink($linkId);
    }

    public function getLiveFilesForLink(int $linkId): array
    {
        return $this->repository->getLiveFilesForLink($linkId);
    }

    public function getLinkedWorkers(int $linkId): array
    {
        return $this->repository->getLinkedWorkers($linkId);
    }

    public function getLinkedCompanies(int $linkId): array
    {
        return $this->repository->getLinkedCompanies($linkId);
    }

    public function toggleActive(int $linkId): bool
    {
        return $this->repository->toggleActive($linkId);
    }

    public function getAllCompanies(): array
    {
        return $this->repository->getAllCompanies();
    }

    public function getCompanyDocumentsForIds(array $companyIds): array
    {
        $response = [];
        foreach ($companyIds as $companyId) {
            $companyId = (int)$companyId;
            $company = $this->repository->getCompanyById($companyId);
            if (!$company) {
                continue;
            }

            $docs = $this->repository->getCompanyDocuments($companyId);
            $response[$companyId] = [
                'company' => $company['name'],
                'documents' => array_map(static fn($doc) => [
                    'id' => $doc['id'],
                    'tipo' => $doc['tipo_documento'],
                    'scadenza' => $doc['scadenza'],
                    'path' => $doc['file_path'],
                ], $docs),
            ];
        }

        return $response;
    }

    public function getWorkerDocuments(int $workerId): array
    {
        return $this->repository->getWorkerDocuments($workerId);
    }

    public function getWorkerDocumentsMultiple(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
        $rows = $this->repository->getWorkerDocumentsByWorkerIds($ids);

        $grouped = [];
        foreach ($rows as $r) {
            $wid = (int)$r['worker_id'];
            if (!isset($grouped[$wid])) {
                $grouped[$wid] = [
                    'worker' => $r['worker'],
                    'company' => $r['company'],
                    'documents' => [],
                ];
            }
            // LEFT JOIN may return null doc_id for workers with no documents
            if (!empty($r['doc_id'])) {
                $grouped[$wid]['documents'][] = $r;
            }
        }

        return $grouped;
    }
}
