<?php
declare(strict_types=1);

/**
 * Export ZIP: cartelle per azienda (solo interne, consorziata=0),
 * dentro un Excel per ogni operaio.
 *
 * Regole identiche al tuo export singolo:
 * - Intero=1, Mezzo=0.5, max 1 al giorno
 * - Pasti conteggiati SOLO se "Loro" (pranzo/cena)
 * - Domenica / No lavoro / Viaggio in rosso
 *
 * Nota tecnica (voluta):
 * - L'azienda NON viene letta da bb_workers (evita errori se cambia azienda).
 * - L'azienda viene determinata da bb_presenze.azienda (snapshot storico).
 */

// bootstrap + middleware already loaded by index.php
require_once APP_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// --------------------------------------------------
// Connessione
// --------------------------------------------------
$db   = new Database();
$conn = $db->connect();

// --------------------------------------------------
// Input (mandatory)
// --------------------------------------------------
$startDate = $_GET['start_date'] ?? null;
$endDate   = $_GET['end_date'] ?? null;

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti: start_date / end_date obbligatori.']);
    exit;
}

// Validazione base date (non faccio magie)
try {
    $dtStart = new DateTime($startDate);
    $dtEnd   = new DateTime($endDate);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Date non valide.']);
    exit;
}

if ($dtStart > $dtEnd) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Intervallo date non valido: start_date > end_date.']);
    exit;
}

// --------------------------------------------------
// Helper: sanitizzazione nomi file/cartelle
// --------------------------------------------------
function safeName(string $name, int $maxLen = 120): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\\:\*\?\"\<\>\|]+/', '-', $name) ?? $name; // niente caratteri illegali
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '') $name = 'SENZA_NOME';
    if (mb_strlen($name) > $maxLen) {
        $name = mb_substr($name, 0, $maxLen);
    }
    return $name;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// --------------------------------------------------
// 1) Elenco (azienda, worker) SOLO INTERNE
//    => join bb_companies (consorziata=0) su p.azienda
// --------------------------------------------------
$stmtPairs = $conn->prepare("
    SELECT DISTINCT
        p.azienda,
        p.worker_id,
        w.first_name,
        w.last_name
    FROM bb_presenze p
    INNER JOIN bb_companies c
        ON c.name = p.azienda
       AND c.consorziata = 0
    INNER JOIN bb_workers w
        ON w.id = p.worker_id
    WHERE p.data BETWEEN :start AND :end
      AND p.azienda IS NOT NULL
      AND p.azienda <> ''
    ORDER BY p.azienda, w.last_name, w.first_name
");

$stmtPairs->execute([
    ':start' => $dtStart->format('Y-m-d'),
    ':end'   => $dtEnd->format('Y-m-d'),
]);

$pairs = $stmtPairs->fetchAll(PDO::FETCH_ASSOC);

if (!$pairs) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Nessuna presenza trovata per aziende interne nell'intervallo selezionato."]);
    exit;
}

// --------------------------------------------------
// 2) Preparazione temp + ZIP
// --------------------------------------------------
$tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
$tmpDir  = $tmpBase . DIRECTORY_SEPARATOR . 'bob_bulk_operai_' . bin2hex(random_bytes(6));
@mkdir($tmpDir, 0775, true);

$zipName = "presenze_operai_" . $dtStart->format('Y-m-d') . "_" . $dtEnd->format('Y-m-d') . ".zip";
$zipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    rrmdir($tmpDir);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Impossibile creare lo ZIP.']);
    exit;
}

