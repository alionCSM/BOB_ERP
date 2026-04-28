<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

final class ApiController
{
    public function __construct(private \PDO $conn) {}

    // ── GET /api/search-company ───────────────────────────────────────────────

    public function searchCompany(Request $request): never
    {
        $q    = $_GET['q'] ?? '';
        $stmt = $this->conn->prepare("SELECT id, name FROM bb_companies WHERE active = 1 AND name LIKE :q ORDER BY name ASC");
        $stmt->execute([':q' => "%$q%"]);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = ['id' => $row['id'], 'name' => $row['name']];
        }

        Response::json($results);
    }

    // ── GET /api/attendance/workers ───────────────────────────────────────────

    public function loadWorkers(Request $request): never
    {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            Response::json([]);
        }

        $context = $_GET['context'] ?? '';

        $sql = "
            SELECT id,
                   CONCAT(first_name, ' ', last_name, ' (', UPPER(LEFT(company, 3)), ')') AS name
            FROM bb_workers
            WHERE CONCAT(first_name, ' ', last_name) LIKE :search
        ";
        if ($context !== 'attendance') {
            $sql .= " AND active = 'Y'";
        }
        $sql .= " ORDER BY first_name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':search' => "%$q%"]);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = ['value' => $row['id'], 'text' => $row['name']];
        }

        Response::json($results);
    }

    // ── GET /api/attendance/worksites ─────────────────────────────────────────

    public function loadWorksites(Request $request): never
    {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 3) {
            Response::json([]);
        }

        $context    = $_GET['context'] ?? '';
        $currentYear = date('Y');
        $yearPrefix = 'C' . substr($currentYear, 2);

        $sql = "
            SELECT w.id, CONCAT(w.worksite_code, ' - ', w.name) AS label
            FROM bb_worksites w
            WHERE (w.name LIKE ? OR w.worksite_code LIKE ?)
        ";
        $params = ['%' . $q . '%', '%' . $q . '%'];

        if ($context === 'attendance') {
            $sql    .= " AND (w.status != 'Completato' OR w.worksite_code LIKE ?)";
            $params[] = $yearPrefix . '%';
        }

        $sql .= " ORDER BY w.created_at DESC LIMIT 50";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = ['value' => $row['id'], 'text' => $row['label']];
        }

        Response::json($results);
    }

    // ── GET /api/attendance/companies ─────────────────────────────────────────
    // Searches distinct bb_presenze.azienda values — the export filters on that
    // text column, not on bb_companies.id.

    public function loadCompanies(Request $request): never
    {
        $q       = trim($_GET['q'] ?? '');
        $context = trim($_GET['context'] ?? '');

        if ($context === 'attendance') {
            // Attendance create/edit form: search all companies by name (no consorziata filter)
            // resolveCompanyId() in AttendanceRepository accepts name or numeric ID
            $stmt = $this->conn->prepare(
                "SELECT id, name FROM bb_companies
                 WHERE name LIKE :q
                 ORDER BY name ASC
                 LIMIT 50"
            );
            $stmt->execute([':q' => "%$q%"]);

            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $results[] = ['value' => (string)$row['id'], 'text' => $row['name']];
            }
        } else {
            // Export filter: distinct azienda text values already stored in bb_presenze
            $stmt = $this->conn->prepare(
                "SELECT DISTINCT azienda
                 FROM bb_presenze
                 WHERE azienda IS NOT NULL AND azienda != '' AND azienda LIKE :q
                 ORDER BY azienda ASC
                 LIMIT 50"
            );
            $stmt->execute([':q' => "%$q%"]);

            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $results[] = ['value' => $row['azienda'], 'text' => $row['azienda']];
            }
        }

        Response::json($results);
    }

    // ── GET /api/attendance/clients ───────────────────────────────────────────

    public function loadClients(Request $request): never
    {
        $q    = trim($_GET['q'] ?? '');
        $stmt = $this->conn->prepare(
            "SELECT id, name FROM bb_clients WHERE name LIKE :q ORDER BY name ASC LIMIT 50"
        );
        $stmt->execute([':q' => "%$q%"]);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = ['value' => $row['id'], 'text' => $row['name']];
        }

        Response::json($results);
    }

    // ── GET /api/attendance/last-day ──────────────────────────────────────────

    public function loadLastDay(Request $request): never
    {
        $cantiereId = $_GET['cantiere_id'] ?? null;
        if (!$cantiereId) {
            Response::json(['success' => false, 'message' => 'Cantiere non valido']);
        }

        $repo = new AttendanceRepository($this->conn);
        $data = $repo->getLastDayData((int)$cantiereId);

        if (!$data) {
            Response::json(['success' => false, 'message' => 'Nessuna presenza trovata']);
        }

        Response::json(['success' => true, 'nostri' => $data['nostri'], 'consorziate' => $data['consorziate']]);
    }

    // ── GET /api/analytics/user-activity ─────────────────────────────────────

    public function userAnalytics(Request $request): never
    {
        $analytics = new \UserAnalytics($this->conn);
        Response::json([
            'onlineCount' => $analytics->countOnlineUsers(),
            'onlineUsers' => $analytics->getOnlineUsers(),
            'topUsers'    => $analytics->getTopUsers('today'),
            'recent'      => $analytics->getRecentActions(10),
        ]);
    }

    // ── POST /api/analytics/heartbeat ─────────────────────────────────────────

    public function heartbeat(Request $request): never
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);

        if (!$data) {
            http_response_code(400);
            exit;
        }

        $page      = (string)($data['page']       ?? '');
        $seconds   = (int)($data['seconds']        ?? 0);
        $sessionId = (string)($data['session_id']  ?? '');

        if ($page === '' || $seconds <= 0 || $sessionId === '') {
            http_response_code(422);
            exit;
        }

        if ($seconds > 300) {
            $seconds = 300;
        }

        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            exit;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO bb_page_analytics (user_id, page, session_id, active_seconds, created_at)
            VALUES (:user_id, :page, :session_id, :seconds, NOW())
        ");
        $stmt->execute([
            ':user_id'    => $userId,
            ':page'       => $page,
            ':session_id' => $sessionId,
            ':seconds'    => $seconds,
        ]);

        http_response_code(204);
        exit;
    }

    // ── GET /api/worksites/search ─────────────────────────────────────────────

    public function searchWorksites(Request $request): never
    {
        $auth      = $GLOBALS['authenticated_user'];
        $userObj   = $GLOBALS['user'];
        $userObj->id = (int)$auth['user_id'];
        $companyId = $userObj->getCompanyId();

        $q        = trim((string)($_GET['q']         ?? ''));
        $status   = trim((string)($_GET['status']    ?? ''));
        $year     = trim((string)($_GET['year']      ?? ''));
        $clientId = trim((string)($_GET['client_id'] ?? ''));

        if ($q === '' || mb_strlen($q) < 2) {
            Response::json([]);
        }

        $usersWithPriceAccess = ['alion', 'laura', 'osman', 'elena', 'ermal'];
        $canSeePrices = in_array((string)($auth['username'] ?? ''), $usersWithPriceAccess, true);

        $select = "SELECT w.id, w.worksite_code, w.name AS worksite_name, w.order_number, w.order_date,
                          w.location, w.total_offer, w.ext_total_offer, w.status,
                          c.name AS client_name, fs.margin
                   FROM bb_worksites w
                   LEFT JOIN bb_clients c ON w.client_id = c.id
                   LEFT JOIN bb_worksite_financial_status fs ON fs.worksite_id = w.id";

        $conditions = ["w.is_draft = 0"];
        $params     = [];

        if ($companyId != 1) {
            $conditions[] = "w.company_id = :company_id";
            $params[':company_id'] = $companyId;
        }

        if ($status !== '' && $status !== 'all') {
            if ($status === 'A rischio') {
                $contractField = ($companyId == 1) ? 'w.total_offer' : 'w.ext_total_offer';
                $conditions[] = "(fs.margin < 0 OR ((fs.margin / NULLIF({$contractField}, 0)) * 100 <= 30))";
            } else {
                $conditions[] = "w.status = :status";
                $params[':status'] = $status;
            }
        }

        if ($year !== '') {
            $conditions[] = "YEAR(w.start_date) = :year";
            $params[':year'] = (int)$year;
        }

        if ($companyId == 1 && $clientId !== '') {
            $conditions[] = "w.client_id = :client_id";
            $params[':client_id'] = (int)$clientId;
        }

        $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
        $searchConditions = [];
        $paramIdx = 0;

        foreach ($words as $word) {
            $likePat = '%' . $word . '%';
            $i = $paramIdx;
            $searchConditions[] = "(w.name LIKE :sn{$i} OR w.worksite_code LIKE :sc{$i} OR w.location LIKE :sl{$i} OR c.name LIKE :scl{$i} OR w.order_number LIKE :so{$i})";
            $params[":sn{$i}"]  = $likePat;
            $params[":sc{$i}"]  = $likePat;
            $params[":sl{$i}"]  = $likePat;
            $params[":scl{$i}"] = $likePat;
            $params[":so{$i}"]  = $likePat;
            $paramIdx++;
        }

        if (!empty($searchConditions)) {
            $conditions[] = '(' . implode(' AND ', $searchConditions) . ')';
        }

        $where = implode(' AND ', $conditions);
        $sql   = "{$select} WHERE {$where} ORDER BY CAST(SUBSTRING(w.worksite_code, 2, 2) AS UNSIGNED) DESC, CAST(SUBSTRING_INDEX(w.worksite_code, '-', -1) AS UNSIGNED) DESC LIMIT 50";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $w) {
            $contract   = ($companyId == 1) ? (float)$w['total_offer'] : (float)$w['ext_total_offer'];
            $margin     = isset($w['margin']) ? (float)$w['margin'] : null;
            $marginPerc = ($margin !== null && $contract > 0) ? ($margin / $contract) * 100 : null;

            $row = [
                'id'            => (int)$w['id'],
                'worksite_code' => $w['worksite_code'],
                'worksite_name' => $w['worksite_name'],
                'order_number'  => $w['order_number'] ?? '-',
                'order_date'    => !empty($w['order_date']) ? (new \DateTime($w['order_date']))->format('d/m/Y') : '-',
                'location'      => $w['location'],
                'status'        => $w['status'],
                'client_name'   => $w['client_name'] ?? '',
                'risk'          => ($margin !== null && ($margin < 0 || ($marginPerc !== null && $marginPerc <= 30))),
                'risk_type'     => $margin !== null && $margin < 0 ? 'loss' : (($marginPerc !== null && $marginPerc <= 30) ? 'low' : null),
            ];

            if ($canSeePrices) {
                $row['total'] = ($companyId == 1) ? (float)$w['total_offer'] : (float)$w['ext_total_offer'];
            }

            $results[] = $row;
        }

        Response::json($results);
    }
}
