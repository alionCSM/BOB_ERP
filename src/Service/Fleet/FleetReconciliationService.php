<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;

/**
 * Engine di riconciliazione Fleet — deterministica, niente AI per ora.
 *
 * Confronta:
 *  - bb_fleet_fuel_tx          (transazioni Q8)
 *  - bb_fleet_gps_trips        (tratte GPS, gia' con rifornimenti rilevati)
 *  - bb_fleet_fuel_card_assignments  (carta→veicolo per data)
 *  - bb_fleet_vehicle_assignments    (veicolo→operaio per data)
 *  - bb_presenze                     (operaio→cantiere per data)
 *  - bb_worksites                    (cantiere→location per check geografico)
 *
 * Regole attive (codice rule_code):
 *   Q8_NO_GPS_TRIP_NEAR      tx Q8 senza tratta GPS entro +-3h          HIGH
 *   GPS_REFUEL_NO_Q8         GPS segnala refuel ma Q8 non risponde nel giorno  MEDIUM
 *   Q8_VS_GPS_LITERS_DELTA   delta L tra GPS-refuels e Q8 nel giorno >5L MEDIUM
 *   CONSUMPTION_OUTLIER      consumo veicolo >25L/100km nel giorno      MEDIUM
 *   Q8_CITY_VS_TRIP_CITY     tx Q8 in citta' non toccata dalle tratte   MEDIUM
 *   MULTIPLE_TX_SAME_DAY     >2 transazioni stessa carta nel giorno     LOW
 *   TX_WITHOUT_ATTENDANCE    carta usata ma operaio non in presenze     HIGH
 *   TX_CITY_VS_WORKSITE      tx Q8 in citta' diversa dal cantiere       MEDIUM
 *
 * Una "run" raggruppa tutte le anomalie generate da una singola
 * esecuzione utente. Si possono fare run multiple (audit).
 */
final class FleetReconciliationService
{
    /** soglie configurabili */
    private const TX_VS_TRIP_WINDOW_HOURS  = 3;
    private const LITERS_DELTA_THRESHOLD   = 5.0;
    private const CONSUMPTION_LP100KM_MAX  = 25.0;
    private const MULTIPLE_TX_THRESHOLD    = 2;

    public function __construct(private PDO $conn) {}

