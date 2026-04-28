<?php
declare(strict_types=1);
namespace App\Repository\Ordini;

use PDO;
use App\Repository\Contracts\OrdineRepositoryInterface;

class OrdineRepository implements OrdineRepositoryInterface
{
    public function __construct(private PDO $conn) {}

    public function getAll(int $userCompanyId): array
    {
        $sql = "SELECT o.id, o.order_number, o.order_date, o.total, o.status,
                       o.oggetto, o.iva_percentage, o.destinatario_id,
                       d.name AS destinatario_name,
                       co.codice AS company_name,
                       w.name AS worksite_name
                FROM bb_ordini o
                LEFT JOIN bb_companies d  ON o.destinatario_id = d.id
                LEFT JOIN bb_companies co ON o.company_id = co.id
                LEFT JOIN bb_worksites w  ON o.worksite_id  = w.id";
        $params = [];
        if ($userCompanyId !== 1) {
            $sql .= ' WHERE (o.company_id IS NULL OR o.company_id = :company_id)';
            $params[':company_id'] = $userCompanyId;
        }
        $sql .= ' ORDER BY o.order_number DESC, o.id DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $userCompanyId): ?array
    {
        $sql = "SELECT o.*, d.name AS destinatario_name, w.name AS worksite_name,
                       co.codice AS company_codice
                FROM bb_ordini o
                LEFT JOIN bb_companies d  ON o.destinatario_id = d.id
                LEFT JOIN bb_companies co ON o.company_id = co.id
                LEFT JOIN bb_worksites w  ON o.worksite_id  = w.id
                WHERE o.id = :id";
        $params = [':id' => $id];
        if ($userCompanyId !== 1) {
            $sql .= ' AND (o.company_id IS NULL OR o.company_id = :company_id)';
            $params[':company_id'] = $userCompanyId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getItems(int $ordineId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM bb_ordini_items WHERE ordine_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':id' => $ordineId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getConsorziate(): array
    {
        $stmt = $this->conn->query(
            'SELECT id, name FROM bb_companies WHERE consorziata = 1 ORDER BY name ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getWorksites(int $userCompanyId): array
    {
        $sql    = "SELECT id, name FROM bb_worksites WHERE is_draft = 0 AND status != 'Completato'";
        $params = [];
        if ($userCompanyId !== 1) {
            $sql .= ' AND company_id = :company_id';
            $params[':company_id'] = $userCompanyId;
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNextOrderNumber(int $companyId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COALESCE(MAX(order_number), 0) + 1 AS next_num FROM bb_ordini WHERE company_id = :company_id AND YEAR(order_date) = :year'
        );
        $stmt->execute([':company_id' => $companyId, ':year' => (int)date('Y')]);
        return (int)($stmt->fetchColumn() ?: 1);
    }

    public function create(array $data, int $companyId): int
    {
        $stmt = $this->conn->prepare("INSERT INTO bb_ordini
            (order_number, worksite_id, order_date, company_id, total,
             destinatario_id, oggetto, termini_pagamento, iva_percentage, note, status)
            VALUES
            (:order_number, :worksite_id, :order_date, :company_id, :total,
             :destinatario_id, :oggetto, :termini_pagamento, :iva_percentage, :note, 'bozza')");
        $stmt->execute([
            ':order_number'      => $data['order_number'],
            ':worksite_id'       => $data['worksite_id'],
            ':order_date'        => $data['order_date'],
            ':company_id'        => $companyId,
            ':total'             => $data['total'],
            ':destinatario_id'   => $data['destinatario_id'] ?: null,
            ':oggetto'           => $data['oggetto'] ?? null,
            ':termini_pagamento' => $data['termini_pagamento'] ?? null,
            ':iva_percentage'    => $data['iva_percentage'] ?? 22.00,
            ':note'              => $data['note'] ?? null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function replaceItems(int $ordineId, array $items): void
    {
        $this->conn->prepare('DELETE FROM bb_ordini_items WHERE ordine_id = :id')
                   ->execute([':id' => $ordineId]);
        if (empty($items)) return;
        $stmt = $this->conn->prepare(
            'INSERT INTO bb_ordini_items (ordine_id, cod_articolo, descrizione, um, qta, prezzo_unitario, importo, sort_order)
             VALUES (:ordine_id, :cod, :desc, :um, :qta, :prezzo, :importo, :sort)'
        );
        foreach ($items as $i => $item) {
            $qta    = (float)($item['qta']    ?? 1);
            $prezzo = (float)($item['prezzo'] ?? 0);
            $stmt->execute([
                ':ordine_id' => $ordineId,
                ':cod'       => trim($item['cod_articolo'] ?? ''),
                ':desc'      => trim($item['descrizione']  ?? ''),
                ':um'        => trim($item['um'] ?? 'N'),
                ':qta'       => $qta,
                ':prezzo'    => $prezzo,
                ':importo'   => round($qta * $prezzo, 2),
                ':sort'      => $i,
            ]);
        }
    }

    public function update(array $data, int $ordineId, int $userCompanyId): bool
    {
        $sql = "UPDATE bb_ordini SET
            worksite_id       = :worksite_id,
            order_date        = :order_date,
            total             = :total,
            destinatario_id   = :destinatario_id,
            oggetto           = :oggetto,
            termini_pagamento = :termini_pagamento,
            iva_percentage    = :iva_percentage,
            note              = :note
            WHERE id = :id";
        $params = [
            ':worksite_id'       => $data['worksite_id'],
            ':order_date'        => $data['order_date'],
            ':total'             => $data['total'],
            ':destinatario_id'   => $data['destinatario_id'] ?: null,
            ':oggetto'           => $data['oggetto'] ?? null,
            ':termini_pagamento' => $data['termini_pagamento'] ?? null,
            ':iva_percentage'    => $data['iva_percentage'] ?? 22.00,
            ':note'              => $data['note'] ?? null,
            ':id'                => $ordineId,
        ];
        if ($userCompanyId !== 1) {
            $sql .= ' AND (company_id IS NULL OR company_id = :company_id)';
            $params[':company_id'] = $userCompanyId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userCompanyId): bool
    {
        $sql = 'DELETE FROM bb_ordini WHERE id = :id';
        $params = [':id' => $id];
        if ($userCompanyId !== 1) {
            $sql .= ' AND (company_id IS NULL OR company_id = :company_id)';
            $params[':company_id'] = $userCompanyId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function updateStatus(int $ordineId, string $status, int $userCompanyId): bool
    {
        $sql = 'UPDATE bb_ordini SET status = :status WHERE id = :id';
        $params = [':status' => $status, ':id' => $ordineId];
        if ($userCompanyId !== 1) {
            $sql .= ' AND (company_id IS NULL OR company_id = :company_id)';
            $params[':company_id'] = $userCompanyId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }
}
