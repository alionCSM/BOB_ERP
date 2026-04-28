<?php

declare(strict_types=1);

namespace App\Repository\Workers;
use RuntimeException;
use PDO;
use App\Repository\Contracts\WorkerRepositoryInterface;

class WorkerRepository implements WorkerRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }


    /**
     * @return array{id: int, uid: string} The new worker's id and uid.
     */
    public function createWorker(array $data, int $createdBy, ?string $photoPath): array
    {
        $uid = $this->generateUniqueUid();

        $stmt = $this->conn->prepare("INSERT INTO bb_workers
            (uid, first_name, last_name, birthday, city_of_birth, company, email, phone, photo, fiscal_code, type_worker, active, active_from, created_at, created_by)
            VALUES
            (:uid, :first_name, :last_name, :birthday, :city_of_birth, :company, :email, :phone, :photo, :fiscal_code, :type_worker, 'Y', :active_from, NOW(), :created_by)");

        $stmt->execute([
            ':uid' => $uid,
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':birthday' => $data['birth_date'],
            ':city_of_birth' => $data['birth_place'],
            ':company' => $data['company'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':photo' => $photoPath,
            ':fiscal_code' => $data['fiscal_code'],
            ':type_worker' => $data['type_worker'],
            ':active_from' => $data['hire_date'],
            ':created_by' => $createdBy,
        ]);

        return ['id' => (int)$this->conn->lastInsertId(), 'uid' => $uid];
    }

    /**
     * Generate a unique 8-character hex uid (e.g. "0005b188", "03d0ac10").
     */
    private function generateUniqueUid(): string
    {
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $uid = bin2hex(random_bytes(4)); // 4 bytes = 8 hex chars
            $stmt = $this->conn->prepare('SELECT COUNT(*) FROM bb_workers WHERE uid = :uid');
            $stmt->execute([':uid' => $uid]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $uid;
            }
        }
        throw new RuntimeException('Impossibile generare un UID univoco.');
    }

    public function getUidById(int $workerId): ?string
    {
        $stmt = $this->conn->prepare('SELECT uid FROM bb_workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $uid = $stmt->fetchColumn();
        return $uid !== false ? (string)$uid : null;
    }

    public function updatePhoto(int $workerId, string $photoPath): bool
    {
        $stmt = $this->conn->prepare('UPDATE bb_workers SET photo = :photo WHERE id = :id');
        return $stmt->execute([':photo' => $photoPath, ':id' => $workerId]);
    }

    public function updateInfo(int $workerId, array $data): void
    {
        $stmt = $this->conn->prepare("UPDATE bb_workers
            SET first_name = :first_name,
                last_name = :last_name,
                birthday = :birthday,
                city_of_birth = :birthplace,
                email = :email,
                phone = :phone,
                company = :company,
                fiscal_code = :fiscal_code,
                active_from = :active_from,
                type_worker = :type_worker
            WHERE id = :id");

        $stmt->execute([
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':birthday' => $data['birthday'],
            ':birthplace' => $data['birthplace'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':company' => $data['company'],
            ':fiscal_code' => $data['fiscal_code'],
            ':active_from' => $data['active_from'],
            ':type_worker' => $data['type_worker'],
            ':id' => $workerId,
        ]);
    }

    public function setActiveStatus(int $workerId, string $status): bool
    {
        $stmt = $this->conn->prepare('UPDATE bb_workers SET active = :status WHERE id = :id');
        return $stmt->execute([':status' => $status, ':id' => $workerId]);
    }

    public function loadByFiscalCode(string $fiscalCode): ?array
    {
        $stmt = $this->conn->prepare('SELECT company, active_from, uid, type_worker FROM bb_workers WHERE fiscal_code = :fiscal_code LIMIT 1');
        $stmt->execute([':fiscal_code' => $fiscalCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertCompanyHistory(array $data): void
    {
        $stmt = $this->conn->prepare('INSERT INTO bb_worker_company_history (fiscal_code, company, internal_company, role, start_date, end_date, uid)
            VALUES (:fiscal_code, :company, :internal_company, :role, :start_date, :end_date, :uid)');
        $stmt->execute($data);
    }

    public function updateCompanyByFiscalCode(string $fiscalCode, string $company, string $startDate, string $role): bool
    {
        $stmt = $this->conn->prepare('UPDATE bb_workers SET company = :company, active_from = :start_date, type_worker = :role WHERE fiscal_code = :fiscal_code');
        return $stmt->execute([
            ':company' => $company,
            ':start_date' => $startDate,
            ':role' => $role,
            ':fiscal_code' => $fiscalCode,
        ]);
    }

    /**
     * Delete a worker by ID.
     */
    public function deleteWorker(int $workerId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_workers WHERE id = :id');
        return $stmt->execute([':id' => $workerId]);
    }

    /**
     * Get worker details by ID.
     */
    public function getWorkerById(int $workerId): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, uid, first_name, last_name, company, company_id, active FROM bb_workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Full row by ID — all columns, for displays that need name/phone/photo/etc.
     */
    public function getFullById(int $workerId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Minimal list (id, nome, company) for dropdowns — all workers including inactive.
     * 'nome' = first_name + last_name.
     */
    public function getAllMinimal(): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, CONCAT(first_name, ' ', last_name) AS nome, company
            FROM bb_workers
            ORDER BY first_name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Active-only list (id, nome, company) for dropdowns.
     * 'nome' = last_name + first_name (matching original Worker::getAllActive format).
     */
    public function getAllActive(): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, CONCAT(last_name, ' ', first_name) AS nome, company
            FROM bb_workers
            WHERE active = 'Y'
            ORDER BY first_name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all documents for a worker.
     */
    public function getDocuments(int $workerId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, tipo_documento, data_emissione, scadenza FROM bb_worker_documents WHERE worker_id = :wid"
        );
        $stmt->execute([':wid' => $workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Company history for a worker (by fiscal code).
     */
    public function getCompanyHistory(string $fiscalCode): array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM bb_worker_company_history WHERE fiscal_code = :fc ORDER BY start_date DESC"
        );
        $stmt->execute([':fc' => $fiscalCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
