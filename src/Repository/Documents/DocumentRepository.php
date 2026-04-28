<?php

declare(strict_types=1);

namespace App\Repository\Documents;

use PDO;

/**
 * All worksite-document SQL in one place.
 * Replaces App\Domain\Document.
 */
final class DocumentRepository
{
    private string $table = 'bb_worksite_documents';

    public function __construct(private PDO $conn) {}

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function create(array $data): bool
    {
        $stmt = $this->conn->prepare("
            INSERT INTO {$this->table}
                (worksite_id, file_name, file_path, file_type, category, created_by, note, subcategory, created_at)
            VALUES
                (:worksite_id, :file_name, :file_path, :file_type, :category, :created_by, :note, :subcategory, NOW())
        ");
        return $stmt->execute([
            ':worksite_id' => $data['worksite_id'],
            ':file_name'   => $data['file_name'],
            ':file_path'   => $data['file_path'],
            ':file_type'   => $data['file_type'],
            ':category'    => $data['category'] ?? 'documenti',
            ':created_by'  => $data['created_by'],
            ':note'        => $data['note'] ?? null,
            ':subcategory' => $data['subcategory'] ?? null,
        ]);
    }

    public function getByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM {$this->table}
            WHERE worksite_id = :worksite_id AND is_deleted = 0
            ORDER BY created_at DESC
        ");
        $stmt->execute([':worksite_id' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM {$this->table} WHERE id = :id AND is_deleted = 0 LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE {$this->table} SET is_deleted = 1, updated_at = NOW() WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    // ── Versioning ───────────────────────────────────────────────────────────

    public function archiveVersion(int $docId): void
    {
        $doc = $this->getById($docId);
        if (!$doc) return;

        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_document_versions
                (document_id, version_number, file_name, file_path, file_type, uploaded_by, note, created_at)
            VALUES
                (:doc_id, :version, :file_name, :file_path, :file_type, :uploaded_by, :note, :created_at)
        ");
        $stmt->execute([
            ':doc_id'      => $docId,
            ':version'     => (int)($doc['current_version'] ?? 1),
            ':file_name'   => $doc['file_name'],
            ':file_path'   => $doc['file_path'],
            ':file_type'   => $doc['file_type'],
            ':uploaded_by' => $doc['created_by'],
            ':note'        => $doc['note'] ?? null,
            ':created_at'  => $doc['created_at'],
        ]);
    }

    public function updateDocument(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE {$this->table}
            SET file_name       = :file_name,
                file_path       = :file_path,
                file_type       = :file_type,
                created_by      = :created_by,
                note            = :note,
                current_version = current_version + 1,
                updated_at      = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([
            ':file_name'  => $data['file_name'],
            ':file_path'  => $data['file_path'],
            ':file_type'  => $data['file_type'],
            ':created_by' => $data['created_by'],
            ':note'       => $data['note'] ?? null,
            ':id'         => $id,
        ]);
    }

    public function getVersions(int $docId): array
    {
        $stmt = $this->conn->prepare("
            SELECT v.*, u.first_name, u.last_name
            FROM bb_worksite_document_versions v
            LEFT JOIN bb_users u ON u.id = v.uploaded_by
            WHERE v.document_id = :doc_id
            ORDER BY v.version_number DESC
        ");
        $stmt->execute([':doc_id' => $docId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getVersionById(int $versionId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT v.*, d.worksite_id
            FROM bb_worksite_document_versions v
            JOIN bb_worksite_documents d ON d.id = v.document_id
            WHERE v.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── Sharing ──────────────────────────────────────────────────────────────

    public function shareWith(array $documentIds, array $userIds, int $sharedBy): int
    {
        $count = 0;
        $stmt  = $this->conn->prepare("
            INSERT IGNORE INTO bb_worksite_document_shares
                (document_id, user_id, shared_by, created_at)
            VALUES (:doc_id, :user_id, :shared_by, NOW())
        ");
        foreach ($documentIds as $docId) {
            foreach ($userIds as $userId) {
                $stmt->execute([
                    ':doc_id'    => (int)$docId,
                    ':user_id'   => (int)$userId,
                    ':shared_by' => $sharedBy,
                ]);
                $count += $stmt->rowCount();
            }
        }
        return $count;
    }

    public function unshare(int $docId, int $userId): bool
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_worksite_document_shares
            WHERE document_id = :doc_id AND user_id = :user_id
        ");
        return $stmt->execute([':doc_id' => $docId, ':user_id' => $userId]);
    }

    public function getSharedUsers(int $docId): array
    {
        $stmt = $this->conn->prepare("
            SELECT s.user_id, u.first_name, u.last_name, u.username
            FROM bb_worksite_document_shares s
            JOIN bb_users u ON u.id = s.user_id
            WHERE s.document_id = :doc_id
            ORDER BY u.first_name
        ");
        $stmt->execute([':doc_id' => $docId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSharedDocIdsByUser(int $userId, int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT s.document_id
            FROM bb_worksite_document_shares s
            JOIN bb_worksite_documents d ON d.id = s.document_id
            WHERE s.user_id = :user_id
              AND d.worksite_id = :worksite_id
              AND d.is_deleted = 0
        ");
        $stmt->execute([':user_id' => $userId, ':worksite_id' => $worksiteId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'document_id');
    }

    public function getSharedUsersBulk(array $docIds): array
    {
        if (empty($docIds)) return [];
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $stmt = $this->conn->prepare("
            SELECT document_id, user_id
            FROM bb_worksite_document_shares
            WHERE document_id IN ($placeholders)
        ");
        $stmt->execute(array_values($docIds));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['document_id']][] = (int)$row['user_id'];
        }
        return $result;
    }
}
