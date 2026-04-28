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

// $clientId and $conn are injected by BillingController::exportDaEmettere()
if (empty($clientId)) {
    http_response_code(400);
    exit('Client ID mancante');
}

$billing = new \App\Domain\Billing($conn);

// Fetch client name
$stmt = $conn->prepare('SELECT name FROM bb_clients WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $clientId]);
$clientName = $stmt->fetchColumn() ?: 'Cliente';

$rows = $billing->getDaEmettereByClient($clientId);

// ── Fetch movimentazione months for all worksites in one query ───────────────
$worksiteIds = array_unique(array_filter(array_column($rows, 'worksite_id')));
$movMap = [];
if (!empty($worksiteIds)) {
    $placeholders = implode(',', array_fill(0, count($worksiteIds), '?'));
    $monthNames   = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
    $movStmt = $conn->prepare("
        SELECT worksite_id, yr, mo
        FROM (
            SELECT worksite_id, YEAR(data) AS yr, MONTH(data) AS mo
            FROM bb_presenze
            WHERE worksite_id IN ({$placeholders})
            UNION
            SELECT worksite_id, YEAR(data_presenza) AS yr, MONTH(data_presenza) AS mo
            FROM bb_presenze_consorziate
            WHERE worksite_id IN ({$placeholders})
        ) AS combined
        GROUP BY worksite_id, yr, mo
        ORDER BY worksite_id, yr DESC, mo DESC
    ");
    $movStmt->execute(array_merge(array_values($worksiteIds), array_values($worksiteIds)));
    foreach ($movStmt->fetchAll(\PDO::FETCH_ASSOC) as $m) {
        $wid = (int)$m['worksite_id'];
        // Keep only the first (= most recent) month per worksite
        if (!isset($movMap[$wid])) {
            $movMap[$wid] = $monthNames[(int)$m['mo'] - 1] . ' ' . $m['yr'];
        }
    }
}

// ── Spreadsheet ─────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Da Emettere');

// Columns: A=Cantiere B=Ordine C=DataOrdine D=Descrizione E=DataFattura F=Imponibile G=Movimentato
$lastCol = 'G';

// ── Title row ────────────────────────────────────────────────────────────────
$sheet->mergeCells("A1:{$lastCol}1");
$sheet->setCellValue('A1', 'Fatture da Emettere – ' . $clientName);
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// ── Header row ───────────────────────────────────────────────────────────────
$headers = [
    'A2' => 'Cantiere',
    'B2' => 'Ordine',
    'C2' => 'Data Ordine',
    'D2' => 'Descrizione',
    'E2' => 'Data Fattura',
    'F2' => 'Imponibile (€)',
    'G2' => 'Movimentato',
];
foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}
$sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

// ── Data rows ────────────────────────────────────────────────────────────────
$rowNum   = 3;
$total    = 0.0;
$altLight = 'F0F4FA';
$altDark  = 'FFFFFF';

foreach ($rows as $row) {
    $bg = ($rowNum % 2 === 0) ? $altLight : $altDark;

    $orderDate = '';
    if (!empty($row['order_date'])) {
        try { $orderDate = (new \DateTime($row['order_date']))->format('d/m/Y'); }
        catch (\Exception $e) { $orderDate = $row['order_date']; }
    }
    $fatDate = '';
    if (!empty($row['data'])) {
        try { $fatDate = (new \DateTime($row['data']))->format('d/m/Y'); }
        catch (\Exception $e) { $fatDate = $row['data']; }
    }

    $imponibile = (float)$row['totale_imponibile'];
    $total     += $imponibile;

    $wid        = (int)$row['worksite_id'];
    $movimentato = $movMap[$wid] ?? '—';

    $sheet->setCellValue("A{$rowNum}", $row['cantiere']     ?? '');
    $sheet->setCellValue("B{$rowNum}", $row['order_number'] ?? '');
    $sheet->setCellValue("C{$rowNum}", $orderDate);
    $sheet->setCellValue("D{$rowNum}", $row['descrizione']  ?? '');
    $sheet->setCellValue("E{$rowNum}", $fatDate);
    $sheet->setCellValue("F{$rowNum}", $imponibile);
    $sheet->setCellValue("G{$rowNum}", $movimentato);

    // Row base style
    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
        'font'      => ['size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);
    // Currency
    $sheet->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('€ #,##0.00');
    $sheet->getStyle("F{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    // Center cols
    $sheet->getStyle("B{$rowNum}:C{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("E{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $rowNum++;
}

// ── Total row ────────────────────────────────────────────────────────────────
$sheet->mergeCells("A{$rowNum}:E{$rowNum}");
$sheet->setCellValue("A{$rowNum}", 'TOTALE');
$sheet->setCellValue("F{$rowNum}", $total);
$sheet->mergeCells("G{$rowNum}:G{$rowNum}"); // keep consistent
$sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('€ #,##0.00');
$sheet->getRowDimension($rowNum)->setRowHeight(20);

// ── Borders ──────────────────────────────────────────────────────────────────
$sheet->getStyle("A2:{$lastCol}{$rowNum}")->applyFromArray([
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D9E6']],
    ],
]);

// ── Column widths: auto-size most, cap description ───────────────────────────
// A: Cantiere – auto
$sheet->getColumnDimension('A')->setAutoSize(true);
// B: Ordine – auto
$sheet->getColumnDimension('B')->setAutoSize(true);
// C: Data Ordine – auto
$sheet->getColumnDimension('C')->setAutoSize(true);
// D: Descrizione – wrap text + fixed max width
$sheet->getColumnDimension('D')->setWidth(60);
$sheet->getStyle("D3:D{$rowNum}")->getAlignment()->setWrapText(true);
// E: Data Fattura – auto
$sheet->getColumnDimension('E')->setAutoSize(true);
// F: Imponibile – auto
$sheet->getColumnDimension('F')->setAutoSize(true);
// G: Movimentato – auto (month list)
$sheet->getColumnDimension('G')->setAutoSize(true);

// ── Row heights: auto for data rows (wrap text drives height) ─────────────────
// PhpSpreadsheet cannot truly auto-calc row height for wrapped text from PHP,
// but setting a generous default lets Excel/LibreOffice reflow on open.
for ($r = 3; $r < $rowNum; $r++) {
    $desc = $sheet->getCell("D{$r}")->getValue();
    // Estimate: ~80 chars per line at width 60, ~15pt per line
    $lines = max(1, (int)ceil(mb_strlen((string)$desc) / 80));
    $sheet->getRowDimension($r)->setRowHeight(max(16, $lines * 16));
}

// ── Output ───────────────────────────────────────────────────────────────────
$safeClient = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $clientName);
$filename   = 'fatture_da_emettere_' . $safeClient . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
