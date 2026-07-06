<?php

declare(strict_types=1);

namespace App\Repository\Equipment;

use PDO;
use PDOException;

/**
 * All lifting-equipment SQL in one place.
 * Replaces the mixed DB + domain logic from LiftingEquipment.php.
 *
 * Note: methods that write audit records accept $userId as an explicit parameter
 * (instead of keeping it in the constructor).
 */
final class EquipmentRepository
{
    public function __construct(private PDO $conn) {}

    // ── Equipment catalogue ──────────────────────────────────────────────────

    public function create(string $descrizione, float $costoGiornaliero): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO bb_lifting_equipment (descrizione, costo_giornaliero)
             VALUES (:descrizione, :costo)"
        );
        return $stmt->execute([':descrizione' => $descrizione, ':costo' => $costoGiornaliero]);
    }

    public function getAll(): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_lifting_equipment ORDER BY descrizione ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update(int $id, string $descrizione, float $costoGiornaliero): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_lifting_equipment
             SET descrizione = :descrizione, costo_giornaliero = :costo
             WHERE id = :id"
        );
        return $stmt->execute([':descrizione' => $descrizione, ':costo' => $costoGiornaliero, ':id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_lifting_equipment WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // ── Rentals (bb_worksite_lifting) ────────────────────────────────────────

    /**
     * Single rental row joined to equipment description.
     */
    public function getRentalById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                wl.*,
                le.id   AS lifting_equipment_id,
                le.descrizione
            FROM bb_worksite_lifting wl
            JOIN bb_lifting_equipment le ON wl.lifting_equipment_id = le.id
            WHERE wl.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * All rentals grouped by worksite (for the rentals overview page).
     */
    public function getAllRentals(): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                wl.worksite_id,
                w.worksite_code,
                w.name AS cantiere_nome,
                SUM(CASE WHEN wl.tipo_noleggio <> 'Una Tantum' THEN wl.quantita ELSE 0 END) AS total_mezzi,
                SUM(CASE WHEN wl.tipo_noleggio <> 'Una Tantum' AND wl.stato = 'Attivo' THEN wl.quantita ELSE 0 END) AS mezzi_attivi,
                MIN(wl.data_inizio) AS prima_data_inizio,
                MAX(wl.data_fine)   AS ultima_data_fine
            FROM bb_worksite_lifting wl
            JOIN bb_worksites w ON wl.worksite_id = w.id
            GROUP BY wl.worksite_id, w.worksite_code, w.name
            ORDER BY prima_data_inizio DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All rentals for a single worksite.
     */
    public function getByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT wl.*, le.descrizione AS mezzo_descrizione,
                   (SELECT COUNT(*) FROM bb_lifting_extra_days e WHERE e.rental_id = wl.id) AS extra_days_count
            FROM bb_worksite_lifting wl
            JOIN bb_lifting_equipment le ON wl.lifting_equipment_id = le.id
            WHERE wl.worksite_id = :ws
            ORDER BY wl.data_inizio DESC
        ");
        $stmt->execute([':ws' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total quantity of equipment assigned to a worksite.
     */
    public function getTotalByWorksite(int $worksiteId): int
    {
        $rows = $this->getByWorksite($worksiteId);
        return array_sum(array_column($rows, 'quantita'));
    }

    public function assignToWorksite(
        int    $worksiteId,
        int    $mezzoId,
        string $tipoNoleggio,
        float  $costo,
        int    $quantita,
        string $dataInizio,
        string $calendario = '1,2,3,4,5',
        bool   $festiviInclusi = false
    ): bool {
        // calendario/festivi hanno senso solo per il Giornaliero
        if ($tipoNoleggio !== 'Giornaliero') {
            $calendario     = '1,2,3,4,5';
            $festiviInclusi = false;
        }
        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_lifting
                (worksite_id, lifting_equipment_id, tipo_noleggio, calendario, festivi_inclusi,
                 stato, data_inizio, costo_giornaliero, quantita)
            VALUES
                (:ws, :mezzo, :tipo, :cal, :fest, 'Attivo', :inizio, :costo, :quantita)
        ");
        return $stmt->execute([
            ':ws'       => $worksiteId,
            ':mezzo'    => $mezzoId,
            ':tipo'     => $tipoNoleggio,
            ':cal'      => $calendario,
            ':fest'     => (int)$festiviInclusi,
            ':inizio'   => $dataInizio,
            ':costo'    => $costo,
            ':quantita' => $quantita,
        ]);
    }

    /**
     * Update rental details; writes field-level audit to bb_lifting_log.
     *
     * @param int $userId  The authenticated user performing the change.
     */
    public function updateRentalDetailsWithStatus(
        int    $id,
        float  $costo,
        string $tipoNoleggio,
        string $dataInizio,
        string $stato,
        int    $quantita,
        string $oldStato,
        int    $userId,
        string $calendario = '1,2,3,4,5',
        bool   $festiviInclusi = false
    ): bool {
        $current = $this->getRentalById($id);
        if (!$current) {
            return false;
        }

        if (in_array($current['stato'], ['Completato', 'Finito']) && $stato !== 'Attivo') {
            return false;
        }

        if ($tipoNoleggio !== 'Giornaliero') {
            $calendario     = '1,2,3,4,5';
            $festiviInclusi = false;
        }

        $dataFine = ($stato === 'Attivo') ? null : $current['data_fine'];

        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_lifting
            SET costo_giornaliero = :costo,
                tipo_noleggio     = :tipo,
                calendario        = :cal,
                festivi_inclusi   = :fest,
                data_inizio       = :di,
                stato             = :st,
                quantita          = :qt,
                data_fine         = :df
            WHERE id = :id
        ");
        $stmt->execute([
            ':costo' => $costo,
            ':tipo'  => $tipoNoleggio,
            ':cal'   => $calendario,
            ':fest'  => (int)$festiviInclusi,
            ':di'    => $dataInizio,
            ':st'    => $stato,
            ':qt'    => $quantita,
            ':df'    => $dataFine,
            ':id'    => $id,
        ]);

        // Field-level audit log
        $fields = [
            'costo_giornaliero' => $costo,
            'tipo_noleggio'     => $tipoNoleggio,
            'calendario'        => $calendario,
            'festivi_inclusi'   => (int)$festiviInclusi,
            'data_inizio'       => $dataInizio,
            'stato'             => $stato,
            'quantita'          => $quantita,
        ];
        $logStmt = $this->conn->prepare("
            INSERT INTO bb_lifting_log (rental_id, field_name, old_value, new_value, changed_by)
            VALUES (:rid, :f, :old, :new, :usr)
        ");
        foreach ($fields as $field => $newVal) {
            if ($current[$field] != $newVal) {
                $logStmt->execute([
                    ':rid' => $id,
                    ':f'   => $field,
                    ':old' => $current[$field],
                    ':new' => $newVal,
                    ':usr' => $userId,
                ]);
            }
        }

        return true;
    }

    /**
     * Batch-update multiple rentals (handles deletes + updates in one call).
     *
     * @param int $userId  The authenticated user performing the changes.
     */
    public function updateMultipleRentals(array $data, int $userId): void
    {
        foreach (($data['delete_ids'] ?? []) as $deleteId) {
            $id = (int)$deleteId;
            if ($id > 0) {
                $this->deleteRental($id, $userId);
            }
        }

        $ids        = $data['id']          ?? [];
        $costi      = $data['costo']        ?? [];
        $tipi       = $data['tipo_noleggio']?? [];
        $dateInizio = $data['data_inizio']  ?? [];
        $stati      = $data['stato']        ?? [];
        $quantita   = $data['quantita']     ?? [];
        $calendari  = $data['calendario']   ?? [];
        $festivi    = $data['festivi_inclusi'] ?? [];
        $extraDays  = $data['extra_days']   ?? []; // [rental_id => [date, ...]]

        for ($i = 0, $n = count($ids); $i < $n; $i++) {
            $rentalId = (int)$ids[$i];
            if ($rentalId <= 0) continue;
            $current = $this->getRentalById($rentalId);
            if (!$current) {
                continue;
            }
            $this->updateRentalDetailsWithStatus(
                $rentalId,
                (float)$costi[$i],
                $tipi[$i],
                $dateInizio[$i],
                $stati[$i],
                (int)$quantita[$i],
                $current['stato'],
                $userId,
                (string)($calendari[$i] ?? '1,2,3,4,5'),
                !empty($festivi[$i])
            );

            // Giorni extra: il form invia la lista completa per rental → sync
            $this->syncExtraDays($rentalId, (array)($extraDays[$rentalId] ?? []), $userId);
        }
    }

    // ── Giorni extra (date fuori calendario conteggiate comunque) ────────────

    /** @return array<int, string[]> rental_id => elenco date Y-m-d */
    public function extraDaysByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT e.rental_id, e.data
            FROM bb_lifting_extra_days e
            JOIN bb_worksite_lifting wl ON wl.id = e.rental_id
            WHERE wl.worksite_id = :ws
            ORDER BY e.data ASC
        ");
        $stmt->execute([':ws' => $worksiteId]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['rental_id']][] = substr((string)$row['data'], 0, 10);
        }
        return $out;
    }

    /** Allinea le date extra del rental alla lista inviata dal form. */
    public function syncExtraDays(int $rentalId, array $dates, int $userId): void
    {
        // normalizza e dedup
        $clean = [];
        foreach ($dates as $d) {
            $d = substr(trim((string)$d), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $clean[$d] = true;
        }
        $clean = array_keys($clean);

        $stmt = $this->conn->prepare("SELECT data FROM bb_lifting_extra_days WHERE rental_id = :r");
        $stmt->execute([':r' => $rentalId]);
        $existing = array_map(fn($v) => substr((string)$v, 0, 10), $stmt->fetchAll(PDO::FETCH_COLUMN));

        $toAdd    = array_diff($clean, $existing);
        $toRemove = array_diff($existing, $clean);

        if (!empty($toAdd)) {
            $ins = $this->conn->prepare("
                INSERT IGNORE INTO bb_lifting_extra_days (rental_id, data, created_by)
                VALUES (:r, :d, :u)
            ");
            foreach ($toAdd as $d) {
                $ins->execute([':r' => $rentalId, ':d' => $d, ':u' => $userId ?: null]);
            }
        }
        if (!empty($toRemove)) {
            $del = $this->conn->prepare("DELETE FROM bb_lifting_extra_days WHERE rental_id = :r AND data = :d");
            foreach ($toRemove as $d) {
                $del->execute([':r' => $rentalId, ':d' => $d]);
            }
        }
    }

    /**
     * Hard-delete a rental with audit log.
     *
     * @param int $userId  The authenticated user performing the deletion.
     */
    public function deleteRental(int $id, int $userId): bool
    {
        $current = $this->getRentalById($id);
        if (!$current) {
            return false;
        }

        $logStmt = $this->conn->prepare("
            INSERT INTO bb_lifting_log (rental_id, field_name, old_value, new_value, changed_by)
            VALUES (:rid, 'DELETE', :old, '', :usr)
        ");
        $logStmt->execute([
            ':rid' => $id,
            ':old' => json_encode($current),
            ':usr' => $userId,
        ]);

        $stmt = $this->conn->prepare("DELETE FROM bb_worksite_lifting WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function markAsFinishedWithDate(int $id, string $dataFine): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksite_lifting SET stato = 'Completato', data_fine = :df WHERE id = :id
        ");
        $stmt->execute([':df' => $dataFine, ':id' => $id]);
    }

    /**
     * Create the "completed" split record and reduce the original quantity.
     */
    public function createSplitRecord(array $mezzo, int $qt, string $dataFine): void
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_worksite_lifting
                    (lifting_equipment_id, worksite_id, quantita, costo_giornaliero,
                     tipo_noleggio, calendario, festivi_inclusi, data_inizio, data_fine, stato)
                VALUES
                    (:le_id, :ws, :qt, :costo, :tipo, :cal, :fest, :di, :df, 'Completato')
            ");
            $stmt->execute([
                ':le_id' => $mezzo['lifting_equipment_id'],
                ':ws'    => $mezzo['worksite_id'],
                ':qt'    => $qt,
                ':costo' => $mezzo['costo_giornaliero'],
                ':tipo'  => $mezzo['tipo_noleggio'],
                ':cal'   => $mezzo['calendario'] ?? 'lun_ven',
                ':fest'  => (int)($mezzo['festivi_inclusi'] ?? 0),
                ':di'    => $mezzo['data_inizio'],
                ':df'    => $dataFine,
            ]);
        } catch (PDOException $e) {
            $logger = \App\Infrastructure\LoggerFactory::database();
            $logger->error('Errore createSplitRecord: ' . $e->getMessage(), ['mezzo' => $mezzo, 'qt' => $qt]);
            throw $e;
        }
    }

    public function updateQuantity(int $id, int $newQuantita): void
    {
        $stmt = $this->conn->prepare("UPDATE bb_worksite_lifting SET quantita = :q WHERE id = :id");
        $stmt->execute([':q' => $newQuantita, ':id' => $id]);
    }
}
