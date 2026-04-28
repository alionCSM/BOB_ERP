<?php
declare(strict_types=1);

// Export Excel Consorziate:
// 1) Dettaglio: Data, Cantiere, Consorziata, Costo unitario, Quantità, Costo manodopera
// 2) Riepilogo (tipo pivot): Cantiere, Presenze, Costo

// Bootstrap stripped — $conn provided by CompaniesController::render()
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// $conn injected by CompaniesController::exportConsorziata via Response::view()
$companyController = new \App\Service\Companies\CompanyController($conn);

// ----------------------------
// Input (dal modal)
// ----------------------------
$aziendaId  = isset($_GET['azienda']) ? trim((string)$_GET['azienda']) : '';
$startDate  = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
$endDate    = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';

if ($aziendaId === '' || $startDate === '' || $endDate === '') {
    http_response_code(400);
    exit('Parametri mancanti. Seleziona azienda e intervallo date.');
}

$sd = DateTime::createFromFormat('Y-m-d', $startDate);
$ed = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$sd || !$ed) {
    http_response_code(400);
    exit('Formato date non valido. Usa YYYY-MM-DD.');
}
if ($sd > $ed) {
    http_response_code(400);
    exit('Intervallo date non valido: data inizio > data fine.');
}

try {
    $exportData = $companyController->getConsorziataExportData((int)$aziendaId, $startDate, $endDate);
} catch (RuntimeException $e) {
    http_response_code(404);
    exit($e->getMessage());
}

$companyName = $exportData['company_name'];
$rows = $exportData['rows'];
$summaryRows = $exportData['summary_rows'];

$startDateLabel = DateTime::createFromFormat('Y-m-d', $startDate)->format('d/m/y');
$endDateLabel   = DateTime::createFromFormat('Y-m-d', $endDate)->format('d/m/y');

// ----------------------------
// Crea Excel
// ----------------------------
$spreadsheet = new Spreadsheet();

// ============================
// SHEET 1: DETTAGLIO
// ============================
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Dettaglio');

$sheet1->setCellValue('A1', 'PRESENZE AZIENDA - DETTAGLIO');
$sheet1->mergeCells('A1:F1');
$sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet1->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$sheet1->setCellValue('A2', 'Azienda:');
$sheet1->setCellValueExplicit('B2', $companyName, DataType::TYPE_STRING);
$sheet1->setCellValue('D2', 'Periodo:');
$sheet1->setCellValue('E2', $startDate . ' → ' . $endDate);
$sheet1->mergeCells('E2:F2');

$sheet1->getStyle('A2')->getFont()->setBold(true);
$sheet1->getStyle('D2')->getFont()->setBold(true);

// Header tabella
$headers = ['Data', 'Cantiere', 'Azienda', 'Costo unitario', 'Quantità', 'Costo manodopera'];
$sheet1->fromArray($headers, null, 'A4');

$headerStyle = $sheet1->getStyle('A4:F4');
$headerStyle->getFont()->setBold(true);
$headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
$headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0070C0');
$sheet1->setAutoFilter("A4:F4");

$sheet1->freezePane('A5');

// Dati
$rowNum = 5;
foreach ($rows as $r) {
    $cantiereLabel = trim(($r['worksite_code'] ?? '') . ' - ' . ($r['worksite_name'] ?? ''));

    $excelDate = ExcelDate::PHPToExcel(
        new DateTime($r['data_presenza'])
    );

    $sheet1->setCellValue("A{$rowNum}", $excelDate);
    $sheet1->setCellValue("B{$rowNum}", $cantiereLabel);
    $sheet1->setCellValueExplicit("C{$rowNum}", (string)$r['company_name'], DataType::TYPE_STRING);

    $sheet1->setCellValue("D{$rowNum}", (float)($r['costo_unitario'] ?? 0));
    $sheet1->setCellValue("E{$rowNum}", (float)($r['quantita'] ?? 0));
    $sheet1->setCellValue("F{$rowNum}", (float)($r['costo_manodopera'] ?? 0));

    $rowNum++;
}

