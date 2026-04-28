<?php

declare(strict_types=1);

namespace App\Service\Documents;
use RuntimeException;
use App\Repository\Documents\WorkerDocumentRepository;

class WorkerDocumentService
{
    private WorkerDocumentRepository $repository;

    public function __construct(WorkerDocumentRepository $repository)
    {
        $this->repository = $repository;
    }

    public function upload(array $payload): void
    {
        $workerId = (int)$payload['worker_id'];
        $docType = (string)$payload['document_type'];
        $dateEmission = (string)$payload['date_emission'];
        $expiryDate = $payload['expiry_date'] ?? null;
        $file = $payload['file'];

        $workerUid = $this->repository->findWorkerUidById($workerId);
        if (!$workerUid) {
            throw new RuntimeException('UID lavoratore non trovato.');
        }

        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        if (!$cloudBase) {
            throw new RuntimeException('Cartella cloud non trovata.');
        }

        $workerDir = $cloudBase . "/Workers/{$workerUid}/";
        $docsDir = $workerDir . 'documents/';

        if (!is_dir($workerDir) && !mkdir($workerDir, 0755, true) && !is_dir($workerDir)) {
            throw new RuntimeException('Impossibile creare cartella lavoratore.');
        }

        if (!is_dir($docsDir) && !mkdir($docsDir, 0755, true) && !is_dir($docsDir)) {
            throw new RuntimeException('Impossibile creare cartella documenti.');
        }

        // Validate file size (20 MB max)
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new RuntimeException('Il file supera la dimensione massima consentita (20 MB).');
        }

        // Validate MIME type server-side
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file((string)$file['tmp_name']);
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
            throw new RuntimeException('Tipo di file non consentito.');
        }

        $ext      = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $fileName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        $targetFile = $docsDir . $fileName;

        if (!move_uploaded_file((string)$file['tmp_name'], $targetFile)) {
            throw new RuntimeException('Errore nel caricamento del file.');
        }

        $relativePath = "Workers/{$workerUid}/documents/{$fileName}";
        $this->repository->createDocument($workerId, $docType, $dateEmission, $expiryDate, $relativePath);
    }

    public function delete(int $documentId): array
    {
        $doc = $this->repository->findDocumentById($documentId);
        if (!$doc) {
            throw new RuntimeException('Documento non trovato.');
        }

        $cloudBasePath = realpath(dirname(APP_ROOT) . '/cloud');
        $filePath = $cloudBasePath ? realpath($cloudBasePath . '/' . (string)$doc['path']) : false;

        $this->repository->deleteDocumentById($documentId);

        if ($filePath && $cloudBasePath && strpos($filePath, $cloudBasePath) === 0 && file_exists($filePath)) {
            @unlink($filePath);
        }

        return $doc;
    }

    public function update(array $payload, int $userId): array
    {
        $documentId = (int)$payload['document_id'];
        $docType = (string)$payload['document_type'];
        $dateEmission = (string)$payload['date_emission'];
        $expiryDate = $payload['expiry_date'] ?? null;
        $file = $payload['file'];

        $current = $this->repository->findDocumentWithWorkerById($documentId);
        if (!$current) {
            throw new RuntimeException('Documento non trovato.');
        }

        $workerUid = (string)$current['uid'];
        $currentPath = (string)$current['path'];

        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        if (!$cloudBase) {
            throw new RuntimeException('Cartella cloud non trovata.');
        }

        $docsDir = $cloudBase . "/Workers/{$workerUid}/documents/";
        $archiveDir = $cloudBase . "/Workers/{$workerUid}/archive/";

        if (!is_dir($docsDir) && !mkdir($docsDir, 0755, true) && !is_dir($docsDir)) {
            throw new RuntimeException('Impossibile creare cartella documenti.');
        }

        if (is_array($file) && !empty($file['tmp_name'])) {
            // Validate file size (20 MB max)
            if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
                throw new RuntimeException('Il file supera la dimensione massima consentita (20 MB).');
            }

            // Validate MIME type server-side
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file((string)$file['tmp_name']);
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
                throw new RuntimeException('Tipo di file non consentito.');
            }

            if (!is_dir($archiveDir) && !mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
                throw new RuntimeException('Impossibile creare cartella archivio.');
            }

            $oldFileName = basename($currentPath);
            $oldFile = $cloudBase . '/' . $currentPath;
            $archivedFile = $archiveDir . $oldFileName;
            $archivedDbPath = "Workers/{$workerUid}/archive/{$oldFileName}";

            $this->repository->archiveDocumentVersion($documentId, $current, $archivedDbPath, $userId);

            if (file_exists($oldFile)) {
                rename($oldFile, $archivedFile);
            }

            $ext         = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $newFileName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
            $newFilePath = $docsDir . $newFileName;

            if (!move_uploaded_file((string)$file['tmp_name'], $newFilePath)) {
                throw new RuntimeException('Errore nel caricamento del nuovo PDF.');
            }

            $currentPath = "Workers/{$workerUid}/documents/{$newFileName}";
        }

        $this->repository->updateDocumentMetadata($documentId, $docType, $dateEmission, $expiryDate, $currentPath);

        return $current;
    }

    public function getExpiredDocuments(string $today, bool $isScoped, array $allowedCompanyNames): array
    {
        return [
            'companyDocs' => $this->repository->getExpiredCompanyDocuments($today, $isScoped, $allowedCompanyNames),
            'workerDocs' => $this->repository->getExpiredWorkerDocuments($today, $isScoped, $allowedCompanyNames),
        ];
    }

    public function getExpiringDocuments(string $today, string $futureDate, bool $isScoped, array $allowedCompanyNames): array
    {
        return [
            'companyDocs' => $this->repository->getExpiringCompanyDocuments($today, $futureDate, $isScoped, $allowedCompanyNames),
            'workerDocs' => $this->repository->getExpiringWorkerDocuments($today, $futureDate, $isScoped, $allowedCompanyNames),
        ];
    }

}
