<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/middleware.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ------------------------------------------------------
// Input validation
// ------------------------------------------------------
$cantiereId = isset($_GET['cantiere_id']) ? (int)$_GET['cantiere_id'] : 0;
$startDate  = $_GET['start_date'] ?? '';
$endDate    = $_GET['end_date'] ?? '';

if ($cantiereId <= 0) {
    http_response_code(400);
    exit('Cantiere non valido');
}

if ($startDate === '' || $endDate === '') {
    http_response_code(400);
    exit('Devi specificare data inizio e data fine');
}

// ------------------------------------------------------
// DB
// ------------------------------------------------------
$db   = new Database();
$conn = $db->connect();

// ------------------------------------------------------
// Load Cantiere info
// ------------------------------------------------------
$stmt = $conn->prepare("
    SELECT worksite_code, name
    FROM bb_worksites
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $cantiereId]);

$worksite = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worksite) {
    http_response_code(404);
    exit('Cantiere non trovato');
}

$worksiteLabel = $worksite['worksite_code'] . ' - ' . $worksite['name'];

// ------------------------------------------------------
// QUERY — NOSTRI
// ------------------------------------------------------
$sqlNostri = "
SELECT
    p.data,
    GROUP_CONCAT(
        DISTINCT UPPER(CONCAT(w.last_name, ' ', w.first_name))
        ORDER BY w.last_name, w.first_name
        SEPARATOR ', '
    ) AS operai,
    COUNT(DISTINCT p.worker_id) AS numero_operai,
    SUM(
        CASE
            WHEN p.turno = 'Intero' THEN 1
            WHEN p.turno = 'Mezzo' THEN 0.5
            ELSE 0
        END
    ) AS presenze
FROM bb_presenze p
JOIN bb_workers w ON w.id = p.worker_id
WHERE p.worksite_id = :cantiere_id
  AND p.data BETWEEN :start_date AND :end_date
GROUP BY p.data
ORDER BY p.data
";

$stmt = $conn->prepare($sqlNostri);
$stmt->execute([
    ':cantiere_id' => $cantiereId,
    ':start_date'  => $startDate,
    ':end_date'    => $endDate,
]);
$nostriRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------
// QUERY — CONSORZIATE
// ------------------------------------------------------
$sqlCons = "
SELECT
    pc.data_presenza,
    c.name AS consorziata,
    SUM(pc.quantita) AS quantita,
    SUM(pc.quantita * pc.costo_unitario) AS costo_manodopera
FROM bb_presenze_consorziate pc
JOIN bb_companies c ON c.id = pc.azienda_id
WHERE pc.worksite_id = :cantiere_id
  AND pc.data_presenza BETWEEN :start_date AND :end_date
GROUP BY pc.data_presenza, c.name
ORDER BY pc.data_presenza, c.name
";

$stmt = $conn->prepare($sqlCons);
$stmt->execute([
    ':cantiere_id' => $cantiereId,
    ':start_date'  => $startDate,
    ':end_date'    => $endDate,
]);
$consRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------
// EXCEL
// ------------------------------------------------------
$spreadsheet = new Spreadsheet();

/* ======================================================
   SHEET 1 — PRESENZE NOSTRI
====================================================== */
$sheetNostri = $spreadsheet->getActiveSheet();
$sheetNostri->setTitle('Presenze Nostri');

// Cantiere label
$sheetNostri->setCellValue('A1', 'CANTIERE: ' . $worksiteLabel);
$sheetNostri->mergeCells('A1:D1');
$sheetNostri->getStyle('A1')->getFont()->setBold(true);

// Header (row 2)
$sheetNostri->fromArray(
    ['Data', 'Operai', 'Numero Operai', 'Presenze'],
    null,
    'A2'
);

// Header style
$sheetNostri->getStyle('A2:D2')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FF0070C0'],
    ],
]);

// Enable filters
$sheetNostri->setAutoFilter('A2:D2');

// Data (start row 3)
$row = 3;
foreach ($nostriRows as $r) {
    $sheetNostri->setCellValue("A{$row}", date('d/m/Y', strtotime($r['data'])));
    $sheetNostri->setCellValue("B{$row}", $r['operai']);
    $sheetNostri->setCellValue("C{$row}", (int)$r['numero_operai']);
    $sheetNostri->setCellValue("D{$row}", (float)$r['presenze']);
    $row++;
}

// Totals row
$sheetNostri->setCellValue("B{$row}", 'TOTALE');
$sheetNostri->setCellValue("C{$row}", "=SUM(C3:C" . ($row - 1) . ")");
$sheetNostri->setCellValue("D{$row}", "=SUM(D3:D" . ($row - 1) . ")");
$sheetNostri->getStyle("B{$row}:D{$row}")->getFont()->setBold(true);

// Formatting
foreach (range('A', 'D') as $col) {
    $sheetNostri->getColumnDimension($col)->setAutoSize(true);
}
$sheetNostri->getStyle("C3:D{$row}")
    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

/* ======================================================
   SHEET 2 — PRESENZE CONSORZIATE
====================================================== */
$sheetCons = $spreadsheet->createSheet();
$sheetCons->setTitle('Presenze Consorziate');

// Cantiere label
$sheetCons->setCellValue('A1', 'CANTIERE: ' . $worksiteLabel);
$sheetCons->mergeCells('A1:D1');
$sheetCons->getStyle('A1')->getFont()->setBold(true);

// Header (row 2)
$sheetCons->fromArray(
    ['Data', 'Consorziata', 'Quantità', 'Costo Manodopera'],
    null,
    'A2'
);

// Header style
$sheetCons->getStyle('A2:D2')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FF0070C0'],
    ],
]);

// Enable filters
$sheetCons->setAutoFilter('A2:D2');

// Data (start row 3)
$row = 3;
foreach ($consRows as $r) {
    $sheetCons->setCellValue("A{$row}", date('d/m/Y', strtotime($r['data_presenza'])));
    $sheetCons->setCellValue("B{$row}", $r['consorziata']);
    $sheetCons->setCellValue("C{$row}", (float)$r['quantita']);
    $sheetCons->setCellValue("D{$row}", (float)$r['costo_manodopera']);
    $row++;
}

// Totals row
$sheetCons->setCellValue("B{$row}", 'TOTALE');
$sheetCons->setCellValue("C{$row}", "=SUM(C3:C" . ($row - 1) . ")");
$sheetCons->setCellValue("D{$row}", "=SUM(D3:D" . ($row - 1) . ")");
$sheetCons->getStyle("B{$row}:D{$row}")->getFont()->setBold(true);

// Formatting
foreach (range('A', 'D') as $col) {
    $sheetCons->getColumnDimension($col)->setAutoSize(true);
}
$sheetCons->getStyle("D3:D{$row}")
    ->getNumberFormat()->setFormatCode('#,##0.00');
$sheetCons->getStyle("C3:D{$row}")
    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ------------------------------------------------------
// OUTPUT
// ------------------------------------------------------
$filename = 'Presenze_Cantiere_' . $worksite['worksite_code'] . '_' . $startDate . '_' . $endDate . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
