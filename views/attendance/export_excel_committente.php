<?php
declare(strict_types=1);

// bootstrap + middleware already loaded by index.php
require_once APP_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// ------------------------------------------------------
// 1) Connessione DB + Validazione input (qui non improvviso niente)
// ------------------------------------------------------
$db = new Database();
$conn = $db->connect();

$clientId   = $_POST['client_id'] ?? null;
$startDate  = $_POST['start_date'] ?? null;
$endDate    = $_POST['end_date'] ?? null;

if (!$clientId || !$startDate || !$endDate) {
    http_response_code(400);
    exit("Dati mancanti per l'esportazione.");
}

$sd = DateTime::createFromFormat('Y-m-d', (string)$startDate);
$ed = DateTime::createFromFormat('Y-m-d', (string)$endDate);
if (!$sd || !$ed) {
    http_response_code(400);
    exit("Formato date non valido. Usa YYYY-MM-DD.");
}
if ($sd > $ed) {
    http_response_code(400);
    exit("Intervallo date non valido: data inizio > data fine.");
}

$startLabel = $sd->format('d/m/y');
$endLabel   = $ed->format('d/m/y');


function styleHeaderRow($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'bold'  => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0070C0'] // blu BOB
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER
        ],
    ]);
}

function autosizeCols($sheet, array $cols): void
{
    foreach ($cols as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// ------------------------------------------------------
// Recupera nome committente
// ------------------------------------------------------
$stmtClient = $conn->prepare("
    SELECT name
    FROM bb_clients
    WHERE id = :id
    LIMIT 1
");
$stmtClient->execute([':id' => $clientId]);

$clientRow = $stmtClient->fetch(PDO::FETCH_ASSOC);
if (!$clientRow) {
    http_response_code(404);
    exit('Committente non trovato.');
}

$clientName = (string)$clientRow['name'];


// ------------------------------------------------------
// 2) Query: PRESENZE (NOSTRI) - dettaglio
// ------------------------------------------------------
$sqlPresenze = "
    SELECT
        p.data,
        wks.worksite_code,
        wks.name AS worksite_name,
        CONCAT(w.first_name, ' ', w.last_name) AS operatore,
        p.turno,
        p.pranzo,
        p.cena
    FROM bb_presenze p
    INNER JOIN bb_worksites wks ON wks.id = p.worksite_id
    INNER JOIN bb_workers  w   ON w.id   = p.worker_id
    WHERE wks.client_id = :client_id
      AND p.data BETWEEN :start AND :end
    ORDER BY p.data ASC, wks.worksite_code ASC, wks.name ASC, operatore ASC
";
$stmt = $conn->prepare($sqlPresenze);
$stmt->execute([
    ':client_id' => $clientId,
    ':start'     => $startDate,
    ':end'       => $endDate
]);
$presenzeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------
// 3) Query: PRESENZE CONSORZIATE - dettaglio
// ------------------------------------------------------
$sqlConsDet = "
    SELECT
        pc.data_presenza,
        wks.worksite_code,
        wks.name AS worksite_name,
        c.name AS consorziata,
        pc.quantita,
        pc.costo_unitario,
        (pc.quantita * IFNULL(pc.costo_unitario, 0)) AS costo_manodopera
    FROM bb_presenze_consorziate pc
    INNER JOIN bb_worksites wks ON wks.id = pc.worksite_id
    LEFT JOIN bb_companies  c  ON c.id   = pc.azienda_id
    WHERE wks.client_id = :client_id
      AND pc.data_presenza BETWEEN :start AND :end
    ORDER BY pc.data_presenza ASC, wks.worksite_code ASC, wks.name ASC, consorziata ASC
";
$stmt = $conn->prepare($sqlConsDet);
$stmt->execute([
    ':client_id' => $clientId,
    ':start'     => $startDate,
    ':end'       => $endDate
]);
$consDetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------
// 4) Query: RIEPILOGO PRESENZE (pivot-like) - per cantiere
//    Intero=1, Mezzo=0.5
// ------------------------------------------------------
$sqlPresRiep = "
    SELECT
        wks.worksite_code,
        wks.name AS worksite_name,
        SUM(
            CASE
                WHEN p.turno = 'Intero' THEN 1
                WHEN p.turno = 'Mezzo'  THEN 0.5
                ELSE 0
            END
        ) AS totale_presenze
    FROM bb_presenze p
    INNER JOIN bb_worksites wks ON wks.id = p.worksite_id
    WHERE wks.client_id = :client_id
      AND p.data BETWEEN :start AND :end
    GROUP BY wks.worksite_code, wks.name
    ORDER BY wks.worksite_code ASC, wks.name ASC
";
$stmt = $conn->prepare($sqlPresRiep);
$stmt->execute([
    ':client_id' => $clientId,
    ':start'     => $startDate,
    ':end'       => $endDate
]);
$presRiepRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------
// 5) Query: RIEPILOGO CONSORZIATE (pivot-like gerarchico)
// ------------------------------------------------------
$sqlConsRiep = "
    SELECT
        wks.id AS worksite_id,
        wks.worksite_code,
        wks.name AS worksite_name,
        COALESCE(c.name, pc.azienda_id) AS consorziata,
        SUM(pc.quantita) AS presenze,
        SUM(pc.quantita * IFNULL(pc.costo_unitario, 0)) AS costo
    FROM bb_presenze_consorziate pc
    INNER JOIN bb_worksites wks ON wks.id = pc.worksite_id
    LEFT JOIN bb_companies c ON c.id = pc.azienda_id
    WHERE wks.client_id = :client_id
      AND pc.data_presenza BETWEEN :start AND :end
GROUP BY
    wks.id,
    wks.worksite_code,
    wks.name,
    COALESCE(c.name, pc.azienda_id)
    ORDER BY wks.worksite_code ASC, wks.name ASC, consorziata ASC
";
$stmt = $conn->prepare($sqlConsRiep);
$stmt->execute([
    ':client_id' => $clientId,
    ':start'     => $startDate,
    ':end'       => $endDate
]);
$consRiepRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Raggruppo in PHP per costruire gerarchia “cantiere -> consorziate”
$consGrouped = [];
foreach ($consRiepRows as $r) {
    $wsKey = (string)$r['worksite_id'];
    if (!isset($consGrouped[$wsKey])) {
        $consGrouped[$wsKey] = [
            'worksite_code' => (string)$r['worksite_code'],
            'worksite_name' => (string)$r['worksite_name'],
            'rows'          => [],
        ];
    }
    $consGrouped[$wsKey]['rows'][] = [
        'consorziata' => (string)$r['consorziata'],
        'presenze'    => (float)$r['presenze'],
        'costo'       => (float)$r['costo'],
    ];
}


// ------------------------------------------------------
// 5.1) Query: RIEPILOGO COMPLETO (nostri vs dumi + consorziate) per cantiere
// ------------------------------------------------------
$sqlFull = "
SELECT
    wks.id AS worksite_id,
    wks.worksite_code,
    wks.name AS worksite_name,

    /* NOSTRI */
    SUM(
        CASE
            WHEN p.azienda = 'DUMI MONTAGGI SRL' THEN 0
            WHEN p.turno = 'Intero' THEN 1
            WHEN p.turno = 'Mezzo'  THEN 0.5
            ELSE 0
        END
    ) AS nostri_presenze,

    /* DUMI */
    SUM(
        CASE
            WHEN p.azienda = 'DUMI MONTAGGI SRL' THEN
                CASE
                    WHEN p.turno = 'Intero' THEN 1
                    WHEN p.turno = 'Mezzo'  THEN 0.5
                    ELSE 0
                END
            ELSE 0
        END
    ) AS dumi_presenze

FROM (
    SELECT DISTINCT worksite_id
    FROM bb_presenze
    WHERE data BETWEEN :start_p AND :end_p

    UNION

    SELECT DISTINCT worksite_id
    FROM bb_presenze_consorziate
    WHERE data_presenza BETWEEN :start_pc AND :end_pc
) ws

INNER JOIN bb_worksites wks
    ON wks.id = ws.worksite_id
   AND wks.client_id = :client_id

LEFT JOIN bb_presenze p
    ON p.worksite_id = wks.id
   AND p.data BETWEEN :start_p2 AND :end_p2

GROUP BY
    wks.id,
    wks.worksite_code,
    wks.name

ORDER BY
    wks.worksite_code ASC,
    wks.name ASC;

";
$stmt = $conn->prepare($sqlFull);
$stmt->execute([
    ':client_id' => $clientId,

    ':start_p'   => $startDate,
    ':end_p'     => $endDate,

    ':start_pc'  => $startDate,
    ':end_pc'    => $endDate,

    ':start_p2'  => $startDate,
    ':end_p2'    => $endDate,
]);

$fullRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mappa consorziate per worksite: tot presenze/costo + nome (o Multiple) + costo a persona
$consByWorksite = [];
foreach ($consGrouped as $wsKey => $ws) {
    $totPres = 0.0;
    $totCost = 0.0;
    $names   = [];

    foreach ($ws['rows'] as $rr) {
        $totPres += (float)$rr['presenze'];
        $totCost += (float)$rr['costo'];
        $names[] = trim((string)$rr['consorziata']);
    }

    $names = array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
    $label = (count($names) === 1) ? $names[0] : (count($names) > 1 ? 'Multiple' : '');

    $consByWorksite[$wsKey] = [
        'multiple' => count($ws['rows']) > 1,
        'rows'     => $ws['rows'], // ogni consorziata singola
        'tot_pres' => $totPres,
        'tot_cost' => $totCost,
    ];

}


// ------------------------------------------------------
// 6) Setup Excel (4 fogli)
// ------------------------------------------------------
$spreadsheet = new Spreadsheet();

/**
 * SHEET 1: Presenze (Nostri)
 */
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Presenze');

$sheet1->setCellValue('A1', 'PRESENZE - DETTAGLIO');
$sheet1->setCellValue('A2', 'Periodo:');
$sheet1->setCellValue('A3', 'Committente:');
$sheet1->getStyle('A3')->getFont()->setBold(true);

$sheet1->setCellValue('B2', $startLabel . ' → ' . $endLabel);
$sheet1->setCellValue('B3', $clientName);

$sheet1->fromArray(['Data', 'Cantiere', 'Operatore', 'Tipo turno', 'Pranzo', 'Cena'], null, 'A4');
styleHeaderRow($sheet1, 'A4:F4');
$sheet1->setAutoFilter('A4:F4');

$r = 5;
foreach ($presenzeRows as $row) {
    $cantiereLabel = trim(($row['worksite_code'] ?? '') . ' - ' . ($row['worksite_name'] ?? ''));

    // Data come vero valore Excel (altrimenti resta testo e non formatta)
    $excelDate = ExcelDate::PHPToExcel(new DateTime((string)$row['data']));

    $sheet1->setCellValue("A{$r}", $excelDate);
    $sheet1->setCellValue("B{$r}", $cantiereLabel);
    $sheet1->setCellValue("C{$r}", (string)$row['operatore']);
    $sheet1->setCellValue("D{$r}", (string)$row['turno']);
    $sheet1->setCellValue("E{$r}", (string)($row['pranzo'] ?? ''));
    $sheet1->setCellValue("F{$r}", (string)($row['cena'] ?? ''));
    $r++;
}

$lastRow1 = $r - 1;
if ($lastRow1 >= 5) {
    $sheet1->getStyle("A5:A{$lastRow1}")->getNumberFormat()->setFormatCode('dd/mm/yy');
}
autosizeCols($sheet1, ['A','B','C','D','E','F']);


/**
 * SHEET 2: Presenze Consorziate (Dettaglio)
 */
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Consorziate');

$sheet2->setCellValue('A1', 'PRESENZE CONSORZIATE - DETTAGLIO');
$sheet2->setCellValue('A2', 'Periodo:');
$sheet2->setCellValue('A3', 'Committente:');
$sheet2->getStyle('A3')->getFont()->setBold(true);
$sheet2->setCellValue('B2', $startLabel . ' → ' . $endLabel);
$sheet2->setCellValue('B3', $clientName);

$sheet2->fromArray(['Data', 'Cantiere', 'Consorziata', 'Quantità', 'Costo unitario', 'Costo manodopera'], null, 'A4');
styleHeaderRow($sheet2, 'A4:F4');
$sheet2->setAutoFilter('A4:F4');

$r = 5;
foreach ($consDetRows as $row) {
    $cantiereLabel = trim(($row['worksite_code'] ?? '') . ' - ' . ($row['worksite_name'] ?? ''));
    $excelDate = ExcelDate::PHPToExcel(new DateTime((string)$row['data_presenza']));

    $sheet2->setCellValue("A{$r}", $excelDate);
    $sheet2->setCellValue("B{$r}", $cantiereLabel);
    $sheet2->setCellValue("C{$r}", (string)($row['consorziata'] ?? ''));
    $sheet2->setCellValue("D{$r}", (float)$row['quantita']);
    $sheet2->setCellValue("E{$r}", (float)($row['costo_unitario'] ?? 0));
    $sheet2->setCellValue("F{$r}", (float)$row['costo_manodopera']);
    $r++;
}

$lastRow2 = $r - 1;
if ($lastRow2 >= 5) {
    $sheet2->getStyle("A5:A{$lastRow2}")->getNumberFormat()->setFormatCode('dd/mm/yy');
    $sheet2->getStyle("D5:D{$lastRow2}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle("E5:F{$lastRow2}")->getNumberFormat()->setFormatCode('#,##0.00');
}
autosizeCols($sheet2, ['A','B','C','D','E','F']);


/**
 * SHEET 3: Riepilogo Presenze (pivot-like)
 */
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Riep Presenze');

$sheet3->setCellValue('A1', 'RIEPILOGO PRESENZE (NOSTRI)');
$sheet3->setCellValue('A2', 'Periodo:');
$sheet3->setCellValue('A3', 'Committente:');
$sheet3->getStyle('A3')->getFont()->setBold(true);
$sheet3->setCellValue('B2', $startLabel . ' → ' . $endLabel);
$sheet3->setCellValue('B3', $clientName);

$sheet3->fromArray(['Cantiere', 'Totale presenze'], null, 'A4');
styleHeaderRow($sheet3, 'A4:B4');
$sheet3->setAutoFilter('A4:B4');


$r = 5;
$grandPres = 0.0;

foreach ($presRiepRows as $row) {
    $cantiereLabel = trim(($row['worksite_code'] ?? '') . ' - ' . ($row['worksite_name'] ?? ''));
    $tot = (float)$row['totale_presenze'];

    $sheet3->setCellValue("A{$r}", $cantiereLabel);
    $sheet3->setCellValue("B{$r}", $tot);

    $grandPres += $tot;
    $r++;
}

$sheet3->setCellValue("A{$r}", 'Totale complessivo');
$sheet3->setCellValue("B{$r}", $grandPres);
$sheet3->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
$sheet3->getStyle("A{$r}:B{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EDF7');

$lastRow3 = $r;
$sheet3->getStyle("B5:B{$lastRow3}")->getNumberFormat()->setFormatCode('#,##0.00');
autosizeCols($sheet3, ['A','B']);


/**
 * SHEET 4: Riepilogo Consorziate (pivot-like gerarchico)
 */
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('Riep Consorziate');

$sheet4->setCellValue('A1', 'RIEPILOGO CONSORZIATE (CANTIERE → CONSORZIATA)');
$sheet4->setCellValue('A2', 'Periodo:');
$sheet4->setCellValue('B2', $startLabel . ' → ' . $endLabel);
$sheet4->mergeCells('B2:C2');

$sheet4->fromArray(['Etichette di riga', 'Presenze', 'Costo'], null, 'A4');
styleHeaderRow($sheet4, 'A4:C4');
$sheet4->setAutoFilter('A4:C4');

$r = 5;
$grandConsPres = 0.0;
$grandConsCost = 0.0;

foreach ($consGrouped as $ws) {
    $cantiereLabel = trim(($ws['worksite_code'] ?? '') . ' - ' . ($ws['worksite_name'] ?? ''));

    $totPresCantiere = 0.0;
    $totCostCantiere = 0.0;
    foreach ($ws['rows'] as $rr) {
        $totPresCantiere += (float)$rr['presenze'];
        $totCostCantiere += (float)$rr['costo'];
    }

    // Riga cantiere (bold)
    $sheet4->setCellValue("A{$r}", $cantiereLabel);
    $sheet4->setCellValue("B{$r}", $totPresCantiere);
    $sheet4->setCellValue("C{$r}", $totCostCantiere);
    $sheet4->getStyle("A{$r}:C{$r}")->getFont()->setBold(true);
    $r++;

    // Righe consorziate sotto (indentate)
    foreach ($ws['rows'] as $rr) {
        $sheet4->setCellValue("A{$r}", '    ' . (string)$rr['consorziata']);
        $sheet4->setCellValue("B{$r}", (float)$rr['presenze']);
        $sheet4->setCellValue("C{$r}", (float)$rr['costo']);
        $r++;
    }

    $grandConsPres += $totPresCantiere;
    $grandConsCost += $totCostCantiere;
}

// Totale complessivo finale
$sheet4->setCellValue("A{$r}", 'Totale complessivo');
$sheet4->setCellValue("B{$r}", $grandConsPres);
$sheet4->setCellValue("C{$r}", $grandConsCost);
$sheet4->getStyle("A{$r}:C{$r}")->getFont()->setBold(true);
$sheet4->getStyle("A{$r}:C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EDF7');

$lastRow4 = $r;
$sheet4->getStyle("B5:B{$lastRow4}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet4->getStyle("C5:C{$lastRow4}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet4->getStyle("B5:C{$lastRow4}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

autosizeCols($sheet4, ['A','B','C']);



/**
 * SHEET 5: Riepilogo Completo
 * Colonne:
 * Cantiere | N persone nostri | Costo a persona | N persone DUMI | Costo a persona |
 * N persone consorziate | Consorziata | Costo a persona | Totale costi | Hotel | Ristorante | Note
 */
$sheet5 = $spreadsheet->createSheet();
$sheet5->setTitle('Riep Completo');

$sheet5->setCellValue('A1', 'RIEPILOGO COMPLETO');
$sheet5->setCellValue('A2', 'Periodo:');
$sheet5->setCellValue('A3', 'Committente:');
$sheet5->getStyle('A3')->getFont()->setBold(true);

$sheet5->setCellValue('B2', $startLabel . ' → ' . $endLabel);
$sheet5->setCellValue('B3', $clientName);

$sheet5->fromArray([
    "Cantiere",
    "N persone\nnostri",
    "Costo a\npersona",
    "N persone\nDUMI",
    "Costo a\npersona",
    "N persone\nconsorziate",
    "Consorziata",
    "Costo a\npersona",
    "Totale\ncosti",
    "Hotel /\nRistorante",
    "Note"
], null, 'A5');

styleHeaderRow($sheet5, 'A5:K5');
$sheet5->setAutoFilter('A5:K5');

$sheet5->getStyle('A5:K5')->getAlignment()->setWrapText(true);
$sheet5->getStyle('A5:K5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$sheet5->getRowDimension(5)->setRowHeight(-1); // auto height



$r = 6;

$grandNostri = 0.0;
$grandDumi   = 0.0;
$grandND     = 0.0;

$grandCons   = 0.0;
$grandCosti  = 0.0;

foreach ($fullRows as $fr) {

    $wsId = (string)$fr['worksite_id'];
    $cantiereLabel = trim(($fr['worksite_code'] ?? '') . ' - ' . ($fr['worksite_name'] ?? ''));

    $nostri = (float)($fr['nostri_presenze'] ?? 0);
    $dumi   = (float)($fr['dumi_presenze'] ?? 0);

    $hasCons = isset($consByWorksite[$wsId]);
    $isMultiple = $hasCons && $consByWorksite[$wsId]['multiple'];

    /**
     * ROW 1 — BASE (NOSTRI + DUMI)
     * Solo se consorziate multiple
     */
    if ($isMultiple) {

        $sheet5->setCellValue("A{$r}", $cantiereLabel);
        $sheet5->setCellValue("B{$r}", $nostri);
        $sheet5->setCellValue("D{$r}", $dumi);

        $sheet5->setCellValue("F{$r}", 0);
        $sheet5->setCellValue("I{$r}", 0);

        $sheet5->getStyle("A{$r}:K{$r}")->getFont()->setBold(true);
        $r++;
    }

    /**
     * ROWS CONSORZIATE
     */
    if ($hasCons) {

        foreach ($consByWorksite[$wsId]['rows'] as $cr) {

            $pres = (float)$cr['presenze'];
            $cost = (float)$cr['costo'];
            $cpp  = ($pres > 0) ? ($cost / $pres) : null;

            $sheet5->setCellValue("A{$r}", $isMultiple ? '  ' . $cantiereLabel : $cantiereLabel);
            $sheet5->setCellValue("B{$r}", $isMultiple ? 0 : $nostri);
            $sheet5->setCellValue("D{$r}", $isMultiple ? 0 : $dumi);

            $sheet5->setCellValue("F{$r}", $pres);
            $sheet5->setCellValue("G{$r}", $cr['consorziata']);
            $sheet5->setCellValue("H{$r}", $cpp);
            $sheet5->setCellValue("I{$r}", $cost);

            $grandCons  += $pres;
            $grandCosti += $cost;

            $r++;
        }


    } else {
        /**
         * Nessuna consorziata → una sola riga classica
         */
        $sheet5->setCellValue("A{$r}", $cantiereLabel);
        $sheet5->setCellValue("B{$r}", $nostri);
        $sheet5->setCellValue("D{$r}", $dumi);
        $r++;
    }

    $grandNostri += $nostri;
    $grandDumi   += $dumi;
}

// Totali finali
$sheet5->setCellValue("A{$r}", 'Totale complessivo');
$sheet5->setCellValue("B{$r}", $grandNostri);
$sheet5->setCellValue("D{$r}", $grandDumi);
$sheet5->setCellValue("F{$r}", $grandCons);
$sheet5->setCellValue("I{$r}", $grandCosti);

$sheet5->getStyle("A{$r}:K{$r}")->getFont()->setBold(true);
$sheet5->getStyle("A{$r}:K{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EDF7');
$r++;

// Totale complessivo Nostri + DUMI (solo persone)
$sheet5->setCellValue("A{$r}", 'Totale complessivo (Nostri + DUMI)');
$sheet5->setCellValue("B{$r}", $grandND);



$sheet5->getStyle("A{$r}:K{$r}")->getFont()->setBold(true);
$sheet5->getStyle("A{$r}:K{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EDF7');

// Formati numerici
$lastRow5 = $r;
$sheet5->getStyle("B6:B{$lastRow5}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet5->getStyle("D6:D{$lastRow5}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet5->getStyle("F6:F{$lastRow5}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet5->getStyle("H6:H{$lastRow5}")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet5->getStyle("I6:I{$lastRow5}")->getNumberFormat()->setFormatCode('#,##0.00');

$sheet5->getStyle("B6:I{$lastRow5}")
    ->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT);

autosizeCols($sheet5, ['A','B','C','D','E','F','G','H','I','J','K']);



// ------------------------------------------------------
// 7) Output file Excel
// ------------------------------------------------------
$safeClient = preg_replace('/[^A-Za-z0-9_-]/', '_', strtoupper($clientName));

$fileName = 'PROSPETTO_' .
    $safeClient . '_' .
    $startLabel . '_to_' . $endLabel . '.xlsx';
$fileName = str_replace('/', '-', $fileName); // Windows-safe

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
