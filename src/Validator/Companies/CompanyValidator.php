<?php

declare(strict_types=1);

namespace App\Validator\Companies;
use InvalidArgumentException;

class CompanyValidator
{
    public function validateCompany(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Ragione Sociale obbligatoria.');
        }

        return [
            'name' => $name,
            'codice' => trim((string)($post['codice'] ?? '')),
            'consorziata' => trim((string)($post['consorziata'] ?? '0')),
        ];
    }

    public function validateDocumentPayload(array $post): array
    {
        return [
            'company_id' => (int)($post['company_id'] ?? 0),
            'document_id' => (int)($post['document_id'] ?? 0),
            'document_type' => trim((string)($post['document_type'] ?? '')),
            'date_emission' => !empty($post['date_emission']) ? (string)$post['date_emission'] : null,
            'expiry_date' => !empty($post['expiry_date']) ? (string)$post['expiry_date'] : null,
        ];
    }
}
