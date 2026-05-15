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
                   n.created_at, n.updated_at,
                   u.username
            FROM bb_worksite_finance_notes n
            LEFT JOIN bb_users u ON u.id = n.user_id
            WHERE n.worksite_id = :wid
            ORDER BY n.created_at DESC, n.id DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    public function delete(int $worksiteId, int $noteId): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM bb_worksite_finance_notes WHERE id = :id AND worksite_id = :wid"
        );
        return $stmt->execute([':id' => $noteId, ':wid' => $worksiteId]);
    }
}
