<?php

declare(strict_types=1);

namespace App\Repository\Fleet;

use PDO;

/**
 * Repo dedicato a telemetria (imports, trips, fuel_tx, anomalies).
 * Separato da FleetRepository (catalogo) per non gonfiarlo.
 */
final class FleetTelemetryRepository
{
    public function __construct(private PDO $conn) {}

    // ─── Imports ──────────────────────────────────────────────────────────────

    public function createImport(string $source, string $filename, ?string $storagePath, ?int $createdBy): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_fleet_imports (source, filename, storage_path, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$source, $filename, $storagePath, $createdBy]);
        return (int)$this->conn->lastInsertId();
    }

    public function finalizeImport(int $id, array $result): void
    {
        $status = empty($result['errors'])
            ? ($result['rows_imported'] > 0 ? 'ok' : 'error')
            : 'partial';
        $stmt = $this->conn->prepare("
            UPDATE bb_fleet_imports
            SET rows_total = ?, rows_imported = ?, rows_skipped = ?,
                period_from = ?, period_to = ?, status = ?, error_log = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $result['rows_total']    ?? 0,
            $result['rows_imported'] ?? 0,
            $result['rows_skipped']  ?? 0,
            $result['period_from']   ?? null,
            $result['period_to']     ?? null,
            $status,
            !empty($result['errors']) ? implode("\n", $result['errors']) : null,
            $id,
        ]);
    }

    public function listImports(int $limit = 50): array
    {
        $stmt = $this->conn->prepare("
            SELECT i.*,
                   COALESCE(u.username, '—') AS by_username
            FROM bb_fleet_imports i
            LEFT JOIN bb_users u ON u.id = i.created_by
            ORDER BY i.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Trips ────────────────────────────────────────────────────────────────

    /**
     * @param array $filter
     *   - vehicle_id?
     *   - targa?
     *   - from?  Y-m-d
     *   - to?    Y-m-d
     *   - limit? int (default 200)
     */
    public function listTrips(array $filter = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filter['vehicle_id'])) {
            $where[] = 't.vehicle_id = :vid';
            $params[':vid'] = (int)$filter['vehicle_id'];
        }
        if (!empty($filter['targa'])) {
            $where[] = 't.vehicle_targa = :targa';
            $params[':targa'] = strtoupper($filter['targa']);
        }
        if (!empty($filter['from'])) {
            $where[] = 't.start_at >= :from';
            $params[':from'] = $filter['from'] . ' 00:00:00';
        }
        if (!empty($filter['to'])) {
            $where[] = 't.start_at <= :to';
            $params[':to'] = $filter['to'] . ' 23:59:59';
        }
        $limit = (int)($filter['limit'] ?? 200);
        $sql = "
            SELECT t.*, v.modello AS vehicle_modello
            FROM bb_fleet_gps_trips t
            LEFT JOIN bb_fleet_vehicles v ON v.id = t.vehicle_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.start_at DESC
            LIMIT {$limit}
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tripStats(array $filter = []): array
    {
        // contatori veloci per la tab
        $stmt = $this->conn->query("
            SELECT
                COUNT(*) AS total_trips,
                COUNT(DISTINCT vehicle_targa) AS vehicles_seen,
                ROUND(SUM(km_done),0) AS total_km,
                ROUND(SUM(refuels_liters),0) AS total_liters_gps,
                MIN(start_at) AS first_seen,
                MAX(end_at)   AS last_seen
            FROM bb_fleet_gps_trips
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ─── Fuel transactions ────────────────────────────────────────────────────

    public function listFuelTx(array $filter = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filter['card_id'])) {
            $where[] = 'tx.card_id = :cid';
            $params[':cid'] = (int)$filter['card_id'];
        }
        if (!empty($filter['card_numero'])) {
            $where[] = 'tx.card_numero = :cn';
            $params[':cn'] = $filter['card_numero'];
        }
        if (!empty($filter['from'])) {
            $where[] = 'tx.tx_at >= :from';
            $params[':from'] = $filter['from'] . ' 00:00:00';
        }
        if (!empty($filter['to'])) {
            $where[] = 'tx.tx_at <= :to';
            $params[':to'] = $filter['to'] . ' 23:59:59';
        }
        $limit = (int)($filter['limit'] ?? 200);
        $sql = "
            SELECT tx.*, c.fornitore
            FROM bb_fleet_fuel_tx tx
            LEFT JOIN bb_fleet_fuel_cards c ON c.id = tx.card_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY tx.tx_at DESC
            LIMIT {$limit}
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fuelTxStats(): array
    {
        $stmt = $this->conn->query("
            SELECT
                COUNT(*) AS total_tx,
                COUNT(DISTINCT card_numero) AS cards_seen,
                ROUND(SUM(litri),0) AS total_liters,
                ROUND(SUM(importo),2) AS total_importo,
                MIN(tx_at) AS first_seen,
                MAX(tx_at) AS last_seen
            FROM bb_fleet_fuel_tx
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ─── Anomalies ────────────────────────────────────────────────────────────

    public function listAnomalies(array $filter = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filter['severity'])) {
            $where[] = 'a.severity = :sev';
            $params[':sev'] = $filter['severity'];
        }
        if (!empty($filter['status'])) {
            $where[] = 'a.status = :st';
            $params[':st'] = $filter['status'];
        } else {
            $where[] = "a.status = 'open'";
        }
        $limit = (int)($filter['limit'] ?? 100);
        $sql = "
            SELECT a.*, v.targa AS vehicle_targa,
                   TRIM(CONCAT(COALESCE(w.first_name,''),' ',COALESCE(w.last_name,''))) AS worker_name
            FROM bb_fleet_anomalies a
            LEFT JOIN bb_fleet_vehicles v ON v.id = a.vehicle_id
            LEFT JOIN bb_workers w        ON w.id = a.worker_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.severity = 'high' DESC,
                     a.severity = 'medium' DESC,
                     a.event_at DESC
            LIMIT {$limit}
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dismissAnomaly(int $id, ?int $reviewedBy, ?string $note): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_fleet_anomalies
            SET status = 'dismissed', reviewed_by = ?, reviewed_at = NOW(), note = ?
            WHERE id = ?
        ");
        $stmt->execute([$reviewedBy, $note, $id]);
    }
}
