<?php
// Esporta Excel Azienda usando il template presenze_azienda_template.xlsx
// Filtra su bb_presenze.azienda e intervallo date, aggregando per operaio.

// Include base BOB
// bootstrap + middleware already loaded by index.php

// Autoload PhpSpreadsheet
require_once APP_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Connessione DB BOB (MySQL)
$db = new Database();
$conn = $db->connect();

// Parametri dal modal
$selectedCompany = isset($_GET['azienda']) ? trim($_GET['azienda']) : null;
$startDate       = $_GET['start_date'] ?? null;
$endDate         = $_GET['end_date'] ?? null;

if (!$selectedCompany || !$startDate || !$endDate) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti. Seleziona azienda e intervallo date.']);
    exit;
}

// Traduco mese in italiano per intestazione
$monthsTranslation = [
    'January' => 'Gennaio',
    'February' => 'Febbraio',
    'March' => 'Marzo',
    'April' => 'Aprile',
    'May' => 'Maggio',
    'June' => 'Giugno',
    'July' => 'Luglio',
    'August' => 'Agosto',
    'September' => 'Settembre',
    'October' => 'Ottobre',
    'November' => 'Novembre',
    'December' => 'Dicembre',
];

$startMonthEnglish  = date('F', strtotime($startDate));
$startYear          = date('Y', strtotime($startDate));
$startMonthItalian  = $monthsTranslation[$startMonthEnglish] ?? $startMonthEnglish;

// ===========================================================
// 1) Carico il template Excel
// ===========================================================
$templatePath = APP_ROOT . '/includes/presenze_azienda_template.xlsx';
if (!file_exists($templatePath)) {
    Response::error("Template non trovato: " . htmlspecialchars($templatePath), 500);
}

$spreadsheet = IOFactory::load($templatePath);
$sheet       = $spreadsheet->getActiveSheet();

// Intestazioni in testa al template
$sheet->setCellValue('A1', strtoupper($selectedCompany)); // nome azienda
$sheet->setCellValue('N3', ucfirst($startMonthItalian)); // mese
$sheet->setCellValue('Q3', $startYear);                  // anno
$sheet->getStyle('N3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Etichetta colonna F (permesso non retribuito) se non c'è già
$sheet->setCellValue('F4', 'PERMESSO NON RETR.');
$sheet->getStyle('F4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Stile base per riga "Totale" alla fine
$styleTotale = [
    'font' => [
        'name' => 'Calibri',
        'size' => 11,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
    ],
];

// ===========================================================
// 2) Calcolo festività italiane per l'anno
// ===========================================================
$easterTimestamp = easter_date($startYear);
$pasqua          = date('d-m', $easterTimestamp);
$pasquetta       = date('d-m', strtotime('+1 day', $easterTimestamp));

$italianHolidays = [
    '01-01', '06-01', '25-04', '01-05', '02-06', '15-08',
    '01-11', '08-12', '25-12', '26-12',
    $pasqua, $pasquetta,
];

// Conta giorni lavorativi (lun-ven esclusi festivi) nell'intervallo
function calculateWeekdays($startDate, $endDate, $holidays)
{
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);
    $days  = 0;

    while ($start <= $end) {
        $dow = $start->format('N');     // 1 = lun, 7 = dom
        $fmt = $start->format('d-m');   // per confronto con array festivi
        if ($dow <= 5 && !in_array($fmt, $holidays)) {
            $days++;
        }
        $start->modify('+1 day');
    }

    return $days;
}

$totalLunVenDays = calculateWeekdays($startDate, $endDate, $italianHolidays);

// ===========================================================
// 3) Recupero presenze da bb_presenze per azienda + periodo
//    Aggrego per operaio + data
// ===========================================================
$sql = "
    SELECT 
        CONCAT(w.last_name, ' ', w.first_name) AS nome_completo,
        p.data,
        SUM(
            CASE 
                WHEN p.turno = 'Intero' THEN 1
                WHEN p.turno = 'Mezzo'  THEN 0.5
                ELSE 0
            END
        ) AS total_days,
        SUM(CASE WHEN p.pranzo = 'Loro' THEN 1 ELSE 0 END) AS total_pranzi_loro,
        SUM(CASE WHEN p.cena   = 'Loro' THEN 1 ELSE 0 END) AS total_cene_loro
    FROM bb_presenze p
    INNER JOIN bb_workers w ON w.id = p.worker_id
    WHERE p.data BETWEEN :startDate AND :endDate
      AND p.azienda = :azienda
    GROUP BY w.first_name, w.last_name, p.data
";

$stmt = $conn->prepare($sql);
$stmt->execute([
    ':startDate' => $startDate,
    ':endDate'   => $endDate,
    ':azienda'   => $selectedCompany,
]);

$presenzeRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===========================================================
// 3.1) Aggiungo anticipi, rimborsi e multe
// ===========================================================
$sqlAnticipi = "
    SELECT 
        CONCAT(w.last_name, ' ', w.first_name) AS nome_completo,
        SUM(a.importo) AS totale_anticipi
    FROM bb_anticipi a
    INNER JOIN bb_workers w ON w.id = a.operaio_id
    WHERE a.data BETWEEN :startDate AND :endDate
    GROUP BY w.id
";

$sqlRimborsi = "
    SELECT 
        CONCAT(w.last_name, ' ', w.first_name) AS nome_completo,
        SUM(r.importo) AS totale_rimborsi
    FROM bb_rimborsi r
    INNER JOIN bb_workers w ON w.id = r.operaio_id
    WHERE r.data BETWEEN :startDate AND :endDate
    GROUP BY w.id
";

$sqlMulte = "
    SELECT 
        CONCAT(w.last_name, ' ', w.first_name) AS nome_completo,
        SUM(m.importo) AS totale_multe
    FROM bb_multe m
    INNER JOIN bb_workers w ON w.id = m.operaio_id
    WHERE m.data BETWEEN :startDate AND :endDate
    GROUP BY w.id
";

function fetchKeyedTotals(PDO $conn, string $sql, array $params, string $field)
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Chiave = nome completo operaio (coerente con presenze)
        $out[$row['nome_completo']] = (float)$row[$field];
    }
    return $out;
}

