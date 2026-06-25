<?php
declare(strict_types=1);

namespace App\Repository\Fieldwire;

use PDO;

/**
 * Template moduli + compilazioni di BOB Zone.
 */
final class ZoneFormRepository
{
    public function __construct(private PDO $db) {}

    // ─── Template ─────────────────────────────────────────────────────────────

    /** Template disponibili per un cantiere: i suoi + gli universali. */
    public function templatesFor(int $worksiteId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.id, t.worksite_id, t.name, t.description, t.active, t.created_at,
                   (SELECT COUNT(*) FROM bb_zone_form_submissions s WHERE s.template_id = t.id) AS sub_count
            FROM bb_zone_form_templates t
            WHERE t.active = 1 AND (t.worksite_id = :w OR t.worksite_id IS NULL)
            ORDER BY (t.worksite_id IS NULL) ASC, t.name ASC
        ");
        $stmt->execute([':w' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_form_templates WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['fields'] = json_decode($r['fields'] ?? '[]', true) ?: [];
        return $r;
    }

    public function create(array $d): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_form_templates (worksite_id, name, description, fields, created_by)
            VALUES (:w, :n, :desc, :f, :u)
        ");
        $stmt->execute([
            ':w'    => $d['worksite_id'],            // null = universale
            ':n'    => $d['name'],
            ':desc' => $d['description'] ?? null,
            ':f'    => is_string($d['fields']) ? $d['fields'] : json_encode($d['fields']),
            ':u'    => $d['created_by'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $this->db->prepare("
            UPDATE bb_zone_form_templates
            SET name = :n, description = :desc, fields = :f, worksite_id = :w
            WHERE id = :id
        ")->execute([
            ':n'    => $d['name'],
            ':desc' => $d['description'] ?? null,
            ':f'    => is_string($d['fields']) ? $d['fields'] : json_encode($d['fields']),
            ':w'    => $d['worksite_id'],
            ':id'   => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare("UPDATE bb_zone_form_templates SET active = 0 WHERE id = :id")->execute([':id' => $id]);
    }

    // ─── Compilazioni ─────────────────────────────────────────────────────────

    public function createSubmission(array $d): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bb_zone_form_submissions
                (template_id, worksite_id, template_name, `values`, submitter_name, submitted_by, source)
            VALUES (:t, :w, :tn, :v, :sn, :sb, :src)
        ");
        $stmt->execute([
            ':t'   => $d['template_id'],
            ':w'   => $d['worksite_id'],
            ':tn'  => $d['template_name'] ?? null,
            ':v'   => is_string($d['values']) ? $d['values'] : json_encode($d['values']),
            ':sn'  => $d['submitter_name'] ?? null,
            ':sb'  => $d['submitted_by'] ?? null,
            ':src' => $d['source'] ?? 'internal',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function submissions(int $worksiteId, ?int $templateId = null): array
    {
        $sql = "
            SELECT s.id, s.template_id, s.template_name, s.submitter_name, s.created_at, s.source,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS user_name
            FROM bb_zone_form_submissions s
            LEFT JOIN bb_users u ON u.id = s.submitted_by
            WHERE s.worksite_id = :w
        ";
        $params = [':w' => $worksiteId];
        if ($templateId) { $sql .= " AND s.template_id = :t"; $params[':t'] = $templateId; }
        $sql .= " ORDER BY s.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findSubmission(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_zone_form_submissions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['values'] = json_decode($r['values'] ?? '{}', true) ?: [];
        return $r;
    }
}
