<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

/**
 * Source of truth per BOB Zone.
 *
 * Strategia architetturale (decisione utente):
 *   - bb_zone_tasks / bb_zone_task_comments / bb_zone_task_checklist sono
 *     la fonte unica di verita'. Funzionano per OGNI cantiere, anche senza
 *     Fieldwire collegato.
 *   - Se il cantiere e' collegato a Fieldwire, fw_id e' popolato e si fanno
 *     chiamate API bidirezionali per sync. I dati pero' restano in BOB.
 *   - Le tabelle bb_fw_* sono deprecate e non vengono piu' scritte.
 */
final class ZoneTaskRepository
{
    public function __construct(private PDO $db) {}

    // ─── Tasks ────────────────────────────────────────────────────────────────

    public function allForWorksite(int $worksiteId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_tasks
            WHERE worksite_id = :wid
            ORDER BY priority DESC, created_at DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_tasks WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByFwId(string $fwId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_tasks WHERE fw_id = :fw LIMIT 1");
        $stmt->execute([':fw' => $fwId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(int $worksiteId, array $data, int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_tasks
                (worksite_id, name, description, status, category, assignee_name, assignee_user_id, start_date, due_date, priority, created_by)
            VALUES
                (:wid, :name, :desc, :status, :cat, :assignee, :auid, :start, :due, :pri, :uid)
        ");
        $stmt->execute([
            ':wid'      => $worksiteId,
            ':name'     => $data['name'] ?? '',
            ':desc'     => $data['description'] ?? null,
            ':status'   => $data['status'] ?? 'open',
            ':cat'      => $data['category'] ?? null,
            ':assignee' => $data['assignee_name'] ?? null,
            ':auid'     => !empty($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null,
            ':start'    => $data['start_date'] ?? null,
            ':due'      => $data['due_date'] ?? null,
            ':pri'      => $data['priority'] ?? 0,
            ':uid'      => $userId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->db->prepare("UPDATE bb_zone_tasks SET status = :s WHERE id = :id")
                 ->execute([':s' => $status, ':id' => $id]);
    }

    public function update(int $id, array $data): void
    {
        $this->db->prepare("
            UPDATE bb_zone_tasks
               SET name          = :name,
                   description   = :desc,
                   status        = :status,
                   category      = :cat,
                   assignee_name = :assignee,
                   assignee_user_id = :auid,
                   start_date    = :start,
                   due_date      = :due,
                   priority      = :pri
             WHERE id = :id
        ")->execute([
            ':name'     => $data['name'] ?? '',
            ':desc'     => $data['description'] ?? null,
            ':status'   => $data['status'] ?? 'open',
            ':cat'      => $data['category'] ?? null,
            ':assignee' => $data['assignee_name'] ?? null,
            ':auid'     => !empty($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null,
            ':start'    => $data['start_date'] ?? null,
            ':due'      => $data['due_date'] ?? null,
            ':pri'      => $data['priority'] ?? 0,
            ':id'       => $id,
        ]);
    }

    public function setFwId(int $id, string $fwId): void
    {
        $this->db->prepare("UPDATE bb_zone_tasks SET fw_id = :fw WHERE id = :id")
                 ->execute([':fw' => $fwId, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        // Le righe figlie (comments/checklist) restano orphan: pulite manualmente
        // per evitare di lasciare spazzatura.
        $this->db->prepare("DELETE FROM bb_zone_task_comments  WHERE task_id = :id")->execute([':id' => $id]);
        $this->db->prepare("DELETE FROM bb_zone_task_checklist WHERE task_id = :id")->execute([':id' => $id]);
        $this->db->prepare("DELETE FROM bb_zone_tasks          WHERE id = :id")     ->execute([':id' => $id]);
    }

    public function deleteByFwId(string $fwId): void
    {
        $task = $this->findByFwId($fwId);
        if ($task) $this->delete((int)$task['id']);
    }

    /**
     * Crea o aggiorna un task BOB partendo dal payload Fieldwire.
     * Idempotente: se gia' esiste un task con quel fw_id viene aggiornato.
     * Ritorna l'id BOB.
     */
    public function upsertFromFieldwire(int $worksiteId, array $fw): int
    {
        $fwId = (string)($fw['id'] ?? '');
        if ($fwId === '') throw new \RuntimeException('Fieldwire task senza id');

        $existing = $this->findByFwId($fwId);

        $data = [
            'name'          => (string)($fw['name'] ?? ''),
            'description'   => $fw['description']   ?? null,
            'status'        => $this->normalizeStatus($fw['status'] ?? 'open'),
            'category'      => $fw['category_name'] ?? null,
            'assignee_name' => $fw['assignee_name'] ?? null,
            'start_date'    => !empty($fw['start_date']) ? substr($fw['start_date'], 0, 10) : null,
            'due_date'      => !empty($fw['due_date'])   ? substr($fw['due_date'],   0, 10) : null,
            'priority'      => (int)($fw['priority']     ?? 0),
        ];

        if ($existing) {
            $this->update((int)$existing['id'], $data);
            return (int)$existing['id'];
        }
        $id = $this->create($worksiteId, $data, 0);
        $this->setFwId($id, $fwId);
        return $id;
    }

    /** Task BOB-only non ancora pushati su Fieldwire (fw_id = NULL). */
    public function findUnsynced(int $worksiteId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_tasks
            WHERE worksite_id = :wid AND (fw_id IS NULL OR fw_id = '')
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizeStatus(string $s): string
    {
        $allowed = ['open', 'in_progress', 'complete', 'verified'];
        return in_array($s, $allowed, true) ? $s : 'open';
    }

    // ─── Comments ─────────────────────────────────────────────────────────────

    public function commentsForTask(int $taskId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_task_comments WHERE task_id = :tid ORDER BY created_at ASC
        ");
        $stmt->execute([':tid' => $taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCommentByFwId(string $fwId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_task_comments WHERE fw_id = :fw LIMIT 1");
        $stmt->execute([':fw' => $fwId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addComment(int $taskId, string $text, string $authorName, ?string $fileUrl = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_task_comments (task_id, text, author_name, file_url)
            VALUES (:tid, :text, :author, :file)
        ");
        $stmt->execute([
            ':tid'    => $taskId,
            ':text'   => $text,
            ':author' => $authorName,
            ':file'   => $fileUrl,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function setCommentFwId(int $id, string $fwId): void
    {
        $this->db->prepare("UPDATE bb_zone_task_comments SET fw_id = :fw WHERE id = :id")
                 ->execute([':fw' => $fwId, ':id' => $id]);
    }

    public function deleteComment(int $id): void
    {
        $this->db->prepare("DELETE FROM bb_zone_task_comments WHERE id = :id")
                 ->execute([':id' => $id]);
    }

    public function findComment(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_task_comments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteCommentByFwId(string $fwId): void
    {
        $this->db->prepare("DELETE FROM bb_zone_task_comments WHERE fw_id = :fw")
                 ->execute([':fw' => $fwId]);
    }

    /** Upsert da Fieldwire. taskBobId = id BOB del task padre (gia' risolto). */
    public function upsertCommentFromFieldwire(int $taskBobId, array $fw): int
    {
        $fwId = (string)($fw['id'] ?? '');
        if ($fwId === '') throw new \RuntimeException('Fieldwire bubble senza id');

        $existing = $this->findCommentByFwId($fwId);
        if ($existing) {
            // niente da aggiornare: i commenti sono immutabili lato BOB
            return (int)$existing['id'];
        }
        $text       = (string)($fw['text']         ?? '');
        $author     = (string)($fw['creator_name'] ?? 'Fieldwire');
        $fileUrl    = $fw['file_url']              ?? null;

        $id = $this->addComment($taskBobId, $text, $author, $fileUrl);
        $this->setCommentFwId($id, $fwId);
        return $id;
    }

    // ─── Checklist ────────────────────────────────────────────────────────────

    public function checklistForTask(int $taskId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_task_checklist WHERE task_id = :tid ORDER BY position ASC, id ASC
        ");
        $stmt->execute([':tid' => $taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findChecklistItem(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_task_checklist WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findChecklistItemByFwId(string $fwId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_task_checklist WHERE fw_id = :fw LIMIT 1");
        $stmt->execute([':fw' => $fwId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addChecklistItem(int $taskId, string $name): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_task_checklist (task_id, name) VALUES (:tid, :name)
        ");
        $stmt->execute([':tid' => $taskId, ':name' => $name]);
        return (int) $this->db->lastInsertId();
    }

    public function completeChecklistItem(int $itemId, bool $completed): void
    {
        $this->db->prepare("UPDATE bb_zone_task_checklist SET completed = :c WHERE id = :id")
                 ->execute([':c' => (int)$completed, ':id' => $itemId]);
    }

    public function setChecklistItemFwId(int $id, string $fwId): void
    {
        $this->db->prepare("UPDATE bb_zone_task_checklist SET fw_id = :fw WHERE id = :id")
                 ->execute([':fw' => $fwId, ':id' => $id]);
    }

    public function deleteChecklistItem(int $id): void
    {
        $this->db->prepare("DELETE FROM bb_zone_task_checklist WHERE id = :id")
                 ->execute([':id' => $id]);
    }

    public function deleteChecklistItemByFwId(string $fwId): void
    {
        $this->db->prepare("DELETE FROM bb_zone_task_checklist WHERE fw_id = :fw")
                 ->execute([':fw' => $fwId]);
    }

    /** Upsert da Fieldwire per un check item. */
    public function upsertChecklistItemFromFieldwire(int $taskBobId, array $fw): int
    {
        $fwId = (string)($fw['id'] ?? '');
        if ($fwId === '') throw new \RuntimeException('Fieldwire check_item senza id');

        $existing = $this->findChecklistItemByFwId($fwId);
        $name      = (string)($fw['name']      ?? '');
        $completed = !empty($fw['completed']);

        if ($existing) {
            $this->db->prepare("
                UPDATE bb_zone_task_checklist
                SET name = :name, completed = :c
                WHERE id = :id
            ")->execute([
                ':name' => $name,
                ':c'    => (int)$completed,
                ':id'   => $existing['id'],
            ]);
            return (int)$existing['id'];
        }
        $id = $this->addChecklistItem($taskBobId, $name);
        $this->completeChecklistItem($id, $completed);
        $this->setChecklistItemFwId($id, $fwId);
        return $id;
    }
}
