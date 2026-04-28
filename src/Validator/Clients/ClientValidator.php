<?php
declare(strict_types=1);

namespace App\Validator\Clients;

final class ClientValidator
{
    /**
     * @return array<int,string>
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = "La Ragione Sociale è obbligatoria.";
        }

        if (empty($data['via'])) {
            $errors[] = "L'indirizzo è obbligatorio.";
        }

        if (!empty($data['cap'])) {
            if (!preg_match('/^[A-Za-z0-9 \-]{3,10}$/', $data['cap'])) {
                $errors[] = "Il CAP deve essere tra 3 e 10 caratteri.";
            }
        }

        if (empty($data['localita'])) {
            $errors[] = "La località è obbligatoria.";
        }

        if (!empty($data['filiale']) && strlen($data['filiale']) < 2) {
            $errors[] = "La descrizione della filiale è troppo breve.";
        }

        if (!empty($data['vat']) && !preg_match('/^[A-Za-z0-9]{8,14}$/', $data['vat'])) {
            $errors[] = "Partita IVA non valida.";
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email non valida.";
        }

        return $errors;
    }
}