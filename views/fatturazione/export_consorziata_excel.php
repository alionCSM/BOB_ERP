<?php
declare(strict_types=1);

require_once APP_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$user = $GLOBALS['user'] ?? null;
if (!$user) {
    http_response_code(403);
    exit;
}

// $aziendaId, $from, $to, $consorziata, $rows, $payments are injected by the controller
if (empty($aziendaId)) {
    http_response_code(400);
    exit('Dati mancanti');
}
$payments = $payments ?? [];

$name   = $consorziata['name']   ?? 'Consorziata';
$codice = $consorziata['codice'] ?? '';

// ── Format helpers ────────────────────────────────────────────────────────────
$fmtDate = function (string $d): string {
    try { return (new \DateTime($d))->format('d/m/Y'); }
    catch (\Exception $e) { return $d; }
};

$fromLabel = $fmtDate($from);
$toLabel   = $fmtDate($to);

// ── Spreadsheet ───────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Dettaglio');

$lastCol = 'G'; // A–G (7 columns)

// ── Row 1: title ──────────────────────────────────────────────────────────────
$title = trim(($codice ? "[{$codice}] " : '') . $name . ' — ' . $fromLabel . ' / ' . $toLabel);
$sheet->mergeCells("A1:{$lastCol}1");
$sheet->setCellValue('A1', $title);
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(26);

// ── Row 2: headers ────────────────────────────────────────────────────────────
$headers = [
    'A' => 'Cantiere',
    'B' => 'Presenze (gg)',
    'C' => 'Costo presenze (€)',
    'D' => 'Valore ordine (€)',
    'E' => 'Già pagato (€)',
    'F' => 'Spese a carico (€)',
    'G' => 'Residuo (€)',
];
foreach ($headers as $col => $text) {
    $sheet->setCellValue("{$col}2", $text);
}
$sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

// ── Data rows ─────────────────────────────────────────────────────────────────
$rowNum     = 3;
$altLight   = 'F0F4FA';
$altDark    = 'FFFFFF';

$sumPresenze = 0.0;
$sumCosto    = 0.0;
$sumOrdine   = 0.0;
$sumPagato   = 0.0;
$sumSpese    = 0.0;
$sumResiduo  = 0.0;

