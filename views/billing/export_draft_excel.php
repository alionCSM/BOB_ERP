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

// $draftId, $clientId, $conn injected by BillingController::exportDraftExcel()
if (empty($draftId) || empty($clientId)) {
    http_response_code(400);
    exit('Parametri mancanti');
}

// ── Load draft + lines ──────────────────────────────────────────────────────
$stmt = $conn->prepare('SELECT * FROM bb_billing_drafts WHERE id = :id AND client_id = :cid LIMIT 1');
$stmt->execute([':id' => $draftId, ':cid' => $clientId]);
$draft = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$draft) {
    http_response_code(404);
    exit('Bozza non trovata');
}

$stmt = $conn->prepare('SELECT name FROM bb_clients WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $clientId]);
$clientName = $stmt->fetchColumn() ?: 'Cliente';

// Only non-excluded lines for the Excel — excluded rows stay in the draft
// for tracking but are not sent to the client.
$stmt = $conn->prepare("
    SELECT
        l.data, l.descrizione, l.totale_imponibile, l.aliquota_iva,
        l.worksite_id,
        w.name         AS cantiere,
        w.order_number,
        w.order_date
    FROM bb_billing_draft_lines l
    JOIN bb_worksites w ON w.id = l.worksite_id
    WHERE l.draft_id = :did AND l.excluded = 0
    ORDER BY l.display_order ASC, l.id ASC
");
$stmt->execute([':did' => $draftId]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// ── Movimentazione months (same logic as legacy export) ─────────────────────
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
        if (!isset($movMap[$wid])) {
            $movMap[$wid] = $monthNames[(int)$m['mo'] - 1] . ' ' . $m['yr'];
        }
    }
}

// ── Spreadsheet ─────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Bozza Fatturazione');

$lastCol = 'G';

// Title row
$sheet->mergeCells("A1:{$lastCol}1");
$title = 'Bozza Fatturazione – ' . $clientName;
if (!empty($draft['period_label'])) {
    $title .= ' – ' . $draft['period_label'];
}
$title .= '  (bozza #' . $draft['id'] . ')';
$sheet->setCellValue('A1', $title);
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '4C1D95']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// Header row
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
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6D28D9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

// Data rows
$rowNum   = 3;
$total    = 0.0;
$altLight = 'F5F3FF';
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

    $wid         = (int)$row['worksite_id'];
    $movimentato = $movMap[$wid] ?? '—';

    $sheet->setCellValue("A{$rowNum}", $row['cantiere']     ?? '');
    $sheet->setCellValue("B{$rowNum}", $row['order_number'] ?? '');
    $sheet->setCellValue("C{$rowNum}", $orderDate);
    $sheet->setCellValue("D{$rowNum}", $row['descrizione']  ?? '');
    $sheet->setCellValue("E{$rowNum}", $fatDate);
    $sheet->setCellValue("F{$rowNum}", $imponibile);
    $sheet->setCellValue("G{$rowNum}", $movimentato);

    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
        'font'      => ['size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);
    $sheet->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('€ #,##0.00');
    $sheet->getStyle("F{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("B{$rowNum}:C{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("E{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $rowNum++;
}

// Total row
$sheet->mergeCells("A{$rowNum}:E{$rowNum}");
$sheet->setCellValue("A{$rowNum}", 'TOTALE');
$sheet->setCellValue("F{$rowNum}", $total);
$sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7C3AED']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('€ #,##0.00');
$sheet->getRowDimension($rowNum)->setRowHeight(20);

// Borders
$sheet->getStyle("A2:{$lastCol}{$rowNum}")->applyFromArray([
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D9E6']],
    ],
]);

// Column widths
$sheet->getColumnDimension('A')->setAutoSize(true);
$sheet->getColumnDimension('B')->setAutoSize(true);
$sheet->getColumnDimension('C')->setAutoSize(true);
$sheet->getColumnDimension('D')->setWidth(60);
$sheet->getStyle("D3:D{$rowNum}")->getAlignment()->setWrapText(true);
$sheet->getColumnDimension('E')->setAutoSize(true);
$sheet->getColumnDimension('F')->setAutoSize(true);
$sheet->getColumnDimension('G')->setAutoSize(true);

for ($r = 3; $r < $rowNum; $r++) {
    $desc  = $sheet->getCell("D{$r}")->getValue();
    $lines = max(1, (int)ceil(mb_strlen((string)$desc) / 80));
    $sheet->getRowDimension($r)->setRowHeight(max(16, $lines * 16));
}

// Mark Excel generated on the draft (best-effort)
try {
    $upd = $conn->prepare('UPDATE bb_billing_drafts SET excel_generated_at = NOW() WHERE id = :id');
    $upd->execute([':id' => $draftId]);
} catch (\Throwable $e) {
    // non-fatal
}

// Output
$safeClient = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $clientName);
$filename   = 'bozza_fatturazione_' . $safeClient . '_n' . $draft['id'] . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