$anticipiMap = fetchKeyedTotals(
    $conn,
    $sqlAnticipi,
    [':startDate' => $startDate, ':endDate' => $endDate],
    'totale_anticipi'
);

$rimborsiMap = fetchKeyedTotals(
    $conn,
    $sqlRimborsi,
    [':startDate' => $startDate, ':endDate' => $endDate],
    'totale_rimborsi'
);

$multeMap = fetchKeyedTotals(
    $conn,
    $sqlMulte,
    [':startDate' => $startDate, ':endDate' => $endDate],
    'totale_multe'
);


// ===========================================================
// 4) Aggrego a livello di operaio come nel vecchio script
// ===========================================================
$workerTotals = [];

foreach ($presenzeRaw as $row) {
    $nome     = $row['nome_completo'];
    $giorno   = $row['data'];
    $totDays  = (float)$row['total_days'];
    $totPr    = (int)$row['total_pranzi_loro'];
    $totCe    = (int)$row['total_cene_loro'];

    if (!isset($workerTotals[$nome])) {
        $workerTotals[$nome] = [
            'total_days'          => 0.0,
            'total_pranzi'        => 0,
            'total_cene'          => 0,
            'lunVenDays'          => 0.0,
            'weekendFestiviDays'  => 0.0,
            // Questi per ora li teniamo ma a 0 (nessun DB BOB per anticipi/rimborsi/multe)
            'total_anticipi'      => 0.0,
            'total_rimborsi'      => 0.0,
            'total_multe'         => 0.0,
            // costo persona lasciato vuoto (colonna resta, ma vuota)
            'costo_giornaliero'   => null,
        ];
    }

    $workerTotals[$nome]['total_days']   += $totDays;
    $workerTotals[$nome]['total_pranzi'] += $totPr;
    $workerTotals[$nome]['total_cene']   += $totCe;

    $dow  = date('N', strtotime($giorno));
    $dstr = date('d-m', strtotime($giorno));
    $isHol = in_array($dstr, $italianHolidays);
    $isWkd = ($dow >= 6);

    if (!$isHol && !$isWkd) {
        $workerTotals[$nome]['lunVenDays'] += $totDays;
    } else {
        $workerTotals[$nome]['weekendFestiviDays'] += $totDays;
    }
}

