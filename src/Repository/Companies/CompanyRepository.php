<?php

declare(strict_types=1);

namespace App\Repository\Companies;
use PDO;
use App\Repository\Contracts\CompanyRepositoryInterface;

class CompanyRepository implements CompanyRepositoryInterface
{
    public function __construct(private PDO $conn)
    {
    }

    public function getAll(): array
    {
        $stmt = $this->conn->query('SELECT * FROM bb_companies ORDER BY name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_companies (name, codice, consorziata) VALUES (:name, :codice, :consorziata)');
        $stmt->execute([
            ':name' => $data['name'],
            ':codice' => $data['codice'],
            ':consorziata' => $data['consorziata'],
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_companies SET name = :name, codice = :codice, consorziata = :consorziata WHERE id = :id');
        $stmt->execute([
            ':name' => $data['name'],
            ':codice' => $data['codice'],
            ':consorziata' => $data['consorziata'],
            ':id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
    }

    public function getDocuments(int $companyId): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_company_documents WHERE company_id = :company_id ORDER BY tipo_documento ASC');
        $stmt->execute([':company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDocumentById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT d.*, c.name AS company_name FROM bb_company_documents d JOIN bb_companies c ON c.id = d.company_id WHERE d.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createDocument(int $companyId, string $docType, ?string $dateEmission, ?string $expiryDate, string $relativePath, int $uploadedBy): void
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_company_documents (company_id, tipo_documento, data_emissione, scadenza, file_path, uploaded_by) VALUES (:company_id, :tipo_documento, :data_emissione, :scadenza, :file_path, :uploaded_by)');
        $stmt->execute([
            ':company_id' => $companyId,
            ':tipo_documento' => $docType,
            ':data_emissione' => $dateEmission,
            ':scadenza' => $expiryDate,
            ':file_path' => $relativePath,
            ':uploaded_by' => $uploadedBy,
        ]);
    }

    public function archiveDocument(int $documentId, array $current, string $archivedDbPath, int $userId): void
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_company_doc_archives (document_id, company_id, company_name, tipo_documento, data_emissione, scadenza, file_path, archived_by) VALUES (:document_id, :company_id, :company_name, :tipo_documento, :data_emissione, :scadenza, :file_path, :archived_by)');
        $stmt->execute([
            ':document_id' => $documentId,
            ':company_id' => $current['company_id'],
            ':company_name' => $current['company_name'],
            ':tipo_documento' => $current['tipo_documento'],
            ':data_emissione' => $current['data_emissione'],
            ':scadenza' => $current['scadenza'],
            ':file_path' => $archivedDbPath,
            ':archived_by' => $userId,
        ]);
    }

    public function updateDocument(int $documentId, string $docType, ?string $dateEmission, ?string $expiryDate, string $path): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_company_documents SET tipo_documento = :tipo_documento, data_emissione = :data_emissione, scadenza = :scadenza, file_path = :file_path WHERE id = :id');
        $stmt->execute([
            ':tipo_documento' => $docType,
            ':data_emissione' => $dateEmission,
            ':scadenza' => $expiryDate,
            ':file_path' => $path,
            ':id' => $documentId,
        ]);
    }

    public function deleteDocumentById(int $id): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_company_documents WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function getWorkersByCompanyId(int $companyId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT w.id, w.uid, w.first_name, w.last_name, w.company, w.active
             FROM bb_workers w
             INNER JOIN bb_companies c ON c.name = w.company
             WHERE c.id = :company_id
             ORDER BY w.last_name ASC, w.first_name ASC'
        );
        $stmt->execute([':company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompanyByName(string $name): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, name, codice, consorziata FROM bb_companies WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getWorkerById(int $workerId): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, uid, first_name, last_name, company, active FROM bb_workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    public function getAllCompanyNames(): array
    {
        $stmt = $this->conn->query('SELECT id, name FROM bb_companies ORDER BY name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hasCompanyAccessMap(): bool
    {
        $stmt = $this->conn->query("SHOW TABLES LIKE 'bb_user_company_access'");
        return (bool)($stmt && $stmt->fetch(PDO::FETCH_NUM));
    }

    public function getAllowedCompanyIdsForUser(int $userId): array
    {
        $stmt = $this->conn->prepare('SELECT company_id FROM bb_user_company_access WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getAssignableCompanyUsers(int $companyId): array
    {
        $stmt = $this->conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email
            FROM bb_users u
            WHERE u.type = 'user'
              AND u.id <> 1
              AND (u.role = 'company_viewer' OR EXISTS (
                    SELECT 1 FROM bb_user_permissions p
                    WHERE p.user_id = u.id AND p.module = 'companies_viewer' AND p.allowed = 1
              ))
              AND NOT EXISTS (
                    SELECT 1 FROM bb_user_company_access a
                    WHERE a.user_id = u.id AND a.company_id = :cid
              )
            ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC");
        $stmt->execute([':cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompaniesByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare("SELECT id, name, codice, consorziata FROM bb_companies WHERE id IN ($placeholders) ORDER BY name ASC");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countCompanyAccessByUserId(int $userId): int
    {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM bb_user_company_access WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function findCompanyNameAndConsorziata(int $companyId): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, name, consorziata FROM bb_companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getConsorziataPresenceDetailRows(int $companyId, string $startDate, string $endDate): array
    {
        $stmt = $this->conn->prepare("SELECT p.data_presenza, w.worksite_code, w.name AS worksite_name, c.name AS company_name, p.costo_unitario, p.quantita, (p.quantita * IFNULL(p.costo_unitario, 0)) AS costo_manodopera
            FROM bb_presenze_consorziate p
            INNER JOIN bb_worksites w ON w.id = p.worksite_id
            INNER JOIN bb_companies c ON c.id = p.azienda_id
            WHERE p.data_presenza BETWEEN :startDate AND :endDate
              AND p.azienda_id = :aziendaId
            ORDER BY p.data_presenza, w.worksite_code");
        $stmt->execute([':startDate' => $startDate, ':endDate' => $endDate, ':aziendaId' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getConsorziataPresenceSummaryRows(int $companyId, string $startDate, string $endDate): array
    {
        $stmt = $this->conn->prepare("SELECT w.worksite_code, w.name AS worksite_name, SUM(p.quantita) AS presenze, SUM(p.quantita * IFNULL(p.costo_unitario, 0)) AS costo
            FROM bb_presenze_consorziate p
            INNER JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE p.data_presenza BETWEEN :startDate AND :endDate
              AND p.azienda_id = :aziendaId
            GROUP BY w.worksite_code, w.name
            ORDER BY w.worksite_code");
        $stmt->execute([':startDate' => $startDate, ':endDate' => $endDate, ':aziendaId' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInternalPresenceDetailRows(string $companyName, string $startDate, string $endDate): array
    {
        $stmt = $this->conn->prepare("SELECT p.data AS data_presenza, w.worksite_code, w.name AS worksite_name, p.azienda AS company_name, 0 AS costo_unitario,
            CASE WHEN p.turno = 'Mezzo' THEN 0.5 ELSE 1 END AS quantita, 0 AS costo_manodopera
            FROM bb_presenze p
            INNER JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE p.data BETWEEN :startDate AND :endDate
              AND p.azienda = :aziendaName
            ORDER BY p.data, w.worksite_code");
        $stmt->execute([':startDate' => $startDate, ':endDate' => $endDate, ':aziendaName' => $companyName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInternalPresenceSummaryRows(string $companyName, string $startDate, string $endDate): array
    {
        $stmt = $this->conn->prepare("SELECT w.worksite_code, w.name AS worksite_name, SUM(CASE WHEN p.turno = 'Mezzo' THEN 0.5 ELSE 1 END) AS presenze, 0 AS costo
            FROM bb_presenze p
            INNER JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE p.data BETWEEN :startDate AND :endDate
              AND p.azienda = :aziendaName
            GROUP BY w.worksite_code, w.name
            ORDER BY w.worksite_code");
        $stmt->execute([':startDate' => $startDate, ':endDate' => $endDate, ':aziendaName' => $companyName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function emailAlreadyUsed(string $email): bool
    {
        $stmt = $this->conn->prepare('SELECT id FROM bb_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function insertCompanyUser(array $data): int
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_users (
            username, password, first_name, last_name, email, phone,
            company, company_id, type, role,
            active, confirmed, must_change_password,
            created_by, created_at
        ) VALUES (
            :username, :password, :first_name, :last_name, :email, :phone,
            :company, :company_id, :type, :role,
            :active, :confirmed, :must_change_password,
            :created_by, NOW()
        )');

        $stmt->execute($data);
        return (int)$this->conn->lastInsertId();
    }

    public function clearUserPermissions(int $userId): void
    {
        $this->conn->prepare('DELETE FROM bb_user_permissions WHERE user_id = :uid')->execute([':uid' => $userId]);
    }

    public function addUserPermission(int $userId, string $module, int $allowed): void
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_user_permissions (user_id, module, allowed) VALUES (:uid, :module, :allowed)');
        $stmt->execute([':uid' => $userId, ':module' => $module, ':allowed' => $allowed]);
    }

    public function addUserCompanyAccess(int $userId, int $companyId): void
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_user_company_access (user_id, company_id, created_at) VALUES (:uid, :cid, NOW())');
        $stmt->execute([':uid' => $userId, ':cid' => $companyId]);
    }

    public function companyExists(int $companyId): bool
    {
        $stmt = $this->conn->prepare('SELECT id FROM bb_companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $companyId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function companyUserExists(int $userId): bool
    {
        $stmt = $this->conn->prepare("SELECT id FROM bb_users WHERE id = :id AND type = 'user' LIMIT 1");
        $stmt->execute([':id' => $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function companyAccessExists(int $userId, int $companyId): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM bb_user_company_access WHERE user_id = :uid AND company_id = :cid LIMIT 1');
        $stmt->execute([':uid' => $userId, ':cid' => $companyId]);
        return (bool)$stmt->fetchColumn();
    }

    public function userHasCompaniesViewerPermission(int $userId): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_user_permissions WHERE user_id = :uid AND module = 'companies_viewer'");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function setActive(int $id, int $active): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_companies SET active = :active WHERE id = :id');
        $stmt->execute([':active' => $active, ':id' => $id]);
    }

    public function deactivateWorkersByCompany(int $companyId, string $companyName): int
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_workers SET active = 'N'
             WHERE (company_id = :cid OR company = :cname)
               AND active != 'N'"
        );
        $stmt->execute([':cid' => $companyId, ':cname' => $companyName]);
        return (int)$stmt->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        try {
            $this->conn->beginTransaction();
            $result = $fn();
            $this->conn->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }
}
