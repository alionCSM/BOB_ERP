<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;

/**
 * Engine di riconciliazione Fleet — deterministica, niente AI per ora.
 *
 * Confronta:
 *  - bb_fleet_fuel_tx          (transazioni Q8)
 *  - bb_fleet_gps_trips        (tratte GPS, con rifornimenti rilevati)
 *  - bb_fleet_fuel_card_assignments  (carta→[veicolo XOR operaio] per data)
 *  - bb_fleet_vehicle_assignments    (veicolo→operaio per data)
 *  - bb_presenze                     (operaio→cantiere per data)
 *  - bb_worksites                    (cantiere→location per check geografico)
 *
 * Regole attive (codice rule_code):
 *   CARD_NOT_REGISTERED      tx Q8 con PAN/numero non registrato in BOB      HIGH
 *   CARD_NOT_ASSIGNED        carta registrata ma senza assegnazione attiva   HIGH
 *   Q8_NO_GPS_TRIP_NEAR      tx Q8 senza tratta GPS entro +-3h               HIGH
 *   TX_WITHOUT_ATTENDANCE    carta usata, operaio non in presenze (feriale)  HIGH
 *   TX_WITHOUT_ATTENDANCE    idem ma weekend                                 LOW
 *   GPS_REFUEL_NO_Q8         GPS segnala refuel ma Q8 niente nel giorno      MEDIUM
 *   Q8_VS_GPS_LITERS_DELTA   delta L > 10L tra GPS-refuels e Q8 nel giorno   MEDIUM
 *   CONSUMPTION_OUTLIER      consumo veicolo > 25L/100km nel giorno          MEDIUM
 *   Q8_CITY_VS_TRIP_CITY     tx Q8 in citta' non toccata dalle tratte        MEDIUM
 *   TX_CITY_VS_WORKSITE      tx Q8 in citta' diversa dal cantiere (con prov) MEDIUM
 *   MULTIPLE_TX_SAME_DAY     > 2 transazioni stessa carta nel giorno         LOW
 *
 * Una "run" raggruppa tutte le anomalie generate da una singola
 * esecuzione utente. Si possono fare run multiple (audit).
 */
final class FleetReconciliationService
{
    /** soglie configurabili */
    private const TX_VS_TRIP_WINDOW_HOURS  = 3;
    private const LITERS_DELTA_THRESHOLD   = 10.0;  // 5L → 10L (sensore GPS rumoroso)
    private const CONSUMPTION_LP100KM_MAX  = 25.0;
    private const MULTIPLE_TX_THRESHOLD    = 2;
    private const MIN_KM_FOR_CONSUMPTION   = 30.0;

    /** evita anomalie duplicate nella stessa run su (rule_code, vehicle, day). */
    private array $emittedDedup = [];

    public function __construct(private PDO $conn) {}

