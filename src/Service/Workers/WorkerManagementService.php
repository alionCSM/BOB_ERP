<?php

declare(strict_types=1);

namespace App\Service\Workers;
use RuntimeException;
use InvalidArgumentException;
use App\Repository\Workers\WorkerRepository;

class WorkerManagementService
{
    private WorkerRepository $repository;

    public function __construct(WorkerRepository $repository)
    {
        $this->repository = $repository;
    }


    public function createWorker(array $data, int $createdBy, bool $isCompanyScopedUser, array $allowedCompanyNames, array $profilePhoto = []): bool
    {
        $company = trim((string)($data['company'] ?? ''));
        if ($company === '') {
            throw new InvalidArgumentException('Azienda obbligatoria.');
        }

        if ($isCompanyScopedUser && !in_array($company, $allowedCompanyNames, true)) {
            throw new RuntimeException('Azienda non consentita per questo utente.');
        }

        // Create worker first (without photo), then upload photo to cloud using uid
        $result = $this->repository->createWorker($data, $createdBy, null);
        $workerId = $result['id'];
        $uid = $result['uid'];

        if (($profilePhoto['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $photoPath = $this->savePhotoToCloud($uid, $profilePhoto);
            $this->repository->updatePhoto($workerId, $photoPath);
        }

        return true;
    }

    public function updatePhoto(int $workerId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Nessuna foto valida caricata.');
        }

        $uid = $this->repository->getUidById($workerId);
        if (!$uid) {
            throw new RuntimeException('Worker non trovato.');
        }

        $photoPath = $this->savePhotoToCloud($uid, $file);
        $this->repository->updatePhoto($workerId, $photoPath);

        return $photoPath;
    }

    /**
     * Save a profile photo to cloud/Workers/{uid}/uploads/
     * Returns the relative path from cloud root (e.g. "Workers/abc123/uploads/photo.jpg")
     */
    private function savePhotoToCloud(string $uid, array $file): string
    {
        // Validate file size (5 MB max)
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException("L'immagine non deve superare i 5 MB.");
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $fileType = $finfo->file((string)$file['tmp_name']);
        if (!in_array($fileType, $allowedTypes, true)) {
            throw new RuntimeException('Formato file non supportato. Carica solo immagini JPEG, PNG, GIF o WebP.');
        }

        $cloudRoot = $_ENV['CLOUD_ROOT'] ?? getenv('CLOUD_ROOT');
        if (!$cloudRoot) {
            $cloudRoot = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
        }
        $cloudRoot = rtrim($cloudRoot, '/\\');

        $uploadDir = $cloudRoot . '/Workers/' . $uid . '/uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0775, true)) {
                throw new RuntimeException('Impossibile creare la directory: ' . $uploadDir);
            }
        }

        $ext = match($fileType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $fileName = 'profile_' . uniqid('', true) . '.' . $ext;
        $targetFile = $uploadDir . $fileName;

        $tmpName = (string)$file['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('File temporaneo non valido: ' . $tmpName);
        }

        if (!move_uploaded_file($tmpName, $targetFile)) {
            throw new RuntimeException('Errore spostamento file. Target: ' . $targetFile);
        }

        return 'Workers/' . $uid . '/uploads/' . $fileName;
    }

    public function updateInfo(int $workerId, array $data, bool $isCompanyScopedUser, array $allowedCompanyNames): void
    {
        $company = trim((string)($data['company'] ?? ''));
        if ($company === '') {
            throw new InvalidArgumentException('Azienda obbligatoria.');
        }

        if ($isCompanyScopedUser && !in_array($company, $allowedCompanyNames, true)) {
            throw new RuntimeException('Azienda non consentita per questo utente.');
        }

        $this->repository->updateInfo($workerId, $data);
    }

    public function changeCompany(array $workerData, string $newCompany, ?string $internalCompany, string $role, string $startDate, string $endDate): bool
    {
        $fiscalCode = (string)$workerData['fiscal_code'];
        $currentData = $this->repository->loadByFiscalCode($fiscalCode);

        if ($currentData) {
            $this->repository->insertCompanyHistory([
                ':fiscal_code' => $fiscalCode,
                ':company' => (string)$currentData['company'],
                ':internal_company' => $internalCompany,
                ':role' => (string)($currentData['type_worker'] ?? ''),
                ':start_date' => (string)$currentData['active_from'],
                ':end_date' => $endDate,
                ':uid' => (string)$currentData['uid'],
            ]);
        }

        return $this->repository->updateCompanyByFiscalCode($fiscalCode, $newCompany, $startDate, $role);
    }

    public function setActiveStatus(int $workerId, string $status): bool
    {
        if (!in_array($status, ['Y', 'N'], true)) {
            throw new InvalidArgumentException('Stato non valido.');
        }

        return $this->repository->setActiveStatus($workerId, $status);
    }
}