// ===========================================================
// 4.1) Merge anticipi, rimborsi e multe nei totali operaio
// ===========================================================
foreach ($workerTotals as $nome => &$totals) {

    // Anticipi: se non esistono → 0
    $totals['total_anticipi'] = $anticipiMap[$nome] ?? 0.0;

    // Rimborsi: se non esistono → 0
    $totals['total_rimborsi'] = $rimborsiMap[$nome] ?? 0.0;

    // Multe: se non esistono → 0
    $totals['total_multe'] = $multeMap[$nome] ?? 0.0;
}
unset($totals);


// ===========================================================
// 5) Scrittura su Excel usando il template
//    Riga base dati: 5 (come nel vecchio script)
// ===========================================================
$rowNumber    = 5;
$lastStaticRow = 5; // la riga che usiamo come "modello" da clonare

foreach ($workerTotals as $workerName => $totals) {

    // ferie = giorni lavorativi teorici periodo - giorni effettivamente lavorati (lun-ven)
    $ferie     = $totalLunVenDays - $totals['lunVenDays'];
    $totalPasti = $totals['total_pranzi'] + $totals['total_cene'];

    // se oltre la prima riga dati, inseriamo una riga nuova copiando lo stile
    if ($rowNumber > $lastStaticRow) {
        $sheet->insertNewRowBefore($rowNumber, 1);
        foreach (range('A', 'Q') as $column) {
            $sheet->duplicateStyle(
                $sheet->getStyle("{$column}{$lastStaticRow}"),
                "{$column}{$rowNumber}"
            );
        }
    }

    // Colonne A–E
    $sheet->setCellValue("A{$rowNumber}", $workerName);
    $sheet->getStyle("A{$rowNumber}")->getFont()->setSize(10);

    $sheet->setCellValue("B{$rowNumber}", $totals['lunVenDays']);
    $sheet->setCellValue("C{$rowNumber}", $totals['weekendFestiviDays']);
    $sheet->setCellValue("D{$rowNumber}", $totals['total_days']);
    $sheet->setCellValue("E{$rowNumber}", $ferie);

    // Colonna F: permesso non retribuito → per ora 0 fisso, come nel vecchio script
    $sheet->setCellValue("F{$rowNumber}", 0);

    // Colonna G: costo persona → la lasciamo vuota (richiesta tua)
    $sheet->setCellValue("G{$rowNumber}", $totals['costo_giornaliero']);

    // Colonna I: totale pasti (solo “Loro”)
    $sheet->setCellValue("I{$rowNumber}", $totalPasti);

    // Colonne J, K, L: rimborsi, anticipi, multe → per ora 0
    $sheet->setCellValue("J{$rowNumber}", $totals['total_rimborsi']);
    $sheet->setCellValue("K{$rowNumber}", $totals['total_anticipi']);
    $sheet->setCellValue("L{$rowNumber}", $totals['total_multe']);

    // Formule in P e Q
    $sheet->setCellValue("P{$rowNumber}", "=N{$rowNumber}+O{$rowNumber}");
    $sheet->setCellValue("Q{$rowNumber}", "=J{$rowNumber}+K{$rowNumber}+M{$rowNumber}");

    $rowNumber++;
}

// ===========================================================
// 6) Riga Totale (somma per colonne B–Q dalla riga 5 all'ultima)
// ===========================================================
$totaleRow = $rowNumber + 1;

foreach (range('B', 'Q') as $col) {
    $sheet->setCellValue("{$col}{$totaleRow}", "=SUM({$col}5:{$col}" . ($rowNumber - 1) . ")");
    $sheet->getStyle("{$col}{$totaleRow}")->applyFromArray($styleTotale);
}

// ===========================================================
// 7) Output al browser
// ===========================================================
$fileName = 'Presenze_' . preg_replace('/\s+/', '_', strtoupper($selectedCompany)) . '_' . $startMonthItalian . '_' . $startYear . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