    /**
     * @return array{run_id:int, anomalies:int, tx:int, trips:int, duration_ms:int, period_from:string, period_to:string}
     */
    public function run(?string $fromDate, ?string $toDate, ?int $vehicleId, ?int $startedBy): array
    {
        $t0 = microtime(true);
        $this->emittedDedup = [];

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

        // 3) carica dati con risoluzione completa carta→{veicolo|operaio}→cantiere
        $txs   = $this->loadFuelTx($from, $to, $vehicleId);
        $trips = $this->loadTrips($from, $to, $vehicleId);

        // 4) indici utili
        $tripsByVehicleDay = $this->indexTripsByVehicleDay($trips);
        $txByVehicleDay    = $this->indexTxByVehicleDay($txs);

        $anomalies = 0;

        // 5) regole transaction-driven
        foreach ($txs as $tx) {
            // regole sulla carta — prima di tutto. Se la carta non c'e' o
            // non e' assegnata, le altre regole non hanno senso (manca il
            // veicolo per fare i confronti GPS).
            if ($this->checkCardNotRegistered($runId, $tx)) { $anomalies++; continue; }
            if ($this->checkCardNotAssigned($runId, $tx))   { $anomalies++; continue; }

            $anomalies += $this->checkTxNoTrip($runId, $tx, $tripsByVehicleDay);
            $anomalies += $this->checkTxCityVsTrip($runId, $tx, $tripsByVehicleDay);
            $anomalies += $this->checkTxWithoutAttendance($runId, $tx);
            $anomalies += $this->checkTxCityVsWorksite($runId, $tx);
        }

        // 6) regole trip-driven (con dedup per (vehicle, day))
        foreach ($trips as $trip) {
            if ((int)$trip['refuels_count'] > 0) {
                $anomalies += $this->checkGpsRefuelVsQ8($runId, $trip, $txByVehicleDay);
            }
        }

        // 7) regole aggregate per (veicolo, giorno).
        // L'index ha multiple keys per tx (id: e targa:) → usiamo una canonical
        // key per (veicolo, giorno) per evitare di processare 2 volte lo stesso.
        $seenAggregate = [];
        foreach ($txByVehicleDay as $vehKey => $byDay) {
            foreach ($byDay as $day => $dayTxs) {
                $first = $dayTxs[0];
                $canonical = $first['vehicle_id']
                    ? 'id:' . $first['vehicle_id']
                    : (!empty($first['vehicle_targa']) ? 'targa:' . strtoupper($first['vehicle_targa'])
                                                       : 'card:' . $first['card_numero']);
                $dedupKey = $canonical . '|' . $day;
                if (isset($seenAggregate[$dedupKey])) continue;
                $seenAggregate[$dedupKey] = true;

                // dedup tx per id (lo stesso tx puo' comparire sotto piu' keys)
                $ids = [];
                $uniqueTxs = array_values(array_filter($dayTxs, function($t) use (&$ids) {
                    if (isset($ids[$t['id']])) return false; $ids[$t['id']] = true; return true;
                }));

                if (count($uniqueTxs) > self::MULTIPLE_TX_THRESHOLD) {
                    $anomalies += $this->emitMultipleTx($runId, $canonical, $day, $uniqueTxs);
                }
                // raccogli trip da entrambe le keys
                $allTrips = array_merge(
                    $tripsByVehicleDay['id:' . ($first['vehicle_id'] ?? 0)][$day]  ?? [],
                    $tripsByVehicleDay['targa:' . strtoupper($first['vehicle_targa'] ?? '')][$day] ?? []
                );
                $tids = [];
                $uniqueTrips = array_values(array_filter($allTrips, function($t) use (&$tids) {
                    if (isset($tids[$t['id']])) return false; $tids[$t['id']] = true; return true;
                }));
                $anomalies += $this->checkDailyAggregates($runId, $canonical, $day, $uniqueTxs, $uniqueTrips);
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
            'period_from' => $from,
            'period_to'   => $to,
        ];
    }

    // ─── Loaders ──────────────────────────────────────────────────────────────

    /**
     * Carica tx con risoluzione carta→{veicolo XOR operaio}→presenza per la data.
     *
     * Gestisce ENTRAMBI i casi di assegnazione carta:
     *   A) carta → veicolo  → (veicolo→operaio) → presenza
     *   B) carta → operaio  → (operaio→presenza), niente vincolo veicolo
     *
     * L'engine determina quale path applicare per ogni tx in base al risultato
     * della risoluzione.
     */
    private function loadFuelTx(string $from, string $to, ?int $vehicleId): array
    {
        $sql = "
            SELECT
                tx.*,
                /* card holder via fca (vehicle XOR worker, una sola riga attiva) */
                fca.id           AS fca_id,
                fca.vehicle_id   AS fca_vehicle_id,
                fca.worker_id    AS fca_worker_id,
                /* path A: veicolo → operaio via vehicle_assignments */
                vAss.worker_id   AS chain_worker_id_via_vehicle,
                /* veicolo info */
                v.targa          AS vehicle_targa,
                v.modello        AS vehicle_modello,
                /* operaio finale: COALESCE(direct, via vehicle) */
                COALESCE(fca.worker_id, vAss.worker_id) AS resolved_worker_id,
                CONCAT(COALESCE(wDirect.first_name, wViaV.first_name, ''),' ',
                       COALESCE(wDirect.last_name,  wViaV.last_name,  '')) AS worker_name,
                /* presenza dell'operaio finale */
                pr.worksite_id   AS worker_worksite_id,
                ws.name          AS worker_worksite_name,
                ws.location      AS worker_worksite_location
            FROM bb_fleet_fuel_tx tx
            /* fca: SOLO una riga, indipendente dal tipo holder */
            LEFT JOIN bb_fleet_fuel_card_assignments fca
                ON fca.card_id = tx.card_id
               AND fca.from_date <= DATE(tx.tx_at)
               AND (fca.to_date IS NULL OR fca.to_date >= DATE(tx.tx_at))
            LEFT JOIN bb_fleet_vehicles v ON v.id = fca.vehicle_id
            /* path A: vehicle → worker assignment */
            LEFT JOIN bb_fleet_vehicle_assignments vAss
                ON vAss.vehicle_id = fca.vehicle_id
               AND vAss.from_date <= DATE(tx.tx_at)
               AND (vAss.to_date IS NULL OR vAss.to_date >= DATE(tx.tx_at))
            LEFT JOIN bb_workers wDirect ON wDirect.id = fca.worker_id
            LEFT JOIN bb_workers wViaV   ON wViaV.id = vAss.worker_id
            /* presenza dell'operaio finale */
            LEFT JOIN bb_presenze pr
                ON pr.worker_id = COALESCE(fca.worker_id, vAss.worker_id)
               AND pr.data = DATE(tx.tx_at)
            LEFT JOIN bb_worksites ws ON ws.id = pr.worksite_id
            WHERE tx.tx_at BETWEEN :from AND :to
        ";
        $params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
        if ($vehicleId) {
            $sql .= " AND fca.vehicle_id = :vid";
            $params[':vid'] = $vehicleId;
        }
        $sql .= " ORDER BY tx.tx_at ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // normalizza vehicle_id finale: SOLO via fca (Plate number Q8 non e' affidabile)
        // vehicle_targa = la targa del veicolo a cui la carta era assegnata
        foreach ($rows as &$r) {
            $r['vehicle_id'] = $r['fca_vehicle_id'] ? (int)$r['fca_vehicle_id'] : null;
            // vehicle_targa proviene gia' dal JOIN su v (es. 'vehicle_targa' colonna)
            $r['holder_type'] = $r['fca_vehicle_id'] ? 'vehicle'
                              : ($r['fca_worker_id'] ? 'worker' : 'none');
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
     *   I trip vengono indicizzati con DUE chiavi: 'id:N' (se risolto) e
     *   'targa:XYZ' (sempre). Cosi' tx con vehicle_id risolto possono
     *   matchare trip con stessa targa ma vehicle_id null (e viceversa).
     */
    private function indexTripsByVehicleDay(array $trips): array
    {
        $map = [];
        foreach ($trips as $t) {
            $day = substr($t['start_at'], 0, 10);
            // sempre via targa (univoca per veicolo fisico)
            if ($t['vehicle_targa']) {
                $map['targa:' . strtoupper($t['vehicle_targa'])][$day][] = $t;
            }
            // anche via id se risolto
            if ($t['vehicle_id']) {
                $map['id:' . $t['vehicle_id']][$day][] = $t;
            }
        }
        return $map;
    }

    /** Restituisce le candidate keys per cercare i trip dato un veicolo risolto. */
    private function lookupKeysForVehicle(array $tx): array
    {
        $keys = [];
        if ($tx['vehicle_id'])     $keys[] = 'id:' . $tx['vehicle_id'];
        if ($tx['vehicle_targa'])  $keys[] = 'targa:' . strtoupper($tx['vehicle_targa']);
        return $keys;
    }

    /**
     * Indicizza tx con DUE keys:
     *   'id:N'         se vehicle_id risolto
     *   'targa:XYZ'    se vehicle_targa noto (da resolved_vehicle)
     *   'card:CN'      fallback per tx senza veicolo risolto
     */
    private function indexTxByVehicleDay(array $txs): array
    {
        $map = [];
        foreach ($txs as $tx) {
            $day = substr($tx['tx_at'], 0, 10);
            if ($tx['vehicle_id']) {
                $map['id:' . $tx['vehicle_id']][$day][] = $tx;
            }
            if (!empty($tx['vehicle_targa'])) {
                $map['targa:' . strtoupper($tx['vehicle_targa'])][$day][] = $tx;
            }
            if (!$tx['vehicle_id'] && empty($tx['vehicle_targa'])) {
                $map['card:' . $tx['card_numero']][$day][] = $tx;
            }
        }
        return $map;
    }

    // ─── Regole carta-livello ─────────────────────────────────────────────────

    /** Carta usata nella fattura ma non registrata in bb_fleet_fuel_cards. */
    private function checkCardNotRegistered(int $runId, array $tx): bool
    {
        if ($tx['card_id']) return false;
        $this->insertAnomaly($runId, [
            'rule_code' => 'CARD_NOT_REGISTERED',
            'severity'  => 'high',
            'event_at'  => $tx['tx_at'],
            'summary'   => 'Carta Q8 ' . $tx['card_numero'] . ' non registrata in BOB',
            'detail'    => sprintf('TX €%s · %sL · %s · %s. Aggiungi la carta in /fleet?tab=cards con numero esatto, poi ri-analizza.',
                                   $tx['importo'], $tx['litri'], $tx['distributore'] ?? '?',
                                   $tx['plate_alias_q8'] ? 'driver-decl: '.$tx['plate_alias_q8'] : ''),
            'ref_tx_id' => (int)$tx['id'],
        ]);
        return true;
    }

    /** Carta registrata, ma senza assegnazione attiva (a veicolo o operaio) alla data tx. */
    private function checkCardNotAssigned(int $runId, array $tx): bool
    {
        if (!$tx['card_id']) return false;        // gia' segnalato come not_registered
        if ($tx['fca_id']) return false;          // c'e' un'assegnazione attiva
        $this->insertAnomaly($runId, [
            'rule_code' => 'CARD_NOT_ASSIGNED',
            'severity'  => 'high',
            'event_at'  => $tx['tx_at'],
            'summary'   => 'Carta ' . $tx['card_numero'] . ' usata ma senza assegnazione attiva in BOB',
            'detail'    => sprintf('TX €%s · %sL · %s. Crea un\'assegnazione (riassegna carta a veicolo o operaio) coprente questa data.',
                                   $tx['importo'], $tx['litri'], $tx['distributore'] ?? '?'),
            'ref_tx_id' => (int)$tx['id'],
        ]);
        return true;
    }

    // ─── Regole geo / GPS ────────────────────────────────────────────────────

    /** TX Q8 senza nessun trip GPS del veicolo entro ±WINDOW_HOURS. */
    private function checkTxNoTrip(int $runId, array $tx, array $tripsIdx): int
    {
        if (!$tx['vehicle_id']) return 0;   // carta a operaio diretto → non controllabile via GPS-veicolo
        $keys = $this->lookupKeysForVehicle($tx);
        $day = substr($tx['tx_at'], 0, 10);
        $prev = date('Y-m-d', strtotime($day . ' -1 day'));
        $next = date('Y-m-d', strtotime($day . ' +1 day'));
        $candidates = [];
        foreach ($keys as $key) {
            $candidates = array_merge(
                $candidates,
                $tripsIdx[$key][$day]  ?? [],
                $tripsIdx[$key][$prev] ?? [],
                $tripsIdx[$key][$next] ?? []
            );
        }
        // dedup by trip.id (stesso trip può comparire sotto chiavi diverse)
        $seen = []; $candidates = array_filter($candidates, function($t) use (&$seen) {
            $id = (int)$t['id']; if (isset($seen[$id])) return false; $seen[$id] = true; return true;
        });

        if (empty($candidates)) {
            $this->insertAnomaly($runId, [
                'vehicle_id' => (int)$tx['vehicle_id'],
                'worker_id'  => $tx['resolved_worker_id'] ? (int)$tx['resolved_worker_id'] : null,
                'rule_code'  => 'Q8_NO_GPS_TRIP_NEAR',
                'severity'   => 'high',
                'event_at'   => $tx['tx_at'],
                'summary'    => 'Rifornimento Q8 ma nessuna tratta GPS del veicolo ' . $tx['vehicle_targa'] . ' in giornata',
                'detail'     => sprintf('Carta %s · %sL · €%s · %s. Manca completamente la copertura GPS — verifica se l\'import GPS contiene quel veicolo per quel giorno.',
                                        $tx['card_numero'], $tx['litri'], $tx['importo'],
                                        $tx['distributore'] ?? '?'),
                'ref_tx_id'  => (int)$tx['id'],
            ]);
            return 1;
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
            'detail'     => sprintf('Carta %s · %sL · €%s · %s. Il veicolo %s ha avuto %d tratte nelle 48h ma nessuna nei ±3h dalla tx.',
                                    $tx['card_numero'], $tx['litri'], $tx['importo'],
                                    $tx['distributore'] ?? '?',
                                    $tx['vehicle_targa'] ?? '?', count($candidates)),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** TX Q8 con citta' che NON appare negli indirizzi dei trip del veicolo nel giorno. */
    private function checkTxCityVsTrip(int $runId, array $tx, array $tripsIdx): int
    {
        if (!$tx['vehicle_id'] || empty($tx['city'])) return 0;
        $day = substr($tx['tx_at'], 0, 10);
        $tripsToday = [];
        foreach ($this->lookupKeysForVehicle($tx) as $key) {
            $tripsToday = array_merge($tripsToday, $tripsIdx[$key][$day] ?? []);
        }
        // dedup
        $seen = []; $tripsToday = array_filter($tripsToday, function($t) use (&$seen) {
            $id = (int)$t['id']; if (isset($seen[$id])) return false; $seen[$id] = true; return true;
        });
        if (empty($tripsToday)) return 0;  // gia' coperto da NO_GPS_TRIP_NEAR

        $txCity = mb_strtolower(trim($tx['city']));
        if (mb_strlen($txCity) < 3) return 0;  // troppo corto, evita falsi positivi
        foreach ($tripsToday as $t) {
            $blob = mb_strtolower(($t['start_address'] ?? '') . ' ' . ($t['end_address'] ?? '') . ' ' .
                                  ($t['start_city'] ?? '')    . ' ' . ($t['end_city']   ?? ''));
            if (str_contains($blob, $txCity)) return 0;
        }
        $this->insertAnomaly($runId, [
            'vehicle_id' => (int)$tx['vehicle_id'],
            'rule_code'  => 'Q8_CITY_VS_TRIP_CITY',
            'severity'   => 'medium',
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento in ' . ucfirst($tx['city']) . ' ma il veicolo ' . ($tx['vehicle_targa'] ?? '?') . ' non e\' transitato in quella citta\'',
            'detail'     => sprintf('Distributore: %s · Tratte GPS del giorno: %s',
                                    $tx['distributore'] ?? '?',
                                    implode(' / ', array_map(fn($t) => ($t['start_city']??'?').'→'.($t['end_city']??'?'), $tripsToday))),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** Carta usata ma l'operaio assegnato non risulta in presenza quel giorno.
     *  Weekend → severity bassa (potrebbe essere normale, ma vale la pena segnalare). */
    private function checkTxWithoutAttendance(int $runId, array $tx): int
    {
        if (!$tx['resolved_worker_id']) return 0;
        if ($tx['worker_worksite_id']) return 0;   // ha presenza → OK

        $dow = (int)date('N', strtotime($tx['tx_at']));   // 1..7
        $isWeekend = $dow >= 6;
        $this->insertAnomaly($runId, [
            'vehicle_id' => $tx['vehicle_id'] ? (int)$tx['vehicle_id'] : null,
            'worker_id'  => (int)$tx['resolved_worker_id'],
            'rule_code'  => 'TX_WITHOUT_ATTENDANCE',
            'severity'   => $isWeekend ? 'low' : 'high',
            'event_at'   => $tx['tx_at'],
            'summary'    => sprintf('%sRifornimento ma operaio %s non risulta in presenza',
                                    $isWeekend ? '[weekend] ' : '',
                                    trim($tx['worker_name'])),
            'detail'     => sprintf('Carta %s · %sL · €%s · %s. %s',
                                    $tx['card_numero'], $tx['litri'], $tx['importo'],
                                    $tx['distributore'] ?? '?',
                                    $isWeekend ? 'Giorno feriato/weekend: verificare se rifornimento legittimo.'
                                               : 'Operaio assente in bb_presenze: verificare se ferie/malattia o uso improprio carta.'),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** TX in citta' diversa dal cantiere in cui l'operaio era in presenza.
     *  Gestisce sigle provincia (BO, FI, ...) e nomi completi (BOLOGNA, FIRENZE). */
    private function checkTxCityVsWorksite(int $runId, array $tx): int
    {
        if (!$tx['city'] || !$tx['worker_worksite_location']) return 0;
        $txCity = mb_strtolower(trim($tx['city']));
        $wsLoc  = mb_strtolower(trim($tx['worker_worksite_location']));
        if ($txCity === '' || $wsLoc === '' || mb_strlen($txCity) < 3) return 0;

        // 1) match diretto: citta' tx compare in worksite location
        if (str_contains($wsLoc, $txCity)) return 0;

        // 2) match via sigla provincia: estrai "(XX)" dalla worksite location
        if (preg_match('/\(([a-z]{2})\)/i', $wsLoc, $m)) {
            $provSigla = mb_strtolower($m[1]);
            $provExpanded = $this->expandProvSigla($provSigla);
            if ($provExpanded && str_contains($txCity, $provExpanded)) return 0;
            if (str_contains($txCity, $provSigla)) return 0;
        }

        $this->insertAnomaly($runId, [
            'vehicle_id' => $tx['vehicle_id'] ? (int)$tx['vehicle_id'] : null,
            'worker_id'  => (int)$tx['resolved_worker_id'],
            'rule_code'  => 'TX_CITY_VS_WORKSITE',
            'severity'   => 'medium',
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento in ' . ucfirst($tx['city']) . ' ma operaio era al cantiere "' . $tx['worker_worksite_name'] . '"',
            'detail'     => sprintf('Cantiere location: %s · TX in: %s · Distributore: %s',
                                    $tx['worker_worksite_location'], ucfirst($tx['city']),
                                    $tx['distributore'] ?? '?'),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** GPS segnala refuel ma il giorno non ci sono TX Q8 per quel veicolo.
     *  Dedup: una sola anomalia per (vehicle, day) anche se ci sono N trip con refuels. */
    private function checkGpsRefuelVsQ8(int $runId, array $trip, array $txByVehDay): int
    {
        // Anche se il veicolo non e' ancora registrato, usa la targa
        $day = substr($trip['start_at'], 0, 10);
        $candKeys = [];
        if ($trip['vehicle_id']) $candKeys[] = 'id:' . $trip['vehicle_id'];
        if (!empty($trip['vehicle_targa'])) $candKeys[] = 'targa:' . strtoupper($trip['vehicle_targa']);
        if (empty($candKeys)) return 0;

        // se per QUALSIASI key c'e' tx → niente anomalia
        foreach ($candKeys as $k) {
            if (!empty($txByVehDay[$k][$day] ?? [])) return 0;
        }

        $dedupKey = "GPS_REFUEL_NO_Q8|{$candKeys[0]}|{$day}";
        if (isset($this->emittedDedup[$dedupKey])) return 0;
        $this->emittedDedup[$dedupKey] = true;

        $this->insertAnomaly($runId, [
            'vehicle_id'  => $trip['vehicle_id'] ? (int)$trip['vehicle_id'] : null,
            'rule_code'   => 'GPS_REFUEL_NO_Q8',
            'severity'    => 'medium',
            'event_at'    => $trip['start_at'],
            'summary'     => sprintf('GPS rileva %d rifornimenti (%sL) sul veicolo %s ma nessuna TX Q8 quel giorno',
                                     $trip['refuels_count'], $trip['refuels_liters'],
                                     $trip['vehicle_targa'] ?? '?'),
            'detail'      => 'Possibili cause: carta non Q8 (altro fornitore), rifornimento privato, carta non assegnata al veicolo in quel periodo.',
            'ref_trip_id' => (int)$trip['id'],
        ]);
        return 1;
    }

    /** Delta litri GPS vs Q8 + consumo L/100km per (veicolo, giorno). */
    private function checkDailyAggregates(int $runId, string $vehKey, string $day, array $dayTxs, array $dayTrips): int
    {
        // accettiamo solo chiavi che identificano un veicolo univoco
        if (!str_starts_with($vehKey, 'id:') && !str_starts_with($vehKey, 'targa:')) return 0;
        $count = 0;
        $vehicleId = str_starts_with($vehKey, 'id:') ? (int)substr($vehKey, 3) : null;

        $txLiters  = array_sum(array_map(fn($r) => (float)$r['litri'], $dayTxs));
        $gpsLiters = array_sum(array_map(fn($r) => (float)$r['refuels_liters'], $dayTrips));
        $gpsKm     = array_sum(array_map(fn($r) => (float)$r['km_done'], $dayTrips));

        // delta litri (solo se entrambi rilevati e sufficienti per essere significativi)
        if ($gpsLiters > 0 && $txLiters > 0) {
            $delta = abs($gpsLiters - $txLiters);
            if ($delta > self::LITERS_DELTA_THRESHOLD) {
                $vehTarga = $dayTxs[0]['vehicle_targa'] ?? '?';
                $this->insertAnomaly($runId, [
                    'vehicle_id' => $vehicleId,
                    'rule_code'  => 'Q8_VS_GPS_LITERS_DELTA',
                    'severity'   => 'medium',
                    'event_at'   => $day . ' 12:00:00',
                    'summary'    => sprintf('Delta litri GPS vs Q8 su %s: %sL (%sL GPS, %sL Q8)',
                                            $vehTarga, number_format($delta, 1),
                                            number_format($gpsLiters, 1), number_format($txLiters, 1)),
                    'detail'     => 'Il sensore serbatoio GPS e\' approssimativo, ma uno scarto > '
                                  . self::LITERS_DELTA_THRESHOLD . 'L indica possibile travaso o carburante non fatturato.',
                    'ref_tx_id'  => (int)$dayTxs[0]['id'],
                ]);
                $count++;
            }
        }

        // consumo L/100km
        if ($gpsKm > self::MIN_KM_FOR_CONSUMPTION && $txLiters > 0) {
            $lp100km = ($txLiters / $gpsKm) * 100;
            if ($lp100km > self::CONSUMPTION_LP100KM_MAX) {
                $vehTarga = $dayTxs[0]['vehicle_targa'] ?? '?';
                $this->insertAnomaly($runId, [
                    'vehicle_id' => $vehicleId,
                    'rule_code'  => 'CONSUMPTION_OUTLIER',
                    'severity'   => 'medium',
                    'event_at'   => $day . ' 12:00:00',
                    'summary'    => sprintf('Consumo anomalo veicolo %s: %.1fL/100km (soglia %.0fL/100km)',
                                            $vehTarga, $lp100km, self::CONSUMPTION_LP100KM_MAX),
                    'detail'     => sprintf('%sL Q8 / %sKm GPS nel giorno. Verifica se possibile travaso, guasto consumo, o uso non lavorativo.',
                                            number_format($txLiters, 1), number_format($gpsKm, 0)),
                    'ref_tx_id'  => (int)$dayTxs[0]['id'],
                ]);
                $count++;
            }
        }
        return $count;
    }

    private function emitMultipleTx(int $runId, string $vehKey, string $day, array $dayTxs): int
    {
        // vehicle_id: prendi il primo non-null tra le tx del giorno
        $vehicleId = null;
        foreach ($dayTxs as $r) {
            if (!empty($r['vehicle_id'])) { $vehicleId = (int)$r['vehicle_id']; break; }
        }
        $cardLabel = $dayTxs[0]['card_numero'] ?? '?';
        $this->insertAnomaly($runId, [
            'vehicle_id' => $vehicleId,
            'rule_code'  => 'MULTIPLE_TX_SAME_DAY',
            'severity'   => 'low',
            'event_at'   => $day . ' 12:00:00',
            'summary'    => count($dayTxs) . ' rifornimenti stessa carta (' . $cardLabel . ') in 1 giorno',
            'detail'     => 'Orari: ' . implode(' · ', array_map(
                                fn($r) => substr($r['tx_at'], 11, 5) . ' ' . number_format((float)$r['litri'],1) . 'L €' . number_format((float)$r['importo'],2),
                                $dayTxs)),
            'ref_tx_id'  => (int)$dayTxs[0]['id'],
        ]);
        return 1;
    }

    // ─── Insert anomaly ───────────────────────────────────────────────────────

    private function insertAnomaly(int $runId, array $a): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_fleet_anomalies
                (run_id, vehicle_id, worker_id, rule_code, severity, event_at,
                 summary, detail, ref_tx_id, ref_trip_id)
            VALUES
                (:run, :vid, :wid, :rule, :sev, :evt, :sum, :det, :rtx, :rtrip)
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Espande sigla provincia → nome citta' capoluogo (minuscolo). */
    private function expandProvSigla(string $sigla): ?string
    {
        static $map = [
            'an'=>'ancona','ao'=>'aosta','ar'=>'arezzo','ap'=>'ascoli','at'=>'asti','av'=>'avellino',
            'ba'=>'bari','bt'=>'barletta','bl'=>'belluno','bn'=>'benevento','bg'=>'bergamo',
            'bi'=>'biella','bo'=>'bologna','bz'=>'bolzano','bs'=>'brescia','br'=>'brindisi',
            'ca'=>'cagliari','cl'=>'caltanissetta','cb'=>'campobasso','ci'=>'carbonia','ce'=>'caserta',
            'ct'=>'catania','cz'=>'catanzaro','ch'=>'chieti','co'=>'como','cs'=>'cosenza','cr'=>'cremona',
            'kr'=>'crotone','cn'=>'cuneo','en'=>'enna','fm'=>'fermo','fe'=>'ferrara','fi'=>'firenze',
            'fg'=>'foggia','fc'=>'forli','fr'=>'frosinone','ge'=>'genova','go'=>'gorizia','gr'=>'grosseto',
            'im'=>'imperia','is'=>'isernia','aq'=>'aquila','sp'=>'spezia','lt'=>'latina','le'=>'lecce',
            'lc'=>'lecco','li'=>'livorno','lo'=>'lodi','lu'=>'lucca','mc'=>'macerata','mn'=>'mantova',
            'ms'=>'massa','mt'=>'matera','me'=>'messina','mi'=>'milano','mo'=>'modena','mb'=>'monza',
            'na'=>'napoli','no'=>'novara','nu'=>'nuoro','or'=>'oristano','pd'=>'padova','pa'=>'palermo',
            'pr'=>'parma','pv'=>'pavia','pg'=>'perugia','pu'=>'pesaro','pe'=>'pescara','pc'=>'piacenza',
            'pi'=>'pisa','pt'=>'pistoia','pn'=>'pordenone','pz'=>'potenza','po'=>'prato','rg'=>'ragusa',
            'ra'=>'ravenna','rc'=>'reggio','re'=>'reggio','ri'=>'rieti','rn'=>'rimini','rm'=>'roma',
            'ro'=>'rovigo','sa'=>'salerno','ss'=>'sassari','sv'=>'savona','si'=>'siena','sr'=>'siracusa',
            'so'=>'sondrio','ta'=>'taranto','te'=>'teramo','tr'=>'terni','to'=>'torino','tp'=>'trapani',
            'tn'=>'trento','tv'=>'treviso','ts'=>'trieste','ud'=>'udine','va'=>'varese','ve'=>'venezia',
            'vb'=>'verbania','vc'=>'vercelli','vr'=>'verona','vv'=>'vibo','vi'=>'vicenza','vt'=>'viterbo',
        ];
        return $map[$sigla] ?? null;
    }

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
