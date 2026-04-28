<?php

declare(strict_types=1);

namespace App\Validator\Documents;
use InvalidArgumentException;

class WorkerDocumentUpdateValidator
{
    public function validate(array $post, array $files): array
    {
        if (empty($post['document_id'])) {
            throw new InvalidArgumentException('Richiesta non valida.');
        }

        $file = $files['document_file'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file((string)$file['tmp_name']);
            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($mime, $allowedMimes, true)) {
                throw new InvalidArgumentException('Formato file non supportato. Carica solo PDF, JPEG o PNG.');
            }
        }

        return [
            'document_id' => (int)$post['document_id'],
            'document_type' => trim((string)($post['document_type'] ?? '')),
            'date_emission' => trim((string)($post['date_emission'] ?? '')),
            'expiry_date' => trim((string)($post['expiry_date'] ?? '')) ?: null,
            'file' => $file,
        ];
    }
}
