<?php

declare(strict_types=1);

namespace App\Repository\Fleet;

use PDO;

/**
 * Single SQL surface per il modulo Flotta.
 *
 * Convenzione "corrente": to_date IS NULL => assegnazione in corso.
 * Le mutazioni di assegnazione passano da {@see closeAndOpen*()} che chiude
 * la corrente con NOW() e ne apre una nuova in una transazione.
 */
final class FleetRepository
{
    public function __construct(private PDO $conn) {}

    // ─── Veicoli ──────────────────────────────────────────────────────────────

    /**
     * Lista veicoli con assegnatario corrente, cantiere corrente, carta Q8
     * corrente, telepass corrente. Una sola query con LEFT JOIN lateralizzate
     * via subquery (MySQL 5.7 friendly).
     */
    public function listVehicles(bool $activeOnly = true): array
    {
        $where = $activeOnly ? "WHERE v.active = 1" : "";
        $sql = "
            SELECT
                v.*,
                ws.name           AS current_worksite_name,
                au.id             AS current_user_id,
                au.username       AS current_username,
                fc.id             AS current_card_id,
                fc.numero         AS current_card_numero,
                fc.fornitore      AS current_card_fornitore,
                tp.id             AS current_telepass_id,
                tp.numero         AS current_telepass_numero
            FROM bb_fleet_vehicles v
            LEFT JOIN bb_worksites ws ON ws.id = v.current_worksite_id
            LEFT JOIN (
                SELECT a.vehicle_id, a.user_id, u.username, a.id
                FROM bb_fleet_vehicle_assignments a
                JOIN bb_users u ON u.id = a.user_id
                WHERE a.to_date IS NULL
            ) au ON au.vehicle_id = v.id
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
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findVehicle(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_fleet_vehicles WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createVehicle(array $data): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO bb_fleet_vehicles
                (targa, modello, tipo, gps_device_id, current_worksite_id, notes, active)
             VALUES (:targa, :modello, :tipo, :gps, :ws, :notes, :active)"
        );
        $stmt->execute([
            ':targa'  => $data['targa'],
            ':modello'=> $data['modello'] ?? null,
            ':tipo'   => $data['tipo']    ?? 'furgone',
            ':gps'    => $data['gps_device_id'] ?? null,
            ':ws'     => $data['current_worksite_id'] ?? null,
            ':notes'  => $data['notes']   ?? null,
            ':active' => !empty($data['active']) ? 1 : 0,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateVehicle(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_fleet_vehicles SET
                targa = :targa,
                modello = :modello,
                tipo = :tipo,
                gps_device_id = :gps,
                current_worksite_id = :ws,
                notes = :notes,
                active = :active
             WHERE id = :id"
        );
        return $stmt->execute([
            ':targa'  => $data['targa'],
            ':modello'=> $data['modello'] ?? null,
            ':tipo'   => $data['tipo']    ?? 'furgone',
            ':gps'    => $data['gps_device_id'] ?? null,
            ':ws'     => $data['current_worksite_id'] ?? null,
            ':notes'  => $data['notes']   ?? null,
            ':active' => !empty($data['active']) ? 1 : 0,
            ':id'     => $id,
        ]);
    }

    public function deleteVehicle(int $id): bool
    {
        // CASCADE pulisce le assegnazioni; carte/telepass restano nel catalogo
        // ma le loro assegnazioni a questo veicolo restano "storiche".
        $stmt = $this->conn->prepare("DELETE FROM bb_fleet_vehicles WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Storico completo di assegnazioni utente per un veicolo, recenti prima.
     */
    public function vehicleHistory(int $vehicleId): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.*, u.username
            FROM bb_fleet_vehicle_assignments a
            JOIN bb_users u ON u.id = a.user_id
            WHERE a.vehicle_id = ?
            ORDER BY a.from_date DESC, a.id DESC
        ");
        $stmt->execute([$vehicleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cambia assegnatario: chiude la corrente con to_date = ieri (in modo
     * da non sovrapporsi al from_date di oggi della nuova) e ne apre una
     * nuova. Se userId e' null => solo chiusura (mezzo libero).
     */
    public function reassignVehicle(int $vehicleId, ?int $userId, string $fromDate, ?int $createdBy, ?string $notes = null): void
    {
        $this->conn->beginTransaction();
        try {
            // chiusura assegnazioni correnti
            $closeDate = (new \DateTime($fromDate))->modify('-1 day')->format('Y-m-d');
            $stmt = $this->conn->prepare("
                UPDATE bb_fleet_vehicle_assignments
                SET to_date = ?
                WHERE vehicle_id = ? AND to_date IS NULL
            ");
            $stmt->execute([$closeDate, $vehicleId]);

            if ($userId !== null) {
                $stmt = $this->conn->prepare("
                    INSERT INTO bb_fleet_vehicle_assignments
                        (vehicle_id, user_id, from_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$vehicleId, $userId, $fromDate, $notes, $createdBy]);
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
                hu.user_id,    hu.username AS holder_username
            FROM bb_fleet_fuel_cards c
            LEFT JOIN (
                SELECT a.card_id, a.vehicle_id, v.targa
                FROM bb_fleet_fuel_card_assignments a
                JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
                WHERE a.to_date IS NULL AND a.vehicle_id IS NOT NULL
            ) hv ON hv.card_id = c.id
            LEFT JOIN (
                SELECT a.card_id, a.user_id, u.username
                FROM bb_fleet_fuel_card_assignments a
                JOIN bb_users u ON u.id = a.user_id
                WHERE a.to_date IS NULL AND a.user_id IS NOT NULL
            ) hu ON hu.card_id = c.id
            {$where}
            ORDER BY c.fornitore ASC, c.numero ASC
        ";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findFuelCard(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_fleet_fuel_cards WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
            SELECT a.*, v.targa AS vehicle_targa, u.username
            FROM bb_fleet_fuel_card_assignments a
            LEFT JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
            LEFT JOIN bb_users u          ON u.id = a.user_id
            WHERE a.card_id = ?
            ORDER BY a.from_date DESC, a.id DESC
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cambia holder di una carta. $vehicleId XOR $userId; uno dei due deve
     * essere non null. Se entrambi null => solo chiusura (carta libera).
     */
    public function reassignFuelCard(int $cardId, ?int $vehicleId, ?int $userId, string $fromDate, ?int $createdBy, ?string $notes = null): void
    {
        $this->reassignHolder(
            'bb_fleet_fuel_card_assignments',
            'card_id',
            $cardId,
            $vehicleId,
            $userId,
            $fromDate,
            $createdBy,
            $notes
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
                hu.user_id,    hu.username AS holder_username
            FROM bb_fleet_telepass t
            LEFT JOIN (
                SELECT a.telepass_id, a.vehicle_id, v.targa
                FROM bb_fleet_telepass_assignments a
                JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
                WHERE a.to_date IS NULL AND a.vehicle_id IS NOT NULL
            ) hv ON hv.telepass_id = t.id
            LEFT JOIN (
                SELECT a.telepass_id, a.user_id, u.username
                FROM bb_fleet_telepass_assignments a
                JOIN bb_users u ON u.id = a.user_id
                WHERE a.to_date IS NULL AND a.user_id IS NOT NULL
            ) hu ON hu.telepass_id = t.id
            {$where}
            ORDER BY t.numero ASC
        ";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findTelepass(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_fleet_telepass WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
            SELECT a.*, v.targa AS vehicle_targa, u.username
            FROM bb_fleet_telepass_assignments a
            LEFT JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
            LEFT JOIN bb_users u          ON u.id = a.user_id
            WHERE a.telepass_id = ?
            ORDER BY a.from_date DESC, a.id DESC
        ");
        $stmt->execute([$telepassId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reassignTelepass(int $telepassId, ?int $vehicleId, ?int $userId, string $fromDate, ?int $createdBy, ?string $notes = null): void
    {
        $this->reassignHolder(
            'bb_fleet_telepass_assignments',
            'telepass_id',
            $telepassId,
            $vehicleId,
            $userId,
            $fromDate,
            $createdBy,
            $notes
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Implementazione comune del "close current + open new" per carte/telepass
     * (holder polimorfico). Enforce XOR lato PHP per supportare MySQL 5.7.
     */
    private function reassignHolder(
        string $table,
        string $fkColumn,
        int $entityId,
        ?int $vehicleId,
        ?int $userId,
        string $fromDate,
        ?int $createdBy,
        ?string $notes
    ): void {
        if ($vehicleId !== null && $userId !== null) {
            throw new \InvalidArgumentException('Holder XOR: passare vehicle_id OPPURE user_id, non entrambi.');
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

            // null + null => carta/telepass "libero", solo chiusura
            if ($vehicleId !== null || $userId !== null) {
                $stmt = $this->conn->prepare("
                    INSERT INTO {$table}
                        ({$fkColumn}, vehicle_id, user_id, from_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$entityId, $vehicleId, $userId, $fromDate, $notes, $createdBy]);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    // ─── Lookup per dropdown ──────────────────────────────────────────────────

    public function activeUsers(): array
    {
        $stmt = $this->conn->query("
            SELECT id, username
            FROM bb_users
            WHERE active = 'Y'
            ORDER BY username ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeWorksites(): array
    {
        $stmt = $this->conn->query("
            SELECT id, name
            FROM bb_worksites
            WHERE status = 'In corso'
            ORDER BY name ASC
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
