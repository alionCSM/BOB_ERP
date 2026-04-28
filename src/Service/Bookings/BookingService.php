<?php

declare(strict_types=1);

namespace App\Service\Bookings;
use InvalidArgumentException;
use PDO;
use App\Repository\Bookings\BookingRepository;
use App\Repository\Bookings\StrutturaRepository;

class BookingService
{
    private BookingRepository $bookingRepo;
    private StrutturaRepository $strutturaRepo;

    public function __construct(PDO $conn)
    {
        $this->bookingRepo = new BookingRepository($conn);
        $this->strutturaRepo = new StrutturaRepository($conn);
    }

    // ── Strutture ───────────────────────────────

    public function searchStrutture(string $query, ?string $type = null): array
    {
        return $this->strutturaRepo->search($query, $type);
    }

    public function getStruttura(int $id): ?array
    {
        return $this->strutturaRepo->getById($id);
    }

    // ── Bookings ────────────────────────────────

    public function getAllBookings(array $filters = []): array
    {
        return $this->bookingRepo->getAllBookings($filters);
    }

    public function getById(int $id): ?array
    {
        return $this->bookingRepo->getById($id);
    }

    public function getPeriods(int $bookingId): array
    {
        return $this->bookingRepo->getPeriods($bookingId);
    }

    public function delete(int $id): void
    {
        $this->bookingRepo->delete($id);
    }

    /**
     * Create a booking from form POST data.
     * Handles struttura creation/selection and period saving.
     */
    public function createFromPayload(array $data, ?int $userId = null): int
    {
        $strutturaId = $this->resolveStruttura($data);
        $data['struttura_id'] = $strutturaId;
        $data['created_by'] = $userId;

        $bookingId = $this->bookingRepo->create($data);
        $this->savePeriods($bookingId, $data['periods'] ?? []);

        return $bookingId;
    }

    /**
     * Update a booking from form POST data.
     * Handles struttura updates and period replacement.
     */
    public function updateFromPayload(int $id, array $data): void
    {
        $strutturaId = $this->resolveStruttura($data);
        $data['struttura_id'] = $strutturaId;

        $this->bookingRepo->update($id, $data);

        // Sync periods: preserve IDs so override period_id FKs stay valid
        $this->bookingRepo->syncPeriods($id, $data['periods'] ?? []);
    }

    /**
     * Resolve struttura: use existing ID or create new record.
     * Also updates existing struttura details if they changed.
     */
    private function resolveStruttura(array $data): int
    {
        $strutturaId = (int)($data['struttura_id'] ?? 0);

        $strutturaData = [
            'type'             => $data['type'],
            'nome'             => trim((string)($data['struttura_nome'] ?? '')),
            'telefono'         => trim((string)($data['struttura_telefono'] ?? '')),
            'indirizzo'        => trim((string)($data['struttura_indirizzo'] ?? '')),
            'citta'            => trim((string)($data['struttura_citta'] ?? '')),
            'provincia'        => trim((string)($data['struttura_provincia'] ?? '')),
            'country'          => trim((string)($data['struttura_country'] ?? 'Italia')),
            'ragione_sociale'  => trim((string)($data['struttura_ragione_sociale'] ?? '')),
        ];

        if ($strutturaId > 0) {
            // Existing struttura — update its details
            $this->strutturaRepo->update($strutturaId, $strutturaData);
            return $strutturaId;
        }

        // New struttura — create it
        if ($strutturaData['nome'] === '') {
            throw new \InvalidArgumentException('Il nome della struttura è obbligatorio.');
        }

        return $this->strutturaRepo->create($strutturaData);
    }

    private function savePeriods(int $bookingId, array $periods): void
    {
        foreach ($periods as $i => $period) {
            $prezzo = trim((string)($period['prezzo_persona'] ?? ''));
            if ($prezzo === '') {
                continue;
            }
            $period['sort_order'] = $i;
            $this->bookingRepo->addPeriod($bookingId, $period);
        }
    }

    public function toggleFatturaPagato(int $fatturaId): int
    {
        return $this->bookingRepo->toggleFatturaPagato($fatturaId);
    }

    // ── Fatture (invoices) ──────────────────────

    public function getFatture(int $bookingId): array
    {
        return $this->bookingRepo->getFatture($bookingId);
    }

    /**
     * Add a fattura with optional file upload.
     * File is saved to cloud/fatture_prenotazioni/{hotel|ristoranti}/
     */
    public function addFattura(int $bookingId, array $data, ?array $uploadedFile = null): int
    {
        $filePath = null;

        if ($uploadedFile && !empty($uploadedFile['tmp_name']) && $uploadedFile['error'] === UPLOAD_ERR_OK) {
            // Validate file size (20 MB max)
            if (($uploadedFile['size'] ?? 0) > 20 * 1024 * 1024) {
                throw new \InvalidArgumentException('Il file supera la dimensione massima consentita (20 MB).');
            }

            // Validate MIME type server-side
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file((string)$uploadedFile['tmp_name']);
            $allowed  = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($mimeType, $allowed, true)) {
                throw new \InvalidArgumentException('Tipo di file non consentito. Carica solo PDF, JPEG o PNG.');
            }

            // Determine subfolder based on booking type
            $booking = $this->bookingRepo->getById($bookingId);
            $typeFolder = ($booking && $booking['type'] === 'hotel') ? 'hotel' : 'ristoranti';

            $cloudBase = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
            $uploadDir = rtrim($cloudBase, '/\\') . '/fatture_prenotazioni/' . $typeFolder . '/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $ext      = strtolower(pathinfo((string)$uploadedFile['name'], PATHINFO_EXTENSION));
            $safeName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
            $filename = "fattura_{$bookingId}_" . $safeName;
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
                $filePath = 'fatture_prenotazioni/' . $typeFolder . '/' . $filename;
            }
        }

        $data['file_path'] = $filePath;
        return $this->bookingRepo->addFattura($bookingId, $data);
    }

    // ── Overrides ───────────────────────────────────

    public function getOverrides(int $bookingId): array
    {
        return $this->bookingRepo->getOverrides($bookingId);
    }

    public function addOverride(int $bookingId, array $data): int
    {
        return $this->bookingRepo->addOverride($bookingId, $data);
    }

    public function deleteOverride(int $overrideId): bool
    {
        return $this->bookingRepo->deleteOverride($overrideId);
    }

    public function deleteFattura(int $fatturaId, int $bookingId): void
    {
        $filePath = $this->bookingRepo->deleteFattura($fatturaId, $bookingId);

        // Remove physical file
        if ($filePath) {
            $cloudBase = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
            $fullPath = rtrim($cloudBase, '/\\') . '/' . $filePath;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
}
