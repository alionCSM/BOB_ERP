<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use PDO;

/**
 * All activity (bb_attivita + bb_attivita_photos) SQL in one place.
 * Replaces App\Domain\Attivita and App\Domain\AttivitaPhoto.
 */
final class ActivityRepository
{
    public function __construct(private PDO $conn) {}

    // ── Attivita ─────────────────────────────────────────────────────────────

    public function getByWorksiteId(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.*, u.username AS created_by_name
            FROM bb_attivita a
            LEFT JOIN bb_users u ON u.id = a.created_by
            WHERE a.worksite_id = :wid
            ORDER BY a.data DESC, a.id DESC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActivityById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_attivita WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createActivity(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_attivita (
                worksite_id, data, attivita, persone_impiegate, tempo_ore,
                quantita, giornata_uomo, attrezzature_impiegate, problemi, soluzioni, note,
                created_by
            ) VALUES (
                :worksite_id, :data, :attivita, :persone_impiegate, :tempo_ore,
                :quantita, :giornata_uomo, :attrezzature_impiegate, :problemi, :soluzioni, :note,
                :created_by
            )
        ");
        $stmt->execute([
            ':worksite_id'             => $data['worksite_id'],
            ':data'                    => $data['data'] ?? null,
            ':attivita'                => $data['attivita'] ?? null,
            ':persone_impiegate'       => $data['persone_impiegate'] ?? null,
            ':tempo_ore'               => $data['tempo_ore'] ?? null,
            ':quantita'                => $data['quantita'] ?? null,
            ':giornata_uomo'           => $data['giornata_uomo'] ?? null,
            ':attrezzature_impiegate'  => $data['attrezzature_impiegate'] ?? null,
            ':problemi'                => $data['problemi'] ?? null,
            ':soluzioni'               => $data['soluzioni'] ?? null,
            ':note'                    => $data['note'] ?? null,
            ':created_by'              => isset($data['created_by']) ? (int)$data['created_by'] : null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateActivity(int $id, array $data): int
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_attivita
            SET data                   = :data,
                attivita               = :attivita,
                persone_impiegate      = :persone_impiegate,
                tempo_ore              = :tempo_ore,
                quantita               = :quantita,
                giornata_uomo          = :giornata_uomo,
                attrezzature_impiegate = :attrezzature_impiegate,
                problemi               = :problemi,
                soluzioni              = :soluzioni,
                note                   = :note
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'                      => $id,
            ':data'                    => $data['data'] ?? null,
            ':attivita'                => $data['attivita'] ?? null,
            ':persone_impiegate'       => $data['persone_impiegate'] ?? null,
            ':tempo_ore'               => $data['tempo_ore'] ?? null,
            ':quantita'                => $data['quantita'] ?? null,
            ':giornata_uomo'           => $data['giornata_uomo'] ?? null,
            ':attrezzature_impiegate'  => $data['attrezzature_impiegate'] ?? null,
            ':problemi'                => $data['problemi'] ?? null,
            ':soluzioni'               => $data['soluzioni'] ?? null,
            ':note'                    => $data['note'] ?? null,
        ]);
        return $stmt->rowCount();
    }

    public function deleteActivity(int $id): int
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_attivita WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Sum of giornata_uomo for a worksite; handles mixed decimal/fraction formats.
     */
    public function getTotaleGiornateUomo(int $worksiteId): float
    {
        $stmt = $this->conn->prepare("
            SELECT giornata_uomo FROM bb_attivita
            WHERE worksite_id = :wid AND giornata_uomo IS NOT NULL AND giornata_uomo != ''
        ");
        $stmt->execute([':wid' => $worksiteId]);
        $rows  = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $total = 0.0;
        foreach ($rows as $value) {
            $value = str_replace(',', '.', trim((string)$value));
            if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $m)) {
                $value = $m[1] + ($m[2] / $m[3]);
            }
            $num = (float)$value;
            if ($num > 0) $total += $num;
        }
        return $total;
    }

    // ── Photos ───────────────────────────────────────────────────────────────

    /** All photos for a single attività entry. */
    public function getPhotosByAttivitaId(int $attivitaId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM bb_attivita_photos WHERE attivita_id = :aid ORDER BY created_at ASC'
        );
        $stmt->execute([':aid' => $attivitaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All photos for a worksite, keyed by attivita_id.
     * Returns [ attivita_id => [ ...photo rows ] ]
     */
    public function getPhotosGroupedByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM bb_attivita_photos WHERE worksite_id = :wid ORDER BY attivita_id, created_at ASC'
        );
        $stmt->execute([':wid' => $worksiteId]);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int)$row['attivita_id']][] = $row;
        }
        return $grouped;
    }

    /** Insert a photo record; returns the new ID. */
    public function savePhoto(array $data): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO bb_attivita_photos
             (attivita_id, worksite_id, file_name, file_path, categoria, created_by)
             VALUES (:aid, :wid, :name, :path, :cat, :uid)'
        );
        $stmt->execute([
            ':aid'  => (int)$data['attivita_id'],
            ':wid'  => (int)$data['worksite_id'],
            ':name' => (string)$data['file_name'],
            ':path' => (string)$data['file_path'],
            ':cat'  => in_array($data['categoria'] ?? '', ['info', 'problemi', 'soluzioni'], true)
                           ? $data['categoria'] : 'info',
            ':uid'  => isset($data['created_by']) ? (int)$data['created_by'] : null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function findPhotoById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_attivita_photos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function deletePhoto(int $id): void
    {
        $this->conn->prepare('DELETE FROM bb_attivita_photos WHERE id = :id')
                   ->execute([':id' => $id]);
    }

    /**
     * Delete all photos for an attività; returns the rows so the caller can unlink files.
     */
    public function deletePhotosByAttivitaId(int $attivitaId): array
    {
        $rows = $this->getPhotosByAttivitaId($attivitaId);
        $this->conn->prepare('DELETE FROM bb_attivita_photos WHERE attivita_id = :aid')
                   ->execute([':aid' => $attivitaId]);
        return $rows;
    }
}
