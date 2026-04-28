<?php

declare(strict_types=1);

namespace App\Repository\Bookings;
use PDO;
use App\Repository\Contracts\BookingRepositoryInterface;

class BookingRepository implements BookingRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Get all bookings with struttura info, current period data, and active status.
     * @param array $filters Keys: type, worksite_id, pagato, search, active
     */
    public function getAllBookings(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'b.type = :type';
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['worksite_id'])) {
            $where[] = 'b.worksite_id = :worksite_id';
            $params[':worksite_id'] = (int)$filters['worksite_id'];
        }

        if (isset($filters['pagato']) && $filters['pagato'] !== '') {
            $where[] = 'b.pagato = :pagato';
            $params[':pagato'] = (int)$filters['pagato'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(s.nome LIKE :search1 OR s.citta LIKE :search2 OR s.indirizzo LIKE :search3 OR w.name LIKE :search4)';
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
            $params[':search4'] = '%' . $filters['search'] . '%';
        }

        // Active filter: has a period covering today
        if (isset($filters['active']) && $filters['active'] !== '') {
            if ((int)$filters['active'] === 1) {
                $where[] = 'EXISTS (
                    SELECT 1 FROM bb_booking_periods p2
                    WHERE p2.booking_id = b.id
                    AND (p2.data_dal IS NULL OR p2.data_dal <= CURDATE())
                    AND (p2.data_al IS NULL OR p2.data_al >= CURDATE())
                )';
            } else {
                $where[] = 'NOT EXISTS (
                    SELECT 1 FROM bb_booking_periods p2
                    WHERE p2.booking_id = b.id
                    AND (p2.data_dal IS NULL OR p2.data_dal <= CURDATE())
                    AND (p2.data_al IS NULL OR p2.data_al >= CURDATE())
                )';
            }
        }

        $sql = "
            SELECT b.*,
                   s.nome AS struttura_nome,
                   s.citta AS struttura_citta,
                   s.indirizzo AS struttura_indirizzo,
                   s.telefono AS struttura_telefono,
                   s.country AS struttura_country,
                   CONCAT('[', w.worksite_code, '] ', w.name) AS cantiere_nome,
                   CONCAT(wr.first_name, ' ', wr.last_name) AS capo_squadra_nome,
                   -- Current or latest period info
                   cp.n_persone AS current_persone,
                   cp.prezzo_persona AS current_prezzo,
                   -- Date range from all periods
                   (SELECT MIN(p3.data_dal) FROM bb_booking_periods p3 WHERE p3.booking_id = b.id) AS first_date,
                   (SELECT MAX(p3.data_al) FROM bb_booking_periods p3 WHERE p3.booking_id = b.id) AS last_date,
                   -- Total cost across all periods (days × persone × prezzo)
                   (SELECT SUM(
                       CASE WHEN p6.data_dal IS NOT NULL AND p6.data_al IS NOT NULL
                            THEN (DATEDIFF(p6.data_al, p6.data_dal) + 1)
                                 * COALESCE(p6.n_persone, 0)
                                 * p6.prezzo_persona
                            ELSE 0 END
                   ) FROM bb_booking_periods p6 WHERE p6.booking_id = b.id) AS booking_total,
                   -- Is active?
                   EXISTS (
                       SELECT 1 FROM bb_booking_periods p4
                       WHERE p4.booking_id = b.id
                       AND (p4.data_dal IS NULL OR p4.data_dal <= CURDATE())
                       AND (p4.data_al IS NULL OR p4.data_al >= CURDATE())
                   ) AS is_active
            FROM bb_bookings b
            JOIN bb_strutture s ON s.id = b.struttura_id
            LEFT JOIN bb_worksites w ON w.id = b.worksite_id
            LEFT JOIN bb_workers wr ON wr.id = b.capo_squadra_id
            -- Current period: active today, or latest by sort_order
            LEFT JOIN bb_booking_periods cp ON cp.id = (
                SELECT p5.id FROM bb_booking_periods p5
                WHERE p5.booking_id = b.id
                ORDER BY
                    CASE WHEN (p5.data_dal IS NULL OR p5.data_dal <= CURDATE())
                         AND (p5.data_al IS NULL OR p5.data_al >= CURDATE())
                         THEN 0 ELSE 1 END,
                    p5.sort_order DESC
                LIMIT 1
            )
        ";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY is_active DESC, b.created_at DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT b.*,
                   s.id AS struttura_id,
                   s.nome AS struttura_nome,
                   s.telefono AS struttura_telefono,
                   s.indirizzo AS struttura_indirizzo,
                   s.citta AS struttura_citta,
                   s.provincia AS struttura_provincia,
                   s.country AS struttura_country,
                   s.ragione_sociale AS struttura_ragione_sociale,
                   CONCAT('[', w.worksite_code, '] ', w.name) AS cantiere_nome,
                   CONCAT(wr.first_name, ' ', wr.last_name) AS capo_squadra_nome
            FROM bb_bookings b
            JOIN bb_strutture s ON s.id = b.struttura_id
            LEFT JOIN bb_worksites w ON w.id = b.worksite_id
            LEFT JOIN bb_workers wr ON wr.id = b.capo_squadra_id
            WHERE b.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        // pagato starts at 0 for all new bookings; syncBookingPagato() updates it
        // whenever a fattura's paid status is toggled — never pass it from the form.
        $stmt = $this->conn->prepare("
            INSERT INTO bb_bookings
                (struttura_id, type, worksite_id, capo_squadra_id,
                 pranzo, cena, regime, pagato, note, created_by,
                 a_carico_consorziata, consorziata_id)
            VALUES
                (:struttura_id, :type, :worksite_id, :capo_squadra_id,
                 :pranzo, :cena, :regime, 0, :note, :created_by,
                 :a_carico_consorziata, :consorziata_id)
        ");

        $stmt->execute([
            ':struttura_id'          => (int)$data['struttura_id'],
            ':type'                  => $data['type'],
            ':worksite_id'           => $data['worksite_id'] ?: null,
            ':capo_squadra_id'       => $data['capo_squadra_id'] ?: null,
            ':pranzo'                => (int)($data['pranzo'] ?? 0),
            ':cena'                  => (int)($data['cena'] ?? 0),
            ':regime'                => $data['regime'] ?: null,
            ':note'                  => $data['note'] ?: null,
            ':created_by'            => $data['created_by'] ?? null,
            ':a_carico_consorziata'  => (int)($data['a_carico_consorziata'] ?? 0),
            ':consorziata_id'        => !empty($data['consorziata_id']) ? (int)$data['consorziata_id'] : null,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        // NOTE: pagato is intentionally excluded — it is a computed/synced
        // field maintained exclusively by syncBookingPagato() which fires
        // whenever a fattura's paid status is toggled. Including it here
        // would overwrite the synced value with a missing POST field (0).
        $stmt = $this->conn->prepare("
            UPDATE bb_bookings SET
                struttura_id = :struttura_id,
                type = :type,
                worksite_id = :worksite_id,
                capo_squadra_id = :capo_squadra_id,
                pranzo = :pranzo,
                cena = :cena,
                regime = :regime,
                note = :note,
                a_carico_consorziata = :a_carico_consorziata,
                consorziata_id = :consorziata_id
            WHERE id = :id
        ");

        $stmt->execute([
            ':id'                    => $id,
            ':struttura_id'          => (int)$data['struttura_id'],
            ':type'                  => $data['type'],
            ':worksite_id'           => $data['worksite_id'] ?: null,
            ':capo_squadra_id'       => $data['capo_squadra_id'] ?: null,
            ':pranzo'                => (int)($data['pranzo'] ?? 0),
            ':cena'                  => (int)($data['cena'] ?? 0),
            ':regime'                => $data['regime'] ?: null,
            ':note'                  => $data['note'] ?: null,
            ':a_carico_consorziata'  => (int)($data['a_carico_consorziata'] ?? 0),
            ':consorziata_id'        => !empty($data['consorziata_id']) ? (int)$data['consorziata_id'] : null,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_bookings WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    // ── Periods ─────────────────────────────────

    public function getPeriods(int $bookingId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM bb_booking_periods
            WHERE booking_id = :bid
            ORDER BY sort_order, id
        ");
        $stmt->execute([':bid' => $bookingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addPeriod(int $bookingId, array $period): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_booking_periods (booking_id, data_dal, data_al, n_persone, prezzo_persona, note, sort_order)
            VALUES (:bid, :dal, :al, :persone, :prezzo, :note, :sort)
        ");
        $stmt->execute([
            ':bid'     => $bookingId,
            ':dal'     => $period['data_dal'] ?: null,
            ':al'      => $period['data_al'] ?: null,
            ':persone' => $period['n_persone'] ?: null,
            ':prezzo'  => (float)$period['prezzo_persona'],
            ':note'    => $period['note'] ?: null,
            ':sort'    => (int)($period['sort_order'] ?? 0),
        ]);
    }

    public function deletePeriods(int $bookingId): void
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_booking_periods WHERE booking_id = :bid");
        $stmt->execute([':bid' => $bookingId]);
    }

    /**
     * Sync periods: UPDATE existing (id provided), INSERT new (no id),
     * DELETE any periods for this booking not in the submitted list.
     * This preserves period IDs so override period_id FKs stay valid.
     */
    public function syncPeriods(int $bookingId, array $periods): void
    {
        // Collect submitted DB ids (only those > 0)
        $submittedIds = [];
        foreach ($periods as $period) {
            $pid = (int)($period['id'] ?? 0);
            if ($pid > 0) {
                $submittedIds[] = $pid;
            }
        }

        // Delete periods that are no longer in the form
        if (!empty($submittedIds)) {
            $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
            $this->conn->prepare(
                "DELETE FROM bb_booking_periods WHERE booking_id = ? AND id NOT IN ($placeholders)"
            )->execute(array_merge([$bookingId], $submittedIds));
        } else {
            $this->conn->prepare("DELETE FROM bb_booking_periods WHERE booking_id = ?")
                       ->execute([$bookingId]);
        }

        // Insert or update each period
        foreach ($periods as $i => $period) {
            $prezzo = trim((string)($period['prezzo_persona'] ?? ''));
            if ($prezzo === '') {
                continue;
            }
            $pid = (int)($period['id'] ?? 0);
            if ($pid > 0) {
                $this->conn->prepare("
                    UPDATE bb_booking_periods SET
                        data_dal = :dal, data_al = :al, n_persone = :persone,
                        prezzo_persona = :prezzo, note = :note, sort_order = :sort
                    WHERE id = :id AND booking_id = :bid
                ")->execute([
                    ':dal'    => $period['data_dal'] ?: null,
                    ':al'     => $period['data_al']  ?: null,
                    ':persone'=> $period['n_persone'] ?: null,
                    ':prezzo' => (float)$period['prezzo_persona'],
                    ':note'   => $period['note'] ?: null,
                    ':sort'   => $i,
                    ':id'     => $pid,
                    ':bid'    => $bookingId,
                ]);
            } else {
                $period['sort_order'] = $i;
                $this->addPeriod($bookingId, $period);
            }
        }
    }

    // ── Fatture (invoices) ──────────────────────

    public function getFatture(int $bookingId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM bb_booking_fatture
            WHERE booking_id = :bid
            ORDER BY data_fattura DESC, id DESC
        ");
        $stmt->execute([':bid' => $bookingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addFattura(int $bookingId, array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_booking_fatture (booking_id, numero, data_fattura, importo, file_path, note)
            VALUES (:bid, :numero, :data, :importo, :file_path, :note)
        ");
        $stmt->execute([
            ':bid'       => $bookingId,
            ':numero'    => $data['numero'] ?: null,
            ':data'      => $data['data_fattura'] ?: null,
            ':importo'   => $data['importo'] ?: null,
            ':file_path' => $data['file_path'] ?: null,
            ':note'      => $data['note'] ?: null,
        ]);
        $newId = (int)$this->conn->lastInsertId();
        // New fattura starts unpaid — re-sync so a previously-paid booking goes back to unpaid
        $this->syncBookingPagato($bookingId);
        return $newId;
    }

    public function deleteFattura(int $fatturaId, int $bookingId): ?string
    {
        // Return file_path before deleting so caller can remove file
        $stmt = $this->conn->prepare("SELECT file_path FROM bb_booking_fatture WHERE id = :id AND booking_id = :bid");
        $stmt->execute([':id' => $fatturaId, ':bid' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $filePath = $row ? $row['file_path'] : null;

        $stmt = $this->conn->prepare("DELETE FROM bb_booking_fatture WHERE id = :id AND booking_id = :bid");
        $stmt->execute([':id' => $fatturaId, ':bid' => $bookingId]);

        // Re-sync: deleting the last unpaid fattura may make the booking fully paid
        $this->syncBookingPagato($bookingId);

        return $filePath;
    }

    /**
     * Re-compute and store bb_bookings.pagato for a single booking.
     * Rule: 1 only when there is ≥1 fattura AND every fattura is paid.
     */
    private function syncBookingPagato(int $bookingId): void
    {
        // Count total and unpaid fatture separately — avoids subquery-in-UPDATE quirks
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) AS total, SUM(pagato = 0) AS unpaid
             FROM bb_booking_fatture WHERE booking_id = :bid'
        );
        $stmt->execute([':bid' => $bookingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $allPaid = ($row['total'] > 0 && (int)$row['unpaid'] === 0) ? 1 : 0;

        error_log('[syncBookingPagato] booking=' . $bookingId . ' total=' . $row['total'] . ' unpaid=' . $row['unpaid'] . ' allPaid=' . $allPaid);

        $this->conn->prepare('UPDATE bb_bookings SET pagato = :p WHERE id = :id')
                   ->execute([':p' => $allPaid, ':id' => $bookingId]);
    }

    // ── Overrides (day exceptions) ──────────────────────────────────────────

    public function getOverrides(int $bookingId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT bbo.*,
                    bp.data_dal AS period_dal,
                    bp.data_al  AS period_al
             FROM bb_booking_overrides bbo
             LEFT JOIN bb_booking_periods bp ON bp.id = bbo.period_id
             WHERE bbo.booking_id = :bid
             ORDER BY bbo.period_id ASC, bbo.override_type DESC, bbo.weekday ASC, bbo.data ASC'
        );
        $stmt->execute([':bid' => $bookingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addOverride(int $bookingId, array $data): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO bb_booking_overrides
                 (booking_id, period_id, override_type, weekday, data, pranzo, cena, regime, skip_day, note)
             VALUES (:booking_id, :period_id, :type, :weekday, :data, :pranzo, :cena, :regime, :skip_day, :note)'
        );
        $isWeekday = $data['override_type'] === 'weekday';
        $stmt->execute([
            ':booking_id' => $bookingId,
            ':period_id'  => !empty($data['period_id']) ? (int)$data['period_id'] : null,
            ':type'       => $data['override_type'],
            ':weekday'    => $isWeekday && isset($data['weekday']) && $data['weekday'] !== ''
                             ? (int)$data['weekday'] : null,
            ':data'       => !$isWeekday && !empty($data['data']) ? $data['data'] : null,
            ':pranzo'     => isset($data['pranzo']) && $data['pranzo'] !== ''
                             ? (int)$data['pranzo'] : null,
            ':cena'       => isset($data['cena']) && $data['cena'] !== ''
                             ? (int)$data['cena'] : null,
            ':regime'     => !empty($data['regime']) ? $data['regime'] : null,
            ':skip_day'   => (int)($data['skip_day'] ?? 0),
            ':note'       => trim($data['note'] ?? '') ?: null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    // ── Fattura pagato toggle ───────────────────────────────────────────────

    /**
     * Toggle pagato on a single fattura, then sync bb_bookings.pagato
     * (1 only when ALL fatture for that booking are paid).
     * Returns the new pagato value (0 or 1).
     */
    public function toggleFatturaPagato(int $fatturaId): int
    {
        // Get current state + booking_id
        $stmt = $this->conn->prepare(
            'SELECT id, booking_id, pagato FROM bb_booking_fatture WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $fatturaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Fattura non trovata.');
        }

        $newPagato = $row['pagato'] ? 0 : 1;
        $bookingId = (int)$row['booking_id'];

        error_log('[toggleFatturaPagato] fatturaId=' . $fatturaId . ' bookingId=' . $bookingId . ' newPagato=' . $newPagato);

        // Toggle
        $this->conn->prepare('UPDATE bb_booking_fatture SET pagato = :p WHERE id = :id')
                   ->execute([':p' => $newPagato, ':id' => $fatturaId]);

        // Re-sync booking-level pagato: 1 only when ≥1 fattura and all paid
        $this->syncBookingPagato($bookingId);

        return $newPagato;
    }

    public function deleteOverride(int $overrideId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM bb_booking_overrides WHERE id = :id');
        $stmt->execute([':id' => $overrideId]);
        return $stmt->rowCount() > 0;
    }
}
