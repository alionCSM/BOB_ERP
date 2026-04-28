<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

final class ProgrammazioneController
{
    public function __construct(private \PDO $conn) {}

    // ── GET /programmazione ───────────────────────────────────────────────────

    public function index(Request $request): never
    {
        $currentMonth = (int)date('n');
        $currentYear  = (int)date('Y');
        $mesiNomi     = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                         'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        $pageTitle    = 'Programmazione Mezzi';

        Response::view('programmazione/index.html.twig', $request, compact(
            'currentMonth', 'currentYear', 'mesiNomi', 'pageTitle'
        ));
    }

    // ── GET /pianificazione ───────────────────────────────────────────────────

    public function pianificazione(Request $request): never
    {
        // Consorziata company names
        $stmt = $this->conn->query("SELECT name FROM bb_companies WHERE consorziata = 1");
        $consCompanyNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $consNamesSet     = array_flip($consCompanyNames);

        // All active workers
        $stmt = $this->conn->query("
            SELECT id, first_name, last_name, company
            FROM bb_workers
            WHERE active = 'Y'
            ORDER BY last_name, first_name
        ");
        $allWorkers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($allWorkers as &$w) {
            $w['is_nostro'] = empty($w['company']) || !isset($consNamesSet[$w['company']]);
        }
        unset($w);

        $nosTriCount    = count(array_filter($allWorkers, static fn($w) => $w['is_nostro']));
        $workersJson    = json_encode($allWorkers, JSON_UNESCAPED_UNICODE);

        // Consorziate companies with active worker count
        $stmt = $this->conn->query("
            SELECT c.id, c.name,
                   COUNT(w.id) AS tot_workers
            FROM bb_companies c
            LEFT JOIN bb_workers w ON w.company = c.name AND w.active = 'Y'
            WHERE c.consorziata = 1
            GROUP BY c.id, c.name
            ORDER BY c.name
        ");
        $consorziate    = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $consorziateJson = json_encode($consorziate, JSON_UNESCAPED_UNICODE);

        $pageTitle = 'Pianificazione Squadre';

        Response::view('pianificazione/index.html.twig', $request, compact(
            'allWorkers', 'nosTriCount', 'workersJson',
            'consorziate', 'consorziateJson', 'pageTitle'
        ));
    }

    // ── GET|POST /programmazione/api ──────────────────────────────────────────

    public function api(Request $request): never
    {
        header('Content-Type: application/json; charset=utf-8');

        // Expose the variables the api view expects
        $conn               = $this->conn;
        $authenticated_user = $GLOBALS['authenticated_user'] ?? [];

        $viewFile = APP_ROOT . '/views/programmazione/api.php';
        $oldCwd   = getcwd();
        chdir(dirname($viewFile));
        include $viewFile;
        chdir($oldCwd);
        exit;
    }

    // ── POST /pianificazione/save ─────────────────────────────────────────────

    public function save(Request $request): never
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['data'])) {
            Response::json(['ok' => false, 'error' => 'Dati non validi'], 400);
        }

        $date     = $input['data'];
        $cantieri = $input['cantieri'] ?? [];
        $userId   = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("DELETE FROM bb_pianificazione WHERE data = :data");
            $stmt->execute([':data' => $date]);

