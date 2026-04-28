<?php

declare(strict_types=1);

namespace App\Service\Offers;
use RuntimeException;
use App\Repository\Offers\OfferRepository;

class OfferManagementService
{
    private OfferRepository $repository;

    public function __construct(OfferRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getNextOfferNumber(): string
    {
        $year = date('y');
        $numbers = $this->repository->getOfferNumbersByYear($year);

        $max = 0;
        foreach ($numbers as $number) {
            $parts = explode('.', (string)$number);
            if (count($parts) === 2 && is_numeric($parts[0])) {
                $max = max($max, (int)$parts[0]);
            }
        }

        return ($max + 1) . '.' . $year;
    }

    public function getNextRevisionNumber(string $baseOfferNumber): string
    {
        $count = $this->repository->countRevisionsForBaseNumber($baseOfferNumber);
        return $baseOfferNumber . ' R' . ($count + 1);
    }

    public function createOffer(array $data, array $items, int $userCompanyId, int $creatorId, array $pdfFile = []): array
    {
        if ($userCompanyId <= 0) {
            throw new RuntimeException('Utente senza azienda associata.');
        }

        $offerNumber = (int)$data['is_revision'] === 1
            ? $this->getNextRevisionNumber((string)$data['base_offer_number'])
            : (trim((string)($data['offer_number'] ?? '')) ?: $this->getNextOfferNumber());

        if ($this->repository->offerNumberExists($offerNumber)) {
            return [
                'success' => false,
                'message' => "Il numero offerta <strong>{$offerNumber}</strong> esiste già.",
            ];
        }

        $docPath = $this->uploadPdf($pdfFile);
        $data['offer_number'] = $offerNumber;
        $data['doc_path'] = $docPath; // Use doc_path for uploaded documents

        $offerId = $this->repository->createOffer($data, $userCompanyId, $creatorId, $docPath);
        $this->repository->replaceOfferItems($offerId, $items);

        return ['success' => true, 'offer_number' => $offerNumber];
    }

    public function updateOffer(int $offerId, array $data, array $items, int $userCompanyId, int $creatorId, array $pdfFile = []): bool
    {
        $current = $this->repository->getOfferById($offerId, $userCompanyId);
        if (!$current) {
            return false;
        }

        // Use doc_path for uploaded documents
        $docPath = $current['doc_path'] ?? null;
        if (($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = $this->uploadPdf($pdfFile);
            if ($uploaded !== null) {
                $docPath = $uploaded;
            }
        }
        $data['doc_path'] = $docPath;

        $this->repository->updateOffer($offerId, $data, $creatorId, $docPath);
        $this->repository->replaceOfferItems($offerId, $items);

        return true;
    }

    public function getOfferById(int $offerId, int $userCompanyId): ?array
    {
        return $this->repository->getOfferById($offerId, $userCompanyId);
    }

    public function getOfferWithClientById(int $offerId, int $userCompanyId): ?array
    {
        return $this->repository->getOfferWithClientById($offerId, $userCompanyId);
    }

    public function getOfferItems(int $offerId): array
    {
        return $this->repository->getOfferItems($offerId);
    }

    public function getClientList(): array
    {
        return $this->repository->getClientList();
    }

    public function getVisibleOffers(int $userCompanyId): array
    {
        return $this->repository->getVisibleOffers($userCompanyId);
    }

    public function searchOfferNumbers(string $query, int $userCompanyId): array
    {
        $rows = $this->repository->searchOfferNumbers($query, $userCompanyId);

        return array_map(static fn(array $row): array => [
            'offer_number' => $row['offer_number'],
            'text' => $row['offer_number'] . ' (' . ($row['codice'] ?? 'Sconosciuto') . ')',
        ], $rows);
    }

    public function updateStatus(int $offerId, string $status, int $userCompanyId): bool
    {
        $allowed = ['bozza', 'inviata', 'in_trattativa', 'approvata', 'rifiutata', 'scaduta'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        return $this->repository->updateStatus($offerId, $status, $userCompanyId);
    }

    public function getFollowups(int $offerId): array
    {
        return $this->repository->getFollowups($offerId);
    }

    public function createFollowup(int $offerId, string $type, string $note, string $date, int $createdBy): int
    {
        $allowedTypes = ['chiamata', 'email', 'sms', 'riunione', 'nota'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'nota';
        }

        return $this->repository->createFollowup($offerId, $type, $note, $date, $createdBy);
    }

    public function deleteFollowup(int $followupId, int $offerId): bool
    {
        return $this->repository->deleteFollowup($followupId, $offerId);
    }

    private function uploadPdf(array $pdfFile): ?string
    {
        if (($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        // Validate file size (20 MB max)
        if (($pdfFile['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new RuntimeException('Il file supera la dimensione massima consentita (20 MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string)$pdfFile['tmp_name']);
        $allowedMimes = [
            'application/pdf',
            'image/jpeg', 'image/png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Formato file non supportato. Carica solo PDF, JPEG, PNG o DOCX.');
        }

        // Use cloud storage instead of local uploads
        $uploadDir = \CloudPath::getOffersDir();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $ext      = strtolower(pathinfo((string)$pdfFile['name'], PATHINFO_EXTENSION));
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
        $uploadFile = $uploadDir . $fileName;

        if (!move_uploaded_file((string)$pdfFile['tmp_name'], $uploadFile)) {
            throw new RuntimeException('Errore durante il caricamento del PDF.');
        }

        // Return relative path from cloud root: offers/{filename}
        return 'offers/' . $fileName;
    }

    /**
     * Delete a PDF file from cloud storage.
     * Used for cleanup when offers are deleted.
     */
    public function deletePdf(string $pdfPath): bool
    {
        if (empty($pdfPath)) {
            return true;
        }

        $fullPath = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $pdfPath;
        return file_exists($fullPath) ? unlink($fullPath) : true;
    }
}
