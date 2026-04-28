<?php

declare(strict_types=1);

namespace App\Repository\Documents;
use PDO;
use App\Repository\Contracts\WorkerDocumentRepositoryInterface;

class WorkerDocumentRepository implements WorkerDocumentRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function findWorkerUidById(int $workerId): ?string
    {
        $stmt = $this->conn->prepare('SELECT uid FROM bb_workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $uid = $stmt->fetchColumn();

        if (!$uid) {
            return null;
        }

        return (string)$uid;
    }

    public function createDocument(int $workerId, string $docType, string $dateEmission, ?string $expiryDate, string $relativePath): void
    {
        $stmt = $this->conn->prepare('
            INSERT INTO bb_worker_documents
            (worker_id, tipo_documento, data_emissione, scadenza, path)
            VALUES (:worker_id, :doc_type, :date_emission, :expiry_date, :path)
        ');

        $stmt->execute([
            ':worker_id' => $workerId,
            ':doc_type' => $docType,
            ':date_emission' => $dateEmission,
            ':expiry_date' => $expiryDate,
            ':path' => $relativePath,
        ]);
    }

    public function findDocumentById(int $documentId): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, worker_id, path FROM bb_worker_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function deleteDocumentById(int $documentId): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_worker_documents WHERE id = :id');
        $stmt->execute([':id' => $documentId]);
    }

    public function findDocumentWithWorkerById(int $documentId): ?array
    {
        $stmt = $this->conn->prepare("SELECT d.worker_id, d.path, d.tipo_documento, d.data_emissione, d.scadenza, w.uid, w.company AS company_name
            FROM bb_worker_documents d
            JOIN bb_workers w ON w.id = d.worker_id
            WHERE d.id = :id
            LIMIT 1");
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function archiveDocumentVersion(int $documentId, array $current, string $archivedDbPath, int $archivedBy): void
    {
        $stmt = $this->conn->prepare("INSERT INTO bb_worker_doc_archives
            (document_id, worker_id, company_name, tipo_documento, data_emissione, scadenza, path, archived_by)
            VALUES
            (:document_id, :worker_id, :company_name, :tipo_documento, :data_emissione, :scadenza, :path, :archived_by)");
        $stmt->execute([
            ':document_id' => $documentId,
            ':worker_id' => (int)$current['worker_id'],
            ':company_name' => (string)($current['company_name'] ?? 'N/D'),
            ':tipo_documento' => (string)$current['tipo_documento'],
            ':data_emissione' => (string)$current['data_emissione'],
            ':scadenza' => $current['scadenza'] ?: null,
            ':path' => $archivedDbPath,
            ':archived_by' => $archivedBy,
        ]);
    }

    public function updateDocumentMetadata(int $documentId, string $docType, string $dateEmission, ?string $expiryDate, string $path): void
    {
        $stmt = $this->conn->prepare("UPDATE bb_worker_documents
            SET tipo_documento = :tipo_documento,
                data_emissione = :data_emissione,
                scadenza = :scadenza,
                path = :path
            WHERE id = :id");
        $stmt->execute([
            ':tipo_documento' => $docType,
            ':data_emissione' => $dateEmission,
            ':scadenza' => $expiryDate,
            ':path' => $path,
            ':id' => $documentId,
        ]);
    }

    public function getExpiredCompanyDocuments(string $today, bool $isScoped, array $allowedCompanyNames): array
    {
        $sql = "SELECT
                d.id,
                d.tipo_documento,
                d.scadenza,
                d.file_path,
                c.id AS company_id,
                c.name AS company_name,
                COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) AS scadenza_norm
            FROM bb_company_documents d
            JOIN bb_companies c ON c.id = d.company_id
            WHERE
                c.active = 1
                AND d.scadenza IS NOT NULL
                AND d.scadenza != ''
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) < :today";

        $params = [':today' => $today];
        if ($isScoped) {
            if (empty($allowedCompanyNames)) {
                $allowedCompanyNames = ['__none__'];
            }

            $ph = [];
            foreach ($allowedCompanyNames as $i => $name) {
                $k = ':company_' . $i;
                $ph[] = $k;
                $params[$k] = $name;
            }
            $sql .= ' AND c.name IN (' . implode(',', $ph) . ')';
        }

        $sql .= ' ORDER BY scadenza_norm ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiredWorkerDocuments(string $today, bool $isScoped, array $allowedCompanyNames): array
    {
        $sql = "SELECT
                d.id,
                d.tipo_documento,
                d.scadenza,
                d.path,
                w.id AS worker_id,
                w.uid AS worker_uid,
                w.company AS company_name,
                CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
                COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) AS scadenza_norm
            FROM bb_worker_documents d
            JOIN bb_workers w ON w.id = d.worker_id
            LEFT JOIN bb_companies c ON c.name = w.company
            WHERE
                d.nascondere = 'N'
                AND w.active != 'n'
                AND (c.id IS NULL OR c.active = 1)
                AND d.scadenza IS NOT NULL
                AND d.scadenza != ''
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) < :today";

        $params = [':today' => $today];
        if ($isScoped) {
            if (empty($allowedCompanyNames)) {
                $allowedCompanyNames = ['__none__'];
            }

            $ph = [];
            foreach ($allowedCompanyNames as $i => $name) {
                $k = ':company_' . $i;
                $ph[] = $k;
                $params[$k] = $name;
            }
            $sql .= ' AND w.company IN (' . implode(',', $ph) . ')';
        }

        $sql .= ' ORDER BY scadenza_norm ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiringCompanyDocuments(string $today, string $futureDate, bool $isScoped, array $allowedCompanyNames): array
    {
        $sql = "SELECT
                d.id,
                d.tipo_documento,
                d.scadenza,
                d.file_path,
                c.id AS company_id,
                c.name AS company_name,
                COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) AS scadenza_norm
            FROM bb_company_documents d
            JOIN bb_companies c ON c.id = d.company_id
            WHERE
                c.active = 1
                AND d.scadenza IS NOT NULL
                AND d.scadenza != ''
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) >= :today
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) <= :future_date";

        $params = [':today' => $today, ':future_date' => $futureDate];
        if ($isScoped) {
            if (empty($allowedCompanyNames)) {
                $allowedCompanyNames = ['__none__'];
            }

            $ph = [];
            foreach ($allowedCompanyNames as $i => $name) {
                $k = ':company_' . $i;
                $ph[] = $k;
                $params[$k] = $name;
            }
            $sql .= ' AND c.name IN (' . implode(',', $ph) . ')';
        }

        $sql .= ' ORDER BY scadenza_norm ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiringWorkerDocuments(string $today, string $futureDate, bool $isScoped, array $allowedCompanyNames): array
    {
        $sql = "SELECT
                d.id,
                d.tipo_documento,
                d.scadenza,
                d.path,
                w.id AS worker_id,
                w.uid AS worker_uid,
                w.company AS company_name,
                CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
                COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) AS scadenza_norm
            FROM bb_worker_documents d
            JOIN bb_workers w ON w.id = d.worker_id
            LEFT JOIN bb_companies c ON c.name = w.company
            WHERE
                d.nascondere = 'N'
                AND w.active != 'n'
                AND (c.id IS NULL OR c.active = 1)
                AND d.scadenza IS NOT NULL
                AND d.scadenza != ''
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) >= :today
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) <= :future_date";

        $params = [':today' => $today, ':future_date' => $futureDate];
        if ($isScoped) {
            if (empty($allowedCompanyNames)) {
                $allowedCompanyNames = ['__none__'];
            }

            $ph = [];
            foreach ($allowedCompanyNames as $i => $name) {
                $k = ':company_' . $i;
                $ph[] = $k;
                $params[$k] = $name;
            }
            $sql .= ' AND w.company IN (' . implode(',', $ph) . ')';
        }

        $sql .= ' ORDER BY scadenza_norm ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get worker documents expiring on an exact date, grouped by company.
     * Used for 30-day and 7-day alerts.
     */
    public function getWorkerDocumentsExpiringOnDate(string $date, array $companyNames = []): array
    {
        $sql = "SELECT
                d.id,
                d.tipo_documento,
                d.scadenza,
                d.path,
                w.id AS worker_id,
                w.company AS company_name,
                CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
                COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) AS scadenza_norm
            FROM bb_worker_documents d
            JOIN bb_workers w ON w.id = d.worker_id
            LEFT JOIN bb_companies c ON c.name = w.company
            WHERE
                d.nascondere = 'N'
                AND w.active != 'n'
                AND (c.id IS NULL OR c.active = 1)
                AND d.scadenza IS NOT NULL
                AND d.scadenza != ''
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) = :target_date";

        $params = [':target_date' => $date];

        if (!empty($companyNames)) {
            $ph = [];
            foreach ($companyNames as $i => $name) {
                $k = ':company_' . $i;
                $ph[] = $k;
                $params[$k] = $name;
            }
            $sql .= ' AND w.company IN (' . implode(',', $ph) . ')';
        }

        $sql .= ' ORDER BY w.company ASC, w.last_name ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get company documents expiring on an exact date.
     * Used for 30-day and 7-day alerts.
     */
    public function getCompanyDocumentsExpiringOnDate(string $date, array $companyNames = []): array
    {
        $sql = "SELECT
                d.id,
                d.tipo_documento,
                d.scadenza,
                d.file_path,
                c.id AS company_id,
                c.name AS company_name,
                COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) AS scadenza_norm
            FROM bb_company_documents d
            JOIN bb_companies c ON c.id = d.company_id
            WHERE
                c.active = 1
                AND d.scadenza IS NOT NULL
                AND d.scadenza != ''
                AND COALESCE(
                    STR_TO_DATE(d.scadenza, '%Y-%m-%d'),
                    STR_TO_DATE(d.scadenza, '%d/%m/%Y'),
                    STR_TO_DATE(d.scadenza, '%d-%m-%Y')
                ) = :target_date";

        $params = [':target_date' => $date];

        if (!empty($companyNames)) {
            $ph = [];
            foreach ($companyNames as $i => $name) {
                $k = ':company_' . $i;
                $ph[] = $k;
                $params[$k] = $name;
            }
            $sql .= ' AND c.name IN (' . implode(',', $ph) . ')';
        }

        $sql .= ' ORDER BY c.name ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}