foreach ($rows as $row) {
    $bg = ($rowNum % 2 === 0) ? $altLight : $altDark;

    $cantiere = trim(
        ($row['worksite_code'] ? '[' . $row['worksite_code'] . '] ' : '') .
        ($row['worksite_name'] ?? '')
    );

    $presenze = (float)$row['presenze_gg'];
    $costo    = (float)$row['costo_presenze'];
    $ordine   = (float)$row['valore_ordine'];
    $pagato   = (float)$row['gia_pagato'];
    $spese    = (float)$row['spese_consorziata'];
    $residuo  = $ordine - $pagato - $spese;

    $sumPresenze += $presenze;
    $sumCosto    += $costo;
    $sumOrdine   += $ordine;
    $sumPagato   += $pagato;
    $sumSpese    += $spese;
    $sumResiduo  += $residuo;

    $sheet->setCellValue("A{$rowNum}", $cantiere);
    $sheet->setCellValue("B{$rowNum}", $presenze);
    $sheet->setCellValue("C{$rowNum}", $costo);
    $sheet->setCellValue("D{$rowNum}", $ordine);
    $sheet->setCellValue("E{$rowNum}", $pagato);
    $sheet->setCellValue("F{$rowNum}", $spese);
    $sheet->setCellValue("G{$rowNum}", $residuo);

    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
        'font'      => ['size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    // Number formats
    $sheet->getStyle("B{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');
    foreach (['C', 'D', 'E', 'F', 'G'] as $c) {
        $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('€ #,##0.00');
        $sheet->getStyle("{$c}{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    $sheet->getStyle("B{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Colour residuo cell when negative
    if ($residuo < 0) {
        $sheet->getStyle("G{$rowNum}")->getFont()->getColor()->setRGB('DC2626');
    }

    $rowNum++;
}

// ── Totals row ────────────────────────────────────────────────────────────────
$sheet->setCellValue("A{$rowNum}", 'TOTALE');
$sheet->setCellValue("B{$rowNum}", $sumPresenze);
$sheet->setCellValue("C{$rowNum}", $sumCosto);
$sheet->setCellValue("D{$rowNum}", $sumOrdine);
$sheet->setCellValue("E{$rowNum}", $sumPagato);
$sheet->setCellValue("F{$rowNum}", $sumSpese);
$sheet->setCellValue("G{$rowNum}", $sumResiduo);

$sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle("A{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$sheet->getStyle("B{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');
foreach (['C', 'D', 'E', 'F', 'G'] as $c) {
    $sheet->getStyle("{$c}{$rowNum}")->getNumberFormat()->setFormatCode('€ #,##0.00');
}
$sheet->getRowDimension($rowNum)->setRowHeight(20);

// ── Borders ───────────────────────────────────────────────────────────────────
$sheet->getStyle("A2:{$lastCol}{$rowNum}")->applyFromArray([
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D9E6']],
    ],
]);

// ── Column widths ─────────────────────────────────────────────────────────────
$sheet->getColumnDimension('A')->setWidth(40);
foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}

// ── Sheet 2: Storico Pagamenti ────────────────────────────────────────────────
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Storico Pagamenti');

$lastCol2 = 'D'; // A–D

// Title
$sheet2->mergeCells("A1:{$lastCol2}1");
$sheet2->setCellValue('A1', 'Storico Pagamenti — ' . trim(($codice ? "[{$codice}] " : '') . $name));
$sheet2->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet2->getRowDimension(1)->setRowHeight(26);

// Headers
$headers2 = ['A' => 'Data pagamento', 'B' => 'Cantiere', 'C' => 'Importo (€)', 'D' => 'Note'];
foreach ($headers2 as $col => $text) {
    $sheet2->setCellValue("{$col}2", $text);
}
$sheet2->getStyle("A2:{$lastCol2}2")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet2->getRowDimension(2)->setRowHeight(20);

$rowNum2   = 3;
$sumPag    = 0.0;

foreach ($payments as $p) {
    $bg2 = ($rowNum2 % 2 === 0) ? $altLight : $altDark;

    $datePag = '';
    if (!empty($p['data_pagamento'])) {
        try { $datePag = (new \DateTime($p['data_pagamento']))->format('d/m/Y'); }
        catch (\Exception $e) { $datePag = $p['data_pagamento']; }
    }

    $cantiere2 = trim(
        (!empty($p['worksite_code']) ? '[' . $p['worksite_code'] . '] ' : '') .
        ($p['worksite_name'] ?? '')
    );
    $importo2 = (float)$p['importo'];
    $sumPag  += $importo2;

    $sheet2->setCellValue("A{$rowNum2}", $datePag);
    $sheet2->setCellValue("B{$rowNum2}", $cantiere2);
    $sheet2->setCellValue("C{$rowNum2}", $importo2);
    $sheet2->setCellValue("D{$rowNum2}", $p['note'] ?? '');

    $sheet2->getStyle("A{$rowNum2}:{$lastCol2}{$rowNum2}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg2]],
        'font'      => ['size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet2->getStyle("A{$rowNum2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("C{$rowNum2}")->getNumberFormat()->setFormatCode('€ #,##0.00');
    $sheet2->getStyle("C{$rowNum2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $rowNum2++;
}

// Totals row
$sheet2->mergeCells("A{$rowNum2}:B{$rowNum2}");
$sheet2->setCellValue("A{$rowNum2}", 'TOTALE PAGATO');
$sheet2->setCellValue("C{$rowNum2}", $sumPag);
$sheet2->setCellValue("D{$rowNum2}", '');
$sheet2->getStyle("A{$rowNum2}:{$lastCol2}{$rowNum2}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet2->getStyle("A{$rowNum2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet2->getStyle("C{$rowNum2}")->getNumberFormat()->setFormatCode('€ #,##0.00');
$sheet2->getRowDimension($rowNum2)->setRowHeight(20);

// Borders
$sheet2->getStyle("A2:{$lastCol2}{$rowNum2}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D9E6']]],
]);

// Column widths
$sheet2->getColumnDimension('A')->setAutoSize(true);
$sheet2->getColumnDimension('B')->setWidth(40);
$sheet2->getColumnDimension('C')->setAutoSize(true);
$sheet2->getColumnDimension('D')->setWidth(40);

// Back to first sheet as active
$spreadsheet->setActiveSheetIndex(0);

// ── Output ────────────────────────────────────────────────────────────────────
$safeCode = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $codice ?: $name);
$safeFrom = str_replace('-', '', $from);
$safeTo   = str_replace('-', '', $to);
$filename = "fatturazione_consorziate_{$safeCode}_{$safeFrom}_{$safeTo}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
