<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;

/**
 * Suggerisce a quale veicolo appartiene ogni carta Q8, incrociando
 * tx Q8 con tratte GPS dello stesso periodo.
 *
 * Algoritmo (per ogni coppia card x vehicle):
 *   Per ogni tx della carta:
 *     - Se esiste un trip del veicolo che inizia/finisce entro ±WINDOW_HOURS
 *       dalla tx_at  → +1.0 punto
 *     - Se inoltre la citta' della tx matcha start_city/end_city del trip
 *                                                           → +0.5 punto
 *     - Se inoltre l'indirizzo del distributore compare nel
 *       start/end_address del trip                          → +0.3 punto
 *   Normalizza per numero di tx della carta.
 *   Confidence = (score / max_possible_score) * 100, capped at 100.
 *
 * Ritorna per ogni carta le top N candidate ordinate per confidence.
 */
final class CardVehicleMappingSuggester
{
    private const WINDOW_HOURS = 3;
    private const TOP_N        = 3;
    private const MIN_TX_FOR_SUGGESTION = 1;

    public function __construct(private PDO $conn) {}

    /**
     * @param ?string $from Limita le tx considerate (yyyy-mm-dd). Null = tutto.
     * @param ?string $to
     * @return array<int, array{
     *     card: array,
     *     suggestions: array<int, array{vehicle: array, score: float, confidence: int, matches: int, total_tx: int, debug: string}>
     * }>
     */
    public function suggestAll(?string $from = null, ?string $to = null): array
    {
        $cards    = $this->loadCardsWithTxs($from, $to);
        $vehicles = $this->loadVehicles();
        $tripsByVehicle = $this->loadTripsByVehicle($from, $to);

        $result = [];
        foreach ($cards as $card) {
            if (count($card['txs']) < self::MIN_TX_FOR_SUGGESTION) continue;

            $cardSuggestions = [];
            foreach ($vehicles as $vehicle) {
                $vehicleTrips = $tripsByVehicle[$vehicle['id']] ?? [];
                if (empty($vehicleTrips)) continue;

                $scoring = $this->scoreCardVsVehicle($card['txs'], $vehicleTrips);
                if ($scoring['score'] <= 0) continue;

                $cardSuggestions[] = [
                    'vehicle'    => $vehicle,
                    'score'      => $scoring['score'],
                    'matches'    => $scoring['matches'],
                    'total_tx'   => count($card['txs']),
                    'confidence' => (int)min(100, round($scoring['score'] / count($card['txs']) * 100 / 1.8)),
                    'debug'      => $scoring['debug'],
                ];
            }

            // ordina per score desc e prendi top N
            usort($cardSuggestions, fn($a, $b) => $b['score'] <=> $a['score']);
            $cardSuggestions = array_slice($cardSuggestions, 0, self::TOP_N);

            $result[] = [
                'card'        => $card,
                'suggestions' => $cardSuggestions,
            ];
        }

        return $result;
    }

    /**
     * Scoring di una singola coppia carta-veicolo.
     * @return array{score: float, matches: int, debug: string}
     */
    private function scoreCardVsVehicle(array $cardTxs, array $vehicleTrips): array
    {
        $score = 0.0;
        $matches = 0;
        $debug = [];

        // ordina trips per start_at per binary search-friendly
        usort($vehicleTrips, fn($a, $b) => strcmp($a['start_at'], $b['start_at']));

        foreach ($cardTxs as $tx) {
            $txTs = strtotime($tx['tx_at']);
            $txDay = substr($tx['tx_at'], 0, 10);
            $txCity = mb_strtolower(trim($tx['city'] ?? ''));
            $txDistribLow = mb_strtolower($tx['distributore'] ?? '');

            $bestForThisTx = 0.0;
            foreach ($vehicleTrips as $trip) {
                $tripDay = substr($trip['start_at'], 0, 10);
                // velocita': se il giorno e' lontano salta
                if (abs(strtotime($tripDay) - strtotime($txDay)) > 86400) continue;

                $sTs = strtotime($trip['start_at']);
                $eTs = strtotime($trip['end_at']);
                $window = self::WINDOW_HOURS * 3600;

                // tx nella finestra del trip (incluso ±WINDOW dai bordi)?
                if ($txTs < $sTs - $window || $txTs > $eTs + $window) continue;

                $thisScore = 1.0;

                if ($txCity !== '') {
                    $sCityLow = mb_strtolower($trip['start_city'] ?? '');
                    $eCityLow = mb_strtolower($trip['end_city']   ?? '');
                    if ($txCity === $sCityLow || $txCity === $eCityLow) {
                        $thisScore += 0.5;
                    } elseif (str_contains(mb_strtolower($trip['start_address'] ?? '') . ' ' . mb_strtolower($trip['end_address'] ?? ''), $txCity)) {
                        $thisScore += 0.3;
                    }
                }

                if ($thisScore > $bestForThisTx) $bestForThisTx = $thisScore;
            }

            if ($bestForThisTx > 0) {
                $score += $bestForThisTx;
                $matches++;
                $debug[] = sprintf('tx %s (%s) +%.1f', substr($tx['tx_at'], 0, 16), $tx['city'] ?? '?', $bestForThisTx);
            }
        }
        return ['score' => $score, 'matches' => $matches, 'debug' => implode(' · ', array_slice($debug, 0, 4))];
    }

    private function loadCardsWithTxs(?string $from, ?string $to): array
    {
        $where = ["c.active = 1"];
        $params = [];
        if ($from) { $where[] = "tx.tx_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
        if ($to)   { $where[] = "tx.tx_at <= :to";   $params[':to']   = $to   . ' 23:59:59'; }

        $stmt = $this->conn->prepare("
            SELECT c.id, c.card_no, c.pan, c.numero, c.fornitore,
                   (SELECT vehicle_id FROM bb_fleet_fuel_card_assignments
                    WHERE card_id = c.id AND to_date IS NULL LIMIT 1) AS current_vehicle_id
            FROM bb_fleet_fuel_cards c
            INNER JOIN bb_fleet_fuel_tx tx ON tx.card_id = c.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY c.id
            ORDER BY c.card_no ASC
        ");
        $stmt->execute($params);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // carica le tx per ogni carta
        foreach ($cards as &$c) {
            $stmt = $this->conn->prepare("
                SELECT id, tx_at, litri, importo, city, distributore, plate_alias_q8
                FROM bb_fleet_fuel_tx
                WHERE card_id = ?
                " . ($from ? " AND tx_at >= '" . $from . " 00:00:00'" : '') . "
                " . ($to   ? " AND tx_at <= '" . $to   . " 23:59:59'" : '') . "
                ORDER BY tx_at ASC
            ");
            $stmt->execute([$c['id']]);
            $c['txs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($c);
        return $cards;
    }

    private function loadVehicles(): array
    {
        $stmt = $this->conn->query("
            SELECT id, targa, modello, tipo
            FROM bb_fleet_vehicles
            WHERE active = 1
            ORDER BY targa ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadTripsByVehicle(?string $from, ?string $to): array
    {
        $where = ['vehicle_id IS NOT NULL'];
        $params = [];
        if ($from) { $where[] = "start_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
        if ($to)   { $where[] = "start_at <= :to";   $params[':to']   = $to   . ' 23:59:59'; }

        $stmt = $this->conn->prepare("
            SELECT id, vehicle_id, vehicle_targa, start_at, end_at,
                   start_address, end_address, start_city, end_city,
                   km_done
            FROM bb_fleet_gps_trips
            WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $map[(int)$t['vehicle_id']][] = $t;
        }
        return $map;
    }
}
