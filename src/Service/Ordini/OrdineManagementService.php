<?php
declare(strict_types=1);
namespace App\Service\Ordini;

use App\Repository\Contracts\OrdineRepositoryInterface;

class OrdineManagementService
{
    private static array $ALLOWED_STATUSES = ['bozza', 'inviato', 'accettato', 'rifiutato'];
    private static array $ALLOWED_IVA      = [0, 4, 5, 10, 22];

    public function __construct(private OrdineRepositoryInterface $repo) {}

    public function getAll(int $userCompanyId): array
    {
        return $this->repo->getAll($userCompanyId);
    }

    public function getById(int $id, int $userCompanyId): ?array
    {
        return $this->repo->getById($id, $userCompanyId);
    }

    public function getItems(int $ordineId): array
    {
        return $this->repo->getItems($ordineId);
    }

    public function getConsorziate(): array
    {
        return $this->repo->getConsorziate();
    }

    public function getWorksites(int $userCompanyId): array
    {
        return $this->repo->getWorksites($userCompanyId);
    }

    public function create(array $post, int $companyId): array
    {
        $validated = $this->validatePayload($post);
        if (!empty($validated['errors'])) {
            return ['success' => false, 'message' => implode(' ', $validated['errors'])];
        }
        $data             = $validated['data'];
        $data['order_number'] = $this->repo->getNextOrderNumber($companyId);

        $items = $this->parseItems($post);
        $data['total'] = array_sum(array_column($items, 'importo'));

        $id = $this->repo->create($data, $companyId);
        $this->repo->replaceItems($id, $items);
        return ['success' => true, 'id' => $id, 'order_number' => $data['order_number']];
    }

    public function update(array $post, int $ordineId, int $userCompanyId): array
    {
        $validated = $this->validatePayload($post);
        if (!empty($validated['errors'])) {
            return ['success' => false, 'message' => implode(' ', $validated['errors'])];
        }
        $data          = $validated['data'];
        $items         = $this->parseItems($post);
        $data['total'] = array_sum(array_column($items, 'importo'));

        $ok = $this->repo->update($data, $ordineId, $userCompanyId);
        if ($ok) {
            $this->repo->replaceItems($ordineId, $items);
        }
        return ['success' => $ok, 'message' => $ok ? '' : 'Ordine non trovato o accesso negato.'];
    }

    public function delete(int $id, int $userCompanyId): bool
    {
        return $this->repo->delete($id, $userCompanyId);
    }

    public function updateStatus(int $id, string $status, int $userCompanyId): bool
    {
        if (!in_array($status, self::$ALLOWED_STATUSES, true)) return false;
        return $this->repo->updateStatus($id, $status, $userCompanyId);
    }

    private function validatePayload(array $post): array
    {
        $errors = [];
        $worksite = (int)($post['worksite_id'] ?? 0);
        $date     = trim($post['order_date'] ?? '');
        $iva      = (float)($post['iva_percentage'] ?? 22);

        if ($worksite <= 0) $errors[] = 'Selezionare un cantiere.';
        if ($date === '')   $errors[] = 'Data ordine obbligatoria.';
        if (!in_array((int)$iva, self::$ALLOWED_IVA, true)) $iva = 22.0;

        return [
            'errors' => $errors,
            'data'   => [
                'worksite_id'       => $worksite,
                'order_date'        => $date,
                'destinatario_id'   => (int)($post['destinatario_id'] ?? 0) ?: null,
                'oggetto'           => trim($post['oggetto'] ?? ''),
                'termini_pagamento' => trim($post['termini_pagamento'] ?? ''),
                'iva_percentage'    => $iva,
                'note'              => trim($post['note'] ?? ''),
                'total'             => 0,
            ],
        ];
    }

    private function parseItems(array $post): array
    {
        $descs  = (array)($post['item_desc']  ?? []);
        $cods   = (array)($post['item_cod']   ?? []);
        $ums    = (array)($post['item_um']    ?? []);
        $qtas   = (array)($post['item_qta']   ?? []);
        $prezzi = (array)($post['item_prezzo'] ?? []);

        $items = [];
        foreach ($descs as $i => $desc) {
            $desc = trim((string)$desc);
            if ($desc === '') continue;
            $qta    = (float)($qtas[$i]   ?? 1);
            $prezzo = (float)($prezzi[$i] ?? 0);
            $items[] = [
                'cod_articolo' => strtoupper(trim((string)($cods[$i] ?? ''))),
                'descrizione'  => $desc,
                'um'           => strtoupper(trim((string)($ums[$i] ?? 'N'))) ?: 'N',
                'qta'          => $qta,
                'prezzo'       => $prezzo,
                'importo'      => round($qta * $prezzo, 2),
            ];
        }
        return $items;
    }
}