$lastDataRow = $rowNum - 1;

if ($lastDataRow >= 5) {
    $sheet1->getStyle("A5:A{$lastDataRow}")->getNumberFormat()->setFormatCode('dd/mm/yy');
    $sheet1->getStyle("D5:D{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet1->getStyle("E5:E{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet1->getStyle("F5:F{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');

    // Totale
    $totRow = $lastDataRow + 2;
    $sheet1->setCellValue("D{$totRow}", 'Totale Presenze:');
    $sheet1->setCellValue("E{$totRow}", "=SUBTOTAL(109,E5:E{$lastDataRow})");
    $sheet1->getStyle("D{$totRow}:E{$totRow}")->getFont()->setBold(true);
    $sheet1->getStyle("E{$totRow}")->getNumberFormat()->setFormatCode('#,##0.00');

}

// Autosize
foreach (range('A', 'F') as $col) {
    $sheet1->getColumnDimension($col)->setAutoSize(true);
}

// ============================
// SHEET 2: RIEPILOGO
// ============================
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Riepilogo');

$sheet2->setCellValue('A1', 'PRESENZE AZIENDA - RIEPILOGO PER CANTIERE');
$sheet2->mergeCells('A1:C1');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(14);

$sheet2->setCellValue('A2', 'Azienda:');
$sheet2->setCellValueExplicit('B2', $companyName, DataType::TYPE_STRING);
$sheet2->setCellValue('A3', 'Periodo:');
$sheet2->setCellValue('B3', $startDateLabel . ' → ' . $endDateLabel);
$sheet2->mergeCells('B3:C3');

$sheet2->getStyle('A2:A3')->getFont()->setBold(true);

$sheet2->fromArray(['Cantiere', 'Presenze', 'Costo'], null, 'A5');

$header2Style = $sheet2->getStyle('A5:C5');
$header2Style->getFont()->setBold(true);
$header2Style->getFont()->getColor()->setARGB('FFFFFFFF'); // white text
$header2Style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$header2Style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0070C0');
$sheet2->setAutoFilter("A5:C5");


$sheet2->freezePane('A6');

$r2 = 6;
foreach ($summaryRows as $sr) {
    $cantiereLabel = trim(($sr['worksite_code'] ?? '') . ' - ' . ($sr['worksite_name'] ?? ''));

    $sheet2->setCellValue("A{$r2}", $cantiereLabel);
    $sheet2->setCellValue("B{$r2}", (float)($sr['presenze'] ?? 0));
    $sheet2->setCellValue("C{$r2}", (float)($sr['costo'] ?? 0));
    $r2++;
}

$lastSumRow = $r2 - 1;

if ($lastSumRow >= 6) {
    $sheet2->getStyle("B6:B{$lastSumRow}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle("C6:C{$lastSumRow}")->getNumberFormat()->setFormatCode('#,##0.00');

    $tot2 = $lastSumRow + 2;
    $sheet2->setCellValue("A{$tot2}", 'Totale');
    $sheet2->setCellValue("B{$tot2}", "=SUBTOTAL(109,B6:B{$lastSumRow})");
    $sheet2->setCellValue("C{$tot2}", "=SUBTOTAL(109,C6:C{$lastSumRow})");
    $sheet2->getStyle("A{$tot2}:C{$tot2}")->getFont()->setBold(true);
    $sheet2->getStyle("B{$tot2}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle("C{$tot2}")->getNumberFormat()->setFormatCode('#,##0.00');
}

foreach (range('A', 'C') as $col) {
    $sheet2->getColumnDimension($col)->setAutoSize(true);
}

// ----------------------------
// Output
// ----------------------------

$fileName = 'Presenze_Azienda_' .
    preg_replace('/\s+/', '_', strtoupper($companyName)) .
    '_' . $startDateLabel . '_to_' . $endDateLabel . '.xlsx';


header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
