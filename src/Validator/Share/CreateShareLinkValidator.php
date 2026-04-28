<?php

declare(strict_types=1);

namespace App\Validator\Share;
use InvalidArgumentException;

class CreateShareLinkValidator
{
    public function validate(array $post): array
    {
        $title = trim((string)($post['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Titolo obbligatorio.');
        }

        $password = trim((string)($post['password'] ?? ''));

        return [
            'title' => $title,
            'expires_at' => !empty($post['expires_at']) ? (string)$post['expires_at'] : null,
            'password' => $password !== '' ? $password : null,
            'documents' => array_map('intval', $post['documents'] ?? []),
            'workers' => !empty($post['workers']) ? (array)json_decode((string)$post['workers'], true) : [],
            'companies' => !empty($post['companies']) ? (array)json_decode((string)$post['companies'], true) : [],
            'manual_docs' => $post['manual_docs'] ?? [],
        ];
    }
}
