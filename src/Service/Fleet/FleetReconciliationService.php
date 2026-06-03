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
    /** Soglia consumo tank-to-tank in L/100km. Sopra → anomalia.
     *  Furgone normale: 10-15. Camion full-load: 25-30. Soglia 20 lascia
     *  margine abbondante per evitare falsi positivi su carichi pesanti. */
    private const CONSUMPTION_LP100KM_MAX  = 20.0;
    /** Km minimi fra due rifornimenti per ritenere il calcolo affidabile.
     *  Sotto questa soglia il rapporto e' rumoroso e ignoriamo. */
    private const MIN_KM_BETWEEN_REFILLS   = 100.0;
    private const MULTIPLE_TX_THRESHOLD    = 2;
    /** I GPS contano spesso "rifornimento" anche eventi a 0L (motore off generico).
     *  Soglia per ignorare il rumore e considerare solo refuel reali. */
    private const MIN_REFUEL_LITERS_FOR_RULE = 5.0;

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
        // Solo refuel con litri sensati: il GPS spesso conta come "refuel"
        // anche eventi a 0L (motore spento generico, rumore del sensore).
        foreach ($trips as $trip) {
            if ((int)$trip['refuels_count'] > 0
                && (float)$trip['refuels_liters'] >= self::MIN_REFUEL_LITERS_FOR_RULE) {
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

        // 8) consumo tank-to-tank: per ogni veicolo, ordina tx per data e
        //    calcola L/100km fra rifornimenti consecutivi. E' il metodo
        //    corretto perche' i litri immessi al pieno N+1 = ai litri
        //    consumati fra pieno N e pieno N+1.
        $anomalies += $this->checkTankToTankConsumption($runId, $txs, $trips);

        // 9) vehicle-level: veicoli con trip nel periodo ma SENZA carte
        //    assegnate → anomalia HIGH "configura assegnazione" (una sola).
        $anomalies += $this->checkVehiclesWithoutCard($runId, $from, $to, $vehicleId);

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
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Dedup per tx.id. I LEFT JOIN su bb_presenze (e potenziali assignment
        // sovrapposti) possono restituire la stessa tx 2+ volte. Tieni la prima
        // riga per id, le altre vengono droppate.
        $seen = [];
        $rows = [];
        foreach ($rawRows as $r) {
            $id = (int)$r['id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $r['vehicle_id']  = $r['fca_vehicle_id'] ? (int)$r['fca_vehicle_id'] : null;
            $r['holder_type'] = $r['fca_vehicle_id'] ? 'vehicle'
                              : ($r['fca_worker_id'] ? 'worker' : 'none');
            $rows[] = $r;
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

    /** TX Q8 con citta' che NON appare negli indirizzi dei trip del veicolo nel giorno.
     *  Tre livelli di tolleranza per evitare falsi positivi su transit:
     *   1) Match substring diretto (city del tx contenuta in addr/city dei trip)
     *   2) Match per regione italiana (citta' nella stessa regione di un endpoint)
     *   3) Skip se cumulato km trip > TRANSIT_KM (autostrada, refuel ovunque OK)
     */
    private function checkTxCityVsTrip(int $runId, array $tx, array $tripsIdx): int
    {
        if (!$tx['vehicle_id'] || empty($tx['city'])) return 0;
        $day = substr($tx['tx_at'], 0, 10);
        $tripsToday = [];
        foreach ($this->lookupKeysForVehicle($tx) as $key) {
            $tripsToday = array_merge($tripsToday, $tripsIdx[$key][$day] ?? []);
        }
        // dedup trips per id
        $seen = []; $tripsToday = array_filter($tripsToday, function($t) use (&$seen) {
            $id = (int)$t['id']; if (isset($seen[$id])) return false; $seen[$id] = true; return true;
        });
        if (empty($tripsToday)) return 0;  // gia' coperto da NO_GPS_TRIP_NEAR

        $txCity = mb_strtolower(trim($tx['city']));
        if (mb_strlen($txCity) < 3) return 0;

        // 1) match substring diretto
        foreach ($tripsToday as $t) {
            $blob = mb_strtolower(($t['start_address'] ?? '') . ' ' . ($t['end_address'] ?? '') . ' ' .
                                  ($t['start_city'] ?? '')    . ' ' . ($t['end_city']   ?? ''));
            if (str_contains($blob, $txCity)) return 0;
        }

        // 2) match per regione: la citta' del tx e' nella stessa regione di
        //    un endpoint dei trip → probabilmente sulla stessa direttrice
        $txRegion = $this->cityToRegion($txCity);
        if ($txRegion) {
            foreach ($tripsToday as $t) {
                $startReg = $this->cityToRegion(mb_strtolower($t['start_city'] ?? ''));
                $endReg   = $this->cityToRegion(mb_strtolower($t['end_city']   ?? ''));
                if ($startReg === $txRegion || $endReg === $txRegion) return 0;
            }
        }

        // 3) trip in transit (cumulato km elevato) → impossibile sapere
        //    esattamente le citta' attraversate, troppi falsi positivi.
        $totalKm = array_sum(array_map(fn($t) => (float)$t['km_done'], $tripsToday));
        if ($totalKm >= self::TRANSIT_KM_THRESHOLD) return 0;

        $this->insertAnomaly($runId, [
            'vehicle_id' => (int)$tx['vehicle_id'],
            'rule_code'  => 'Q8_CITY_VS_TRIP_CITY',
            'severity'   => 'low',          // declassato: rule rumorosa senza geocoding
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento in ' . ucfirst($tx['city']) . ' ma il veicolo ' . ($tx['vehicle_targa'] ?? '?') . ' non e\' transitato in quella citta\'',
            'detail'     => sprintf(
                "Distributore: %s · Tratte GPS del giorno: %s · Km totali: %s.\n\nNota: il match e' su nome citta'/regione, senza geocoding. Se il distributore e' su autostrada/superstrada lungo il percorso (es. ADS, FI-PI-LI, A1), e' falso positivo — archivia.",
                $tx['distributore'] ?? '?',
                implode(' / ', array_map(fn($t) => ($t['start_city']??'?').'→'.($t['end_city']??'?'), $tripsToday)),
                number_format($totalKm, 0)
            ),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** Soglia km cumulato per considerare la giornata "in transito" (autostrada). */
    private const TRANSIT_KM_THRESHOLD = 100.0;

    /**
     * Mappa citta' italiane → regione (lowercase).
     * Inclusi capoluoghi e comuni piu' frequenti per copertura wide. Match
     * case-insensitive sul nome esatto (no fuzzy per evitare collisioni).
     */
    private function cityToRegion(string $cityLower): ?string
    {
        $city = mb_strtolower(trim($cityLower));
        if ($city === '') return null;
        static $map = null;
        if ($map === null) $map = self::buildCityToRegionMap();
        return $map[$city] ?? null;
    }

    private static function buildCityToRegionMap(): array
    {
        // Province italiane raggruppate per regione. La key e' il nome
        // del capoluogo lowercase. Per comuni non capoluogo importanti,
        // aggiungiamo voci esplicite.
        $regions = [
            'piemonte'        => ['torino','alessandria','asti','biella','cuneo','novara','verbania','vercelli'],
            'lombardia'       => ['milano','bergamo','brescia','como','cremona','lecco','lodi','monza','mantova','pavia','sondrio','varese'],
            'liguria'         => ['genova','imperia','spezia','la spezia','savona'],
            'veneto'          => ['venezia','belluno','padova','rovigo','treviso','vicenza','verona','mestre','marghera'],
            'friuli'          => ['trieste','gorizia','pordenone','udine'],
            'trentino'        => ['trento','bolzano'],
            'emilia-romagna'  => ['bologna','ferrara','forli','forli-cesena','modena','piacenza','parma','ravenna','reggio emilia','rimini','cesena','castenaso','budrio','imola'],
            'toscana'         => ['firenze','arezzo','grosseto','livorno','lucca','massa','massa-carrara','pisa','prato','pistoia','siena','pontedera','empoli','viareggio','piombino','scarperia','sesto fiorentino'],
            'umbria'          => ['perugia','terni','assisi','foligno','spoleto'],
            'marche'          => ['ancona','ascoli','ascoli piceno','fermo','macerata','pesaro','urbino','senigallia','jesi'],
            'lazio'           => ['roma','frosinone','latina','rieti','viterbo','fiumicino','tivoli','aprilia','pomezia'],
            'abruzzo'         => ['aquila','l\'aquila','chieti','pescara','teramo'],
            'molise'          => ['campobasso','isernia'],
            'campania'        => ['napoli','avellino','benevento','caserta','salerno','torre del greco','giugliano','aversa','pozzuoli','battipaglia','nocera','nola','marcianise'],
            'puglia'          => ['bari','brindisi','barletta','foggia','lecce','taranto','andria','trani','altamura','molfetta','bisceglie','manfredonia'],
            'basilicata'      => ['potenza','matera'],
            'calabria'        => ['catanzaro','cosenza','crotone','reggio calabria','vibo','vibo valentia','lamezia','rende','corigliano'],
            'sicilia'         => ['palermo','agrigento','caltanissetta','catania','enna','messina','ragusa','siracusa','trapani','marsala','gela','vittoria','bagheria','acireale'],
            'sardegna'        => ['cagliari','nuoro','oristano','sassari','olbia','alghero','quartu'],
        ];
        $map = [];
        foreach ($regions as $region => $cities) {
            foreach ($cities as $c) $map[mb_strtolower($c)] = $region;
        }
        return $map;
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

        // 3) match per regione italiana: stesso macro-area → probabilmente
        //    rifornimento legittimo nel tragitto tra cantiere e casa/altro lavoro
        $txRegion = $this->cityToRegion($txCity);
        $wsRegion = $this->extractRegionFromWorksite($wsLoc);
        if ($txRegion && $wsRegion && $txRegion === $wsRegion) return 0;

        $this->insertAnomaly($runId, [
            'vehicle_id' => $tx['vehicle_id'] ? (int)$tx['vehicle_id'] : null,
            'worker_id'  => (int)$tx['resolved_worker_id'],
            'rule_code'  => 'TX_CITY_VS_WORKSITE',
            'severity'   => 'low',           // declassato: senza geocoding e' rumorosa
            'event_at'   => $tx['tx_at'],
            'summary'    => 'Rifornimento in ' . ucfirst($tx['city']) . ' ma operaio era al cantiere "' . $tx['worker_worksite_name'] . '"',
            'detail'     => sprintf("Cantiere: %s · TX in: %s · Distributore: %s.\n\nNota: match basato su nome citta'/regione. Possibile falso positivo se l'operaio ha fatto tragitto andata-ritorno o lavora su piu' siti.",
                                    $tx['worker_worksite_location'], ucfirst($tx['city']),
                                    $tx['distributore'] ?? '?'),
            'ref_tx_id'  => (int)$tx['id'],
        ]);
        return 1;
    }

    /** Cerca una citta' nota nella stringa location del cantiere → regione. */
    private function extractRegionFromWorksite(string $wsLoc): ?string
    {
        // prova prima sigla provincia
        if (preg_match('/\(([a-z]{2})\)/i', $wsLoc, $m)) {
            $cap = $this->expandProvSigla(mb_strtolower($m[1]));
            if ($cap) {
                $r = $this->cityToRegion($cap);
                if ($r) return $r;
            }
        }
        // scan parole della location contro la mappa citta'
        $words = preg_split('/[\s,;]+/', mb_strtolower($wsLoc));
        foreach ($words as $w) {
            $r = $this->cityToRegion(trim($w, " .-'"));
            if ($r) return $r;
        }
        return null;
    }

    /** GPS segnala refuel ma il giorno non ci sono TX Q8 per quel veicolo.
     *  Dedup: una sola anomalia per (vehicle, day) anche se ci sono N trip con refuels. */
    private function checkGpsRefuelVsQ8(int $runId, array $trip, array $txByVehDay): int
    {
        $day = substr($trip['start_at'], 0, 10);
        $vehicleId = $trip['vehicle_id'] ? (int)$trip['vehicle_id'] : null;
        $targa = $trip['vehicle_targa'] ?? '';

        $candKeys = [];
        if ($vehicleId) $candKeys[] = 'id:' . $vehicleId;
        if ($targa)     $candKeys[] = 'targa:' . strtoupper($targa);
        if (empty($candKeys)) return 0;

        // tx assegnate a QUESTO veicolo nel giorno?
        $hasTxForVehicle = false;
        foreach ($candKeys as $k) {
            if (!empty($txByVehDay[$k][$day] ?? [])) {
                $hasTxForVehicle = true;
                break;
            }
        }
        if ($hasTxForVehicle) return 0;

        $dedupKey = "GPS_REFUEL_NO_Q8|{$candKeys[0]}|{$day}";
        if (isset($this->emittedDedup[$dedupKey])) return 0;
        $this->emittedDedup[$dedupKey] = true;

        // Analisi smart per spiegare la causa REALE:
        $analysis = $this->analyzeWhyNoTxForVehicle($vehicleId, $targa, $day);

        $this->insertAnomaly($runId, [
            'vehicle_id'  => $vehicleId,
            'rule_code'   => 'GPS_REFUEL_NO_Q8',
            'severity'    => $analysis['severity'],
            'event_at'    => $trip['start_at'],
            'summary'     => sprintf('GPS: %d rifornimento (%sL) su %s — %s',
                                     $trip['refuels_count'], $trip['refuels_liters'], $targa ?: '?',
                                     $analysis['short_cause']),
            'detail'      => $analysis['detail'],
            'ref_trip_id' => (int)$trip['id'],
        ]);
        return 1;
    }

    /**
     * Quando un trip ha refuel ma niente Q8 per il veicolo, spiega PERCHE'.
     * Possibili scenari:
     *   a) Veicolo non ha nessuna carta assegnata in BOB           → high
     *      "Assegna una carta a questo veicolo"
     *   b) Veicolo ha una carta assegnata ma quella carta non ha tx nel giorno  → medium
     *      "La carta assegnata non ha avuto transazioni: forse usata carta sbagliata?"
     *   c) Q8 ha tx quel giorno ma di carte non collegate a nessun veicolo  → high
     *      "Ci sono N tx Q8 quel giorno con carte non assegnate: probabilmente una e' di questo veicolo"
     */
    private function analyzeWhyNoTxForVehicle(?int $vehicleId, string $targa, string $day): array
    {
        if (!$vehicleId) {
            return [
                'severity' => 'low',
                'short_cause' => 'veicolo non in catalogo',
                'detail' => sprintf('Il veicolo %s non e\' registrato in bb_fleet_vehicles → impossibile risalire alla carta. Registra il veicolo e assegnali la carta giusta.', $targa ?: '?'),
            ];
        }

        // a) il veicolo ha carte assegnate al giorno?
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM bb_fleet_fuel_card_assignments
            WHERE vehicle_id = ? AND from_date <= ? AND (to_date IS NULL OR to_date >= ?)
        ");
        $stmt->execute([$vehicleId, $day, $day]);
        $cardsAssigned = (int)$stmt->fetchColumn();

        // c) quante tx Q8 quel giorno hanno carta senza assegnazione?
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT tx.id), GROUP_CONCAT(DISTINCT tx.card_numero SEPARATOR ', ')
            FROM bb_fleet_fuel_tx tx
            LEFT JOIN bb_fleet_fuel_card_assignments fca
              ON fca.card_id = tx.card_id
             AND fca.from_date <= DATE(tx.tx_at)
             AND (fca.to_date IS NULL OR fca.to_date >= DATE(tx.tx_at))
            WHERE DATE(tx.tx_at) = ?
              AND fca.id IS NULL
        ");
        $stmt->execute([$day]);
        [$unassignedTxCount, $unassignedCards] = $stmt->fetch(\PDO::FETCH_NUM);
        $unassignedTxCount = (int)$unassignedTxCount;

        if ($cardsAssigned === 0) {
            return [
                'severity' => 'high',
                'short_cause' => 'NESSUNA carta assegnata al veicolo',
                'detail' => sprintf(
                    'Il veicolo %s non ha nessuna carta carburante assegnata in BOB per il %s.%s Assegna la carta corretta da /fleet?tab=cards (icona riassegna su ogni riga).',
                    $targa ?: '?',
                    $day,
                    $unassignedTxCount > 0
                        ? sprintf(' In Q8 quel giorno ci sono %d transazioni con carte NON assegnate (Card No: %s) — una di queste e\' probabilmente di %s.',
                                  $unassignedTxCount, $unassignedCards, $targa)
                        : ''
                ),
            ];
        }

        // ha carte assegnate ma niente tx
        return [
            'severity' => 'medium',
            'short_cause' => 'carta assegnata ma niente Q8',
            'detail' => sprintf(
                'Il veicolo %s ha %d carta/e assegnata/e al %s ma nessuna di esse risulta tra le tx Q8 del giorno.%s Verifica se l\'autista ha usato una carta diversa.',
                $targa ?: '?', $cardsAssigned, $day,
                $unassignedTxCount > 0
                    ? sprintf(' (Q8 del giorno ha anche %d tx con carte non in BOB: %s).', $unassignedTxCount, $unassignedCards)
                    : ''
            ),
        ];
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
        // consumo L/100km gestito da checkTankToTankConsumption (tra rifornimenti)
        return $count;
    }

    /**
     * Consumo tank-to-tank: per ogni veicolo, ordina le tx Q8 per orario e
     * confronta ogni rifornimento col precedente. I litri immessi al pieno
     * (N+1) approssimano i litri consumati nel tragitto fra (N) e (N+1).
     *
     *   consumo L/100km = litri_pieno_N+1 / km_fra_i_due_pieni * 100
     *
     * Skippa se km_fra < MIN_KM_BETWEEN_REFILLS (rumore: due pieni vicini sono
     * di solito top-up, non hai consumato abbastanza).
     */
    private function checkTankToTankConsumption(int $runId, array $txs, array $trips): int
    {
        // raggruppa tx per veicolo (solo quelli risolti — niente vehicle_id, niente check)
        $byVehicle = [];
        foreach ($txs as $tx) {
            if (!$tx['vehicle_id']) continue;
            $byVehicle[(int)$tx['vehicle_id']][] = $tx;
        }
        // gia' ORDER BY tx_at ASC dalla query, ma riordina per sicurezza
        foreach ($byVehicle as &$arr) {
            usort($arr, fn($a, $b) => strcmp($a['tx_at'], $b['tx_at']));
        }
        unset($arr);

        // indice trips per vehicle_id (e fallback per targa)
        $tripsByVeh = [];
        foreach ($trips as $t) {
            if ($t['vehicle_id']) {
                $tripsByVeh[(int)$t['vehicle_id']][] = $t;
            }
        }
        foreach ($tripsByVeh as &$arr) {
            usort($arr, fn($a, $b) => strcmp($a['start_at'], $b['start_at']));
        }
        unset($arr);

        $count = 0;
        foreach ($byVehicle as $vehicleId => $vehicleTxs) {
            for ($i = 1, $n = count($vehicleTxs); $i < $n; $i++) {
                $prev = $vehicleTxs[$i - 1];
                $curr = $vehicleTxs[$i];

                // somma km dei trip fra prev.tx_at e curr.tx_at
                $kmBetween = $this->sumKmBetween($tripsByVeh[$vehicleId] ?? [], $prev['tx_at'], $curr['tx_at']);
                if ($kmBetween < self::MIN_KM_BETWEEN_REFILLS) continue;  // rumore

                $liters = (float)$curr['litri'];
                if ($liters <= 0) continue;
                $lp100 = $liters / $kmBetween * 100.0;
                if ($lp100 <= self::CONSUMPTION_LP100KM_MAX) continue;  // dentro la norma

                $vehTarga = $curr['vehicle_targa'] ?? '?';
                $this->insertAnomaly($runId, [
                    'vehicle_id' => $vehicleId,
                    'rule_code'  => 'CONSUMPTION_OUTLIER',
                    'severity'   => $lp100 > 35 ? 'high' : 'medium',
                    'event_at'   => $curr['tx_at'],
                    'summary'    => sprintf('Consumo anomalo su %s tra due rifornimenti: %.1f L/100km',
                                            $vehTarga, $lp100),
                    'detail'     => sprintf(
                        "Rifornimento precedente: %s · %sL\n".
                        "Rifornimento attuale:    %s · %sL\n".
                        "Km percorsi tra i due:   %s km\n".
                        "Consumo calcolato:       %.1f L/100km (soglia %.0f)\n\n".
                        "Possibili cause: travaso, fuga carburante, autista che ha messo gasolio in altra auto, errore lettura.",
                        $prev['tx_at'], number_format((float)$prev['litri'], 1),
                        $curr['tx_at'], number_format($liters, 1),
                        number_format($kmBetween, 0),
                        $lp100, self::CONSUMPTION_LP100KM_MAX
                    ),
                    'ref_tx_id'  => (int)$curr['id'],
                ]);
                $count++;
            }
        }
        return $count;
    }

    /** Somma km_done dei trip con start_at strettamente fra $afterDt e $beforeDt. */
    private function sumKmBetween(array $sortedTrips, string $afterDt, string $beforeDt): float
    {
        $sum = 0.0;
        foreach ($sortedTrips as $t) {
            if ($t['start_at'] <= $afterDt) continue;
            if ($t['start_at'] >  $beforeDt) break;   // sorted, possiamo uscire
            $sum += (float)$t['km_done'];
        }
        return $sum;
    }

    /**
     * Emette una sola anomalia VEHICLE_NO_CARD_LINK per ogni veicolo che ha
     * tratte nel periodo ma a cui non e' mai stata assegnata una carta.
     * E' l'azione che lo user deve fare PRIMA di poter scoprire altre anomalie.
     */
    private function checkVehiclesWithoutCard(int $runId, string $from, string $to, ?int $vehicleFilter): int
    {
        $params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
        $vFilter = '';
        if ($vehicleFilter) {
            $vFilter = ' AND v.id = :vid';
            $params[':vid'] = $vehicleFilter;
        }
        $stmt = $this->conn->prepare("
            SELECT v.id, v.targa, COUNT(DISTINCT DATE(t.start_at)) AS trip_days
            FROM bb_fleet_gps_trips t
            JOIN bb_fleet_vehicles v ON v.id = t.vehicle_id
            WHERE t.start_at BETWEEN :from AND :to
              AND NOT EXISTS (
                SELECT 1 FROM bb_fleet_fuel_card_assignments fca
                WHERE fca.vehicle_id = v.id
                  AND fca.from_date <= DATE(:to)
                  AND (fca.to_date IS NULL OR fca.to_date >= DATE(:from))
              )
              {$vFilter}
            GROUP BY v.id
            HAVING trip_days > 0
        ");
        $stmt->execute($params);
        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $this->insertAnomaly($runId, [
                'vehicle_id' => (int)$r['id'],
                'rule_code'  => 'VEHICLE_NO_CARD_LINK',
                'severity'   => 'high',
                'event_at'   => $from . ' 00:00:00',
                'summary'    => sprintf('Veicolo %s ha %d giorni di tratte ma NESSUNA carta carburante assegnata in BOB',
                                        $r['targa'], $r['trip_days']),
                'detail'     => 'Senza assegnazione carta non possiamo correlare i rifornimenti Q8. Vai su /fleet?tab=cards, trova la carta usata dall\'autista di questo veicolo, clicca riassegna e collegala al veicolo. Poi ri-analizza.',
            ]);
            $count++;
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
