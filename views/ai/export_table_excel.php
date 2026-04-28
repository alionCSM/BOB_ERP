<?php
declare(strict_types=1);

require_once APP_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$user = $GLOBALS['user'] ?? null;
if (!$user) {
    http_response_code(403);
    exit;
}

// $headers, $rows, $filename are injected by the controller
if (empty($headers) || !is_array($headers)) {
    http_response_code(400);
    exit('Dati mancanti');
}
$rows     = $rows     ?? [];
$filename = $filename ?? ('bob_ai_' . date('Y-m-d') . '.xlsx');

// ── Palette (matches chat.css) ────────────────────────────────────────────────
$PURPLE_DARK  = '4F46E5'; // header bg (indigo-600)
$PURPLE_MID   = '7C3AED'; // accent (violet-600)
$PURPLE_LIGHT = 'F5F3FF'; // alternating row (violet-50)
$WHITE        = 'FFFFFF';
$TEXT_DARK    = '1E293B'; // slate-800
$BORDER_COLOR = 'E2E8F0'; // slate-200
$HEADER_BORDER= '3730A3'; // indigo-800

$colCount = count($headers);
$lastCol  = Coordinate::stringFromColumnIndex($colCount);

// ── Spreadsheet ───────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('BOB AI');

// ── Row 1: decorative title bar ───────────────────────────────────────────────
$sheet->mergeCells("A1:{$lastCol}1");
$sheet->setCellValue('A1', 'BOB AI — Risultati esportati il ' . date('d/m/Y H:i'));
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $WHITE]],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $PURPLE_MID]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// ── Row 2: column headers ─────────────────────────────────────────────────────
for ($c = 0; $c < $colCount; $c++) {
    $col = Coordinate::stringFromColumnIndex($c + 1);
    $sheet->setCellValue($col . '2', $headers[$c]);
}
$sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => $WHITE], 'size' => 10],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $PURPLE_DARK]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $HEADER_BORDER]],
    ],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

// ── Data rows ─────────────────────────────────────────────────────────────────
foreach ($rows as $rowIndex => $row) {
    $rowNum  = $rowIndex + 3; // rows start at 3 (1=title, 2=header)
    $isEven  = ($rowIndex % 2 === 1);
    $bgColor = $isEven ? $PURPLE_LIGHT : $WHITE;

    for ($c = 0; $c < $colCount; $c++) {
        $col   = Coordinate::stringFromColumnIndex($c + 1);
        $value = $row[$c] ?? '';

        // Try to detect numeric values for proper Excel typing
        if (is_numeric(str_replace(['.', ',', ' ', '€', '%'], ['', '.', '', '', ''], $value))) {
            $numericVal = (float) str_replace([',', ' ', '€', '%'], ['.', '', '', ''], $value);
            $sheet->setCellValue($col . $rowNum, $numericVal);
        } else {
            $sheet->setCellValue($col . $rowNum, $value);
        }
    }

    // Row style: alternating fill + thin borders
    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
        'font'      => ['size' => 10, 'color' => ['rgb' => $TEXT_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $BORDER_COLOR]],
        ],
    ]);
    $sheet->getRowDimension($rowNum)->setRowHeight(18);
}

// ── Row count footer ──────────────────────────────────────────────────────────
if (!empty($rows)) {
    $footerRow = count($rows) + 3;
    $sheet->mergeCells("A{$footerRow}:{$lastCol}{$footerRow}");
    $sheet->setCellValue("A{$footerRow}", count($rows) . ' righe totali');
    $sheet->getStyle("A{$footerRow}")->applyFromArray([
        'font'      => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '94A3B8']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
    ]);
    $sheet->getRowDimension($footerRow)->setRowHeight(16);
}

// ── Auto-width columns ────────────────────────────────────────────────────────
for ($c = 1; $c <= $colCount; $c++) {
    $col = Coordinate::stringFromColumnIndex($c);
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
