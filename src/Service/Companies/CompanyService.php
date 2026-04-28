<?php

declare(strict_types=1);

namespace App\Service\Companies;
use RuntimeException;
use InvalidArgumentException;
use App\Repository\Companies\CompanyRepository;
use App\Validator\Companies\CompanyValidator;
use App\Domain\User;

class CompanyService
{
    public function __construct(private CompanyRepository $repository, private CompanyValidator $validator)
    {
    }

    public function getAll(): array
    {
        return $this->repository->getAll();
    }

    public function getById(int $id): ?array
    {
        return $this->repository->getById($id);
    }

    public function create(array $post): int
    {
        $data = $this->validator->validateCompany($post);
        return $this->repository->create($data);
    }

    public function update(int $id, array $post): void
    {
        $data = $this->validator->validateCompany($post);
        $this->repository->update($id, $data);
    }

    public function delete(User $user, int $id): void
    {
        $canManage = (int)$user->id === 1 || !empty($user->permissions['companies']);
        if (!$canManage) {
            throw new RuntimeException('Accesso negato.');
        }

        $company = $this->repository->getById($id);
        if (!$company) {
            throw new RuntimeException('Azienda non trovata.');
        }

        $workers = $this->repository->getWorkersByCompanyId($id);
        if (!empty($workers)) {
            throw new RuntimeException('Impossibile eliminare: l\'azienda ha ' . count($workers) . ' lavorator' . (count($workers) === 1 ? 'e' : 'i') . ' associat' . (count($workers) === 1 ? 'o' : 'i') . '.');
        }

        $this->repository->delete($id);
    }

    public function toggleActive(int $id): array
    {
        $company = $this->repository->getById($id);
        if (!$company) {
            throw new \RuntimeException('Azienda non trovata.');
        }
        $newActive = ((int)($company['active'] ?? 1)) === 1 ? 0 : 1;
        $this->repository->setActive($id, $newActive);

        $deactivatedWorkers = 0;
        if ($newActive === 0) {
            $deactivatedWorkers = $this->repository->deactivateWorkersByCompany(
                $id,
                (string)($company['name'] ?? '')
            );
        }

        return ['active' => (bool)$newActive, 'deactivated_workers' => $deactivatedWorkers];
    }

    public function uploadDocument(array $post, array $files, int $uploadedBy): void
    {
        $payload = $this->validator->validateDocumentPayload($post);
        if ($payload['company_id'] <= 0 || $payload['document_type'] === '') {
            throw new RuntimeException('Richiesta non valida.');
        }
        if (empty($files['document_file']['tmp_name'])) {
            throw new RuntimeException('Nessun file caricato.');
        }

        // Validate file size (20 MB max)
        if (($files['document_file']['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new RuntimeException('Il file supera la dimensione massima consentita (20 MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string)$files['document_file']['tmp_name']);
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Formato file non supportato. Carica solo PDF, JPEG o PNG.');
        }

        $companyId = $payload['company_id'];
        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        if (!$cloudBase) {
            throw new RuntimeException('Cartella cloud non trovata.');
        }

        $docsDir = $cloudBase . "/Companies/{$companyId}/documents/";
        if (!is_dir($docsDir)) {
            mkdir($docsDir, 0755, true);
        }

        $ext      = strtolower(pathinfo((string)$files['document_file']['name'], PATHINFO_EXTENSION));
        $fileName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        $target = $docsDir . $fileName;
        if (!move_uploaded_file((string)$files['document_file']['tmp_name'], $target)) {
            throw new RuntimeException('Errore nel caricamento del file.');
        }

        $relativePath = "Companies/{$companyId}/documents/{$fileName}";
        $this->repository->createDocument($companyId, $payload['document_type'], $payload['date_emission'], $payload['expiry_date'], $relativePath, $uploadedBy);
    }

    public function updateDocument(array $post, array $files, int $userId): void
    {
        $payload = $this->validator->validateDocumentPayload($post);
        if ($payload['document_id'] <= 0 || $payload['document_type'] === '') {
            throw new RuntimeException('Richiesta non valida.');
        }

        $current = $this->repository->getDocumentById($payload['document_id']);
        if (!$current) {
            throw new RuntimeException('Documento non trovato.');
        }

        $companyId = (int)$current['company_id'];
        $currentPath = (string)$current['file_path'];
        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        if (!$cloudBase) {
            throw new RuntimeException('Cartella cloud non trovata.');
        }

        $docsDir = $cloudBase . "/Companies/{$companyId}/documents/";
        $archiveDir = $cloudBase . "/Companies/{$companyId}/archive/";
        if (!is_dir($docsDir)) {
            mkdir($docsDir, 0755, true);
        }

        if (!empty($files['document_file']['tmp_name'])) {
            // Validate file size (20 MB max)
            if (($files['document_file']['size'] ?? 0) > 20 * 1024 * 1024) {
                throw new RuntimeException('Il file supera la dimensione massima consentita (20 MB).');
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file((string)$files['document_file']['tmp_name']);
            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($mime, $allowedMimes, true)) {
                throw new RuntimeException('Formato file non supportato. Carica solo PDF, JPEG o PNG.');
            }

            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }
            $oldFileName = basename($currentPath);
            $oldFile = $cloudBase . '/' . $currentPath;
            $archivedDbPath = "Companies/{$companyId}/archive/{$oldFileName}";
            $this->repository->archiveDocument($payload['document_id'], $current, $archivedDbPath, $userId);
            if (file_exists($oldFile)) {
                rename($oldFile, $archiveDir . $oldFileName);
            }

            $ext         = strtolower(pathinfo((string)$files['document_file']['name'], PATHINFO_EXTENSION));
            $newFileName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
            $newFilePath = $docsDir . $newFileName;
            if (!move_uploaded_file((string)$files['document_file']['tmp_name'], $newFilePath)) {
                throw new RuntimeException('Errore nel caricamento del nuovo file.');
            }
            $currentPath = "Companies/{$companyId}/documents/{$newFileName}";
        }

        $this->repository->updateDocument($payload['document_id'], $payload['document_type'], $payload['date_emission'], $payload['expiry_date'], $currentPath);
    }