    /**
     * @return array{run_id:int, anomalies:int, tx:int, trips:int, duration_ms:int}
     */
    public function run(?string $fromDate, ?string $toDate, ?int $vehicleId, ?int $startedBy): array
    {
        $t0 = microtime(true);

        // 1) finestra effettiva: se non specificata, usa tutto l'imported
        [$from, $to] = $this->resolvePeriod($fromDate, $toDate);
        [$year, $month] = [(int)substr($from, 0, 4), (int)substr($from, 5, 2)];

        // 2) crea run
        $stmt = $this->conn->prepare("
            INSERT INTO bb_fleet_reconciliation_runs (period_year, period_month, vehicle_id, started_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$year, $month, $vehicleId, $startedBy]);
        $runId = (int)$this->conn->lastInsertId();

        // 3) carica dati
        $txs   = $this->loadFuelTx($from, $to, $vehicleId);
        $trips = $this->loadTrips($from, $to, $vehicleId);

        // 4) indici utili
        $tripsByVehicleDay = $this->indexByVehicleDay($trips);
        $txByVehicleDay    = $this->indexTxByVehicleDay($txs);

        $anomalies = 0;

        // 5) regole transaction-driven
        foreach ($txs as $tx) {
            $anomalies += $this->checkTxNoTrip($runId, $tx, $tripsByVehicleDay);
            $anomalies += $this->checkTxCityVsTrip($runId, $tx, $tripsByVehicleDay);
            $anomalies += $this->checkTxWithoutAttendance($runId, $tx);
            $anomalies += $this->checkTxCityVsWorksite($runId, $tx);
        }

        // 6) regole trip-driven
        foreach ($trips as $trip) {
            if ((int)$trip['refuels_count'] > 0) {
                $anomalies += $this->checkGpsRefuelVsQ8($runId, $trip, $txByVehicleDay);
            }
        }

        // 7) regole aggregate per (veicolo, giorno)
        foreach ($txByVehicleDay as $vehKey => $byDay) {
            foreach ($byDay as $day => $dayTxs) {
                if (count($dayTxs) > self::MULTIPLE_TX_THRESHOLD) {
                    $this->insertAnomaly($runId, [
                        'vehicle_id' => (int)$dayTxs[0]['vehicle_id'] ?: null,
                        'rule_code'  => 'MULTIPLE_TX_SAME_DAY',
                        'severity'   => 'low',
                        'event_at'   => $day . ' 12:00:00',
                        'summary'    => count($dayTxs) . ' rifornimenti stessa carta in 1 giorno (' . $dayTxs[0]['card_numero'] . ')',
                        'detail'     => 'Transazioni: ' . implode(', ', array_map(fn($r) => substr($r['tx_at'], 11, 5) . ' / ' . $r['litri'] . 'L', $dayTxs)),
                        'ref_tx_id'  => (int)$dayTxs[0]['id'],
                    ]);
                    $anomalies++;
                }

                // litri delta + consumption
                $anomalies += $this->checkDailyAggregates($runId, $vehKey, $day, $dayTxs, $tripsByVehicleDay[$vehKey][$day] ?? []);
            }
        }

        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $stmt = $this->conn->prepare("
            UPDATE bb_fleet_reconciliation_runs
            SET tx_total = ?, trips_total = ?, anomalies = ?, duration_ms = ?
            WHERE id = ?
        ");
        $stmt->execute([count($txs), count($trips), $anomalies, $durationMs, $runId]);

        return [
            'run_id'      => $runId,
            'anomalies'   => $anomalies,
            'tx'          => count($txs),
            'trips'       => count($trips),
            'duration_ms' => $durationMs,
        ];
    }

    // ─── Loaders ──────────────────────────────────────────────────────────────

    /** carica tx con risoluzione carta→veicolo→operaio→presenza per la data della tx */
    private function loadFuelTx(string $from, string $to, ?int $vehicleId): array
    {
        $sql = "
            SELECT
                tx.*,
                fca.vehicle_id AS resolved_vehicle_id,
                v.targa        AS resolved_vehicle_targa,
                va.worker_id   AS resolved_worker_id,
                CONCAT(w.first_name,' ',w.last_name) AS worker_name,
                pr.worksite_id AS worker_worksite_id,
                ws.name        AS worker_worksite_name,
                ws.location    AS worker_worksite_location
            FROM bb_fleet_fuel_tx tx
            LEFT JOIN bb_fleet_fuel_card_assignments fca
                ON fca.card_id = tx.card_id
               AND fca.from_date <= DATE(tx.tx_at)
               AND (fca.to_date IS NULL OR fca.to_date >= DATE(tx.tx_at))
               AND fca.vehicle_id IS NOT NULL
            LEFT JOIN bb_fleet_vehicles v ON v.id = COALESCE(fca.vehicle_id, tx.vehicle_id_at_tx)
            LEFT JOIN bb_fleet_vehicle_assignments va
                ON va.vehicle_id = v.id
               AND va.from_date <= DATE(tx.tx_at)
               AND (va.to_date IS NULL OR va.to_date >= DATE(tx.tx_at))
            LEFT JOIN bb_workers w ON w.id = va.worker_id
            LEFT JOIN bb_presenze pr
                ON pr.worker_id = va.worker_id
               AND pr.data = DATE(tx.tx_at)
            LEFT JOIN bb_worksites ws ON ws.id = pr.worksite_id
            WHERE tx.tx_at BETWEEN :from AND :to
        ";
        $params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
        if ($vehicleId) {
            $sql .= " AND v.id = :vid";
            $params[':vid'] = $vehicleId;
        }
        $sql .= " ORDER BY tx.tx_at ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // normalizza vehicle_id finale
        foreach ($rows as &$r) {
            $r['vehicle_id'] = $r['resolved_vehicle_id'] ?: ($r['vehicle_id_at_tx'] ?: null);
        }
        return $rows;
    }

    private function loadTrips(string $from, string $to, ?int $vehicleId): array
    {
        $sql = "
            SELECT t.*
            FROM bb_fleet_gps_trips t
            WHERE t.start_at BETWEEN :from AND :to
        ";
        $params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
        if ($vehicleId) {
            $sql .= " AND t.vehicle_id = :vid";
            $params[':vid'] = $vehicleId;
        }
        $sql .= " ORDER BY t.start_at ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Indici ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, array>>  $map[vehicleKey][YYYY-MM-DD] = trips[]
     *   vehicleKey: 'id:N' se vehicle_id presente, altrimenti 'targa:XYZ'
     */
    private function indexByVehicleDay(array $trips): array
    {
        $map = [];
        foreach ($trips as $t) {
            $key = $t['vehicle_id'] ? 'id:' . $t['vehicle_id'] : 'targa:' . $t['vehicle_targa'];
            $day = substr($t['start_at'], 0, 10);
            $map[$key][$day][] = $t;
        }
        return $map;
    }

    private function indexTxByVehicleDay(array $txs): array
    {
        $map = [];
        foreach ($txs as $tx) {
            $key = $tx['vehicle_id'] ? 'id:' . $tx['vehicle_id'] : 'card:' . $tx['card_numero'];
            $day = substr($tx['tx_at'], 0, 10);
            $map[$key][$day][] = $tx;
        }
        return $map;
    }

    // ─── Regole ───────────────────────────────────────────────────────────────

    /** TX Q8 senza nessun trip GPS del veicolo entro +-WINDOW_HOURS. */
    private function checkTxNoTrip(int $runId, array $tx, array $tripsIdx): int
    {
        if (!$tx['vehicle_id']) return 0;   // niente veicolo risolto → skip
        $key = 'id:' . $tx['vehicle_id'];
        $day = substr($tx['tx_at'], 0, 10);
        $candidates = $tripsIdx[$key][$day] ?? [];
        if (empty($candidates)) {
            // anche giorni adiacenti per coprire mezzanotte
            $prev = date('Y-m-d', strtotime($day . ' -1 day'));
            $next = date('Y-m-d', strtotime($day . ' +1 day'));
            $candidates = array_merge($tripsIdx[$key][$prev] ?? [], $tripsIdx[$key][$next] ?? []);
        }
        $txTs = strtotime($tx['tx_at']);
        $window = self::TX_VS_TRIP_WINDOW_HOURS * 3600;
        foreach ($candidates as $trip) {
            $sTs = strtotime($trip['start_at']);
            $eTs = strtotime($trip['end_at']);
            if (($txTs >= $sTs - $window) && ($txTs <= $eTs + $window)) {
                return 0;
            }
        }
        $this->insertAnomaly($runId, [
            'vehicle_id' => (int)$tx['vehicle_id'],
            'worker_id'  => $tx['resolved_worker_id'] ? (int)$tx['resolved_worker_id'] : null,
            'rule_code'  => 'Q8_NO_GPS_TRIP_NEAR',
            'severity'   => 'high',
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento Q8 senza tratta GPS coerente entro ±' . self::TX_VS_TRIP_WINDOW_HOURS . 'h',
            'detail'     => sprintf('Carta %s · %sL · €%s · %s. Nessun trip GPS del veicolo %s nei pressi.',
                                    $tx['card_numero'], $tx['litri'], $tx['importo'],
                                    $tx['distributore'] ?? '?', $tx['resolved_vehicle_targa'] ?? '?'),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** TX Q8 con citta' che NON appare in start_city/end_city dei trip del veicolo nel giorno. */
    private function checkTxCityVsTrip(int $runId, array $tx, array $tripsIdx): int
    {
        if (!$tx['vehicle_id'] || empty($tx['city'])) return 0;
        $key = 'id:' . $tx['vehicle_id'];
        $day = substr($tx['tx_at'], 0, 10);
        $tripsToday = $tripsIdx[$key][$day] ?? [];
        if (empty($tripsToday)) return 0;  // gia' coperto da NO_GPS_TRIP_NEAR

        $txCity = mb_strtolower(trim($tx['city']));
        foreach ($tripsToday as $t) {
            if (mb_strtolower(trim($t['start_city'] ?? '')) === $txCity) return 0;
            if (mb_strtolower(trim($t['end_city']   ?? '')) === $txCity) return 0;
            // tolleranza: anche match parziale ("Castenaso" in "Bologna Castenaso")
            $blob = mb_strtolower(($t['start_address'] ?? '') . ' ' . ($t['end_address'] ?? ''));
            if ($txCity !== '' && str_contains($blob, $txCity)) return 0;
        }
        $this->insertAnomaly($runId, [
            'vehicle_id' => (int)$tx['vehicle_id'],
            'rule_code'  => 'Q8_CITY_VS_TRIP_CITY',
            'severity'   => 'medium',
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento in ' . ucfirst($tx['city']) . ' ma il veicolo non e\' transitato in quella citta\'',
            'detail'     => sprintf('Distributore: %s. Tratte GPS del giorno: %s',
                                    $tx['distributore'] ?? '?',
                                    implode(' / ', array_map(fn($t) => ($t['start_city']??'?').'→'.($t['end_city']??'?'), $tripsToday))),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** Carta usata ma l'operaio assegnato al veicolo non risulta in presenza quel giorno. */
    private function checkTxWithoutAttendance(int $runId, array $tx): int
    {
        if (!$tx['resolved_worker_id']) return 0;  // niente operaio risolto → skip
        if ($tx['worker_worksite_id']) return 0;   // ha presenza, OK
        $this->insertAnomaly($runId, [
            'vehicle_id' => (int)($tx['vehicle_id'] ?: 0) ?: null,
            'worker_id'  => (int)$tx['resolved_worker_id'],
            'rule_code'  => 'TX_WITHOUT_ATTENDANCE',
            'severity'   => 'high',
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento ma operaio ' . trim($tx['worker_name']) . ' non risulta in presenza quel giorno',
            'detail'     => sprintf('Carta %s · %sL · €%s · %s.', $tx['card_numero'], $tx['litri'], $tx['importo'], $tx['distributore'] ?? '?'),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** TX in citta' diversa dal cantiere in cui l'operaio era in presenza. */
    private function checkTxCityVsWorksite(int $runId, array $tx): int
    {
        if (!$tx['city'] || !$tx['worker_worksite_location']) return 0;
        $txCity = mb_strtolower(trim($tx['city']));
        $wsLoc  = mb_strtolower(trim($tx['worker_worksite_location']));
        if ($txCity === '' || $wsLoc === '') return 0;
        // match permissivo: la citta' compare nella location
        if (str_contains($wsLoc, $txCity)) return 0;
        $this->insertAnomaly($runId, [
            'vehicle_id' => $tx['vehicle_id'] ? (int)$tx['vehicle_id'] : null,
            'worker_id'  => (int)$tx['resolved_worker_id'],
            'rule_code'  => 'TX_CITY_VS_WORKSITE',
            'severity'   => 'medium',
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento in ' . ucfirst($tx['city']) . ' ma operaio era al cantiere "' . $tx['worker_worksite_name'] . '"',
            'detail'     => sprintf('Cantiere location: %s · TX in: %s · Distributore: %s',
                                    $tx['worker_worksite_location'], ucfirst($tx['city']), $tx['distributore'] ?? '?'),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** GPS segnala refuel ma il giorno non ci sono TX Q8 per quel veicolo. */
    private function checkGpsRefuelVsQ8(int $runId, array $trip, array $txByVehDay): int
    {
        if (!$trip['vehicle_id']) return 0;
        $key = 'id:' . $trip['vehicle_id'];
        $day = substr($trip['start_at'], 0, 10);
        $dayTxs = $txByVehDay[$key][$day] ?? [];
        if (!empty($dayTxs)) return 0;
        $this->insertAnomaly($runId, [
            'vehicle_id' => (int)$trip['vehicle_id'],
            'rule_code'  => 'GPS_REFUEL_NO_Q8',
            'severity'   => 'medium',
            'event_at'   => $trip['start_at'],
            'summary'    => sprintf('GPS rileva %d rifornimenti (%sL) ma nessuna transazione Q8 quel giorno', $trip['refuels_count'], $trip['refuels_liters']),
            'detail'     => 'Tratta ' . $trip['start_address'] . ' → ' . $trip['end_address'],
            'ref_trip_id'=> (int)$trip['id'],
        ]);
        return 1;
    }

    /** Delta litri GPS vs Q8 + consumo L/100km per (veicolo, giorno). */
    private function checkDailyAggregates(int $runId, string $vehKey, string $day, array $dayTxs, array $dayTrips): int
    {
        $count = 0;
        $vehicleId = (int)str_replace('id:', '', $vehKey);
        if (!str_starts_with($vehKey, 'id:')) return 0;

        $txLiters    = array_sum(array_column($dayTxs, 'litri'));
        $gpsLiters   = array_sum(array_column($dayTrips, 'refuels_liters'));
        $gpsKm       = array_sum(array_column($dayTrips, 'km_done'));

        // delta litri (solo se entrambi presenti)
        if ($gpsLiters > 0 && $txLiters > 0) {
            $delta = abs($gpsLiters - $txLiters);
            if ($delta > self::LITERS_DELTA_THRESHOLD) {
                $this->insertAnomaly($runId, [
                    'vehicle_id' => $vehicleId,
                    'rule_code'  => 'Q8_VS_GPS_LITERS_DELTA',
                    'severity'   => 'medium',
                    'event_at'   => $day . ' 12:00:00',
                    'summary'    => sprintf('Delta litri GPS vs Q8: %sL nel giorno (%sL GPS vs %sL Q8)', number_format($delta, 1), $gpsLiters, $txLiters),
                    'detail'     => 'I due dati dovrebbero coincidere se il rifornimento e\' lo stesso evento.',
                    'ref_tx_id'  => (int)$dayTxs[0]['id'],
                ]);
                $count++;
            }
        }

        // consumo L/100km
        if ($gpsKm > 30 && $txLiters > 0) {  // ignora giorni con km bassi (noise)
            $lp100km = ($txLiters / $gpsKm) * 100;
            if ($lp100km > self::CONSUMPTION_LP100KM_MAX) {
                $this->insertAnomaly($runId, [
                    'vehicle_id' => $vehicleId,
                    'rule_code'  => 'CONSUMPTION_OUTLIER',
                    'severity'   => 'medium',
                    'event_at'   => $day . ' 12:00:00',
                    'summary'    => sprintf('Consumo elevato: %.1fL/100km (soglia %.0fL/100km)', $lp100km, self::CONSUMPTION_LP100KM_MAX),
                    'detail'     => sprintf('%sL Q8 su %sKm GPS nel giorno', $txLiters, $gpsKm),
                    'ref_tx_id'  => (int)$dayTxs[0]['id'],
                ]);
                $count++;
            }
        }
        return $count;
    }

    // ─── Insert anomaly ───────────────────────────────────────────────────────

    private function insertAnomaly(int $runId, array $a): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_fleet_anomalies
                (run_id, vehicle_id, worker_id, rule_code, severity, event_at,
                 summary, detail, ref_tx_id, ref_trip_id)
            VALUES
                (:run, :vid, :wid, :rule, :sev, :evt,
                 :sum, :det, :rtx, :rtrip)
        ");
        $stmt->execute([
            ':run'  => $runId,
            ':vid'  => $a['vehicle_id']  ?? null,
            ':wid'  => $a['worker_id']   ?? null,
            ':rule' => $a['rule_code'],
            ':sev'  => $a['severity']    ?? 'medium',
            ':evt'  => $a['event_at']    ?? null,
            ':sum'  => mb_substr($a['summary'], 0, 255),
            ':det'  => $a['detail']      ?? null,
            ':rtx'  => $a['ref_tx_id']   ?? null,
            ':rtrip'=> $a['ref_trip_id'] ?? null,
        ]);
    }

    // ─── Period resolver ──────────────────────────────────────────────────────

    /** Se manca from/to, prende il min/max delle tabelle import. */
    private function resolvePeriod(?string $from, ?string $to): array
    {
        if ($from && $to) return [$from, $to];
        $stmt = $this->conn->query("
            SELECT MIN(d) AS dmin, MAX(d) AS dmax FROM (
                SELECT DATE(tx_at) AS d FROM bb_fleet_fuel_tx
                UNION ALL
                SELECT DATE(start_at) FROM bb_fleet_gps_trips
            ) x
        ");
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            $from ?: ($r['dmin'] ?: date('Y-m-01')),
            $to   ?: ($r['dmax'] ?: date('Y-m-d')),
        ];
    }
}
