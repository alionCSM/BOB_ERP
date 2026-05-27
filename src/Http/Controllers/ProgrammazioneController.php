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

        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $myId   = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);

        try {
            if ($action === 'list'     && $method === 'GET')  { $this->apiList(); }
            if ($action === 'save'     && $method === 'POST') { $this->apiSave($myId); }
            if ($action === 'status'   && $method === 'POST') { $this->apiStatus(); }
            if ($action === 'delete'   && $method === 'POST') { $this->apiDelete(); }
            if ($action === 'reorder'  && $method === 'POST') { $this->apiReorder(); }
            if ($action === 'alerts'   && $method === 'GET')  { $this->apiAlerts(); }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    // ─── API actions ──────────────────────────────────────────────────────────

    private function apiList(): never
    {
        $mese = (int)($_GET['mese'] ?? date('n'));
        $anno = (int)($_GET['anno'] ?? date('Y'));

        $stmt = $this->conn->prepare("
            SELECT id, data, indirizzo, committente, mezzi, stato_mezzi,
                   durata, referente, capo_squadra, tot_persone,
                   trasferta, stato_trasferta,
                   info_beppe, stato_beppe, sort_order
            FROM bb_programmazione
            WHERE mese = :m AND anno = :a
            ORDER BY data IS NULL, data, sort_order, id
        ");
        $stmt->execute([':m' => $mese, ':a' => $anno]);
        echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        exit;
    }

    private function apiSave(int $myId): never
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $id          = (int)($input['id'] ?? 0);
        $mese        = (int)($input['mese'] ?? date('n'));
        $anno        = (int)($input['anno'] ?? date('Y'));
        $data        = !empty($input['data']) ? $input['data'] : null;
        $indirizzo   = trim((string)($input['indirizzo']   ?? ''));
        $committente = trim((string)($input['committente'] ?? ''));
        $mezzi       = trim((string)($input['mezzi']       ?? ''));
        $durata      = trim((string)($input['durata']      ?? ''));
        $referente   = trim((string)($input['referente']   ?? ''));
        $capoSquadra = trim((string)($input['capo_squadra'] ?? ''));
        $totPersone  = !empty($input['tot_persone']) ? (int)$input['tot_persone'] : null;
        $trasferta   = trim((string)($input['trasferta']   ?? ''));
        $infoBeppe   = trim((string)($input['info_beppe']  ?? ''));
        $sortOrder   = (int)($input['sort_order'] ?? 0);

        // Auto-move row to its own month if the date falls outside the current
        // mese/anno tab (operator pasted a date from another month).
        $movedToMonth = null;
        if ($data) {
            $ts = strtotime($data);
            if ($ts !== false) {
                $dm = (int)date('n', $ts);
                $dy = (int)date('Y', $ts);
                if ($dm !== $mese || $dy !== $anno) {
                    $mese = $dm;
                    $anno = $dy;
                    $movedToMonth = $dm;
                }
            }
        }

        $oldRow = $id > 0 ? $this->fetchProgrammazioneRow($id) : null;

        if ($id > 0) {
            $stmt = $this->conn->prepare("
                UPDATE bb_programmazione
                   SET mese = :mese, anno = :anno, data = :data,
                       indirizzo = :ind, committente = :com, mezzi = :mez,
                       durata = :dur, referente = :ref, capo_squadra = :cs, tot_persone = :tp,
                       trasferta = :tra, info_beppe = :inf, sort_order = :so
                 WHERE id = :id
            ");
            $stmt->execute([
                ':mese' => $mese, ':anno' => $anno, ':data' => $data,
                ':ind'  => $indirizzo, ':com' => $committente, ':mez' => $mezzi,
                ':dur'  => $durata, ':ref' => $referente, ':cs' => $capoSquadra, ':tp' => $totPersone,
                ':tra'  => $trasferta, ':inf' => $infoBeppe, ':so' => $sortOrder,
                ':id'   => $id,
            ]);
        } else {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_programmazione (mese, anno, data, indirizzo, committente, mezzi, durata, referente, capo_squadra, tot_persone, trasferta, info_beppe, sort_order, created_by)
                VALUES (:mese, :anno, :data, :ind, :com, :mez, :dur, :ref, :cs, :tp, :tra, :inf, :so, :cb)
            ");
            $stmt->execute([
                ':mese' => $mese, ':anno' => $anno, ':data' => $data,
                ':ind'  => $indirizzo, ':com' => $committente, ':mez' => $mezzi,
                ':dur'  => $durata, ':ref' => $referente, ':cs' => $capoSquadra, ':tp' => $totPersone,
                ':tra'  => $trasferta, ':inf' => $infoBeppe, ':so' => $sortOrder,
                ':cb'   => $myId,
            ]);
            $id = (int)$this->conn->lastInsertId();
        }

        $currentRow = [
            'data' => $data, 'indirizzo' => $indirizzo, 'committente' => $committente,
            'mezzi' => $mezzi, 'stato_mezzi' => $oldRow['stato_mezzi'] ?? null,
            'trasferta' => $trasferta, 'stato_trasferta' => $oldRow['stato_trasferta'] ?? null,
            'info_beppe' => $infoBeppe, 'stato_beppe' => $oldRow['stato_beppe'] ?? null,
        ];
        $this->sendProgrammazioneNotifications($currentRow, $oldRow, $myId);

        $resp = ['ok' => true, 'id' => $id];
        if ($movedToMonth !== null) {
            $resp['moved_to_month'] = $movedToMonth;
            $resp['moved_to_year']  = $anno;
        }
        echo json_encode($resp);
        exit;
    }

    private function apiStatus(): never
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $id    = (int)($input['id'] ?? 0);
        $field = (string)($input['field'] ?? '');
        $value = $input['value'] ?? null;

        $allowed     = ['stato_mezzi', 'stato_trasferta', 'stato_beppe'];
        $validValues = [null, 'in_lavorazione', 'completato'];

        if ($id <= 0 || !in_array($field, $allowed, true) || !in_array($value, $validValues, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid params']);
            exit;
        }

        $stmt = $this->conn->prepare("UPDATE bb_programmazione SET {$field} = :val WHERE id = :id");
        $stmt->execute([':val' => $value, ':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    private function apiDelete(): never
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        $stmt = $this->conn->prepare("DELETE FROM bb_programmazione WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    private function apiReorder(): never
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $order = $input['order'] ?? [];

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("UPDATE bb_programmazione SET sort_order = :so WHERE id = :id");
            foreach ($order as $i => $rowId) {
                $stmt->execute([':so' => $i, ':id' => (int)$rowId]);
            }
            $this->conn->commit();
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    private function apiAlerts(): never
    {
        $stmt = $this->conn->prepare("
            SELECT p.id, p.data, p.indirizzo, p.committente,
                   p.mezzi, p.stato_mezzi,
                   p.trasferta, p.stato_trasferta,
                   p.info_beppe, p.stato_beppe
            FROM bb_programmazione p
            WHERE p.data IS NOT NULL
              AND p.data BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              AND (
                  (p.stato_mezzi IS NULL OR p.stato_mezzi != 'completato')
                  OR (p.stato_trasferta IS NULL OR p.stato_trasferta != 'completato')
                  OR (p.stato_beppe IS NULL OR p.stato_beppe != 'completato')
              )
            ORDER BY p.data ASC
        ");
        $stmt->execute();
        $alerts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($alerts as $row) {
            $d        = \DateTime::createFromFormat('Y-m-d', $row['data']);
            $dateStr  = $d ? $d->format('d/m/Y') : $row['data'];
            $daysLeft = $d ? (int)$d->diff(new \DateTime('today'))->days : 0;
            $urgency  = $daysLeft <= 2 ? 'high' : 'normal';
            $label    = trim(($row['committente'] ?? '') . ' — ' . ($row['indirizzo'] ?? ''), ' —');

            $pending = [];
            if (($row['stato_mezzi']     ?? '') !== 'completato') $pending[] = 'Mezzi';
            if (($row['stato_trasferta'] ?? '') !== 'completato') $pending[] = 'Trasferta';
            if (($row['stato_beppe']     ?? '') !== 'completato') $pending[] = 'Info Beppe';

            $result[] = [
                'id'        => $row['id'],
                'data'      => $dateStr,
                'days_left' => $daysLeft,
                'urgency'   => $urgency,
                'label'     => $label,
                'pending'   => $pending,
            ];
        }

        echo json_encode(['ok' => true, 'alerts' => $result]);
        exit;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function fetchProgrammazioneRow(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_programmazione WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Notifications: for each field (mezzi, trasferta, info_beppe):
     *   - empty + not completato     → notify scrivere
     *   - has text + not completato  → notify azione
     *   - completato                 → no notification
     */
    private function sendProgrammazioneNotifications(array $currentRow, ?array $oldRow, int $myId): void
    {
        $link  = '/programmazione';
        $label = $this->buildProgrammazioneLabel($currentRow);

        $fields = [
            'mezzi'      => ['stato' => 'stato_mezzi',     'scrivere' => 'notif_mezzi_scrivere',     'azione' => 'notif_mezzi_azione',     'labelIt' => 'Mezzi',      'cat' => 'programmazione_mezzi'],
            'trasferta'  => ['stato' => 'stato_trasferta', 'scrivere' => 'notif_trasferta_scrivere', 'azione' => 'notif_trasferta_azione', 'labelIt' => 'Trasferta',  'cat' => 'programmazione_trasferta'],
            'info_beppe' => ['stato' => 'stato_beppe',     'scrivere' => 'notif_beppe_scrivere',     'azione' => 'notif_beppe_azione',     'labelIt' => 'Info Beppe', 'cat' => 'programmazione_info'],
        ];

        foreach ($fields as $field => $cfg) {
            $value    = trim((string)($currentRow[$field]    ?? ''));
            $oldValue = trim((string)($oldRow[$field]        ?? ''));
            $stato    = $currentRow[$cfg['stato']]           ?? null;

            if ($stato === 'completato') continue;

            $isNew       = $oldRow === null;
            $wasEmpty    = $oldValue === '';
            $isEmpty     = $value === '';
            $textChanged = !$isNew && !$wasEmpty && !$isEmpty && $value !== $oldValue;

            if ($isEmpty && !$isNew && $wasEmpty) continue;

            if ($isNew || ($isEmpty && $wasEmpty)) {
                if ($isEmpty) {
                    $this->notifyByPermission($cfg['scrivere'], "{$cfg['labelIt']} da compilare",
                        "Nuovo cantiere — {$cfg['labelIt']} ancora da scrivere.\n{$label}",
                        $link, $myId, $cfg['cat'], 'high');
                }
                if (!$isEmpty) {
                    $short = strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
                    $this->notifyByPermission($cfg['azione'], "{$cfg['labelIt']} da gestire",
                        "Nuova richiesta: {$short}\n{$label}",
                        $link, $myId, $cfg['cat'], 'high');
                }
            } elseif (!$wasEmpty && $isEmpty) {
                $this->notifyByPermission($cfg['scrivere'], "{$cfg['labelIt']} rimosso",
                    "{$cfg['labelIt']} è stato svuotato. Da ricompilare.\n{$label}",
                    $link, $myId, $cfg['cat'], 'high');
            } elseif ($wasEmpty && !$isEmpty) {
                $short = strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
                $this->notifyByPermission($cfg['azione'], "{$cfg['labelIt']} da gestire",
                    "Nuova richiesta: {$short}\n{$label}",
                    $link, $myId, $cfg['cat'], 'high');
            } elseif ($textChanged) {
                $short = strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
                $this->notifyByPermission($cfg['scrivere'], "{$cfg['labelIt']} modificato",
                    "Modifica: {$short}\n{$label}", $link, $myId, $cfg['cat'], 'normal');
                $this->notifyByPermission($cfg['azione'],   "{$cfg['labelIt']} modificato",
                    "Modifica: {$short}\n{$label}", $link, $myId, $cfg['cat'], 'normal');
            }
        }
    }

    private function buildProgrammazioneLabel(array $row): string
    {
        $parts = [];
        if (!empty($row['committente'])) $parts[] = $row['committente'];
        if (!empty($row['indirizzo'])) {
            $addr = $row['indirizzo'];
            if (strlen($addr) > 60) $addr = substr($addr, 0, 57) . '...';
            $parts[] = $addr;
        }
        if (!empty($row['data'])) {
            $d = \DateTime::createFromFormat('Y-m-d', $row['data']);
            if ($d) $parts[] = $d->format('d/m/Y');
        }
        return implode(' — ', $parts) ?: 'Nuova riga';
    }

    private function notifyByPermission(string $permission, string $title, string $message,
                                        string $link, int $excludeUserId, string $category,
                                        string $priority = 'normal'): void
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id
            FROM bb_users u
            INNER JOIN bb_user_permissions p ON p.user_id = u.id
            WHERE p.module = :mod AND u.active = 1 AND u.id != :me
        ");
        $stmt->execute([':mod' => $permission, ':me' => $excludeUserId]);
        $userIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($userIds)) return;

        $ins = $this->conn->prepare("
            INSERT INTO bb_notifications (user_id, title, message, link, category, priority, created_by, is_read, created_at)
            VALUES (:uid, :title, :msg, :link, :cat, :pri, :cb, 0, NOW())
        ");
        foreach ($userIds as $uid) {
            $ins->execute([
                ':uid'   => $uid,
                ':title' => $title,
                ':msg'   => $message,
                ':link'  => $link,
                ':cat'   => $category,
                ':pri'   => $priority,
                ':cb'    => $excludeUserId,
            ]);
        }
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
