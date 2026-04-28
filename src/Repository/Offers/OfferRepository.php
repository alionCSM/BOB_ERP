<?php

declare(strict_types=1);

namespace App\Repository\Offers;
use PDO;
use App\Repository\Contracts\OfferRepositoryInterface;

class OfferRepository implements OfferRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getOfferNumbersByYear(string $yearSuffix): array
    {
        $stmt = $this->conn->prepare('SELECT offer_number FROM bb_offers WHERE RIGHT(offer_number, 2) = :year AND is_revision = 0');
        $stmt->execute([':year' => $yearSuffix]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function countRevisionsForBaseNumber(string $baseOfferNumber): int
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM bb_offers WHERE base_offer_number = :offer');
        $stmt->execute([':offer' => $baseOfferNumber]);

        return (int)$stmt->fetchColumn();
    }

    public function offerNumberExists(string $offerNumber): bool
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM bb_offers WHERE offer_number = :offer_number');
        $stmt->execute([':offer_number' => $offerNumber]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function createOffer(array $data, int $companyId, int $creatorId, ?string $pdfPath): int
    {
        $stmt = $this->conn->prepare("INSERT INTO bb_offers (
            offer_number, client_id, reference, cortese_att, subject, offer_date, total_amount, status,
            pdf_path, note_interne, note, termini_pagamento, condizioni, is_revision, base_offer_number, offer_template, company_id, creator_id, doc_path
        ) VALUES (
            :offer_number, :client_id, :reference, :cortese_att, :subject, :offer_date, :total_amount, 'bozza',
            :pdf_path, :note_interne, :additional, :termini_pagamento, :condizioni, :is_revision, :base_offer_number, :offer_template, :company_id, :creator_id, :doc_path
        )");

        $stmt->execute([
            ':offer_number' => $data['offer_number'],
            ':client_id' => $data['client'],
            ':reference' => $data['riferimento'],
            ':cortese_att' => $data['cortese_att'],
            ':subject' => $data['oggetto'],
            ':offer_date' => $data['offer_date'],
            ':total_amount' => $data['total_amount'],
            ':pdf_path' => $pdfPath,
            ':note_interne' => $data['note_interne'] ?? '',
            ':additional' => $data['additional'],
            ':termini_pagamento' => $data['termini_pagamento'],
            ':condizioni' => $data['condizioni'],
            ':is_revision' => $data['is_revision'],
            ':base_offer_number' => $data['base_offer_number'] ?? null,
            ':offer_template' => $data['offer_template'] ?? null,
            ':company_id' => $companyId,
            ':creator_id' => $creatorId,
            ':doc_path' => $data['doc_path'] ?? null,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function replaceOfferItems(int $offerId, array $items): void
    {
        $this->conn->prepare('DELETE FROM bb_offer_items WHERE offer_id = :id')->execute([':id' => $offerId]);

        $stmtItem = $this->conn->prepare('INSERT INTO bb_offer_items (offer_id, description, amount) VALUES (:offer_id, :description, :amount)');
        foreach ($items as $item) {
            $stmtItem->execute([
                ':offer_id' => $offerId,
                ':description' => (string)($item['description'] ?? ''),
                ':amount' => (string)($item['amount'] ?? '0'),
            ]);
        }
    }

    public function updateOffer(int $offerId, array $data, int $creatorId, ?string $pdfPath): void
    {
        $stmt = $this->conn->prepare("UPDATE bb_offers SET
            client_id = :client_id,
            reference = :reference,
            cortese_att = :cortese_att,
            subject = :subject,
            offer_date = :offer_date,
            total_amount = :total_amount,
            pdf_path = :pdf_path,
            note_interne = :note_interne,
            termini_pagamento = :termini_pagamento,
            condizioni = :condizioni,
            note = :additional,
            doc_path = :doc_path,
            creator_id = :creator_id
        WHERE id = :id");

        $stmt->execute([
            ':client_id' => $data['client'],
            ':reference' => $data['riferimento'],
            ':cortese_att' => $data['cortese_att'],
            ':subject' => $data['oggetto'],
            ':offer_date' => $data['offer_date'],
            ':total_amount' => $data['total_amount'],
            ':pdf_path' => $pdfPath,
            ':note_interne' => $data['note_interne'] ?? '',
            ':termini_pagamento' => $data['termini_pagamento'],
            ':condizioni' => $data['condizioni'],
            ':additional' => $data['additional'],
            ':doc_path' => $data['doc_path'] ?? null,
            ':creator_id' => $creatorId,
            ':id' => $offerId,
        ]);
    }

    public function getOfferById(int $offerId, int $userCompanyId): ?array
    {
        $query = 'SELECT * FROM bb_offers WHERE id = :id';
        $params = [':id' => $offerId];

        if ($userCompanyId !== 1) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = $userCompanyId;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getOfferWithClientById(int $offerId, int $userCompanyId): ?array
    {
        $query = 'SELECT o.*, c.name AS client_name, c.via, c.cap, c.localita
            FROM bb_offers o
            LEFT JOIN bb_clients c ON o.client_id = c.id
            WHERE o.id = :id';
        $params = [':id' => $offerId];

        if ($userCompanyId !== 1) {
            $query .= ' AND o.company_id = :company_id';
            $params[':company_id'] = $userCompanyId;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getOfferItems(int $offerId): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_offer_items WHERE offer_id = :offer_id');
        $stmt->execute([':offer_id' => $offerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClientList(): array
    {
        $stmt = $this->conn->query('SELECT id, name FROM bb_clients ORDER BY name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getVisibleOffers(int $userCompanyId): array
    {
        $query = "SELECT
            o.offer_number,
            o.total_amount,
            o.created_at,
            o.id,
            o.subject,
            o.note_interne,
            o.doc_path,
            o.status,
            c.name AS client_name,
            co.codice AS company_name,
            u.username AS creator_name
            FROM bb_offers o
            LEFT JOIN bb_clients c ON o.client_id = c.id
            LEFT JOIN bb_companies co ON o.company_id = co.id
            LEFT JOIN bb_users u ON o.creator_id = u.id";

        $params = [];
        if ($userCompanyId !== 1) {
            $query .= ' WHERE o.company_id = :company_id';
            $params[':company_id'] = $userCompanyId;
        }

        $query .= ' ORDER BY
            CAST(SUBSTRING_INDEX(o.offer_number, ".", -1) AS UNSIGNED) DESC,
            CAST(SUBSTRING_INDEX(o.offer_number, ".", 1) AS UNSIGNED) DESC';

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchOfferNumbers(string $query, int $userCompanyId): array
    {
        $sql = 'SELECT o.offer_number, c.codice
            FROM bb_offers o
            LEFT JOIN bb_companies c ON o.company_id = c.id
            WHERE o.offer_number LIKE :query';

        $params = [':query' => '%' . $query . '%'];
        if ($userCompanyId !== 1) {
            $sql .= ' AND o.company_id = :company_id';
            $params[':company_id'] = $userCompanyId;
        }

        $sql .= ' ORDER BY o.offer_number DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $offerId, string $status, int $userCompanyId): bool
    {
        $sql = 'UPDATE bb_offers SET status = :status WHERE id = :id';
        $params = [':status' => $status, ':id' => $offerId];

        if ($userCompanyId !== 1) {
            $sql .= ' AND company_id = :company_id';
            $params[':company_id'] = $userCompanyId;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function getFollowups(int $offerId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT f.*, u.username AS creator_name
             FROM bb_offer_followups f
             LEFT JOIN bb_users u ON f.created_by = u.id
             WHERE f.offer_id = :offer_id
             ORDER BY f.followup_date DESC, f.created_at DESC'
        );
        $stmt->execute([':offer_id' => $offerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createFollowup(int $offerId, string $type, string $note, string $date, int $createdBy): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO bb_offer_followups (offer_id, type, note, followup_date, created_by)
             VALUES (:offer_id, :type, :note, :date, :created_by)'
        );
        $stmt->execute([
            ':offer_id'   => $offerId,
            ':type'       => $type,
            ':note'       => $note,
            ':date'       => $date,
            ':created_by' => $createdBy,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function deleteFollowup(int $followupId, int $offerId): bool
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM bb_offer_followups WHERE id = :id AND offer_id = :offer_id'
        );
        $stmt->execute([':id' => $followupId, ':offer_id' => $offerId]);

        return $stmt->rowCount() > 0;
    }
}
