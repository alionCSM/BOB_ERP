<?php

declare(strict_types=1);

namespace App\Repository\Share;
use PDO;
use App\Repository\Contracts\SharedLinkRepositoryInterface;

class SharedLinkRepository implements SharedLinkRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getAllLinksByUser(int $userId): array
    {
        $stmt = $this->conn->prepare("
            SELECT l.*,
                (SELECT COUNT(*) FROM bb_shared_link_workers WHERE shared_link_id = l.id) AS worker_count,
                (SELECT COUNT(*) FROM bb_shared_link_companies WHERE shared_link_id = l.id) AS company_count,
                (SELECT COUNT(*) FROM bb_shared_link_files WHERE shared_link_id = l.id AND source = 'manual') AS manual_count
            FROM bb_shared_links l
            WHERE l.user_id = :uid
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllLinks(): array
    {
        $stmt = $this->conn->prepare("
            SELECT l.*,
                (SELECT COUNT(*) FROM bb_shared_link_workers WHERE shared_link_id = l.id) AS worker_count,
                (SELECT COUNT(*) FROM bb_shared_link_companies WHERE shared_link_id = l.id) AS company_count,
                (SELECT COUNT(*) FROM bb_shared_link_files WHERE shared_link_id = l.id AND source = 'manual') AS manual_count
            FROM bb_shared_links l
            ORDER BY l.created_at DESC
        ");

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createLink(int $userId, string $title, ?string $expiresAt, ?string $passwordHash): int
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->conn->prepare("INSERT INTO bb_shared_links
            (user_id, link_token, password, title, expires_at, is_active)
            VALUES (:uid, :token, :pwd, :title, :exp, 1)");
        $stmt->execute([
            ':uid' => $userId,
            ':token' => $token,
            ':pwd' => $passwordHash,
            ':title' => $title,
            ':exp' => $expiresAt,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function deleteLink(int $userId, int $linkId): bool
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare('DELETE FROM bb_shared_link_files WHERE shared_link_id = :id');
            $stmt->execute([':id' => $linkId]);

            $stmt = $this->conn->prepare('DELETE FROM bb_shared_links WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $linkId, ':uid' => $userId]);

            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function addFileToLink(int $linkId, string $filePath, string $originalName, ?int $workerId, string $source, ?int $companyId): void
    {
        $stmt = $this->conn->prepare("INSERT INTO bb_shared_link_files
            (shared_link_id, file_path, original_name, worker_id, company_id, source)
            VALUES (:sid, :path, :name, :wid, :cid, :source)");
        $stmt->execute([
            ':sid' => $linkId,
            ':path' => $filePath,
            ':name' => $originalName,
            ':wid' => $workerId,
            ':cid' => $companyId,
            ':source' => $source,
        ]);
    }

    public function getWorkerDocumentById(int $docId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_worker_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getCompanyDocumentById(int $docId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_company_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAllCompanies(): array
    {
        $stmt = $this->conn->query('SELECT id, name FROM bb_companies ORDER BY name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompanyById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, name FROM bb_companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getCompanyDocuments(int $companyId): array
    {
        $stmt = $this->conn->prepare('SELECT id, tipo_documento, scadenza, file_path FROM bb_company_documents WHERE company_id = :id ORDER BY tipo_documento ASC');
        $stmt->execute([':id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getWorkerDocuments(int $workerId): array
    {
        $stmt = $this->conn->prepare("
        SELECT d.id, d.tipo_documento, d.data_emissione, d.scadenza, d.path
        FROM bb_worker_documents d
        JOIN bb_workers w ON d.worker_id = w.id
        WHERE d.worker_id = :wid
        AND w.active = 'Y'
        ORDER BY d.tipo_documento ASC
    ");

        $stmt->execute([':wid' => $workerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePassword(int $linkId, ?string $passwordHash): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_shared_links SET password = :pwd WHERE id = :id');
        $stmt->execute([':pwd' => $passwordHash, ':id' => $linkId]);
    }

    public function getLinkByToken(string $token): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_shared_links WHERE link_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLinkById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_shared_links WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getFilesForLink(int $linkId): array
    {
        $stmt = $this->conn->prepare("
            SELECT f.*,
                w.first_name AS worker_first_name,
                w.last_name AS worker_last_name,
                w.company AS worker_company,
                c.name AS company_name
            FROM bb_shared_link_files f
            LEFT JOIN bb_workers w ON f.worker_id = w.id
            LEFT JOIN bb_companies c ON f.company_id = c.id
            WHERE f.shared_link_id = :lid
            ORDER BY COALESCE(c.name, w.company), f.source, w.last_name, f.original_name
        ");
        $stmt->execute([':lid' => $linkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateLink(int $linkId, string $title, ?string $expiresAt): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_shared_links SET title = :title, expires_at = :exp WHERE id = :id');
        $stmt->execute([':title' => $title, ':exp' => $expiresAt, ':id' => $linkId]);
    }

    public function removeFileFromLink(int $fileId, int $linkId): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_shared_link_files WHERE id = :fid AND shared_link_id = :lid');
        $stmt->execute([':fid' => $fileId, ':lid' => $linkId]);
    }

    public function removeFilesBySource(int $linkId, string $source, ?int $entityId = null): void
    {
        if ($source === 'worker' && $entityId !== null) {
            $stmt = $this->conn->prepare('DELETE FROM bb_shared_link_files WHERE shared_link_id = :lid AND source = :src AND worker_id = :eid');
            $stmt->execute([':lid' => $linkId, ':src' => $source, ':eid' => $entityId]);
        } elseif ($source === 'company' && $entityId !== null) {
            $stmt = $this->conn->prepare('DELETE FROM bb_shared_link_files WHERE shared_link_id = :lid AND source = :src AND company_id = :eid');
            $stmt->execute([':lid' => $linkId, ':src' => $source, ':eid' => $entityId]);
        }
    }

    public function deactivateLink(int $linkId): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_shared_links SET is_active = 0 WHERE id = :id');
        $stmt->execute([':id' => $linkId]);
    }

    public function activateLink(int $linkId): void
    {
        $stmt = $this->conn->prepare('UPDATE bb_shared_links SET is_active = 1 WHERE id = :id');
        $stmt->execute([':id' => $linkId]);
    }

    public function toggleActive(int $linkId): bool
    {
        $link = $this->getLinkById($linkId);
        if (!$link) return false;
        $newStatus = $link['is_active'] ? 0 : 1;
        $stmt = $this->conn->prepare('UPDATE bb_shared_links SET is_active = :status WHERE id = :id');
        $stmt->execute([':status' => $newStatus, ':id' => $linkId]);
        return (bool)$newStatus;
    }

    // ── Worker/Company association methods ──────────────

    public function addWorkerToLink(int $linkId, int $workerId): void
    {
        $stmt = $this->conn->prepare('INSERT IGNORE INTO bb_shared_link_workers (shared_link_id, worker_id) VALUES (:lid, :wid)');
        $stmt->execute([':lid' => $linkId, ':wid' => $workerId]);
    }

    public function addCompanyToLink(int $linkId, int $companyId): void
    {
        $stmt = $this->conn->prepare('INSERT IGNORE INTO bb_shared_link_companies (shared_link_id, company_id) VALUES (:lid, :cid)');
        $stmt->execute([':lid' => $linkId, ':cid' => $companyId]);
    }

    public function removeWorkerFromLink(int $linkId, int $workerId): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_shared_link_workers WHERE shared_link_id = :lid AND worker_id = :wid');
        $stmt->execute([':lid' => $linkId, ':wid' => $workerId]);
    }

    public function removeCompanyFromLink(int $linkId, int $companyId): void
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_shared_link_companies WHERE shared_link_id = :lid AND company_id = :cid');
        $stmt->execute([':lid' => $linkId, ':cid' => $companyId]);
    }

    public function getLinkedWorkers(int $linkId): array
    {
        $stmt = $this->conn->prepare("
            SELECT lw.worker_id, w.first_name, w.last_name, w.company
            FROM bb_shared_link_workers lw
            JOIN bb_workers w ON w.id = lw.worker_id
            WHERE lw.shared_link_id = :lid
            ORDER BY w.company, w.last_name, w.first_name
        ");
        $stmt->execute([':lid' => $linkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLinkedCompanies(int $linkId): array
    {
        $stmt = $this->conn->prepare("
            SELECT lc.company_id, c.name
            FROM bb_shared_link_companies lc
            JOIN bb_companies c ON c.id = lc.company_id
            WHERE lc.shared_link_id = :lid
            ORDER BY c.name
        ");
        $stmt->execute([':lid' => $linkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build a live file list from linked workers, companies, and manual uploads.
     * Worker/company documents are resolved dynamically from their source tables.
     */
    public function getLiveFilesForLink(int $linkId): array
    {
        $files = [];

        // 1. Worker documents (live from bb_worker_documents)
        $stmt = $this->conn->prepare("
            SELECT d.id AS doc_id, d.tipo_documento, d.path AS file_path, d.scadenza,
                   w.id AS worker_id, w.first_name AS worker_first_name, w.last_name AS worker_last_name, w.company AS worker_company,
                   'worker' AS source
            FROM bb_shared_link_workers lw
            JOIN bb_workers w ON w.id = lw.worker_id
            JOIN bb_worker_documents d ON d.worker_id = w.id
            WHERE lw.shared_link_id = :lid
            ORDER BY w.company, w.last_name, w.first_name, d.tipo_documento
        ");
        $stmt->execute([':lid' => $linkId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $files[] = [
                'id' => 'w_' . $row['doc_id'],
                'doc_id' => (int)$row['doc_id'],
                'source' => 'worker',
                'file_path' => $row['file_path'],
                'original_name' => $row['tipo_documento'],
                'worker_id' => (int)$row['worker_id'],
                'worker_first_name' => $row['worker_first_name'],
                'worker_last_name' => $row['worker_last_name'],
                'worker_company' => $row['worker_company'],
                'company_id' => null,
                'company_name' => null,
            ];
        }

        // 2. Company documents (live from bb_company_documents)
        $stmt = $this->conn->prepare("
            SELECT d.id AS doc_id, d.tipo_documento, d.file_path, d.scadenza,
                   c.id AS company_id, c.name AS company_name,
                   'company' AS source
            FROM bb_shared_link_companies lc
            JOIN bb_companies c ON c.id = lc.company_id
            JOIN bb_company_documents d ON d.company_id = c.id
            WHERE lc.shared_link_id = :lid
            ORDER BY c.name, d.tipo_documento
        ");
        $stmt->execute([':lid' => $linkId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $files[] = [
                'id' => 'c_' . $row['doc_id'],
                'doc_id' => (int)$row['doc_id'],
                'source' => 'company',
                'file_path' => $row['file_path'],
                'original_name' => $row['tipo_documento'],
                'worker_id' => null,
                'worker_first_name' => null,
                'worker_last_name' => null,
                'worker_company' => null,
                'company_id' => (int)$row['company_id'],
                'company_name' => $row['company_name'],
            ];
        }

        // 3. Manual uploads (static from bb_shared_link_files)
        $stmt = $this->conn->prepare("
            SELECT f.*, c.name AS company_name
            FROM bb_shared_link_files f
            LEFT JOIN bb_companies c ON f.company_id = c.id
            WHERE f.shared_link_id = :lid AND f.source = 'manual'
            ORDER BY f.original_name
        ");
        $stmt->execute([':lid' => $linkId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $files[] = [
                'id' => 'm_' . $row['id'],
                'doc_id' => (int)$row['id'],
                'source' => 'manual',
                'file_path' => $row['file_path'],
                'original_name' => $row['original_name'],
                'worker_id' => null,
                'worker_first_name' => null,
                'worker_last_name' => null,
                'worker_company' => null,
                'company_id' => $row['company_id'] ? (int)$row['company_id'] : null,
                'company_name' => $row['company_name'],
            ];
        }

        return $files;
    }

    /**
     * Get counts for a link (workers, companies, live file total)
     */
    public function getLinkCounts(int $linkId): array
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_shared_link_workers WHERE shared_link_id = :lid");
        $stmt->execute([':lid' => $linkId]);
        $workers = (int)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_shared_link_companies WHERE shared_link_id = :lid");
        $stmt->execute([':lid' => $linkId]);
        $companies = (int)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_shared_link_files WHERE shared_link_id = :lid AND source = 'manual'");
        $stmt->execute([':lid' => $linkId]);
        $manuals = (int)$stmt->fetchColumn();

        return ['workers' => $workers, 'companies' => $companies, 'manuals' => $manuals];
    }

    public function logDownload(int $linkId, ?int $fileId, string $ip, string $fileName): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_shared_downloads (shared_link_id, file_id, ip_address, downloaded_file)
            VALUES (:lid, :fid, :ip, :file)
        ");
        $stmt->execute([':lid' => $linkId, ':fid' => $fileId, ':ip' => $ip, ':file' => $fileName]);
    }

    public function getWorkerDocumentsByWorkerIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT w.id AS worker_id, CONCAT(w.first_name, ' ', w.last_name) AS worker, w.company,
                       d.id AS doc_id, d.tipo_documento, d.scadenza
            FROM bb_workers w
            LEFT JOIN bb_worker_documents d ON d.worker_id = w.id
            WHERE w.id IN ($placeholders)
            ORDER BY w.company, worker, d.tipo_documento";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
