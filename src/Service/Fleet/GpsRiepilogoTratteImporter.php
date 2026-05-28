<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importer per il file "Riepilogo tratte" del provider GPS.
 *
 * Layout file (osservato):
 *  - Riga 0: "Applied filters:..." (skippa)
 *  - Riga 2: header con queste colonne (in ordine variabile, ricerca per nome):
 *      Veicolo, Nominativo, Data di partenza, Indirizzo di partenza,
 *      Data di arrivo, Indirizzo di arrivo, Km Percorsi, Velocita Media,
 *      Velocita Max, Ore di guida, Rifornimenti Nr., Rifornimento L.,
 *      Km Cruscotto, PersonCode, Geopoints, etc.
 *  - Dati dalla riga 3 in poi.
 *
 * Strategia: detect header riga scansionando le prime 5 righe per la
 * stringa "Veicolo". Poi mappa colonne per nome (case-insensitive,
 * tolerante a varianti).
 *
 * Dedup: raw_hash = sha1(targa|start_at|end_at|km) — saltiamo le righe
 * gia' importate (utile se l'utente ricarica per sbaglio lo stesso file).
 */
final class GpsRiepilogoTratteImporter
{
    public function __construct(private PDO $conn) {}

    /**
     * @param string $filePath Path assoluto al file xlsx.
     * @param int    $importId id della riga bb_fleet_imports gia' creata.
     * @return array{rows_total:int, rows_imported:int, rows_skipped:int, errors:array, period_from:?string, period_to:?string}
     */
    public function import(string $filePath, int $importId): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // col letters as keys

        // 1) trova riga header
        $headerRow = null;
        $headerIdx = null;
        foreach (array_slice($rows, 0, 8, true) as $idx => $r) {
            foreach ($r as $cell) {
                if (is_string($cell) && stripos($cell, 'Veicolo') !== false) {
                    $headerRow = $r;
                    $headerIdx = $idx;
                    break 2;
                }
            }
        }
        if (!$headerRow) {
            return ['rows_total'=>0,'rows_imported'=>0,'rows_skipped'=>0,
                    'errors'=>['Header "Veicolo" non trovato nelle prime 8 righe'],
                    'period_from'=>null,'period_to'=>null];
        }

        // 2) mappa header -> column letter
        $colMap = $this->buildColumnMap($headerRow);
        if (empty($colMap['targa']) || empty($colMap['start_at']) || empty($colMap['end_at'])) {
            return ['rows_total'=>0,'rows_imported'=>0,'rows_skipped'=>0,
                    'errors'=>['Colonne minime mancanti (Veicolo/Data di partenza/Data di arrivo)'],
                    'period_from'=>null,'period_to'=>null];
        }

        // 3) cache targhe -> vehicle_id (un solo SELECT)
        $vehicleMap = $this->loadVehicleMap();

