<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importer per il file Excel/CSV scaricato dal portale Q8.
 *
 * Senza un sample del file ufficiale, il parser e' "permissive":
 * scansiona le prime 10 righe per trovare l'header, poi mappa per
 * keyword (data, ora, importo, litri, carta, distributore, ...).
 *
 * Quando arrivera' il sample reale, sara' sufficiente affinare il
 * mapping in HEADER_ALIASES — il resto della pipeline e' generico.
 *
 * Dedup: raw_hash = sha1(carta|tx_at|litri|importo).
 */
final class Q8FuelInvoiceImporter
{
    public function __construct(private PDO $conn) {}

    /**
     * @return array{rows_total:int, rows_imported:int, rows_skipped:int, errors:array, period_from:?string, period_to:?string}
     */
    public function import(string $filePath, int $importId): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // detect header riga
        $headerIdx = null;
        foreach (array_slice($rows, 0, 12, true) as $idx => $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                $low = mb_strtolower($cell);
                if (str_contains($low, 'carta') || str_contains($low, 'litri') ||
                    str_contains($low, 'distributore') || str_contains($low, 'numero card')) {
                    $headerIdx = $idx;
                    break 2;
                }
            }
        }
        if ($headerIdx === null) {
            return ['rows_total'=>0,'rows_imported'=>0,'rows_skipped'=>0,
                    'errors'=>['Header non riconosciuto (cerca: carta / litri / distributore)'],
                    'period_from'=>null,'period_to'=>null];
        }
        $headerRow = $rows[$headerIdx];
        $colMap = $this->buildColumnMap($headerRow);

        if (empty($colMap['card']) || empty($colMap['date']) || empty($colMap['litri'])) {
            return ['rows_total'=>0,'rows_imported'=>0,'rows_skipped'=>0,
                    'errors'=>['Colonne minime mancanti (carta/data/litri). Trovate: '.implode(',', array_keys($colMap))],
                    'period_from'=>null,'period_to'=>null];
        }

        $cardMap = $this->loadCardMap();

        $imported = 0; $skipped = 0; $total = 0;
        $errors = [];
        $minDate = null; $maxDate = null;

        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO bb_fleet_fuel_tx (
                import_id, card_numero, card_id, tx_at,
                importo, litri, prezzo_unit, carburante,
                distributore, city, prov, km_dichiarati, raw_hash
            ) VALUES (
                :import_id, :card_numero, :card_id, :tx_at,
                :importo, :litri, :prezzo, :carb,
                :distrib, :city, :prov, :km, :raw_hash
            )
        ");

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $idx => $r) {
                if ($idx <= $headerIdx) continue;
                $total++;

                $cardRaw = $this->str($r, $colMap, 'card');
                if ($cardRaw === '') { $skipped++; continue; }
                $cardNum = $this->normalizeCardNumber($cardRaw);

                $txAt = $this->parseDateAndTime(
                    $this->get($r, $colMap, 'date'),
                    $this->get($r, $colMap, 'time')
                );
                if (!$txAt) { $skipped++; continue; }

                $litri = $this->num($r, $colMap, 'litri') ?? 0;
                if ($litri <= 0) { $skipped++; continue; }

                $importo = $this->num($r, $colMap, 'importo') ?? 0;
                $prezzo  = $this->num($r, $colMap, 'prezzo');
                if ($prezzo === null && $litri > 0 && $importo > 0) {
                    $prezzo = round($importo / $litri, 3);
                }

                $distrib = $this->str($r, $colMap, 'distrib') ?: null;
                $carb    = $this->str($r, $colMap, 'carb')    ?: null;
                $km      = $this->num($r, $colMap, 'km');

                [$city, $prov] = $this->parseDistribLocation($distrib ?? '');

                $rawHash = sha1($cardNum.'|'.$txAt.'|'.$litri.'|'.$importo);

                try {
                    $stmt->execute([
                        ':import_id'   => $importId,
                        ':card_numero' => $cardNum,
                        ':card_id'     => $cardMap[$cardNum] ?? null,
                        ':tx_at'       => $txAt,
                        ':importo'     => $importo,
                        ':litri'       => $litri,
                        ':prezzo'      => $prezzo,
                        ':carb'        => $carb,
                        ':distrib'     => $distrib ? mb_substr($distrib, 0, 255) : null,
                        ':city'        => $city,
                        ':prov'        => $prov,
                        ':km'          => $km !== null ? (int)$km : null,
                        ':raw_hash'    => $rawHash,
                    ]);
                    if ($stmt->rowCount() === 1) {
                        $imported++;
                        if (!$minDate || $txAt < $minDate) $minDate = $txAt;
                        if (!$maxDate || $txAt > $maxDate) $maxDate = $txAt;
                    } else {
                        $skipped++;
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

    private const HEADER_ALIASES = [
        'card'    => ['numero card', 'numero carta', 'carta', 'card number', 'cardnumber'],
        'date'    => ['data transazione', 'data', 'date'],
        'time'    => ['ora transazione', 'ora', 'time'],
        'importo' => ['importo totale', 'importo lordo', 'importo', 'totale', 'amount'],
        'litri'   => ['litri', 'quantita', 'liters', 'volume'],
        'prezzo'  => ['prezzo unitario', 'prezzo unit', 'prezzo', 'price per liter'],
        'carb'    => ['prodotto', 'carburante', 'fuel type', 'tipo carburante'],
        'distrib' => ['distributore', 'punto vendita', 'pv', 'station', 'indirizzo pv'],
        'km'      => ['chilometraggio', 'km dichiarati', 'km', 'mileage'],
    ];

    private function buildColumnMap(array $headerRow): array
    {
        $map = [];
        foreach (self::HEADER_ALIASES as $key => $aliases) {
            foreach ($headerRow as $col => $val) {
                if (!is_string($val)) continue;
                $low = mb_strtolower($val);
                foreach ($aliases as $a) {
                    if (str_contains($low, $a)) {
                        if (!isset($map[$key])) $map[$key] = $col;
                        break;
                    }
                }
            }
        }
        return $map;
    }

    private function get(array $row, array $map, string $key) {
        $col = $map[$key] ?? null;
        return $col ? ($row[$col] ?? null) : null;
    }
    private function str(array $row, array $map, string $key): string {
        $v = $this->get($row, $map, $key);
        return $v === null ? '' : trim((string)$v);
    }
    private function num(array $row, array $map, string $key): ?float {
        $v = $this->get($row, $map, $key);
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        $s = str_replace([' €', '€', ' L', 'L'], '', (string)$v);
        $s = trim($s);
        // formato italiano
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    }

    /** Combina cella data (eventualmente con time) + cella ora separata. */
    private function parseDateAndTime($d, $t): ?string
    {
        if ($d === null || $d === '') return null;

        // 1) numero serial Excel
        if (is_numeric($d)) {
            $unix = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$d);
            $dateStr = date('Y-m-d', $unix);
            // se ha frazione, contiene gia' l'ora
            if (((float)$d - floor((float)$d)) > 0) {
                return date('Y-m-d H:i:s', $unix);
            }
            return $dateStr . ' ' . $this->parseTime($t);
        }

        $dStr = trim((string)$d);
        // gia' include ora?
        $ts = strtotime($dStr);
        if ($ts === false) return null;
        $hasTime = (bool)preg_match('/\d{1,2}:\d{2}/', $dStr);
        if ($hasTime) return date('Y-m-d H:i:s', $ts);

        return date('Y-m-d', $ts) . ' ' . $this->parseTime($t);
    }

    private function parseTime($t): string
    {
        if ($t === null || $t === '') return '00:00:00';
        if (is_numeric($t)) {
            // frazione di giorno
            $sec = (int)round(((float)$t) * 86400);
            return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
        }
        $s = trim((string)$t);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
        }
        return '00:00:00';
    }

    private function normalizeCardNumber(string $raw): string
    {
        // alcuni esport hanno apici/spazi per non far perdere zeri
        return preg_replace('/[\s\']/', '', $raw);
    }

    private function parseDistribLocation(string $s): array
    {
        if ($s === '') return [null, null];
        // pattern grezzo: cerca "(XX)" provincia, e l'ultima parola capitalizzata
        $prov = null;
        if (preg_match('/\(([A-Z]{2})\)/', $s, $m)) $prov = $m[1];
        $city = null;
        if (preg_match('/\b([A-ZÀÈÉÌÒÙ][a-zA-ZÀ-ÿ]{3,})\b\s*(?:\([A-Z]{2}\))?$/u', $s, $m)) {
            $city = $m[1];
        }
        return [$city, $prov];
    }

    private function loadCardMap(): array
    {
        $stmt = $this->conn->query("SELECT id, numero FROM bb_fleet_fuel_cards");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$this->normalizeCardNumber($r['numero'])] = (int)$r['id'];
        }
        return $map;
    }
}
