<?php
// Esporta Excel per un operaio utilizzando SOLO BOB (MySQL)
// Durata: Intero = 1, Mezzo = 0.5 (max 1 al giorno)
// Pasti: conteggiati solo se "Loro" (pranzo/cena)

// bootstrap + middleware already loaded by index.php
require_once APP_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Connessione MySQL
$db = new Database();
$conn = $db->connect();

// Recupero parametri dal modal
$workerId  = $_GET['worker_id'] ?? null;
$startDate = $_GET['start_date'] ?? null;
$endDate   = $_GET['end_date'] ?? null;

if (!$workerId || !$startDate || !$endDate) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti.']);
    exit;
}

// ===================================================================================
// 1) Nome operaio
// ===================================================================================
$stmtWorker = $conn->prepare("SELECT CONCAT(last_name, ' ', first_name) AS nome FROM bb_workers WHERE id = ?");
$stmtWorker->execute([$workerId]);
$workerName = $stmtWorker->fetchColumn();

if (!$workerName) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Operaio non trovato.']);
    exit;
}

// ===================================================================================
// 2) Presenze per intervallo date
// ===================================================================================
$query = "
    SELECT 
        p.data,
        p.turno,
        p.pranzo,
        p.cena,
        p.note,
        w.worksite_code,
        w.name
    FROM bb_presenze p
    INNER JOIN bb_worksites w ON w.id = p.worksite_id
    WHERE p.worker_id = :worker
      AND p.data BETWEEN :start AND :end
    ORDER BY p.data
";

$stmt = $conn->prepare($query);
$stmt->execute([
    ':worker' => $workerId,
    ':start'  => $startDate,
    ':end'    => $endDate,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Raggruppo le presenze per data
$byDate = [];
foreach ($rows as $r) {
    $d = $r['data']; // formato Y-m-d
    if (!isset($byDate[$d])) $byDate[$d] = [];
    $byDate[$d][] = $r;
}

// ===================================================================================
// 3) Preparazione foglio Excel
// ===================================================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Presenze Operaio");

// intestazioni (Pasti = solo LORO)
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

// ===================================================================================
// 4) Loop giorno per giorno
// ===================================================================================
$row = 2;
$current = new DateTime($startDate);
$end     = new DateTime($endDate);

while ($current <= $end) {

    $dString     = $current->format('Y-m-d');
    $displayDate = $current->format('d/m/Y');
    $isSunday    = $current->format('N') == 7;

    $cantiere = $isSunday ? "DOMENICA" : "NO LAVORO";
    $durata   = 0;
    $pasti    = 0; // totale pranzi LORO + cene LORO
    $viaggio  = false;

    if (isset($byDate[$dString])) {

        $cNames          = [];
        $dayDurata       = 0.0;
        $dayPastiPranzo  = 0;
        $dayPastiCena    = 0;

        foreach ($byDate[$dString] as $p) {

            // Nome cantiere: codice + nome
            $cNames[] = $p['worksite_code'] . " - " . $p['name'];

            // Turno → durata (Intero = 1, Mezzo = 0.5)
            if ($p['turno'] === "Intero") {
                $dayDurata += 1;
            } elseif ($p['turno'] === "Mezzo") {
                $dayDurata += 0.5;
            }

            // Pasti: conteggio SOLO se "Loro"
            if ($p['pranzo'] === "Loro") $dayPastiPranzo++;
            if ($p['cena'] === "Loro")   $dayPastiCena++;

            // Note viaggio
            if (!empty($p['note']) && stripos($p['note'], "viaggio") !== false) {
                $cantiere = $p['note'];
                $viaggio  = true;
            }
        }

        // Se non è un giorno “viaggio” uso l’elenco cantieri
        if (!$viaggio) {
            $cantiere = implode(", ", array_unique($cNames));
        }

        // Durata max 1 al giorno
        $durata = min($dayDurata, 1);

        // Pasti = pranzi Loro + cene Loro
        $pasti = $dayPastiPranzo + $dayPastiCena;
    }

    // Scrittura riga
    $sheet->setCellValue("A$row", $displayDate);
    $sheet->setCellValue("B$row", $cantiere);
    $sheet->setCellValue("C$row", $workerName);
    $sheet->setCellValue("D$row", $durata);
    $sheet->setCellValue("E$row", $pasti);

    // Nome operaio in bold
    $sheet->getStyle("C$row")->getFont()->setBold(true);

    // Rosso per DOMENICA / NO LAVORO / viaggio
    if ($cantiere === "DOMENICA" || $cantiere === "NO LAVORO" || $viaggio) {
        $sheet->getStyle("B$row")->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }

    // Bordi riga
    $sheet->getStyle("A$row:E$row")->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['argb' => 'FF000000']
            ]
        ]
    ]);

    $row++;
    $current->modify('+1 day');
}

// ===================================================================================
// 5) Totali in fondo (Excel li calcola, così resti libero di filtrare)
// ===================================================================================
$sheet->setCellValue("D$row", "=SUM(D2:D" . ($row - 1) . ")");
$sheet->setCellValue("E$row", "=SUM(E2:E" . ($row - 1) . ")");

$sheet->getStyle("D$row:E$row")->applyFromArray([
    'font' => ['bold' => true],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color'       => ['argb' => 'FF000000']
        ]
    ]
]);

foreach (range('A','E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ===================================================================================
// 6) Download
// ===================================================================================
$fileName = $workerName . "_presenze.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$fileName}\"");

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
