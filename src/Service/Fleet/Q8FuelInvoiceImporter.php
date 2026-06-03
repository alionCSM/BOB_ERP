<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importer fattura Q8 "Q8 Fleet transactions".
 *
 * IMPORTANTE — perche' usiamo Card No. e NON Card PAN come chiave primaria:
 *
 *   Il Card PAN e' un intero a 19 cifre (es. 7028012731700025018).
 *   Excel lo salva come numerico, PhpSpreadsheet lo legge come float PHP,
 *   e i float PHP hanno solo ~15.95 cifre di precisione → le ultime
 *   3-4 cifre vengono perse e il match diventa impossibile.
 *
 *   Il Card No. invece e' un intero piccolo (1-7 cifre) → entra
 *   perfettamente nei float, niente precisione persa, match 100% affidabile.
 *
 * Il PAN viene comunque letto e salvato (con la precisione disponibile)
 * per display e per il dedup hash. Le carte in BOB hanno due colonne:
 *   - card_no  (chiave matching, breve)
 *   - pan      (display + dedup)
 *
 * Auto-stub: se $autoCreateStubs e' true, le carte sconosciute vengono
 * inserite automaticamente in bb_fleet_fuel_cards (status active) cosi'
 * il primo import non genera 82 anomalie "carta non registrata" e
 * l'utente puo' poi assegnarle ai veicoli da UI.
 */
final class Q8FuelInvoiceImporter
{
    public function __construct(private PDO $conn) {}