    public function deleteDocument(int $documentId): void
    {
        $current = $this->repository->getDocumentById($documentId);
        if (!$current) {
            throw new RuntimeException('Documento non trovato.');
        }

        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        $filePath = $cloudBase ? realpath($cloudBase . '/' . (string)$current['file_path']) : false;
        $this->repository->deleteDocumentById($documentId);
        if ($filePath && $cloudBase && str_starts_with($filePath, $cloudBase) && file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    public function deleteWorker(User $user, int $companyId, int $workerId): void
    {
        $canManage = (int)$user->id === 1 || !empty($user->permissions['companies']);
        if (!$canManage) {
            throw new RuntimeException('Accesso negato.');
        }

        $worker = $this->repository->getWorkerById($workerId);
        if (!$worker) {
            throw new RuntimeException('Operaio non trovato.');
        }

        $workerCompany = $this->repository->getCompanyByName((string)$worker['company']);
        if (!$workerCompany || (int)$workerCompany['id'] !== $companyId) {
            throw new RuntimeException('Operaio non appartiene a questa azienda.');
        }

        $workerRepository = new \App\Repository\Workers\WorkerRepository($this->repository->getConnection());
        $workerRepository->deleteWorker($workerId);
    }

    public function resolveCompanyDetails(User $user, int $companyId): array
    {
        $company = $this->repository->getById($companyId);
        if (!$company) {
            throw new RuntimeException('Azienda non trovata.');
        }

        $isCompanyViewer = ((($user->role ?? '') === 'company_viewer') || !empty($user->client_id));
        $allowedCompanyIdsForViewer = [];
        if ($isCompanyViewer) {
            if ($this->repository->hasCompanyAccessMap()) {
                $allowedCompanyIdsForViewer = $this->repository->getAllowedCompanyIdsForUser((int)$user->id);
            }
            if (empty($allowedCompanyIdsForViewer) && !empty($user->company_id)) {
                $allowedCompanyIdsForViewer = [(int)$user->company_id];
            }
            if (!in_array($companyId, $allowedCompanyIdsForViewer, true)) {
                throw new RuntimeException('Access denied to this company');
            }
        }

        $allCompanies = [];
        $assignableCompanyUsers = [];
        if (!$isCompanyViewer) {
            $allCompanies = $this->repository->getAllCompanyNames();
            if ($this->repository->hasCompanyAccessMap()) {
                $assignableCompanyUsers = $this->repository->getAssignableCompanyUsers($companyId);
            }
        }

        return [
            'company' => $company,
            'documents' => $this->repository->getDocuments($companyId),
            'workers' => $this->repository->getWorkersByCompanyId($companyId),
            'isCompanyViewer' => $isCompanyViewer,
            'allCompanies' => $allCompanies,
            'assignableCompanyUsers' => $assignableCompanyUsers,
        ];
    }

    public function getMyCompanies(User $user): array
    {
        $accessibleCompanyIds = [];
        $isCompanyViewer = (($user->role ?? '') === 'company_viewer' || !empty($user->permissions['companies_viewer']));

        if (!$isCompanyViewer && $this->repository->hasCompanyAccessMap()) {
            $isCompanyViewer = $this->repository->countCompanyAccessByUserId((int)$user->id) > 0;
        }

        if ($isCompanyViewer) {
            if ($this->repository->hasCompanyAccessMap()) {
                $accessibleCompanyIds = $this->repository->getAllowedCompanyIdsForUser((int)$user->id);
            }
            if (empty($accessibleCompanyIds) && !empty($user->company_id)) {
                $accessibleCompanyIds = [(int)$user->company_id];
            }
        }

        return !empty($accessibleCompanyIds) ? $this->repository->getCompaniesByIds($accessibleCompanyIds) : [];
    }

    public function createCompanyUser(int $actingUserId, array $post): array
    {
        $companyId = (int)($post['company_id'] ?? 0);
        $firstName = trim((string)($post['first_name'] ?? ''));
        $lastName = trim((string)($post['last_name'] ?? ''));
        $email = trim((string)($post['email'] ?? ''));
        $phone = trim((string)($post['phone'] ?? ''));
        $selectedCompanyIds = array_map('intval', $post['company_ids'] ?? []);

        if ($companyId <= 0 || $firstName === '' || $lastName === '' || $email === '') {
            throw new InvalidArgumentException('missing');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email');
        }

        $company = $this->repository->findCompanyNameAndConsorziata($companyId);
        if (!$company) {
            throw new RuntimeException('company_not_found');
        }
        if ($this->repository->emailAlreadyUsed($email)) {
            throw new RuntimeException('exists');
        }

        $tempPassword = bin2hex(random_bytes(6));
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $username = strtolower($email);

        $newUserId = $this->repository->transaction(function () use ($username, $passwordHash, $firstName, $lastName, $email, $phone, $company, $actingUserId, $selectedCompanyIds, $companyId) {
            $newUserId = $this->repository->insertCompanyUser([
                ':username' => $username,
                ':password' => $passwordHash,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone !== '' ? $phone : '-',
                ':company' => $company['name'],
                ':company_id' => $company['id'],
                ':type' => 'user',
                ':role' => 'company_viewer',
                ':active' => 'Y',
                ':confirmed' => 0,
                ':must_change_password' => 1,
                ':created_by' => $actingUserId,
            ]);

            if ($this->repository->hasCompanyAccessMap()) {
                $companyIds = array_values(array_unique(array_filter($selectedCompanyIds, static fn($id) => $id > 0)));
                if (empty($companyIds)) {
                    $companyIds = [$companyId];
                }
                foreach ($companyIds as $cid) {
                    $this->repository->addUserCompanyAccess($newUserId, $cid);
                }
            }

            $this->repository->clearUserPermissions($newUserId);
            $this->repository->addUserPermission($newUserId, 'companies', 1);
            $this->repository->addUserPermission($newUserId, 'companies_viewer', 1);

            return $newUserId;
        });

        return ['user_id' => $newUserId, 'username' => $username, 'temp_password' => $tempPassword];
    }

    public function assignCompanyAccess(int $companyId, int $userId): void
    {
        if ($companyId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('missing');
        }
        if (!$this->repository->hasCompanyAccessMap()) {
            throw new RuntimeException('map_missing');
        }
        if (!$this->repository->companyExists($companyId)) {
            throw new RuntimeException('company_not_found');
        }
        if (!$this->repository->companyUserExists($userId)) {
            throw new RuntimeException('user_not_found');
        }
        if ($this->repository->companyAccessExists($userId, $companyId)) {
            throw new RuntimeException('exists');
        }

        $this->repository->addUserCompanyAccess($userId, $companyId);
        if (!$this->repository->userHasCompaniesViewerPermission($userId)) {
            $this->repository->addUserPermission($userId, 'companies_viewer', 1);
        }
    }

    public function getConsorziataExportData(int $companyId, string $startDate, string $endDate): array
    {
        $company = $this->repository->findCompanyNameAndConsorziata($companyId);
        if (!$company) {
            throw new RuntimeException('Azienda non trovata.');
        }

        $isConsorziata = (int)$company['consorziata'] === 1;
        $companyName = (string)$company['name'];
        if ($isConsorziata) {
            $rows = $this->repository->getConsorziataPresenceDetailRows($companyId, $startDate, $endDate);
            $summaryRows = $this->repository->getConsorziataPresenceSummaryRows($companyId, $startDate, $endDate);
        } else {
            $rows = $this->repository->getInternalPresenceDetailRows($companyName, $startDate, $endDate);
            $summaryRows = $this->repository->getInternalPresenceSummaryRows($companyName, $startDate, $endDate);
        }

        return [
            'company_name' => $companyName,
            'rows' => $rows,
            'summary_rows' => $summaryRows,
            'is_consorziata' => $isConsorziata,
        ];
    }
}