// --------------------------------------------------
// 3) Query presenze per (azienda, worker) e creazione Excel
// --------------------------------------------------
$stmtRows = $conn->prepare("
   SELECT
    p.data,
    p.turno,
    p.pranzo,
    p.cena,
    p.note,
    ws.worksite_code,
    ws.name,
    ws.`location` AS worksite_location
FROM bb_presenze p
INNER JOIN bb_companies c
    ON c.name = p.azienda
   AND c.consorziata = 0
INNER JOIN bb_worksites ws
    ON ws.id = p.worksite_id
WHERE p.worker_id = :worker
  AND p.azienda = :azienda
  AND p.data BETWEEN :start AND :end
ORDER BY p.data ASC

");

foreach ($pairs as $pair) {

    $azienda   = (string)$pair['azienda'];
    $workerId  = (int)$pair['worker_id'];
    $firstName = (string)$pair['first_name'];
    $lastName  = (string)$pair['last_name'];
    $workerName = trim($lastName . ' ' . $firstName);

    // --- carica righe per questa coppia (azienda, operaio)
    $stmtRows->execute([
        ':worker' => $workerId,
        ':azienda'=> $azienda,
        ':start'  => $dtStart->format('Y-m-d'),
        ':end'    => $dtEnd->format('Y-m-d'),
    ]);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        // niente presenze, salto (coerente con "solo interne" + coppia distinta)
        continue;
    }

    // Raggruppo per data (stesso schema del file singolo)
    $byDate = [];
    foreach ($rows as $r) {
        $d = (string)$r['data'];
        if (!isset($byDate[$d])) $byDate[$d] = [];
        $byDate[$d][] = $r;
    }

    // --- Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Presenze Operaio");

    $headers = ["Data", "Nome Cantiere", "Operatore", "Durata", "Pasti Loro"];
    $sheet->fromArray($headers, null, 'A1');

    $sheet->getStyle('A1:E1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_WHITE]],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FF0070C0'],
        ],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['argb' => 'FF000000']
            ]
        ]
    ]);

    $sheet->setAutoFilter('A1:E1');

    // Loop giorno per giorno (date range completo)
    $rowIndex = 2;
    $current  = new DateTime($dtStart->format('Y-m-d'));
    $end      = new DateTime($dtEnd->format('Y-m-d'));

    while ($current <= $end) {

        $dString     = $current->format('Y-m-d');
        $displayDate = $current->format('d/m/Y');
        $isSunday    = $current->format('N') == 7;

        $cantiere = $isSunday ? "DOMENICA" : "NO LAVORO";
        $durata   = 0.0;
        $pasti    = 0;
        $viaggio  = false;

        if (isset($byDate[$dString])) {

            $cNames          = [];
            $dayDurata       = 0.0;
            $dayPastiPranzo  = 0;
            $dayPastiCena    = 0;

            foreach ($byDate[$dString] as $p) {

                $label = (string)$p['worksite_code'] . " - " . (string)$p['name'];

                $location = trim((string)($p['worksite_location'] ?? ''));
                if ($location !== '') {
                    $label .= " (" . $location . ")";
                }

                $cNames[] = $label;

                if (($p['turno'] ?? '') === "Intero") {
                    $dayDurata += 1;
                } elseif (($p['turno'] ?? '') === "Mezzo") {
                    $dayDurata += 0.5;
                }

                if (($p['pranzo'] ?? '') === "Loro") $dayPastiPranzo++;
                if (($p['cena'] ?? '') === "Loro")   $dayPastiCena++;

                // Rilevo "viaggio" dalla nota (identico al tuo file)
                $note = (string)($p['note'] ?? '');
                if ($note !== '' && stripos($note, "viaggio") !== false) {
                    $cantiere = $note;
                    $viaggio  = true;
                }
            }

            if (!$viaggio) {
                $cantiere = implode(", ", array_unique($cNames));
            }

            $durata = min($dayDurata, 1.0);
            $pasti  = $dayPastiPranzo + $dayPastiCena;
        }

        $sheet->setCellValue("A{$rowIndex}", $displayDate);
        $sheet->setCellValue("B{$rowIndex}", $cantiere);
        $sheet->setCellValue("C{$rowIndex}", $workerName);
        $sheet->setCellValue("D{$rowIndex}", $durata);
        $sheet->setCellValue("E{$rowIndex}", $pasti);

        $sheet->getStyle("C{$rowIndex}")->getFont()->setBold(true);

        if ($cantiere === "DOMENICA" || $cantiere === "NO LAVORO" || $viaggio) {
            $sheet->getStyle("B{$rowIndex}")->getFont()->getColor()->setARGB(Color::COLOR_RED);
        }

        $sheet->getStyle("A{$rowIndex}:E{$rowIndex}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => 'FF000000']
                ]
            ]
        ]);

        $rowIndex++;
        $current->modify('+1 day');
    }

    // Totali (Excel calcola)
    $sheet->setCellValue("D{$rowIndex}", "=SUM(D2:D" . ($rowIndex - 1) . ")");
    $sheet->setCellValue("E{$rowIndex}", "=SUM(E2:E" . ($rowIndex - 1) . ")");

    $sheet->getStyle("D{$rowIndex}:E{$rowIndex}")->applyFromArray([
        'font' => ['bold' => true],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_MEDIUM,
                'color'       => ['argb' => 'FF000000']
            ]
        ]
    ]);

    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // --- path file dentro ZIP
    $aziendaFolder = safeName($azienda);
    $fileBase = safeName($lastName . "_" . $firstName . "_presenze_" . $dtStart->format('Y-m-d') . "_" . $dtEnd->format('Y-m-d'));
    $excelRelPath = $aziendaFolder . "/" . $fileBase . ".xlsx";

    // --- salva su temp file e aggiungi allo zip
    $tmpXlsx = $tmpDir . DIRECTORY_SEPARATOR . 'tmp_' . bin2hex(random_bytes(6)) . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($tmpXlsx);

    $zip->addFile($tmpXlsx, $excelRelPath);

    // Chiudo e libero memoria pesante
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
}

$zip->close();

// --------------------------------------------------
// 4) Download ZIP
// --------------------------------------------------
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);

// Cleanup
rrmdir($tmpDir);
exit;