    /**
     * @return array{rows_total:int, rows_imported:int, rows_skipped:int, errors:array, period_from:?string, period_to:?string, cards_created:int}
     */
    public function import(string $filePath, int $importId, bool $autoCreateStubs = false): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // 1) detect header
        $headerIdx = null;
        foreach (array_slice($rows, 0, 15, true) as $idx => $r) {
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
        $colMap = $this->buildColumnMap($rows[$headerIdx]);

        // 2) colonne minime
        $missing = [];
        foreach (['card_no', 'date', 'litri', 'importo'] as $req) {
            if (empty($colMap[$req])) $missing[] = $req;
        }
        if ($missing) {
            return $this->emptyResult(
                'Colonne mancanti: ' . implode(',', $missing) .
                '. Trovate: ' . implode(',', array_keys($colMap))
            );
        }

        // 3) lookup carte: BOTH card_no e pan (e legacy numero)
        $cardMap = $this->loadCardMap();

        $imported = 0; $skipped = 0; $total = 0; $cardsCreated = 0;
        $errors = [];
        $minDate = null; $maxDate = null;
        $seenCardsThisRun = [];

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

                // Card No. (intero piccolo, esce dalle problematiche di precisione)
                $cardNoRaw = $this->get($r, $colMap, 'card_no');
                if ($cardNoRaw === null || $cardNoRaw === '') { $skipped++; continue; }
                $cardNo = $this->intLikeToString($cardNoRaw);

                // PAN: lo leggiamo MA sappiamo che potrebbe avere precisione persa
                $panRaw = $this->get($r, $colMap, 'card_pan');
                $pan = $panRaw !== null ? $this->intLikeToString($panRaw) : '';

                // resolve card_id: primo tentativo via card_no, poi via pan
                $cardId = $this->resolveCardId($cardMap, $cardNo, $pan);

                // auto-stub
                if (!$cardId && $autoCreateStubs && !isset($seenCardsThisRun[$cardNo])) {
                    $cardId = $this->createStubCard($cardNo, $pan);
                    $cardMap['no:' . $cardNo] = $cardId;
                    if ($pan !== '') $cardMap['pan:' . $pan] = $cardId;
                    $cardsCreated++;
                    $seenCardsThisRun[$cardNo] = true;
                }

                $txAt = $this->parseDateTime($this->get($r, $colMap, 'date'));
                if (!$txAt) { $skipped++; continue; }

                $litri = $this->num($r, $colMap, 'litri') ?? 0;
                if ($litri <= 0) { $skipped++; continue; }

                $importo = $this->num($r, $colMap, 'importo') ?? 0;
                $prezzo  = $this->num($r, $colMap, 'prezzo_disc')
                        ?? $this->num($r, $colMap, 'prezzo')
                        ?? ($importo > 0 ? round($importo / $litri, 3) : null);

                $plateAlias = $this->str($r, $colMap, 'plate') ?: null;
                $distribCode = $this->str($r, $colMap, 'distrib_code');
                $distribAddr = $this->str($r, $colMap, 'distrib_addr');
                $distrib = trim(($distribCode ? "PV {$distribCode} · " : '') . $distribAddr) ?: null;
                $city = $this->str($r, $colMap, 'city');
                $carb = $this->str($r, $colMap, 'carb');
                $km   = $this->num($r, $colMap, 'km');

                // dedup hash: usa pan se disponibile (piu' preciso), altrimenti card_no
                $hashKey = $pan !== '' ? $pan : $cardNo;
                $rawHash = sha1($hashKey . '|' . $txAt . '|' . $litri . '|' . $importo);

                try {
                    $stmt->execute([
                        ':import_id'   => $importId,
                        ':card_no'     => $cardNo,
                        ':plate_alias' => $plateAlias,
                        ':card_id'     => $cardId,
                        ':vehicle_id'  => null,   // non risolviamo da Plate number, sempre via fca runtime
                        ':tx_at'       => $txAt,
                        ':importo'     => $importo,
                        ':litri'       => $litri,
                        ':prezzo'      => $prezzo,
                        ':carb'        => $carb ?: null,
                        ':distrib'     => $distrib ? mb_substr($distrib, 0, 255) : null,
                        ':city'        => $city ? ucfirst(mb_strtolower($city)) : null,
                        ':prov'        => null,
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
            'cards_created' => $cardsCreated,
        ];
    }

    // ─── Header mapping ───────────────────────────────────────────────────────

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
                // priorita' alias: uguaglianza esatta vince su substring
                foreach ($aliases as $a) {
                    if ($low === $a) { $map[$key] = $col; break 2; }
                }
            }
        }
        // fallback substring per header con suffissi/varianti
        foreach ($headerRow as $col => $val) {
            if (!is_string($val)) continue;
            $low = mb_strtolower(trim($val));
            foreach (self::HEADER_ALIASES as $key => $aliases) {
                if (isset($map[$key])) continue;
                foreach ($aliases as $a) {
                    if (str_contains($low, $a)) { $map[$key] = $col; break; }
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
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    }

    /**
     * Converte un valore Excel che dovrebbe essere un intero in una stringa
     * di sole cifre. Tollera:
     *   - int     "25"                       → "25"
     *   - float   25.0                       → "25"
     *   - float   7.0280127317E+18 (perso!)  → "7028012731700025024" (warning loss)
     *   - string  "00025"                    → "00025" → trim zeros → "25"
     *   - string  "7028012731700025018"      → "7028012731700025018" (preciso!)
     */
    private function intLikeToString($v): string
    {
        if ($v === null) return '';
        if (is_int($v))   return (string)$v;
        if (is_float($v)) return sprintf('%.0F', $v);   // niente scientific
        $s = trim((string)$v);
        // a volte Excel mette apostrofo per forzare stringa: "'00025"
        $s = preg_replace("/[\s'\-]/", '', $s);
        return $s;
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

    // ─── Card matching ───────────────────────────────────────────────────────

    /**
     * cardMap ha keys multiple per ogni carta:
     *   - "no:<normalized_card_no>"   → id
     *   - "pan:<normalized_pan>"      → id
     *   - "legacy:<normalized_numero>" → id  (per chi ha registrato pre-split)
     */
    private function loadCardMap(): array
    {
        $stmt = $this->conn->query("
            SELECT id,
                   COALESCE(card_no, '') AS card_no,
                   COALESCE(pan, '')     AS pan,
                   COALESCE(numero, '')  AS numero
            FROM bb_fleet_fuel_cards
        ");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            if ($r['card_no'] !== '') {
                $map['no:' . $this->normalize($r['card_no'])] = $id;
            }
            if ($r['pan'] !== '') {
                $map['pan:' . $this->normalize($r['pan'])] = $id;
            }
            if ($r['numero'] !== '') {
                $n = $this->normalize($r['numero']);
                $map['legacy:' . $n] = $id;
                // doppia indicizzazione: se "numero" e' lungo, indicizza anche come pan
                if (strlen($n) >= 13) $map['pan:' . $n] = $id;
                // e anche come no
                if (strlen($n) <= 9)  $map['no:'  . ltrim($n, '0') ?: '0'] = $id;
            }
        }
        return $map;
    }

    /**
     * Tenta nell'ordine:
     *   1) match esatto via card_no (chiave primaria, sempre affidabile)
     *   2) match esatto via PAN (potrebbe fallire per precisione persa)
     *   3) legacy: campo "numero" pre-split
     */
    private function resolveCardId(array $cardMap, string $cardNo, string $pan): ?int
    {
        $cardNoKey = ltrim($this->normalize($cardNo), '0') ?: '0';

        // 1) card_no diretto
        if (isset($cardMap['no:' . $cardNoKey])) return $cardMap['no:' . $cardNoKey];

        // 2) PAN diretto (se preciso)
        if ($pan !== '') {
            $panKey = $this->normalize($pan);
            if (isset($cardMap['pan:' . $panKey])) return $cardMap['pan:' . $panKey];
        }

        // 3) legacy "numero" (utenti che hanno registrato prima del split)
        if (isset($cardMap['legacy:' . $cardNoKey])) return $cardMap['legacy:' . $cardNoKey];
        if ($pan !== '') {
            $panKey = $this->normalize($pan);
            if (isset($cardMap['legacy:' . $panKey])) return $cardMap['legacy:' . $panKey];
        }

        return null;
    }

    private function normalize(string $raw): string
    {
        return preg_replace('/[^0-9]/', '', $raw);
    }

    private function createStubCard(string $cardNo, string $pan): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_fleet_fuel_cards (numero, card_no, pan, fornitore, notes, active)
            VALUES (?, ?, ?, 'Q8', ?, 1)
        ");
        $note = 'Auto-creata da import Q8 il ' . date('d/m/Y H:i') . ' — assegnala a un veicolo';
        $stmt->execute([
            $cardNo,                       // legacy "numero" = card_no per compat
            ltrim($cardNo, '0') ?: '0',
            $pan ?: null,
            $note,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    private function emptyResult(string $err): array
    {
        return ['rows_total'=>0, 'rows_imported'=>0, 'rows_skipped'=>0,
                'errors'=>[$err], 'period_from'=>null, 'period_to'=>null,
                'cards_created'=>0];
    }
}
