<?php

declare(strict_types=1);

namespace App\Validator\Documents;
use InvalidArgumentException;

class WorkerDocumentUploadValidator
{
    public function validate(array $post, array $files): array
    {
        if (!isset($files['document_file'])) {
            throw new InvalidArgumentException('File mancante.');
        }

        if (!isset($post['worker_id'], $post['document_type'], $post['date_emission'])) {
            throw new InvalidArgumentException('Dati mancanti per l\'upload del documento.');
        }

        $workerId = (int)$post['worker_id'];
        $docType = trim((string)$post['document_type']);
        $dateEmission = trim((string)$post['date_emission']);
        $expiryDate = trim((string)($post['expiry_date'] ?? ''));
        $expiryDate = $expiryDate !== '' ? $expiryDate : null;

        if ($workerId <= 0 || $docType === '' || $dateEmission === '') {
            throw new InvalidArgumentException('Parametri non validi.');
        }

        $file = $files['document_file'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string)$file['tmp_name']);
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException('Formato file non supportato. Carica solo PDF, JPEG o PNG.');
        }

        return [
            'worker_id' => $workerId,
            'document_type' => $docType,
            'date_emission' => $dateEmission,
            'expiry_date' => $expiryDate,
            'file' => $file,
        ];
    }
}