        $imported = 0; $skipped = 0; $total = 0;
        $errors = [];
        $minDate = null; $maxDate = null;

        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO bb_fleet_gps_trips (
                import_id, vehicle_targa, vehicle_id, driver_name, driver_person_code,
                start_at, end_at, start_address, end_address,
                start_city, start_prov, end_city, end_prov,
                end_lat, end_lng,
                km_done, km_odometer, avg_speed, max_speed, drive_seconds,
                refuels_count, refuels_liters, raw_hash
            ) VALUES (
                :import_id, :targa, :vehicle_id, :driver_name, :person_code,
                :start_at, :end_at, :start_addr, :end_addr,
                :start_city, :start_prov, :end_city, :end_prov,
                :end_lat, :end_lng,
                :km_done, :km_odo, :avg_speed, :max_speed, :drive_seconds,
                :refuels_n, :refuels_l, :raw_hash
            )
        ");

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $idx => $r) {
                if ($idx <= $headerIdx) continue;
                $total++;

                $targa = $this->get($r, $colMap, 'targa');
                if (!$targa) { $skipped++; continue; }
                $targa = strtoupper(trim((string)$targa));

                $startAt = $this->parseDateTime($this->get($r, $colMap, 'start_at'));
                $endAt   = $this->parseDateTime($this->get($r, $colMap, 'end_at'));
                if (!$startAt || !$endAt) { $skipped++; continue; }

                $startAddr = $this->str($r, $colMap, 'start_addr');
                $endAddr   = $this->str($r, $colMap, 'end_addr');
                [$startCity, $startProv] = $this->parseItalianAddress($startAddr);
                [$endCity,   $endProv]   = $this->parseItalianAddress($endAddr);

                [$lat, $lng] = $this->parsePoint($this->str($r, $colMap, 'geopoints'));

                $driveSec = $this->parseHmsToSec($this->str($r, $colMap, 'drive_time'));

                $rawHash = sha1($targa . '|' . $startAt . '|' . $endAt . '|' . ($this->num($r, $colMap, 'km_done') ?? ''));

                try {
                    $stmt->execute([
                        ':import_id'    => $importId,
                        ':targa'        => $targa,
                        ':vehicle_id'   => $vehicleMap[$targa] ?? null,
                        ':driver_name'  => $this->str($r, $colMap, 'driver') ?: null,
                        ':person_code'  => $this->str($r, $colMap, 'person_code') ?: null,
                        ':start_at'     => $startAt,
                        ':end_at'       => $endAt,
                        ':start_addr'   => $this->cleanAddr($startAddr),
                        ':end_addr'     => $this->cleanAddr($endAddr),
                        ':start_city'   => $startCity,
                        ':start_prov'   => $startProv,
                        ':end_city'     => $endCity,
                        ':end_prov'     => $endProv,
                        ':end_lat'      => $lat,
                        ':end_lng'      => $lng,
                        ':km_done'      => $this->num($r, $colMap, 'km_done'),
                        ':km_odo'       => $this->num($r, $colMap, 'km_odo'),
                        ':avg_speed'    => $this->num($r, $colMap, 'avg_speed'),
                        ':max_speed'    => $this->intOrNull($this->num($r, $colMap, 'max_speed')),
                        ':drive_seconds'=> $driveSec,
                        ':refuels_n'    => (int)($this->num($r, $colMap, 'refuels_n') ?? 0),
                        ':refuels_l'    => $this->num($r, $colMap, 'refuels_l') ?? 0,
                        ':raw_hash'     => $rawHash,
                    ]);
                    if ($stmt->rowCount() === 1) {
                        $imported++;
                        // aggiorna periodo
                        if (!$minDate || $startAt < $minDate) $minDate = $startAt;
                        if (!$maxDate || $endAt > $maxDate)   $maxDate = $endAt;
                    } else {
                        $skipped++; // duplicate (raw_hash already present)
                    }
                } catch (\PDOException $e) {
                    $skipped++;
                    $errors[] = "Riga {$idx}: " . $e->getMessage();
                    if (count($errors) > 20) break;
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return [
            'rows_total'    => $total,
            'rows_imported' => $imported,
            'rows_skipped'  => $skipped,
            'errors'        => $errors,
            'period_from'   => $minDate ? substr($minDate, 0, 10) : null,
            'period_to'     => $maxDate ? substr($maxDate, 0, 10) : null,
        ];
    }

    // ─── Header mapping ───────────────────────────────────────────────────────

    /**
     * Per ogni "campo logico" che vogliamo, lista di stringhe possibili
     * nel header. Match case-insensitive, substring.
     */
    private const HEADER_ALIASES = [
        'targa'        => ['veicolo'],
        'driver'       => ['nominativo'],
        'person_code'  => ['personcode'],
        'start_at'     => ['data di partenza'],
        'end_at'       => ['data di arrivo'],
        'start_addr'   => ['indirizzo di partenza'],
        'end_addr'     => ['indirizzo di arrivo'],
        'km_done'      => ['km percorsi'],
        'km_odo'       => ['km cruscotto'],
        'avg_speed'    => ['velocit'],   // matches "Velocità Media (km/h)" — first found wins
        'max_speed'    => ['velocit'],   // verra' sovrascritta se trovata "max"
        'drive_time'   => ['ore di guida'],
        'refuels_n'    => ['rifornimenti nr'],
        'refuels_l'    => ['rifornimento l'],
        'geopoints'    => ['geopoint'],
    ];

    private function buildColumnMap(array $headerRow): array
    {
        $map = [];
        // primo pass: alias semplici
        foreach (self::HEADER_ALIASES as $key => $aliases) {
            foreach ($headerRow as $col => $val) {
                if (!is_string($val)) continue;
                $low = mb_strtolower($val);
                foreach ($aliases as $a) {
                    if (str_contains($low, $a)) {
                        if (!isset($map[$key])) {
                            $map[$key] = $col;
                        }
                        break;
                    }
                }
            }
        }
        // override speed: avg vs max
        foreach ($headerRow as $col => $val) {
            if (!is_string($val)) continue;
            $low = mb_strtolower($val);
            if (str_contains($low, 'velocit') && str_contains($low, 'media')) $map['avg_speed'] = $col;
            if (str_contains($low, 'velocit') && str_contains($low, 'max'))   $map['max_speed'] = $col;
        }
        return $map;
    }

    // ─── Cell readers ────────────────────────────────────────────────────────

    private function get(array $row, array $map, string $key) {
        $col = $map[$key] ?? null;
        if (!$col) return null;
        return $row[$col] ?? null;
    }
    private function str(array $row, array $map, string $key): string {
        $v = $this->get($row, $map, $key);
        return $v === null ? '' : trim((string)$v);
    }
    private function num(array $row, array $map, string $key): ?float {
        $v = $this->get($row, $map, $key);
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        // formato italiano "1.234,56" → "1234.56"
        $s = str_replace(['.', ','], ['', '.'], (string)$v);
        return is_numeric($s) ? (float)$s : null;
    }
    private function intOrNull(?float $n): ?int { return $n === null ? null : (int)$n; }

    private function parseDateTime($v): ?string
    {
        if ($v === null || $v === '') return null;
        // PhpSpreadsheet a volte ritorna stringa, a volte serial Excel
        if (is_numeric($v)) {
            $unix = (\PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$v));
            return date('Y-m-d H:i:s', $unix);
        }
        $s = trim((string)$v);
        $ts = strtotime($s);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function parseHmsToSec(string $hms): ?int
    {
        if ($hms === '') return null;
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', trim($hms), $m)) {
            return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)$m[3];
        }
        return null;
    }

    /** "POINT (11.43 44.50)" → [44.50, 11.43]  (lat, lng) */
    private function parsePoint(string $s): array
    {
        if (preg_match('/POINT\s*\(\s*([\-0-9.]+)\s+([\-0-9.]+)\s*\)/i', $s, $m)) {
            // formato WKT: POINT(lng lat)
            return [(float)$m[2], (float)$m[1]];
        }
        return [null, null];
    }

    /**
     * Indirizzo GPS tipico: "IT Via Tevere FIRENZE Sesto Fiorentino".
     * Strategia best-effort: trovi la prima parola in MAIUSCOLO (>=4 char)
     * dopo "IT " → e' la citta'. Per la provincia non c'e' una regola
     * univoca, ritorniamo null e useremo la citta'.
     */
    private function parseItalianAddress(string $s): array
    {
        if ($s === '') return [null, null];
        // skip "IT " prefix
        $s = preg_replace('/^IT\s+/', '', $s);
        // cerca pattern UPPERCASE di almeno 4 caratteri (citta' di provincia)
        if (preg_match('/\b([A-ZÀÈÉÌÒÙ]{4,})\b/u', $s, $m)) {
            return [ucfirst(mb_strtolower($m[1])), null];
        }
        return [null, null];
    }

    private function cleanAddr(string $s): ?string
    {
        if ($s === '') return null;
        // sostituisce '' doppio (artefatto Excel) con apostrofo singolo
        $s = str_replace("''", "'", $s);
        return mb_substr($s, 0, 255);
    }

    // ─── Lookup veicoli ──────────────────────────────────────────────────────

    private function loadVehicleMap(): array
    {
        $stmt = $this->conn->query("SELECT id, targa FROM bb_fleet_vehicles");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[strtoupper($r['targa'])] = (int)$r['id'];
        }
        return $map;
    }
}
