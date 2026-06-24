<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

final class ZoneTaskRepository
{
    public function __construct(private PDO $db) {}

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

    public function create(int $worksiteId, array $data, int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_tasks
                (worksite_id, name, description, status, category, assignee_name, start_date, due_date, priority, created_by)
            VALUES
                (:wid, :name, :desc, :status, :cat, :assignee, :start, :due, :pri, :uid)
        ");
        $stmt->execute([
            ':wid'      => $worksiteId,
            ':name'     => $data['name'] ?? '',
            ':desc'     => $data['description'] ?? null,
            ':status'   => $data['status'] ?? 'open',
            ':cat'      => $data['category'] ?? null,
            ':assignee' => $data['assignee_name'] ?? null,
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
        $this->db->prepare("DELETE FROM bb_zone_tasks WHERE id = :id")->execute([':id' => $id]);
    }

    // ── Comments ──────────────────────────────────────────────────────────────

    public function commentsForTask(int $taskId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_task_comments WHERE task_id = :tid ORDER BY created_at ASC
        ");
        $stmt->execute([':tid' => $taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addComment(int $taskId, string $text, string $authorName): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_task_comments (task_id, text, author_name) VALUES (:tid, :text, :author)
        ");
        $stmt->execute([':tid' => $taskId, ':text' => $text, ':author' => $authorName]);
        return (int) $this->db->lastInsertId();
    }

    // ── Checklist ─────────────────────────────────────────────────────────────

    public function checklistForTask(int $taskId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bb_zone_task_checklist WHERE task_id = :tid ORDER BY position ASC, id ASC
        ");
        $stmt->execute([':tid' => $taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}
