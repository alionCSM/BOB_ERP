<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importer per il file Excel della fattura Q8 ("Q8 Fleet transactions").
 *
 * Layout reale (verificato su fattura PJ11539032):
 *  - Sheet "Q8 Fleet transactions"
 *  - Riga 0: vuota
 *  - Riga 1: header
 *  - Riga 2+: dati (1 riga = 1 rifornimento)
 *
 * Colonne chiave:
 *  - Card No.       numero carta breve (es "00025") → bb_fleet_fuel_cards.numero
 *  - Card PAN       codice completo carta (16-19 cifre, fallback se No. mancante)
 *  - Date           datetime completo (gia' include ora)
 *  - Plate number   ALIAS Q8 del veicolo (es "JOLLY", "JOLLY 2") — NON la targa fisica
 *                   match via bb_fleet_vehicles.plate_alias_q8
 *  - Prod.          carburante (es "GASOLIO")
 *  - Volume         litri (campo critico)
 *  - Full amount    importo lordo €
 *  - Discounted price  €/L effettivo (scontato)
 *  - Address        indirizzo distributore (concat con Petrol station code)
 *  - City           citta' (gia' pulita, niente parsing)
 *  - Km             contachilometri dichiarato dal driver
 *
 * Dedup: raw_hash = sha1(card_pan|tx_at|litri|importo).
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

        // detect header riga — cerca "Card No." (la fattura Q8 standard)
        $headerIdx = null;
        foreach (array_slice($rows, 0, 12, true) as $idx => $r) {
            foreach ($r as $cell) {
                if (!is_string($cell)) continue;
                $low = mb_strtolower(trim($cell));
                if ($low === 'card no.' || $low === 'card pan' ||
                    str_contains($low, 'numero card') || $low === 'volume') {
                    $headerIdx = $idx;
                    break 2;
                }
            }
        }
        if ($headerIdx === null) {
            return $this->emptyResult('Header non riconosciuto (cerca: Card No. / Card PAN / Volume)');
        }
        $headerRow = $rows[$headerIdx];
        $colMap = $this->buildColumnMap($headerRow);

        // colonne minime
        $missing = [];
        foreach (['card_no','date','litri','importo'] as $req) {
            if (empty($colMap[$req])) $missing[] = $req;
        }
        if ($missing) {
            return $this->emptyResult('Colonne mancanti: ' . implode(',', $missing) .
                                      '. Trovate: ' . implode(',', array_keys($colMap)));
        }

        // mappe lookup. NON usiamo Plate number per risolvere il veicolo:
        // i driver lo compilano a caso (vedi "JOLLY 2", "1", numeri random).
        // Il match veicolo passa via Card No. → bb_fleet_fuel_card_assignments
        // a runtime nell'engine di riconciliazione. Qui salviamo solo il card_id.
        $cardMap = $this->loadCardMap();

        $imported = 0; $skipped = 0; $total = 0;
        $errors = [];
        $minDate = null; $maxDate = null;

        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO bb_fleet_fuel_tx (
                import_id, card_numero, plate_alias_q8, card_id, vehicle_id_at_tx,
                tx_at, importo, litri, prezzo_unit, carburante,
                distributore, city, prov, km_dichiarati, raw_hash
            ) VALUES (
                :import_id, :card_no, :plate_alias, :card_id, :vehicle_id,
                :tx_at, :importo, :litri, :prezzo, :carb,
                :distrib, :city, :prov, :km, :raw_hash
            )
        ");

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $idx => $r) {
                if ($idx <= $headerIdx) continue;
                $total++;

                // Card identifier: usiamo il PAN (lungo, univoco per carta fisica).
                // "Card No." e' un alias breve riassegnabile (Q8 lo riusa quando una
                // carta scade), il PAN no. Fallback a Card No. se PAN manca.
                $cardPan = $this->str($r, $colMap, 'card_pan');
                $cardNo  = $this->str($r, $colMap, 'card_no');
                if ($cardPan === '' && $cardNo === '') { $skipped++; continue; }
                $cardKey = $this->normalizeCardNumber($cardPan ?: $cardNo);

                $txAt = $this->parseDateTime($this->get($r, $colMap, 'date'));
                if (!$txAt) { $skipped++; continue; }

                $litri = $this->num($r, $colMap, 'litri') ?? 0;
                if ($litri <= 0) { $skipped++; continue; }

                $importo = $this->num($r, $colMap, 'importo') ?? 0;
                $prezzo  = $this->num($r, $colMap, 'prezzo_disc')
                        ?? $this->num($r, $colMap, 'prezzo')
                        ?? ($importo > 0 ? round($importo / $litri, 3) : null);

                // Salviamo l'alias raw solo per diagnostica (vederlo nella lista);
                // non lo usiamo per il match veicolo.
                $plateAlias = $this->str($r, $colMap, 'plate') ?: null;
                $vehicleId  = null;

                $distribCode = $this->str($r, $colMap, 'distrib_code');
                $distribAddr = $this->str($r, $colMap, 'distrib_addr');
                $distrib = trim(($distribCode ? "PV {$distribCode} · " : '') . $distribAddr) ?: null;

                $city = $this->str($r, $colMap, 'city');
                $carb = $this->str($r, $colMap, 'carb');
                $km   = $this->num($r, $colMap, 'km');

                // resolve card_id: primo tentativo match esatto col PAN,
                // se fallisce e abbiamo un Card No. corto, ritenta su quello.
                $cardId = $cardMap[$cardKey] ?? null;
                if (!$cardId && $cardNo !== '') {
                    $cardId = $cardMap[$this->normalizeCardNumber($cardNo)] ?? null;
                }

                $rawHash = sha1(($cardPan ?: $cardNo) . '|' . $txAt . '|' . $litri . '|' . $importo);

                try {
                    $stmt->execute([
                        ':import_id'   => $importId,
                        ':card_no'     => $cardKey,
                        ':plate_alias' => $plateAlias,
                        ':card_id'     => $cardId,
                        ':vehicle_id'  => $vehicleId,
                        ':tx_at'       => $txAt,
                        ':importo'     => $importo,
                        ':litri'       => $litri,
                        ':prezzo'      => $prezzo,
                        ':carb'        => $carb ?: null,
                        ':distrib'     => $distrib ? mb_substr($distrib, 0, 255) : null,
                        ':city'        => $city ? ucfirst(mb_strtolower($city)) : null,
                        ':prov'        => null, // Q8 non fornisce sigla provincia
                        // Km dichiarato dal driver — inaffidabile (vedi "1", "12345").
                        // Lo salviamo solo come dato grezzo, NON viene usato per
                        // anomalie di consumo (per quello il GPS km_done e' la verita').
                        ':km'          => ($km !== null && $km > 0) ? (int)$km : null,
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

    // Alias header: chiave logica → array di varianti accettate.
    // Match case-insensitive, su uguaglianza o substring (per esports diversi).
    private const HEADER_ALIASES = [
        'card_no'      => ['card no.', 'card no', 'numero carta', 'numero card'],
        'card_pan'     => ['card pan', 'pan'],
        'date'         => ['date', 'data'],
        'time'         => ['time'],
        'carb'         => ['prod.', 'prodotto', 'product', 'carburante'],
        'plate'        => ['plate number', 'plate', 'targa'],
        'km'           => ['km'],
        'distrib_code' => ['petrol station', 'cod. impianto', 'codice pv'],
        'distrib_addr' => ['address', 'indirizzo'],
        'city'         => ['city', 'citta', 'localita'],
        'importo'      => ['full amount', 'importo lordo', 'importo totale', 'importo'],
        'litri'        => ['volume', 'litri', 'quantita'],
        'prezzo'       => ['prezzo eur/l', 'prezzo eur', 'prezzo unitario'],
        'prezzo_disc'  => ['discounted price', 'prezzo scontato'],
    ];

    private function buildColumnMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $col => $val) {
            if (!is_string($val)) continue;
            $low = mb_strtolower(trim($val));
            foreach (self::HEADER_ALIASES as $key => $aliases) {
                if (isset($map[$key])) continue;
                foreach ($aliases as $a) {
                    // match esatto OR contains-as-word (per evitare collisioni
                    // tra "Volume" e "Full amount, no VAT" che e' diverso)
                    if ($low === $a || str_contains($low, $a)) {
                        $map[$key] = $col;
                        break;
                    }
                }
            }
        }
        return $map;
    }

    // ─── Cell readers ────────────────────────────────────────────────────────

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
        $s = (string)$v;
        $s = preg_replace('/[^\d.,\-]/', '', $s);
        // formato italiano "1.234,56"
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    }

    private function parseDateTime($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            $unix = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$v);
            return date('Y-m-d H:i:s', $unix);
        }
        $s = trim((string)$v);
        $ts = strtotime($s);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Normalizza il PAN/Card No.
     *  - togli spazi, apostrofi (anti-cast Excel), trattini
     *  - i PAN sono numerici → strip tutto il resto
     *  - mantiene zeri leading (significativi)
     */
    private function normalizeCardNumber(string $raw): string
    {
        $s = preg_replace('/[\s\'\-]/', '', $raw);
        // Se il valore e' interamente numerico (o numerico con leading zeros),
        // tieni solo le cifre. Altrimenti lascia stare (potrebbe essere un
        // identificativo alfanumerico custom).
        if (preg_match('/^\d+$/', $s)) {
            return $s;
        }
        return $s;
    }

    // ─── Lookups ─────────────────────────────────────────────────────────────

    /**
     * @return array<string,int>  card_pan_normalized → bb_fleet_fuel_cards.id
     *
     * Match a doppia chiave: il numero che l'utente ha registrato in BOB
     * potrebbe essere il PAN completo OPPURE il Card No. corto.
     * Indicizziamo entrambe le possibili lookup key cosi' il match funziona
     * comunque, evitando di forzare l'utente a riscrivere tutte le carte.
     */
    private function loadCardMap(): array
    {
        $stmt = $this->conn->query("SELECT id, numero FROM bb_fleet_fuel_cards");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $this->normalizeCardNumber((string)$r['numero']);
            if ($key !== '') {
                $map[$key] = (int)$r['id'];
            }
        }
        return $map;
    }

    private function emptyResult(string $err): array
    {
        return ['rows_total'=>0,'rows_imported'=>0,'rows_skipped'=>0,
                'errors'=>[$err], 'period_from'=>null, 'period_to'=>null];
    }
}
