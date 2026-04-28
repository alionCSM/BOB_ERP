<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// --------------------------------------------------
// Bootstrap (middleware already ran, just get globals)
// --------------------------------------------------
$db   = new Database();
$conn = $db->connect();

$user = $GLOBALS['user'] ?? null;

if (!$user) {
    http_response_code(403);
    exit;
}

$companyId = $user->getCompanyId();

// --------------------------------------------------
// Input
// --------------------------------------------------
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// --------------------------------------------------
// Data
// --------------------------------------------------
$billing = new Billing($conn);
$rows = $billing->getMovedWorksitesWithBilling($companyId, $year, $month);

// --------------------------------------------------
// Spreadsheet
// --------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cantieri movimentati');

// Header
$headers = [
    'A1' => 'Ragione Sociale',
    'B1' => 'Cantiere',
    'C1' => 'Ordine',
    'D1' => 'Residuo (€)',
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// Header style
$sheet->getStyle('A1:D1')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FF0070C0'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
]);

// Rows
$rowNum = 2;
foreach ($rows as $row) {
    $sheet->setCellValue("A{$rowNum}", $row['cliente']);
    $sheet->setCellValue("B{$rowNum}", $row['name']);
    $sheet->setCellValue("C{$rowNum}", $row['order_number'] ?: '-');
    $sheet->setCellValue("D{$rowNum}", (float)$row['residuo']);
    $rowNum++;
}

// Format €
$sheet->getStyle("D2:D{$rowNum}")
    ->getNumberFormat()
    ->setFormatCode('€ #,##0.00');

// Autosize
foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --------------------------------------------------
// Output
// --------------------------------------------------
$filename = sprintf(
    'cantieri_movimentati_%04d_%02d.xlsx',
    $year,
    $month
);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
