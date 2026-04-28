<?php

declare(strict_types=1);

namespace App\Validator\Offers;
use InvalidArgumentException;

class OfferPayloadValidator
{
    public function validate(array $post): array
    {
        $data = [
            'client' => isset($post['client']) ? (int)$post['client'] : 0,
            'riferimento' => trim((string)($post['riferimento'] ?? '')),
            'cortese_att' => trim((string)($post['cortese_att'] ?? '')),
            'oggetto' => trim((string)($post['oggetto'] ?? '')),
            'offer_date' => trim((string)($post['offer_date'] ?? '')),
            'total_amount' => trim((string)($post['total_amount'] ?? '0')),
            'termini_pagamento' => (string)($post['termini_pagamento'] ?? ''),
            'condizioni' => (string)($post['condizioni'] ?? ''),
            'additional' => (string)($post['additional'] ?? ''),
            'note_interne' => (string)($post['note_interne'] ?? ''),
            'is_revision' => !empty($post['is_revision']) ? 1 : 0,
            'offer_template' => trim((string)($post['offer_template'] ?? 'template1')),
            'base_offer_number' => !empty($post['base_offer_number']) ? trim((string)$post['base_offer_number']) : null,
            'offer_number' => !empty($post['offer_number']) ? trim((string)$post['offer_number']) : null,
        ];

        if ($data['client'] <= 0) {
            throw new InvalidArgumentException('Cliente obbligatorio.');
        }

        if ($data['offer_date'] === '') {
            throw new InvalidArgumentException('Data offerta obbligatoria.');
        }

        return $data;
    }

    public function validateItems(array $post): array
    {
        $items = json_decode((string)($post['items_data'] ?? '[]'), true);
        return is_array($items) ? $items : [];
    }
}
