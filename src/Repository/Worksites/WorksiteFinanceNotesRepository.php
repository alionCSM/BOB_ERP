<?php

declare(strict_types=1);

namespace App\Repository\Worksites;

use PDO;

/**
 * Finance-only notes attached to a cantiere. Visibility / write gated
 * at the controller / template level by canSeePrices.
 */
final class WorksiteFinanceNotesRepository
{
    public function __construct(private PDO $conn) {}

    /**
     * All notes for a worksite, newest first, joined to the author's
     * username so the UI can show "by Alion · 14/05/2026 10:32".
     *
     * @return array<int, array{
     *   id:int, worksite_id:int, user_id:?int, content:string,
     *   created_at:string, updated_at:string, username:?string
     * }>
     */
    public function getByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT n.id, n.worksite_id, n.user_id, n.content,
                   COALESCE(n.is_pinned, 0) AS is_pinned,
                   n.updated_by,
                   n.created_at, n.updated_at,
                   u.username  AS author_username,
                   eu.username AS editor_username
            FROM bb_worksite_finance_notes n
            LEFT JOIN bb_users u  ON u.id  = n.user_id
            LEFT JOIN bb_users eu ON eu.id = n.updated_by
            WHERE n.worksite_id = :wid
            ORDER BY COALESCE(n.is_pinned, 0) DESC,
                     n.created_at DESC, n.id DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $worksiteId, int $noteId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM bb_worksite_finance_notes WHERE id = :id AND worksite_id = :wid"
        );
        $stmt->execute([':id' => $noteId, ':wid' => $worksiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $worksiteId, ?int $userId, string $content): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_finance_notes (worksite_id, user_id, content)
            VALUES (:wid, :uid, :content)
        ");
        $stmt->execute([
            ':wid'     => $worksiteId,
            ':uid'     => $userId,
            ':content' => $content,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $worksiteId, int $noteId, ?int $editorUserId, string $content): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_finance_notes
               SET content = :content, updated_by = :uid
             WHERE id = :id AND worksite_id = :wid
        ");
        return $stmt->execute([
            ':content' => $content,
            ':uid'     => $editorUserId,
            ':id'      => $noteId,
            ':wid'     => $worksiteId,
        ]);
    }

    public function togglePinned(int $worksiteId, int $noteId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_finance_notes
               SET is_pinned = IF(COALESCE(is_pinned, 0) = 1, 0, 1)
             WHERE id = :id AND worksite_id = :wid
        ");
        return $stmt->execute([':id' => $noteId, ':wid' => $worksiteId]);
    }

    public function delete(int $worksiteId, int $noteId): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM bb_worksite_finance_notes WHERE id = :id AND worksite_id = :wid"
        );
        return $stmt->execute([':id' => $noteId, ':wid' => $worksiteId]);
    }
}
