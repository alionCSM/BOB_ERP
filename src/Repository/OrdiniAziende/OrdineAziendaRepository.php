<?php

declare(strict_types=1);

namespace App\Repository\OrdiniAziende;

use PDO;

/**
 * Tracking ordini per aziende non-consorziate.
 *
 * Schema: bb_ordini_aziende (one row per azienda + calendar month).
 * Cantieri-list prefill is derived from bb_presenze via the
 * (free-text) azienda name match — same approach as the existing
 * presenze-azienda export.
 */
final class OrdineAziendaRepository
{
    public function __construct(private PDO $conn) {}

    // ── List ─────────────────────────────────────────────────────────────────

    public function listOrdini(?int $year = null, ?int $aziendaId = null): array
    {
        $where = [];
        $params = [];
        if ($year !== null) {
            $where[] = 'o.anno = :year';
            $params[':year'] = $year;
        }
        if ($aziendaId !== null) {
            $where[] = 'o.azienda_id = :aid';
            $params[':aid'] = $aziendaId;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT
                o.id, o.azienda_id, o.anno, o.mese,
                o.order_number, o.order_date, o.total,
                o.descrizione, o.note, o.created_at,
                c.name AS azienda_name, c.codice AS azienda_codice
            FROM bb_ordini_aziende o
            INNER JOIN bb_companies c ON c.id = o.azienda_id
            {$whereSql}
            ORDER BY o.anno DESC, o.mese DESC, o.id DESC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT o.*, c.name AS azienda_name, c.codice AS azienda_codice
            FROM bb_ordini_aziende o
            INNER JOIN bb_companies c ON c.id = o.azienda_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Aziende non-consorziate (for the picker) ─────────────────────────────

    public function listAziendeNonConsorziate(): array
    {
        $stmt = $this->conn->query("
            SELECT id, name, codice
            FROM bb_companies
            WHERE COALESCE(consorziata, 0) = 0
              AND COALESCE(active, 1) = 1
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Cantieri worked at by an azienda in a given month ────────────────────

    /**
     * Distinct cantieri found in bb_presenze for the given azienda name
     * during the given calendar month. Returns rows with worksite_code +
     * worksite_name + total presenze (giorni) so the descrizione prefill
     * can be informative.
     *
     * @return array<int, array{worksite_id:int, worksite_code:?string, worksite_name:?string, presenze_gg:float}>
     */
    public function getCantieriForAziendaMonth(string $aziendaName, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $stmt = $this->conn->prepare("
            SELECT
                w.id   AS worksite_id,
                w.worksite_code,
                w.name AS worksite_name,
                SUM(
                    CASE
                        WHEN p.turno = 'Intero' THEN 1
                        WHEN p.turno = 'Mezzo'  THEN 0.5
                        ELSE 0
                    END
                ) AS presenze_gg
            FROM bb_presenze p
            INNER JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE p.azienda = :azienda
              AND p.data BETWEEN :start AND :end
            GROUP BY w.id, w.worksite_code, w.name
            ORDER BY w.worksite_code ASC, w.name ASC
        ");
        $stmt->execute([
            ':azienda' => $aziendaName,
            ':start'   => $start,
            ':end'     => $end,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build the descrizione text from the cantieri list. Plain text so it
     * remains operator-editable.
     */
    public function buildDescrizione(array $cantieri, int $year, int $month): string
    {
        $monthLabels = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                        'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        $label = ($monthLabels[$month - 1] ?? '') . ' ' . $year;

        if (empty($cantieri)) {
            return "Periodo: {$label}\n\nNessun cantiere registrato per il periodo.";
        }

        $lines = ["Periodo: {$label}", '', 'Cantieri:'];
        foreach ($cantieri as $r) {
            $code  = trim((string)($r['worksite_code'] ?? ''));
            $name  = trim((string)($r['worksite_name'] ?? ''));
            $gg    = (float)($r['presenze_gg'] ?? 0);
            $left  = $code !== '' ? "[{$code}] {$name}" : $name;
            $lines[] = '- ' . $left . ' — ' . rtrim(rtrim(number_format($gg, 2, ',', '.'), '0'), ',') . ' gg';
        }
        return implode("\n", $lines);
    }

    // ── Order-number generation ──────────────────────────────────────────────

    /**
     * Next order number in the form OA_YYYY_NNNN (per-year sequence).
     */
    public function nextOrderNumber(int $year): string
    {
        $stmt = $this->conn->prepare("
            SELECT order_number FROM bb_ordini_aziende
            WHERE order_number LIKE :prefix
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':prefix' => 'OA_' . $year . '_%']);
        $last = $stmt->fetchColumn();

        $next = 1;
        if ($last && preg_match('/^OA_\d{4}_(\d+)$/', $last, $m)) {
            $next = (int)$m[1] + 1;
        }
        return sprintf('OA_%04d_%04d', $year, $next);
    }

    // ── Write ────────────────────────────────────────────────────────────────

    public function insert(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ordini_aziende
                (azienda_id, anno, mese, order_number, order_date, total, descrizione, note, created_by)
            VALUES
                (:aid, :anno, :mese, :num, :data, :total, :descr, :note, :uid)
        ");
        $stmt->execute([
            ':aid'   => (int)$data['azienda_id'],
            ':anno'  => (int)$data['anno'],
            ':mese'  => (int)$data['mese'],
            ':num'   => (string)$data['order_number'],
            ':data'  => (string)$data['order_date'],
            ':total' => (float)($data['total'] ?? 0),
            ':descr' => $data['descrizione'] ?? null,
            ':note'  => $data['note'] ?? null,
            ':uid'   => $data['created_by'] ?? null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_ordini_aziende SET
                azienda_id  = :aid,
                anno        = :anno,
                mese        = :mese,
                order_date  = :data,
                total       = :total,
                descrizione = :descr,
                note        = :note
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'    => $id,
            ':aid'   => (int)$data['azienda_id'],
            ':anno'  => (int)$data['anno'],
            ':mese'  => (int)$data['mese'],
            ':data'  => (string)$data['order_date'],
            ':total' => (float)($data['total'] ?? 0),
            ':descr' => $data['descrizione'] ?? null,
            ':note'  => $data['note'] ?? null,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_ordini_aziende WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