            $sortOrder = 0;
            foreach ($cantieri as $c) {
                $cantiere = trim($c['cantiere'] ?? '');
                if ($cantiere === '') continue;

                $stmt = $this->conn->prepare("
                    INSERT INTO bb_pianificazione (data, cantiere, sort_order, created_by)
                    VALUES (:data, :cantiere, :sort, :uid)
                ");
                $stmt->execute([':data' => $date, ':cantiere' => $cantiere, ':sort' => $sortOrder++, ':uid' => $userId]);
                $pid = (int)$this->conn->lastInsertId();

                foreach ($c['nostri'] ?? [] as $n) {
                    $wid   = (int)($n['worker_id'] ?? 0);
                    $wname = trim($n['worker_name'] ?? '');
                    if ($wid <= 0 && $wname === '') continue;
                    $stmt = $this->conn->prepare("
                        INSERT INTO bb_pianificazione_nostri (pianificazione_id, worker_id, worker_name, auto_targa, note)
                        VALUES (:pid, :wid, :wname, :targa, :note)
                    ");
                    $stmt->execute([':pid' => $pid, ':wid' => $wid > 0 ? $wid : null, ':wname' => $wname ?: null, ':targa' => trim($n['auto_targa'] ?? ''), ':note' => trim($n['note'] ?? '')]);
                }

                foreach ($c['consorziate'] ?? [] as $cons) {
                    $nome = trim($cons['azienda_nome'] ?? '');
                    if ($nome === '') continue;
                    $stmt = $this->conn->prepare("
                        INSERT INTO bb_pianificazione_consorziate (pianificazione_id, azienda_nome, quantita, note)
                        VALUES (:pid, :nome, :qty, :note)
                    ");
                    $stmt->execute([':pid' => $pid, ':nome' => $nome, ':qty' => max(1, (int)($cons['quantita'] ?? 1)), ':note' => trim($cons['note'] ?? '')]);
                }
            }

            $this->conn->commit();
            Response::json(['ok' => true]);
        } catch (\Exception $e) {
            $this->conn->rollBack();
            Response::json(['ok' => false, 'error' => 'Errore nel salvataggio: ' . $e->getMessage()], 500);
        }
    }

    // ── POST /pianificazione/copy ─────────────────────────────────────────────

    public function copy(Request $request): never
    {
        $input    = json_decode(file_get_contents('php://input'), true);
        $fromDate = $input['from_date'] ?? '';
        $toDate   = $input['to_date']   ?? '';

        if (!$fromDate || !$toDate) {
            Response::json(['ok' => false, 'error' => 'Date mancanti'], 400);
        }

        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);

        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_pianificazione WHERE data = :data");
        $stmt->execute([':data' => $fromDate]);
        if ((int)$stmt->fetchColumn() === 0) {
            Response::json(['ok' => false, 'error' => 'Nessun piano trovato per ' . $fromDate]);
        }

        $stmt = $this->conn->prepare("SELECT id, cantiere, sort_order FROM bb_pianificazione WHERE data = :data ORDER BY sort_order, id");
        $stmt->execute([':data' => $fromDate]);
        $sourceCantieri = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("DELETE FROM bb_pianificazione WHERE data = :data");
            $stmt->execute([':data' => $toDate]);

            foreach ($sourceCantieri as $sc) {
                $stmt = $this->conn->prepare("INSERT INTO bb_pianificazione (data, cantiere, sort_order, created_by) VALUES (:data, :cantiere, :sort, :uid)");
                $stmt->execute([':data' => $toDate, ':cantiere' => $sc['cantiere'], ':sort' => $sc['sort_order'], ':uid' => $userId]);
                $newPid = (int)$this->conn->lastInsertId();

                $stmt2 = $this->conn->prepare("SELECT worker_id, worker_name, auto_targa, note FROM bb_pianificazione_nostri WHERE pianificazione_id = :pid");
                $stmt2->execute([':pid' => $sc['id']]);
                foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $n) {
                    $ins = $this->conn->prepare("INSERT INTO bb_pianificazione_nostri (pianificazione_id, worker_id, worker_name, auto_targa, note) VALUES (:pid, :wid, :wn, :t, :n)");
                    $ins->execute([':pid' => $newPid, ':wid' => $n['worker_id'], ':wn' => $n['worker_name'], ':t' => $n['auto_targa'], ':n' => $n['note']]);
                }

                $stmt3 = $this->conn->prepare("SELECT azienda_nome, quantita, note FROM bb_pianificazione_consorziate WHERE pianificazione_id = :pid");
                $stmt3->execute([':pid' => $sc['id']]);
                foreach ($stmt3->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    $ins = $this->conn->prepare("INSERT INTO bb_pianificazione_consorziate (pianificazione_id, azienda_nome, quantita, note) VALUES (:pid, :nome, :qty, :n)");
                    $ins->execute([':pid' => $newPid, ':nome' => $c['azienda_nome'], ':qty' => $c['quantita'], ':n' => $c['note']]);
                }
            }

