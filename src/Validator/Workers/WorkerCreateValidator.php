<?php

declare(strict_types=1);

namespace App\Validator\Workers;
use InvalidArgumentException;

class WorkerCreateValidator
{
    public function validate(array $post): array
    {
        $data = [
            'first_name' => trim((string)($post['first_name'] ?? '')),
            'last_name' => trim((string)($post['last_name'] ?? '')),
            'company' => trim((string)($post['company'] ?? '')),
            'email' => trim((string)($post['email'] ?? '')),
            'phone' => trim((string)($post['phone'] ?? '')),
            'birth_date' => trim((string)($post['birth_date'] ?? '')),
            'birth_place' => trim((string)($post['birth_place'] ?? '')),
            'hire_date' => trim((string)($post['hire_date'] ?? '')),
            'fiscal_code' => trim((string)($post['fiscal_code'] ?? '')),
            'type_worker' => trim((string)($post['role'] ?? 'OPERAIO')),
        ];

        if ($data['first_name'] === '' || $data['last_name'] === '' || $data['birth_date'] === '' || $data['birth_place'] === '') {
            throw new InvalidArgumentException('I campi Nome, Cognome, Data di nascita e Luogo di nascita sono obbligatori.');
        }

        if ($data['company'] === '') {
            throw new InvalidArgumentException('Azienda obbligatoria.');
        }

        return $data;
    }
}
