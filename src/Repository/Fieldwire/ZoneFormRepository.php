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
    /**
     * I modelli di un cantiere: i suoi, quelli di CSM per tutti i cantieri e
     * quelli del committente per tutti i suoi cantieri.
     *
     * @param int|null $soloCliente il committente che guarda: solo i modelli
     *                              della sua azienda, non quelli interni di CSM
     */
    public function templatesFor(int $worksiteId, ?int $soloCliente = null): array
    {
        $stmt = $this->db->prepare("
            SELECT t.id, t.worksite_id, t.client_id, t.folder_id, t.name, t.description, t.active, t.created_at, t.created_by,
                   (SELECT COUNT(*) FROM bb_zone_form_submissions s WHERE s.template_id = t.id) AS sub_count
            FROM bb_zone_form_templates t
            WHERE t.active = 1
              AND (t.worksite_id = :w
                   OR (t.worksite_id IS NULL AND (t.client_id IS NULL
                        OR t.client_id = (SELECT w.client_id FROM bb_worksites w WHERE w.id = :w2))))
              " . ($soloCliente !== null ? 'AND t.client_id = :c' : '') . "
            ORDER BY (t.worksite_id IS NULL) ASC, t.name ASC
        ");
        $stmt->execute([':w' => $worksiteId, ':w2' => $worksiteId] + ($soloCliente !== null ? [':c' => $soloCliente] : []));
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
            INSERT INTO bb_zone_form_templates (worksite_id, client_id, folder_id, name, description, fields, created_by)
            VALUES (:w, :c, :cart, :n, :desc, :f, :u)
        ");
        $stmt->execute([
            ':cart' => $d['folder_id'] ?? null,      // la cartella dei Documenti (null = in cima)
            ':w'    => $d['worksite_id'],            // null = universale
            ':c'    => $d['client_id'] ?? null,      // il committente a cui appartiene
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
            SET name = :n, description = :desc, fields = :f, worksite_id = :w, client_id = :c, folder_id = :cart
            WHERE id = :id
        ")->execute([
            ':cart' => $d['folder_id'] ?? null,
            ':c'    => $d['client_id'] ?? null,
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
            SELECT s.id, s.template_id, s.template_name, s.submitter_name, s.created_at, s.source, s.submitted_by,
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
