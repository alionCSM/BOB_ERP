<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

/**
 * Repository della sezione File di BOB Zone: cartelle, file, commenti.
 */
final class ZoneFileRepository
{
    public function __construct(private PDO $db) {}

    // ─── Cartelle ─────────────────────────────────────────────────────────────

    public function folders(int $worksiteId): array
    {
        $stmt = $this->db->prepare("
            SELECT f.*,
                   (SELECT COUNT(*) FROM bb_zone_files x WHERE x.folder_id = f.id) AS file_count
            FROM bb_zone_folders f
            WHERE f.worksite_id = :w
            ORDER BY f.name ASC
        ");
        $stmt->execute([':w' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param int|null $parentId la cartella che la contiene (null = in cima) */
    public function createFolder(int $worksiteId, string $name, ?int $userId, ?int $parentId = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_folders (worksite_id, parent_id, name, created_by) VALUES (:w, :p, :n, :u)
        ");
        $stmt->execute([':w' => $worksiteId, ':p' => $parentId, ':n' => $name, ':u' => $userId]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteFolder(int $worksiteId, int $folderId): void
    {
        // quello che c'era dentro (file, cartelle, moduli) sale di un livello
        $st = $this->db->prepare("SELECT parent_id FROM bb_zone_folders WHERE id = :f AND worksite_id = :w");
        $st->execute([':f' => $folderId, ':w' => $worksiteId]);
        $su = $st->fetchColumn();
        $su = $su === false || $su === null ? null : (int)$su;
        $this->db->prepare("UPDATE bb_zone_files SET folder_id = :su WHERE folder_id = :f AND worksite_id = :w")
                 ->execute([':su' => $su, ':f' => $folderId, ':w' => $worksiteId]);
        $this->db->prepare("UPDATE bb_zone_folders SET parent_id = :su WHERE parent_id = :f AND worksite_id = :w")
                 ->execute([':su' => $su, ':f' => $folderId, ':w' => $worksiteId]);
        $this->db->prepare("UPDATE bb_zone_form_templates SET folder_id = :su WHERE folder_id = :f AND worksite_id = :w")
                 ->execute([':su' => $su, ':f' => $folderId, ':w' => $worksiteId]);
        $this->db->prepare("DELETE FROM bb_zone_folders WHERE id = :f AND worksite_id = :w")
                 ->execute([':f' => $folderId, ':w' => $worksiteId]);
    }

    // ─── File ─────────────────────────────────────────────────────────────────

    /** @param int|null $folderId  null = root (file senza cartella) */
    public function files(int $worksiteId, ?int $folderId): array
    {
        $sql = "
            SELECT f.*,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS uploader,
                   (SELECT COUNT(*) FROM bb_zone_file_comments c WHERE c.file_id = f.id) AS comment_count,
                   r.titolo AS richiesta_titolo
            FROM bb_zone_files f
            LEFT JOIN bb_users u ON u.id = f.uploaded_by
            LEFT JOIN bb_zone_richieste r ON r.id = f.richiesta_id
            WHERE f.worksite_id = :w AND " . ($folderId === null ? "f.folder_id IS NULL" : "f.folder_id = :f") . "
            ORDER BY f.created_at DESC
        ";
        $stmt = $this->db->prepare($sql);
        $params = [':w' => $worksiteId];
        if ($folderId !== null) $params[':f'] = $folderId;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_files WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $d): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_files (worksite_id, folder_id, file_name, file_path, file_type, size_bytes, uploaded_by, origine_file_id, richiesta_id)
            VALUES (:w, :folder, :name, :path, :type, :size, :uid, :orig, :rich)
        ");
        $stmt->execute([
            ':orig'   => $d['origine_file_id'] ?? null,   // compilato da questo documento
            ':rich'   => $d['richiesta_id'] ?? null,      // arrivato con questa richiesta
            ':w'      => $d['worksite_id'],
            ':folder' => $d['folder_id'] ?? null,
            ':name'   => $d['file_name'],
            ':path'   => $d['file_path'],
            ':type'   => $d['file_type'] ?? null,
            ':size'   => $d['size_bytes'] ?? null,
            ':uid'    => $d['uploaded_by'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $worksiteId, int $fileId): ?array
    {
        $file = $this->find($fileId);
        if (!$file || (int)$file['worksite_id'] !== $worksiteId) return null;
        $this->db->prepare("DELETE FROM bb_zone_file_comments WHERE file_id = :f")->execute([':f' => $fileId]);
        $this->db->prepare("DELETE FROM bb_zone_files WHERE id = :f")->execute([':f' => $fileId]);
        return $file;
    }

    // ─── Commenti file ────────────────────────────────────────────────────────

    public function comments(int $fileId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_file_comments WHERE file_id = :f ORDER BY created_at ASC
        ");
        $stmt->execute([':f' => $fileId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addComment(int $fileId, string $text, string $authorName, ?int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_file_comments (file_id, text, author_name, created_by)
            VALUES (:f, :t, :a, :u)
        ");
        $stmt->execute([':f' => $fileId, ':t' => $text, ':a' => $authorName, ':u' => $userId]);
        return (int)$this->db->lastInsertId();
    }
}