            $this->conn->commit();
            Response::json(['ok' => true]);
        } catch (\Exception $e) {
            $this->conn->rollBack();
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── GET /pianificazione/get ───────────────────────────────────────────────

    public function get(Request $request): never
    {
        $date = trim($request->get('data', ''));
        if (!$date) {
            Response::json(['ok' => false, 'error' => 'Data mancante']);
        }

        $stmt = $this->conn->prepare("SELECT id, cantiere, sort_order FROM bb_pianificazione WHERE data = :data ORDER BY sort_order, id");
        $stmt->execute([':data' => $date]);
        $cantieri = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($cantieri as $c) {
            $pid = (int)$c['id'];

            $stmt2 = $this->conn->prepare("
                SELECT pn.worker_id, pn.worker_name, pn.auto_targa, pn.note,
                       w.first_name, w.last_name
                FROM bb_pianificazione_nostri pn
                LEFT JOIN bb_workers w ON w.id = pn.worker_id
                WHERE pn.pianificazione_id = :pid
                ORDER BY COALESCE(w.last_name, pn.worker_name), w.first_name
            ");
            $stmt2->execute([':pid' => $pid]);

            $stmt3 = $this->conn->prepare("SELECT azienda_nome, quantita, note FROM bb_pianificazione_consorziate WHERE pianificazione_id = :pid");
            $stmt3->execute([':pid' => $pid]);

            $result[] = [
                'id'          => $pid,
                'cantiere'    => $c['cantiere'],
                'nostri'      => $stmt2->fetchAll(\PDO::FETCH_ASSOC),
                'consorziate' => $stmt3->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }

        Response::json(['ok' => true, 'cantieri' => $result]);
    }

    // ── GET /pianificazione/print ─────────────────────────────────────────────

    public function print(Request $request): never
    {
        $date = trim($request->get('data', ''));
        if (!$date) {
            Response::error('Data mancante', 400);
        }

        $stmt = $this->conn->prepare("SELECT id, cantiere, sort_order FROM bb_pianificazione WHERE data = :data ORDER BY sort_order, id");
        $stmt->execute([':data' => $date]);
        $cantieri = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($cantieri)) {
            Response::error('Nessun piano per questa data.', 404);
        }

        $dateFormatted = date('d/m/Y', strtotime($date));
        $dayNames = [
            'Monday' => 'Lunedì', 'Tuesday' => 'Martedì', 'Wednesday' => 'Mercoledì',
            'Thursday' => 'Giovedì', 'Friday' => 'Venerdì', 'Saturday' => 'Sabato', 'Sunday' => 'Domenica',
        ];
        $dayName = $dayNames[date('l', strtotime($date))] ?? '';

        $blocks      = [];
        $totalNostri = 0;
        $totalCons   = 0;

        foreach ($cantieri as $c) {
            $pid = (int)$c['id'];

            $stmt2 = $this->conn->prepare("
                SELECT pn.worker_name, w.last_name, w.first_name, pn.auto_targa, pn.note
                FROM bb_pianificazione_nostri pn
                LEFT JOIN bb_workers w ON w.id = pn.worker_id
                WHERE pn.pianificazione_id = :pid
                ORDER BY COALESCE(w.last_name, pn.worker_name), w.first_name
            ");
            $stmt2->execute([':pid' => $pid]);
            $nostri = $stmt2->fetchAll(\PDO::FETCH_ASSOC);

            $stmt3 = $this->conn->prepare("SELECT azienda_nome, quantita, note FROM bb_pianificazione_consorziate WHERE pianificazione_id = :pid ORDER BY azienda_nome");
            $stmt3->execute([':pid' => $pid]);
            $cons = $stmt3->fetchAll(\PDO::FETCH_ASSOC);

            $nostCount = count($nostri);
            $consCount = 0;
            foreach ($cons as $co) $consCount += (int)$co['quantita'];
            $totalNostri += $nostCount;
            $totalCons   += $consCount;

            $lines    = 1 + $nostCount + count($cons);
            $blocks[] = ['cantiere' => $c['cantiere'], 'nostri' => $nostri, 'cons' => $cons, 'lines' => $lines];
        }

        $grandTotal = $totalNostri + $totalCons;
        $h     = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nonce = csp_nonce();

        // ── Build all blocks in order — CSS columns handles the layout ────────
        $blocksHtml = '';
        foreach ($blocks as $block) {
            $blocksHtml .= '<div class="block">';
            $blocksHtml .= '<div class="block-name">' . $h($block['cantiere']) . '</div>';

            foreach ($block['nostri'] as $n) {
                $name  = $n['last_name']
                    ? mb_strtoupper($n['last_name']) . ' ' . $n['first_name']
                    : mb_strtoupper($n['worker_name'] ?? '');
                $plate = trim($n['auto_targa'] ?? '');
                $note  = trim($n['note'] ?? '');

                $extra = '';
                if ($plate !== '') $extra .= ' <span class="plate">' . $h($plate) . '</span>';
                if ($note  !== '') $extra .= ' <span class="wnote">' . $h($note) . '</span>';

                $blocksHtml .= '<div class="row">'
                    . '<span class="wname">' . $h($name) . '</span>'
                    . $extra
                    . '</div>';
            }

            foreach ($block['cons'] as $co) {
                $note = trim($co['note'] ?? '');
                $blocksHtml .= '<div class="row cons-row">'
                    . '<span class="cons-qty">' . (int)$co['quantita'] . 'x</span>'
                    . ' <span class="cons-name">' . $h($co['azienda_nome']) . '</span>'
                    . ($note !== '' ? ' <span class="wnote">(' . $h($note) . ')</span>' : '')
                    . '</div>';
            }

            $blocksHtml .= '</div>';
        }

        // ── Inline CSS ────────────────────────────────────────────────────────
        $css = <<<'CSS'
* { box-sizing: border-box; margin: 0; padding: 0; }
@page { margin: 10mm 8mm 8mm 8mm; size: A4 portrait; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8.5pt;
    line-height: 1.3;
    color: #1e293b;
    background: #f8fafc;
}

/* ── Screen toolbar ── */
.toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
}
.toolbar-title {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    flex: 1;
}
.btn-print {
    padding: 7px 20px;
    background: #3b82f6;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.btn-print:hover { background: #2563eb; }

/* ── Date header ── */
.date-header {
    font-size: 11pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #1e293b;
    border-bottom: 2px solid #cbd5e1;
    padding-bottom: 4px;
    margin-bottom: 10px;
}

/* ── 3-column grid ── */
.piano-grid {
    columns: 3;
    column-gap: 8px;
    column-fill: balance;
}

/* ── Cantiere block ── */
.block {
    break-inside: avoid;
    -webkit-column-break-inside: avoid;
    page-break-inside: avoid;
    margin-bottom: 6px;
    display: inline-block;
    width: 100%;
    border-radius: 4px;
    overflow: hidden;
    border: 1px solid #cbd5e1;
    background: #fff;
}

.block-name {
    font-size: 7.5pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    background: #dbeafe;
    color: #1e3a5f;
    padding: 2px 6px;
    border-bottom: 1px solid #bfdbfe;
}

/* ── Rows ── */
.row {
    display: block;
    border-bottom: 1px solid #f1f5f9;
    padding: 1px 6px;
    white-space: nowrap;
    overflow: hidden;
    font-size: 7.5pt;
    color: #1e293b;
}
.row:last-child { border-bottom: none; }

.wname { font-weight: 700; }
.plate {
    font-weight: 600;
    font-size: 7pt;
    margin-left: 5px;
    color: #64748b;
}
.wnote {
    font-style: italic;
    font-size: 7pt;
    margin-left: 5px;
    color: #94a3b8;
}

/* ── Consorziata row ── */
.cons-row { background: #f8fafc; }
.cons-qty { font-weight: 800; color: #475569; }
.cons-name { font-weight: 600; margin-left: 2px; color: #475569; }

/* ── Footer ── */
.footer {
    margin-top: 10px;
    padding-top: 5px;
    border-top: 1px solid #e2e8f0;
    font-size: 7pt;
    color: #94a3b8;
    text-align: center;
}

@media print {
    .toolbar { display: none; }
    body { background: #fff; font-size: 8pt; }
    .block { border-color: #d1d5db; }
    .block-name { background: #dbeafe; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
CSS;

        header('Content-Type: text/html; charset=utf-8');
        $cantCount = count($cantieri);
        $printDate = date('d/m/Y H:i');
        echo <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Pianificazione {$h($dayName)} {$h($dateFormatted)}</title>
<style>{$css}</style>
</head>
<body>

<div class="toolbar">
    <span class="toolbar-title">Pianificazione &mdash; {$h($dayName)} {$h($dateFormatted)}</span>
    <button class="btn-print" id="btn-print">&#128438;&nbsp; Stampa / Salva PDF</button>
</div>

<div class="date-header">{$h($dayName)}, {$h($dateFormatted)}</div>

<div class="piano-grid">{$blocksHtml}</div>

<div class="footer">
    Totale: <strong>{$grandTotal}</strong> persone &nbsp;&mdash;&nbsp;
    {$totalNostri} nostri &nbsp;+&nbsp; {$totalCons} consorziati &nbsp;&mdash;&nbsp;
    {$cantCount} cantieri &nbsp;&mdash;&nbsp; stampato il {$h($printDate)}
</div>

<script nonce="{$nonce}">
document.getElementById('btn-print').addEventListener('click', function() {
    window.print();
});
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 400);
});
</script>
</body>
</html>
HTML;
        exit;
    }
}
