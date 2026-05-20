<?php

declare(strict_types=1);

namespace App\Repository\Worksites;

use PDO;

/**
 * Finance/commercial notes attached to a cantiere — gated at the
 * controller/template layer by canSeePrices.
 *
 * Each note has:
 *   - tipo:   fatturazione | sconto | generica
 *   - status: aperta | applicata
 *
 * Open notes (status='aperta') surface as banners on the Fatturazione
 * tab of the cantiere and on the bozza fatturazione editor of the
 * cantiere's client. Marking "Applicata" archives the note (still
 * visible in the Note tab with a tag).
 */
final class WorksiteFinanceNotesRepository
{
    public const TIPI = ['fatturazione', 'sconto', 'generica'];
    public const TIPI_BILLING = ['fatturazione', 'sconto'];

    public function __construct(private PDO $conn) {}

    /**
     * Normalize an incoming tipo to one of the allowed values.
     */
    public static function normalizeTipo(?string $tipo): string
    {
        $tipo = strtolower(trim((string)$tipo));
        return in_array($tipo, self::TIPI, true) ? $tipo : 'generica';
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Full list for a cantiere — used by the Note tab.
     * Aperte prima, poi per data desc.
     */
    public function getByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT n.id, n.worksite_id, n.user_id, n.content,
                   n.tipo, n.status,
                   n.applied_by, n.applied_at,
                   n.updated_by, n.created_at, n.updated_at,
                   u.username  AS author_username,
                   eu.username AS editor_username,
                   au.username AS applier_username
            FROM bb_worksite_finance_notes n
            LEFT JOIN bb_users u  ON u.id  = n.user_id
            LEFT JOIN bb_users eu ON eu.id = n.updated_by
            LEFT JOIN bb_users au ON au.id = n.applied_by
            WHERE n.worksite_id = :wid
            ORDER BY CASE n.status WHEN 'aperta' THEN 0 ELSE 1 END,
                     n.created_at DESC, n.id DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Open notes for a worksite, optionally filtered by tipo. Used by
     * the banner above the Fatturazione tab of the cantiere page.
     *
     * @param string[] $tipi
     */
    public function getOpenForWorksite(int $worksiteId, array $tipi = []): array
    {
        $sql = "
            SELECT n.id, n.worksite_id, n.content, n.tipo, n.created_at,
                   u.username AS author_username
            FROM bb_worksite_finance_notes n
            LEFT JOIN bb_users u ON u.id = n.user_id
            WHERE n.worksite_id = :wid AND n.status = 'aperta'
        ";
        $params = [':wid' => $worksiteId];
        if (!empty($tipi)) {
            $placeholders = [];
            foreach (array_values($tipi) as $i => $t) {
                $k = ":t{$i}";
                $placeholders[] = $k;
                $params[$k]     = $t;
            }
            $sql .= ' AND n.tipo IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' ORDER BY n.created_at DESC, n.id DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Open notes across all worksites of a given client. Used by the
     * banner above the bozza fatturazione editor.
     *
     * @param string[] $tipi
     */
    public function getOpenForClient(int $clientId, array $tipi = []): array
    {
        $sql = "
            SELECT n.id, n.worksite_id, n.content, n.tipo, n.created_at,
                   u.username AS author_username,
                   w.name AS worksite_name, w.worksite_code
            FROM bb_worksite_finance_notes n
            JOIN bb_worksites w ON w.id = n.worksite_id
            LEFT JOIN bb_users u ON u.id = n.user_id
            WHERE w.client_id = :cid AND n.status = 'aperta'
        ";
        $params = [':cid' => $clientId];
        if (!empty($tipi)) {
            $placeholders = [];
            foreach (array_values($tipi) as $i => $t) {
                $k = ":t{$i}";
                $placeholders[] = $k;
                $params[$k]     = $t;
            }
            $sql .= ' AND n.tipo IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' ORDER BY w.name ASC, n.created_at DESC, n.id DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
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

    // ── Write ────────────────────────────────────────────────────────────────

    public function create(int $worksiteId, ?int $userId, string $content, string $tipo): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_finance_notes (worksite_id, user_id, content, tipo)
            VALUES (:wid, :uid, :content, :tipo)
        ");
        $stmt->execute([
            ':wid'     => $worksiteId,
            ':uid'     => $userId,
            ':content' => $content,
            ':tipo'    => self::normalizeTipo($tipo),
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $worksiteId, int $noteId, ?int $editorUserId, string $content, string $tipo): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_finance_notes
               SET content    = :content,
                   tipo       = :tipo,
                   updated_by = :uid
             WHERE id = :id AND worksite_id = :wid
        ");
        return $stmt->execute([
            ':content' => $content,
            ':tipo'    => self::normalizeTipo($tipo),
            ':uid'     => $editorUserId,
            ':id'      => $noteId,
            ':wid'     => $worksiteId,
        ]);
    }

    public function markApplied(int $worksiteId, int $noteId, ?int $userId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_finance_notes
               SET status     = 'applicata',
                   applied_by = :uid,
                   applied_at = NOW()
             WHERE id = :id AND worksite_id = :wid
        ");
        return $stmt->execute([
            ':uid' => $userId,
            ':id'  => $noteId,
            ':wid' => $worksiteId,
        ]);
    }

    public function reopen(int $worksiteId, int $noteId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_finance_notes
               SET status     = 'aperta',
                   applied_by = NULL,
                   applied_at = NULL
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
