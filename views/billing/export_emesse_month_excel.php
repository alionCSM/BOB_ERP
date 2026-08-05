<?php
declare(strict_types=1);

/**
 * Export Excel del dettaglio "Fatture emesse reale" di un mese.
 *
 * I dati arrivano da Yard (dbo.CNT_cantieri_brogliacci), gia' raggruppati per
 * documento: una riga per ogni voce di brogliaccio, con il numero e la data
 * documento (tm_datdoc) ripetuti per rendere il foglio filtrabile in Excel.
 *
 * Variabili iniettate da BillingController::exportEmesseMonth():
 *   $fatture (array raggruppato), $label (es. "Marzo 2026"), $year, $month
 */

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

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Emesse ' . ($month ?? ''));

$lastCol = 'F';

// ── Titolo ──────────────────────────────────────────────────────────────────
$sheet->mergeCells("A1:{$lastCol}1");
$sheet->setCellValue('A1', 'Fatture emesse — ' . $label);
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// ── Intestazioni ────────────────────────────────────────────────────────────
$headers = [
    'A2' => 'Numero',
    'B2' => 'Data documento',
    'C2' => 'Cliente',
    'D2' => 'Cantiere',
    'E2' => 'Descrizione',
    'F2' => 'Imponibile (€)',
];
foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}
$sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

// ── Righe ───────────────────────────────────────────────────────────────────
$rowNum = 3;
$totale = 0.0;

foreach ($fatture as $f) {
    $firstRowOfDoc = $rowNum;

    foreach ($f['rows'] as $r) {
        $imponibile = (float)($r['totale_imponibile'] ?? 0);
        $totale    += $imponibile;

        $sheet->setCellValue("A{$rowNum}", $f['numero_label']);

        // tm_datdoc arriva come stringa/datetime da SQL Server: la scriviamo
        // come testo gg/mm/aaaa per evitare sorprese di localizzazione
        $dataDoc = $r['data'] ?? $f['data'] ?? null;
        $sheet->setCellValueExplicit(
            "B{$rowNum}",
            $dataDoc ? date('d/m/Y', strtotime((string)$dataDoc)) : '',
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );

        $sheet->setCellValue("C{$rowNum}", (string)($r['nome_cliente']  ?? ''));
        $sheet->setCellValue("D{$rowNum}", (string)($r['nome_cantiere'] ?? ''));
        $sheet->setCellValue("E{$rowNum}", (string)($r['descrizione']   ?? ''));
        $sheet->setCellValue("F{$rowNum}", $imponibile);

        $rowNum++;
    }

    // separatore visivo tra documenti diversi
    $sheet->getStyle("A{$firstRowOfDoc}:{$lastCol}{$firstRowOfDoc}")
          ->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
}

if ($rowNum === 3) {
    $sheet->mergeCells("A3:{$lastCol}3");
    $sheet->setCellValue('A3', 'Nessuna fattura emessa nel periodo.');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $rowNum++;
}

// ── Totale ──────────────────────────────────────────────────────────────────
$sheet->setCellValue("E{$rowNum}", 'TOTALE');
$sheet->setCellValue("F{$rowNum}", $totale);
$sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2F7']],
]);
$sheet->getStyle("E{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// ── Formattazione ───────────────────────────────────────────────────────────
$sheet->getStyle("F3:F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("F3:F{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

foreach (['A', 'B', 'C', 'D'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->getColumnDimension('E')->setWidth(60);
$sheet->getColumnDimension('F')->setAutoSize(true);
$sheet->getStyle("E3:E{$rowNum}")->getAlignment()->setWrapText(true);

// altezza riga proporzionale alla descrizione (come negli altri export)
for ($r = 3; $r < $rowNum; $r++) {
    $desc  = $sheet->getCell("E{$r}")->getValue();
    $lines = max(1, (int)ceil(mb_strlen((string)$desc) / 80));
    $sheet->getRowDimension($r)->setRowHeight(max(16, $lines * 16));
}

// filtro automatico sull'intestazione: comodo per isolare un cliente
$sheet->setAutoFilter("A2:{$lastCol}" . ($rowNum - 1));
$sheet->freezePane('A3');

// ── Output ──────────────────────────────────────────────────────────────────
$filename = 'fatture_emesse_' . sprintf('%04d-%02d', $year, $month) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
