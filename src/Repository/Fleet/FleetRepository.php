<?php

declare(strict_types=1);

namespace App\Repository\Fleet;

use PDO;

/**
 * Single SQL surface per il modulo Flotta.
 *
 * Convenzione "corrente": to_date IS NULL => assegnazione in corso.
 * Le mutazioni di assegnazione passano da reassign*() che chiude la
 * corrente con to_date = ieri e ne apre una nuova in transazione.
 *
 * Assegnatari = operai (bb_workers). Niente FK a bb_workers per non
 * incastrarsi sulla signedness della loro PK.
 */
final class FleetRepository
{
    public function __construct(private PDO $conn) {}

    // ─── Veicoli ──────────────────────────────────────────────────────────────

    /**
     * Lista veicoli con assegnatario corrente (operaio), carta Q8 corrente,
     * telepass corrente. Subquery LEFT-JOINate (compatibili MySQL 5.7).
     */
    public function listVehicles(bool $activeOnly = true): array
    {
        $where = $activeOnly ? "WHERE v.active = 1" : "";
        $sql = "
            SELECT
                v.*,
                aw.worker_id,
                aw.first_name, aw.last_name, aw.assignment_id,
                aw.from_date AS assignment_from,
                fc.id          AS current_card_id,
                fc.numero      AS current_card_numero,
                fc.fornitore   AS current_card_fornitore,
                tp.id          AS current_telepass_id,
                tp.numero      AS current_telepass_numero
            FROM bb_fleet_vehicles v
            LEFT JOIN (
                SELECT a.vehicle_id, a.worker_id, a.id AS assignment_id, a.from_date,
                       w.first_name, w.last_name
                FROM bb_fleet_vehicle_assignments a
                JOIN bb_workers w ON w.id = a.worker_id
                WHERE a.to_date IS NULL
            ) aw ON aw.vehicle_id = v.id
            LEFT JOIN (
                SELECT ca.vehicle_id, c.id, c.numero, c.fornitore
                FROM bb_fleet_fuel_card_assignments ca
                JOIN bb_fleet_fuel_cards c ON c.id = ca.card_id
                WHERE ca.to_date IS NULL AND ca.vehicle_id IS NOT NULL
            ) fc ON fc.vehicle_id = v.id
            LEFT JOIN (
                SELECT ta.vehicle_id, t.id, t.numero
                FROM bb_fleet_telepass_assignments ta
                JOIN bb_fleet_telepass t ON t.id = ta.telepass_id
                WHERE ta.to_date IS NULL AND ta.vehicle_id IS NOT NULL
            ) tp ON tp.vehicle_id = v.id
            {$where}
            ORDER BY v.targa ASC
        ";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findVehicle(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_fleet_vehicles WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createVehicle(array $data): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO bb_fleet_vehicles (targa, modello, tipo, gps_device_id, notes, active)
             VALUES (:targa, :modello, :tipo, :gps, :notes, :active)"
        );
        $stmt->execute([
            ':targa'  => $data['targa'],
            ':modello'=> $data['modello'] ?? null,
            ':tipo'   => $data['tipo']    ?? 'furgone',
            ':gps'    => $data['gps_device_id'] ?? null,
            ':notes'  => $data['notes']   ?? null,
            ':active' => !empty($data['active']) ? 1 : 0,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateVehicle(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_fleet_vehicles SET
                targa = :targa, modello = :modello, tipo = :tipo,
                gps_device_id = :gps, notes = :notes, active = :active
             WHERE id = :id"
        );
        return $stmt->execute([
            ':targa'  => $data['targa'],
            ':modello'=> $data['modello'] ?? null,
            ':tipo'   => $data['tipo']    ?? 'furgone',
            ':gps'    => $data['gps_device_id'] ?? null,
            ':notes'  => $data['notes']   ?? null,
            ':active' => !empty($data['active']) ? 1 : 0,
            ':id'     => $id,
        ]);
    }

    public function deleteVehicle(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_fleet_vehicles WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Storico assegnazioni operaio -> veicolo, recenti prima.
     */
    public function vehicleHistory(int $vehicleId): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.id, a.from_date, a.to_date, a.notes, a.worker_id,
                   w.first_name, w.last_name
            FROM bb_fleet_vehicle_assignments a
            LEFT JOIN bb_workers w ON w.id = a.worker_id
            WHERE a.vehicle_id = ?
            ORDER BY a.from_date DESC, a.id DESC
        ");
        $stmt->execute([$vehicleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reassignVehicle(int $vehicleId, ?int $workerId, string $fromDate, ?int $createdBy, ?string $notes = null): void
    {
        $this->conn->beginTransaction();
        try {
            $closeDate = (new \DateTime($fromDate))->modify('-1 day')->format('Y-m-d');
            $stmt = $this->conn->prepare("
                UPDATE bb_fleet_vehicle_assignments
                SET to_date = ?
                WHERE vehicle_id = ? AND to_date IS NULL
            ");
            $stmt->execute([$closeDate, $vehicleId]);

            if ($workerId !== null) {
                $stmt = $this->conn->prepare("
                    INSERT INTO bb_fleet_vehicle_assignments
                        (vehicle_id, worker_id, from_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$vehicleId, $workerId, $fromDate, $notes, $createdBy]);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    // ─── Carte carburante ─────────────────────────────────────────────────────

    public function listFuelCards(bool $activeOnly = true): array
    {
        $where = $activeOnly ? "WHERE c.active = 1" : "";
        $sql = "
            SELECT
                c.*,
                hv.vehicle_id, hv.targa AS holder_vehicle_targa,
                hw.worker_id,  hw.first_name AS holder_first_name, hw.last_name AS holder_last_name
            FROM bb_fleet_fuel_cards c
            LEFT JOIN (
                SELECT a.card_id, a.vehicle_id, v.targa
                FROM bb_fleet_fuel_card_assignments a
                JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
                WHERE a.to_date IS NULL AND a.vehicle_id IS NOT NULL
            ) hv ON hv.card_id = c.id
            LEFT JOIN (
                SELECT a.card_id, a.worker_id, w.first_name, w.last_name
                FROM bb_fleet_fuel_card_assignments a
                JOIN bb_workers w ON w.id = a.worker_id
                WHERE a.to_date IS NULL AND a.worker_id IS NOT NULL
            ) hw ON hw.card_id = c.id
            {$where}
            ORDER BY c.fornitore ASC, c.numero ASC
        ";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findFuelCard(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_fleet_fuel_cards WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createFuelCard(array $data): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO bb_fleet_fuel_cards (numero, fornitore, notes, active)
             VALUES (:numero, :fornitore, :notes, :active)"
        );
        $stmt->execute([
            ':numero'    => $data['numero'],
            ':fornitore' => $data['fornitore'] ?? 'Q8',
            ':notes'     => $data['notes']     ?? null,
            ':active'    => !empty($data['active']) ? 1 : 0,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateFuelCard(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_fleet_fuel_cards
             SET numero = :numero, fornitore = :fornitore, notes = :notes, active = :active
             WHERE id = :id"
        );
        return $stmt->execute([
            ':numero'    => $data['numero'],
            ':fornitore' => $data['fornitore'] ?? 'Q8',
            ':notes'     => $data['notes']     ?? null,
            ':active'    => !empty($data['active']) ? 1 : 0,
            ':id'        => $id,
        ]);
    }

    public function deleteFuelCard(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_fleet_fuel_cards WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function fuelCardHistory(int $cardId): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.id, a.from_date, a.to_date, a.notes,
                   a.vehicle_id, v.targa AS vehicle_targa,
                   a.worker_id, w.first_name, w.last_name
            FROM bb_fleet_fuel_card_assignments a
            LEFT JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
            LEFT JOIN bb_workers w        ON w.id = a.worker_id
            WHERE a.card_id = ?
            ORDER BY a.from_date DESC, a.id DESC
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reassignFuelCard(int $cardId, ?int $vehicleId, ?int $workerId, string $fromDate, ?int $createdBy, ?string $notes = null): void
    {
        $this->reassignHolder(
            'bb_fleet_fuel_card_assignments', 'card_id', $cardId,
            $vehicleId, $workerId, $fromDate, $createdBy, $notes
        );
    }

    // ─── Telepass ─────────────────────────────────────────────────────────────

    public function listTelepass(bool $activeOnly = true): array
    {
        $where = $activeOnly ? "WHERE t.active = 1" : "";
        $sql = "
            SELECT
                t.*,
                hv.vehicle_id, hv.targa AS holder_vehicle_targa,
                hw.worker_id,  hw.first_name AS holder_first_name, hw.last_name AS holder_last_name
            FROM bb_fleet_telepass t
            LEFT JOIN (
                SELECT a.telepass_id, a.vehicle_id, v.targa
                FROM bb_fleet_telepass_assignments a
                JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
                WHERE a.to_date IS NULL AND a.vehicle_id IS NOT NULL
            ) hv ON hv.telepass_id = t.id
            LEFT JOIN (
                SELECT a.telepass_id, a.worker_id, w.first_name, w.last_name
                FROM bb_fleet_telepass_assignments a
                JOIN bb_workers w ON w.id = a.worker_id
                WHERE a.to_date IS NULL AND a.worker_id IS NOT NULL
            ) hw ON hw.telepass_id = t.id
            {$where}
            ORDER BY t.numero ASC
        ";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findTelepass(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_fleet_telepass WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createTelepass(array $data): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO bb_fleet_telepass (numero, tipo, notes, active)
             VALUES (:numero, :tipo, :notes, :active)"
        );
        $stmt->execute([
            ':numero' => $data['numero'],
            ':tipo'   => $data['tipo']   ?? null,
            ':notes'  => $data['notes']  ?? null,
            ':active' => !empty($data['active']) ? 1 : 0,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateTelepass(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_fleet_telepass
             SET numero = :numero, tipo = :tipo, notes = :notes, active = :active
             WHERE id = :id"
        );
        return $stmt->execute([
            ':numero' => $data['numero'],
            ':tipo'   => $data['tipo']   ?? null,
            ':notes'  => $data['notes']  ?? null,
            ':active' => !empty($data['active']) ? 1 : 0,
            ':id'     => $id,
        ]);
    }

    public function deleteTelepass(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_fleet_telepass WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function telepassHistory(int $telepassId): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.id, a.from_date, a.to_date, a.notes,
                   a.vehicle_id, v.targa AS vehicle_targa,
                   a.worker_id, w.first_name, w.last_name
            FROM bb_fleet_telepass_assignments a
            LEFT JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
            LEFT JOIN bb_workers w        ON w.id = a.worker_id
            WHERE a.telepass_id = ?
            ORDER BY a.from_date DESC, a.id DESC
        ");
        $stmt->execute([$telepassId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reassignTelepass(int $telepassId, ?int $vehicleId, ?int $workerId, string $fromDate, ?int $createdBy, ?string $notes = null): void
    {
        $this->reassignHolder(
            'bb_fleet_telepass_assignments', 'telepass_id', $telepassId,
            $vehicleId, $workerId, $fromDate, $createdBy, $notes
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * close-current + open-new comune per carte/telepass.
     * XOR enforced lato PHP (MySQL 5.7 ignora CHECK).
     */
    private function reassignHolder(
        string $table,
        string $fkColumn,
        int $entityId,
        ?int $vehicleId,
        ?int $workerId,
        string $fromDate,
        ?int $createdBy,
        ?string $notes
    ): void {
        if ($vehicleId !== null && $workerId !== null) {
            throw new \InvalidArgumentException('Holder XOR: passare vehicle_id OPPURE worker_id, non entrambi.');
        }

        $this->conn->beginTransaction();
        try {
            $closeDate = (new \DateTime($fromDate))->modify('-1 day')->format('Y-m-d');
            $stmt = $this->conn->prepare("
                UPDATE {$table}
                SET to_date = ?
                WHERE {$fkColumn} = ? AND to_date IS NULL
            ");
            $stmt->execute([$closeDate, $entityId]);

            if ($vehicleId !== null || $workerId !== null) {
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                        ({$fkColumn}, vehicle_id, worker_id, from_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$entityId, $vehicleId, $workerId, $fromDate, $notes, $createdBy]);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    // ─── Lookup per dropdown ──────────────────────────────────────────────────

    /**
     * Operai attivi per i select di assegnazione.
     * Concat nome + cognome lato SQL per ordinare correttamente.
     */
    public function activeWorkers(): array
    {
        $stmt = $this->conn->query("
            SELECT id, first_name, last_name,
                   TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name
            FROM bb_workers
            WHERE active = 'Y'
            ORDER BY last_name ASC, first_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeVehicles(): array
    {
        $stmt = $this->conn->query("
            SELECT id, targa, modello
            FROM bb_fleet_vehicles
            WHERE active = 1
            ORDER BY targa ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
