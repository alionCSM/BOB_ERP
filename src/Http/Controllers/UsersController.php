<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Workers\WorkerRepository;

final class UsersController
{
    private \PDO $conn;
    private WorkerRepository $workerRepo;

    public function __construct(\PDO $conn, WorkerRepository $workerRepo)
    {
        $this->conn       = $conn;
        $this->workerRepo = $workerRepo;
    }

    public function index(Request $request): void
    {
        $page   = max(1, (int)($request->get('page') ?: 1));
        $limit  = max(1, (int)($request->get('limit') ?: 10));
        $offset = ($page - 1) * $limit;
        $search = trim($request->get('search') ?: '');

        $baseQuery    = " FROM bb_workers WHERE removed = 'N'";
        $searchParams = [];
        $baseQuery    = $this->applyWorkerSearch($baseQuery, $search, $searchParams);

        $totalStmt = $this->conn->prepare("SELECT COUNT(*) AS total" . $baseQuery);
        $this->bindWorkerSearchParams($totalStmt, $searchParams);
        $totalStmt->execute();
        $totalRecords = (int)$totalStmt->fetch(\PDO::FETCH_ASSOC)['total'];
        $totalPages   = (int)ceil($totalRecords / $limit);

        $totalActive = $totalAll = $totalInactive = 0;
        if ($request->get('ajax') !== '1') {
            $activeStmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM bb_workers WHERE removed = 'N' AND (active = 'Y' OR active = '1')");
            $activeStmt->execute();
            $totalActive   = (int)$activeStmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
            $totalAll      = (int)$this->conn->query("SELECT COUNT(*) FROM bb_workers WHERE removed = 'N'")->fetchColumn();
            $totalInactive = $totalAll - $totalActive;
        }

        $stmt = $this->conn->prepare("SELECT id, uid, first_name, last_name, company, fiscal_code, photo, active" . $baseQuery . " ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $this->bindWorkerSearchParams($stmt, $searchParams);
        $stmt->bindParam(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $workers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($request->get('ajax') === '1') {
            $ldp      = new \App\View\LayoutDataProvider(
                $this->conn,
                $GLOBALS['authenticated_user'] ?? [],
                $request->user(),
                new \App\Infrastructure\Config()
            );
            $renderer = new \App\View\TwigRenderer($ldp);

            $rowsHtml       = $renderer->render('users/_rows.html.twig',       compact('workers'));
            $paginationHtml = $renderer->render('users/_pagination.html.twig', compact('page', 'totalPages', 'limit', 'search'));
            $summary        = 'Mostrando ' . (($totalRecords > 0) ? ($offset + 1) : 0) . ' a ' . min($offset + $limit, $totalRecords) . ' di ' . $totalRecords . ' operai';

            Response::json([
                'summary'    => $summary,
                'rows'       => $rowsHtml,
                'pagination' => $paginationHtml,
            ]);
        }

        Response::view('users/index.html.twig', $request, compact(
            'page', 'limit', 'offset', 'search',
            'workers', 'totalRecords', 'totalPages',
            'totalActive', 'totalAll', 'totalInactive'
        ));
    }

    public function workers(Request $request): void
    {
        $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $request->user());
        if (!$isCompanyScopedUser) {
            Response::redirect('/users');
        }

        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $request->user());
        if (empty($allowedCompanyNames)) {
            Response::redirect('/companies/my');
        }

        $placeholders = [];
        $params       = [];
        foreach ($allowedCompanyNames as $i => $name) {
            $key            = ':company_' . $i;
            $placeholders[] = $key;
            $params[$key]   = $name;
        }
        $inClause = implode(',', $placeholders);

        $search    = trim($request->get('search') ?: '');
        $baseQuery = "FROM bb_workers WHERE removed = 'N' AND company IN ($inClause)";

        if ($search !== '') {
            $like       = '%' . $search . '%';
            $baseQuery .= " AND (
                first_name LIKE :search_first
                OR last_name LIKE :search_last
                OR CONCAT_WS(' ', first_name, last_name) LIKE :search_full
                OR CONCAT_WS(' ', last_name, first_name) LIKE :search_full_rev
                OR fiscal_code LIKE :search_fiscal
            )";
            $params[':search_first']    = $like;
            $params[':search_last']     = $like;
            $params[':search_full']     = $like;
            $params[':search_full_rev'] = $like;
            $params[':search_fiscal']   = $like;
        }

        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total $baseQuery");
        foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
        $countStmt->execute();
        $totalWorkers = (int)$countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

        $activeStmt = $this->conn->prepare("SELECT COUNT(*) AS cnt $baseQuery AND (active = 'Y' OR active = '1')");
        foreach ($params as $k => $v) { $activeStmt->bindValue($k, $v); }
        $activeStmt->execute();
        $totalActive   = (int)$activeStmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
        $totalInactive = $totalWorkers - $totalActive;

        $stmt = $this->conn->prepare("SELECT id, uid, first_name, last_name, company, fiscal_code, photo, active, type_worker $baseQuery ORDER BY company ASC, last_name ASC, first_name ASC");
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        $allWorkers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($allWorkers as $w) {
            $company           = $w['company'] ?: 'Senza Azienda';
            $grouped[$company][] = $w;
        }

        Response::view('users/workers_list.html.twig', $request, compact(
            'search', 'allWorkers', 'grouped',
            'totalWorkers', 'totalActive', 'totalInactive', 'allowedCompanyNames'
        ));
    }

    public function create(Request $request): void
    {
        $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $request->user());
        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $request->user());

        if ($isCompanyScopedUser) {
            $companies = array_map(fn($n) => ['name' => $n], $allowedCompanyNames);
        } else {
            $stmt = $this->conn->prepare("SELECT name FROM bb_companies ORDER BY name ASC");
            $stmt->execute();
            $companies = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $pageTitle = 'Nuovo Operaio';
        Response::view('users/create.html.twig', $request, compact('companies', 'isCompanyScopedUser', 'pageTitle'));
    }

    public function store(Request $request): void
    {
        try {
            $validator        = new WorkerCreateValidator();
            $data             = $validator->validate($_POST);
            $workerRepository = new WorkerRepository($this->conn);
            $workerService    = new WorkerManagementService($workerRepository);

            $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $request->user());
            $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $request->user());

            $created = $workerService->createWorker(
                $data,
                (int)($GLOBALS['authenticated_user']['user_id'] ?? 0),
                $isCompanyScopedUser,
                $allowedCompanyNames,
                $_FILES['profile_photo'] ?? []
            );

            if (!$created) {
                throw new \RuntimeException('Errore durante il salvataggio dei dati.');
            }

            $_SESSION['success'] = 'Lavoratore creato con successo.';
            $redirectTo = $isCompanyScopedUser ? '/users/workers' : '/users';
            Response::redirect($redirectTo);
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect('/users/create');
        }
    }

    public function show(Request $request): void
    {
        $workerId = $request->intParam('id');
        if (!$workerId) {
            Response::redirect('/users');
        }

        // Validate uid parameter for security
        $providedUid = (string)($request->get('uid') ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            Response::error('Access denied', 403);
        }

        $workerData = $this->workerRepo->getFullById($workerId);
        if (!$workerData) {
            Response::redirect('/users');
        }

        $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $request->user());
        if ($isCompanyScopedUser) {
            assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);
        }

        $isExternalLimitedUi = $isCompanyScopedUser;
        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $request->user());
        $userService         = new User($this->conn);
        $workerUser          = $userService->getByWorkerId($workerId);
        $tempPassword        = $_SESSION['temp_user_password'] ?? null;
        $canCreateUser       = !$workerUser && $workerData['active'] === 'Y' && !empty($workerData['email']);
        $companyHistory      = $this->workerRepo->getCompanyHistory($workerData['fiscal_code'] ?? '');
        $pageTitle           = 'Modifica Profilo';

        // Capture legacy PHP document partials as HTML strings for Twig
        // The partials expect: $workerId, $connection, $user, $conn
        $connection = $this->conn;
        $conn       = $this->conn;
        $user       = $request->user();

        ob_start();
        include APP_ROOT . '/views/documents/documenti_aziendali.php';
        $documentiAziendali = ob_get_clean();

        ob_start();
        include APP_ROOT . '/views/documents/documenti_personali.php';
        $documentiPersonali = ob_get_clean();

        Response::view('users/edit.html.twig', $request, compact(
            'workerId', 'workerData', 'isCompanyScopedUser', 'isExternalLimitedUi',
            'allowedCompanyNames', 'userService', 'workerUser', 'canCreateUser',
            'tempPassword', 'companyHistory', 'pageTitle',
            'documentiAziendali', 'documentiPersonali'
        ));
    }

    public function update(Request $request): void
    {
        $workerId         = $request->intParam('id');

        // Validate uid parameter for security
        $providedUid = (string)($_POST['uid'] ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        // Verify user has access to this worker (company scope check)
        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);

        $workerRepository = new WorkerRepository($this->conn);
        $workerService    = new WorkerManagementService($workerRepository);

        $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $request->user());
        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $request->user());

        $workerService->updateInfo($workerId, [
            'first_name'  => (string)($_POST['first_name']  ?? ''),
            'last_name'   => (string)($_POST['last_name']   ?? ''),
            'birthday'    => (string)($_POST['birthday']    ?? ''),
            'birthplace'  => (string)($_POST['birthplace']  ?? ''),
            'email'       => (string)($_POST['email']       ?? ''),
            'phone'       => (string)($_POST['phone']       ?? ''),
            'company'     => (string)($_POST['company']     ?? ''),
            'fiscal_code' => (string)($_POST['fiscal_code'] ?? ''),
            'active_from' => (string)($_POST['active_from'] ?? ''),
            'type_worker' => (string)($_POST['type_worker'] ?? ''),
        ], $isCompanyScopedUser, $allowedCompanyNames);

        $_SESSION['success'] = 'Modifiche salvate con successo.';
        Response::redirect("/users/{$workerId}/edit?uid={$providedUid}");
    }

    public function updatePhoto(Request $request): void
    {
        $workerId         = $request->intParam('id');

        // Validate uid parameter for security
        $providedUid = (string)($_POST['uid'] ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        // Verify user has access to this worker (company scope check)
        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);

        $workerRepository = new WorkerRepository($this->conn);
        $workerService    = new WorkerManagementService($workerRepository);

        try {
            $workerService->updatePhoto($workerId, $_FILES['profile_photo'] ?? []);
            $_SESSION['success'] = 'Foto aggiornata.';
            Response::redirect("/users/{$workerId}/edit?uid={$providedUid}");
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/users/{$workerId}/edit?uid={$providedUid}");
        }
    }

    public function toggleActive(Request $request): void
    {
        $workerId         = $request->intParam('id');

        // Validate uid parameter for security
        $providedUid = (string)($_POST['uid'] ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        // Verify user has access to this worker (company scope check)
        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);

        $newStatus        = ($_POST['new_status'] ?? '') === 'Y' ? 'Y' : 'N';
        $workerRepository = new WorkerRepository($this->conn);
        $workerService    = new WorkerManagementService($workerRepository);
        $workerService->setActiveStatus($workerId, $newStatus);

        // When activating a worker, auto-activate their company if it is currently inactive
        if ($newStatus === 'Y') {
            $worker = $workerRepository->getWorkerById($workerId);
            if ($worker) {
                $companyId   = (int)($worker['company_id'] ?? 0);
                $companyName = (string)($worker['company'] ?? '');

                if ($companyId > 0) {
                    $this->conn->prepare('UPDATE bb_companies SET active = 1 WHERE id = :id AND active = 0 LIMIT 1')
                        ->execute([':id' => $companyId]);
                } elseif ($companyName !== '') {
                    $this->conn->prepare('UPDATE bb_companies SET active = 1 WHERE name = :name AND active = 0 LIMIT 1')
                        ->execute([':name' => $companyName]);
                }
            }
        }

        $_SESSION['success'] = 'Stato aggiornato.';
        Response::redirect("/users/{$workerId}/edit?uid={$providedUid}");
    }

    public function changeCompany(Request $request): void
    {
        $workerId   = $request->intParam('id');

        // Validate uid parameter for security
        $providedUid = (string)($_POST['uid'] ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        $newCompany = trim((string)($_POST['company']    ?? ''));
        $role       = (string)($_POST['role']       ?? '');
        $startDate  = (string)($_POST['start_date'] ?? '');
        $endDate    = (string)($_POST['end_date']   ?? '') ?: date('Y-m-d', strtotime($startDate . ' -1 day'));

        $workerData = $this->workerRepo->getFullById($workerId);

        if (empty($workerData)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        // Company scope check
        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);

        $user              = $request->user();
        $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $user);
        $allowedCompanyNames = getCompanyScopeAllowedNames($this->conn, $user);

        // For company changes, verify user has access to the TARGET company
        if ($isCompanyScopedUser && !in_array($newCompany, $allowedCompanyNames, true)) {
            Response::error('Access denied: you cannot assign workers to this company', 403);
        }

        // Validate inputs
        if ($newCompany === '' || $role === '' || $startDate === '') {
            $_SESSION['error'] = 'Compila tutti i campi obbligatori.';
            Response::redirect("/users/{$workerId}/edit");
        }

        $workerService = new \App\Service\Workers\WorkerManagementService(new \App\Repository\Workers\WorkerRepository($this->conn));

        try {
            $result = $workerService->changeCompany($workerData, $newCompany, null, $role, $startDate, $endDate);

            if ($result) {
                $name = htmlspecialchars($workerData['last_name'] . ' ' . $workerData['first_name']);
                AuditLogger::log($this->conn, $user, 'worker_company_change', 'worker', $workerId, "{$name}: {$workerData['company']} → {$newCompany}");
                $_SESSION['success'] = 'Azienda aggiornata con successo.';
                Response::redirect("/users/{$workerId}/edit?uid={$workerData['uid']}");
            } else {
                $_SESSION['error'] = 'Aggiornamento azienda fallito.';
                Response::redirect("/users/{$workerId}/edit");
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/users/{$workerId}/edit");
        }
    }

    public function createAccount(Request $request): void
    {
        $workerId = $request->intParam('id');

        // Validate uid parameter for security
        $providedUid = (string)($_POST['uid'] ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        // company_viewer may create accounts only for workers in their company
        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);

        $workerData  = $this->workerRepo->getFullById($workerId);
        $userService = new User($this->conn);
        $userService->company_id = $request->user()->company_id;

        try {
            $result = $userService->createFromWorker($workerData, $request->user()->id);
            $_SESSION['temp_user_password'] = $result['temp_password'];
            $_SESSION['success'] = 'Account creato con successo.';
            Response::redirect("/users/{$workerId}/edit?uid={$providedUid}");
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/users/{$workerId}/edit");
        }
    }

    public function destroy(Request $request): void
    {
        $workerId = $request->intParam('id');
        if (!$workerId) {
            Response::redirect('/users');
        }

        // Validate uid parameter for security
        $providedUid = (string)($request->post('uid') ?? '');
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            $_SESSION['error'] = 'Operaio non trovato.';
            Response::redirect('/users');
        }

        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);
        $isCompanyScopedUser = isCompanyScopedUserByContext($this->conn, $request->user());
        $redirectBase        = $isCompanyScopedUser ? '/users/workers' : '/users';

        $stmt = $this->conn->prepare("UPDATE bb_workers SET removed = 'Y' WHERE id = :id");
        $stmt->bindParam(':id', $workerId, \PDO::PARAM_INT);

        if ($stmt->execute()) {
            $nameStmt = $this->conn->prepare('SELECT first_name, last_name FROM bb_workers WHERE id = :id LIMIT 1');
            $nameStmt->execute([':id' => $workerId]);
            $row   = $nameStmt->fetch(\PDO::FETCH_ASSOC);
            $label = $row ? trim($row['first_name'] . ' ' . $row['last_name']) : (string)$workerId;
            AuditLogger::log($this->conn, $request->user(), 'worker_delete', 'worker', $workerId, $label);
            $_SESSION['success'] = 'Lavoratore rimosso con successo.';
            Response::redirect($redirectBase);
        } else {
            $_SESSION['error'] = 'Errore durante la rimozione del lavoratore.';
            Response::redirect($redirectBase);
        }
    }

    public function searchWorkers(Request $request): never
    {
        $q = trim($request->get('q') ?? '');
        if ($q === '' || mb_strlen($q) < 2) {
            Response::json([]);
        }

        $like = '%' . $q . '%';
        $sql  = "
            SELECT id, uid, first_name, last_name, active
            FROM bb_workers
            WHERE removed = 'N'
              AND (
                  first_name LIKE :search_first
                  OR last_name LIKE :search_last
                  OR CONCAT_WS(' ', first_name, last_name) LIKE :search_full_name
                  OR CONCAT_WS(' ', last_name, first_name) LIKE :search_full_name_reverse
              )
            ORDER BY last_name ASC, first_name ASC
            LIMIT 20
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':search_first', $like, \PDO::PARAM_STR);
        $stmt->bindValue(':search_last', $like, \PDO::PARAM_STR);
        $stmt->bindValue(':search_full_name', $like, \PDO::PARAM_STR);
        $stmt->bindValue(':search_full_name_reverse', $like, \PDO::PARAM_STR);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'id'     => $row['id'],
                'uid'    => $row['uid'],
                'text'   => trim($row['last_name'] . ' ' . $row['first_name']),
                'active' => $row['active'],
            ];
        }
        Response::json($results);
    }

    public function badge(Request $request): never
    {
        $workerId = $request->intParam('id');
        if ($workerId <= 0) {
            Response::error('Operaio non specificato.', 400);
        }

        require APP_ROOT . '/views/workers/badge.php';
        exit;
    }

    public function serveWorkerPhoto(Request $request): never
    {
        $workerId = $request->intParam('id');
        if (!$workerId) {
            Response::error('Operaio non specificato.', 400);
        }

        // Validate uid parameter for security (only if worker has a UID)
        $providedUid = (string)($request->get('uid') ?? '');
        if ($providedUid && !validateWorkerUid($this->conn, $workerId, $providedUid)) {
            Response::error('Operaio non trovato.', 404);
        }

        assertCompanyScopeWorkerAccess($this->conn, $request->user(), $workerId);

        $stmt = $this->conn->prepare('SELECT photo FROM bb_workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['photo'])) {
            Response::error('Foto non trovata.', 404);
        }

        $cloudBasePath = realpath(dirname(APP_ROOT) . '/cloud');
        $filePath      = realpath($cloudBasePath . '/' . $row['photo']);

        if (!$filePath || !file_exists($filePath)) {
            Response::error('File non trovato.', 404);
        }

        if (strpos($filePath, $cloudBasePath) !== 0) {
            Response::error('Percorso non valido.', 403);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath) ?: 'image/jpeg';
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }

    public function serveUserPhoto(Request $request): never
    {
        $targetId = $request->intParam('id');
        if (!$targetId) {
            Response::error('Utente non specificato.', 400);
        }

        $stmt = $this->conn->prepare('SELECT photo FROM bb_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['photo'])) {
            Response::error('Foto non trovata.', 404);
        }

        $cloudRoot = $_ENV['CLOUD_ROOT'] ?? getenv('CLOUD_ROOT');
        if (!$cloudRoot) {
            $cloudRoot = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
        }
        $cloudRoot = rtrim($cloudRoot, '/\\');
        $filePath  = realpath($cloudRoot . '/' . $row['photo']);

        if (!$filePath || !file_exists($filePath)) {
            $legacyPath = realpath(APP_ROOT . '/' . $row['photo']);
            if ($legacyPath && file_exists($legacyPath)) {
                $filePath = $legacyPath;
            } else {
                Response::error('File non trovato.', 404);
            }
        }

        if (strpos($filePath, $cloudRoot) !== 0) {
            $uploadsRoot = realpath(APP_ROOT . '/uploads');
            if (!$uploadsRoot || strpos($filePath, $uploadsRoot) !== 0) {
                Response::error('Percorso non valido.', 403);
            }
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath) ?: 'image/jpeg';
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }

    // ── GET /users/audit-log ─────────────────────────────────────────────────

    public function auditLog(Request $request): void
    {
        $auth = $GLOBALS['authenticated_user'] ?? [];
        if ((int)($auth['user_id'] ?? 0) !== 1 && empty($request->user()->permissions['users'])) {
            Response::error('Accesso negato.', 403);
        }

        $filterAction   = trim($request->get('action')    ?? '');
        $filterUser     = trim($request->get('username')  ?? '');
        $filterDateFrom = trim($request->get('date_from') ?? '');
        $filterDateTo   = trim($request->get('date_to')   ?? '');
        $page           = max(1, (int)($request->get('page') ?? 1));
        $perPage        = 50;
        $offset         = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        if ($filterAction !== '') {
            $where[]           = 'action = :action';
            $params[':action'] = $filterAction;
        }
        if ($filterUser !== '') {
            $where[]              = 'username LIKE :username';
            $params[':username']  = '%' . $filterUser . '%';
        }
        if ($filterDateFrom !== '') {
            $where[]              = 'created_at >= :date_from';
            $params[':date_from'] = $filterDateFrom . ' 00:00:00';
        }
        if ($filterDateTo !== '') {
            $where[]            = 'created_at <= :date_to';
            $params[':date_to'] = $filterDateTo . ' 23:59:59';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_audit_log $whereSql");
        $countStmt->execute($params);
        $total      = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));

        $rowStmt = $this->conn->prepare("SELECT * FROM bb_audit_log $whereSql ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $rowStmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $rowStmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $rowStmt->bindValue($k, $v);
        }
        $rowStmt->execute();
        $rows = $rowStmt->fetchAll(\PDO::FETCH_ASSOC);

        $actionsStmt = $this->conn->query("SELECT DISTINCT action FROM bb_audit_log ORDER BY action ASC");
        $allActions  = $actionsStmt ? $actionsStmt->fetchAll(\PDO::FETCH_COLUMN) : [];

        Response::view('users/audit_log.html.twig', $request, compact(
            'rows', 'allActions', 'total', 'totalPages', 'page',
            'filterAction', 'filterUser', 'filterDateFrom', 'filterDateTo'
        ));
    }

    // ── GET /users/permissions ────────────────────────────────────────────────

    public function permissionsList(Request $request): void
    {
        $auth = $GLOBALS['authenticated_user'] ?? [];
        if ((int)($auth['user_id'] ?? 0) !== 1) {
            Response::error('Accesso negato.', 403);
        }

        // Fetch all users with their active modules from bb_user_permissions
        $stmt = $this->conn->query(
            'SELECT u.id, u.username, u.email, u.company,
                    COALESCE(c.name, u.company, \'\') AS company_name,
                    (SELECT GROUP_CONCAT(p.module)
                     FROM bb_user_permissions p
                     WHERE p.user_id = u.id AND p.allowed = 1) AS active_modules
             FROM   bb_users u
             LEFT JOIN bb_companies c ON c.id = u.company_id
             WHERE  u.type != "external"
             ORDER BY u.username'
        );
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get all available modules
        $groups = $this->buildPermissionGroups();
        $allModules = [];
        foreach ($groups as $g) {
            foreach ($g['perms'] as $key => $mod) {
                $allModules[$key] = $mod;
            }
        }

        $totalUsers = count($users);
        $totalModules = count($allModules);

        Response::view('users/permissions_list.html.twig', $request, [
            'users'        => $users,
            'allModules'   => $allModules,
            'totalUsers'   => $totalUsers,
            'totalModules' => $totalModules,
        ]);
    }

    // ── GET|POST /users/permissions/{id} ──────────────────────────────────────

    public function permissionsEdit(Request $request): void
    {
        $auth = $GLOBALS['authenticated_user'] ?? [];
        if ((int)($auth['user_id'] ?? 0) !== 1) {
            Response::error('Accesso negato.', 403);
        }
        $targetId = $request->intParam('id');
        if (!$targetId) {
            Response::redirect('/users/permissions');
        }

        $groups  = $this->buildPermissionGroups();
        $modules = [];
        foreach ($groups as $g) {
            foreach ($g['perms'] as $key => $mod) {
                $modules[$key] = $mod;
            }
        }

        $target = new \User($this->conn, $targetId);
        $target->loadPermissions();

        $message = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $perms = [];
            foreach ($modules as $key => $mod) {
                $perms[$key] = isset($_POST['perm_' . $key]) ? 1 : 0;
            }
            $target->savePermissions($perms);
            AuditLogger::log(
                $this->conn, $request->user(), 'permission_change', 'user', (int)$target->id,
                $target->username ?? (string)$target->id,
                ['granted' => array_keys(array_filter($perms))]
            );
            $target->loadPermissions();
            $message = 'Permessi aggiornati!';
        }

        $activeCount = 0;
        foreach ($modules as $key => $mod) {
            if (!empty($target->permissions[$key])) $activeCount++;
        }

        Response::view('users/permissions_edit.html.twig', $request, compact(
            'target', 'groups', 'modules', 'activeCount', 'message'
        ));
    }

    // ── GET|POST /users/bob/create ────────────────────────────────────────────

    public function createBobUser(Request $request): void
    {
        $auth = $GLOBALS['authenticated_user'] ?? [];
        if ((int)($auth['user_id'] ?? 0) !== 1) {
            Response::error('Accesso negato.', 403);
        }

        $stmtC     = $this->conn->query("SELECT id, name FROM bb_companies ORDER BY name ASC");
        $companies = $stmtC->fetchAll(\PDO::FETCH_ASSOC);

        $success      = '';
        $error        = '';
        $tempPassword = '';
        $post         = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post       = $_POST;
            $username   = trim($_POST['username']   ?? '');
            $email      = trim($_POST['email']      ?? '');
            $firstName  = trim($_POST['first_name'] ?? '');
            $lastName   = trim($_POST['last_name']  ?? '');
            $phone      = trim($_POST['phone']      ?? '');
            $type       = $_POST['type']            ?? 'user';
            $accessProf = $_POST['access_profile']  ?? 'INTERNAL';
            $companyId  = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
            $role       = trim($_POST['role']       ?? 'user');
            $sendEmail  = !empty($_POST['send_email']);

            if ($username === '' || $email === '' || $firstName === '' || $lastName === '') {
                $error = 'Username, email, nome e cognome sono obbligatori.';
            } else {
                $chk = $this->conn->prepare("SELECT id FROM bb_users WHERE username = :u AND removed = 'N' LIMIT 1");
                $chk->execute([':u' => $username]);
                if ($chk->fetch()) {
                    $error = 'Username già esistente.';
                } else {
                    $chk2 = $this->conn->prepare("SELECT id FROM bb_users WHERE email = :e AND removed = 'N' LIMIT 1");
                    $chk2->execute([':e' => $email]);
                    if ($chk2->fetch()) {
                        $error = 'Email già registrata.';
                    }
                }
            }

            if ($error === '') {
                try {
                    $rawPassword  = bin2hex(random_bytes(6));
                    $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);

                    $companyName = '';
                    if ($companyId) {
                        $cStmt = $this->conn->prepare("SELECT name FROM bb_companies WHERE id = :id LIMIT 1");
                        $cStmt->execute([':id' => $companyId]);
                        $companyName = (string)($cStmt->fetchColumn() ?: '');
                    }

                    $stmt = $this->conn->prepare("
                        INSERT INTO bb_users (
                            username, password, first_name, last_name, email, phone,
                            company, type, role, access_profile, company_id,
                            active, confirmed, must_change_password,
                            created_by, created_at
                        ) VALUES (
                            :username, :password, :first_name, :last_name, :email, :phone,
                            :company, :type, :role, :access_profile, :company_id,
                            'Y', 1, 1,
                            :created_by, NOW()
                        )
                    ");
                    $stmt->execute([
                        ':username'       => $username,
                        ':password'       => $passwordHash,
                        ':first_name'     => $firstName,
                        ':last_name'      => $lastName,
                        ':email'          => $email,
                        ':phone'          => $phone,
                        ':type'           => $type,
                        ':role'           => $role,
                        ':company'        => $companyName,
                        ':access_profile' => $accessProf,
                        ':company_id'     => $companyId,
                        ':created_by'     => (int)($auth['user_id'] ?? 0),
                    ]);

                    $newUserId    = (int)$this->conn->lastInsertId();
                    $tempPassword = $rawPassword;
                    $post         = [];

                    if ($sendEmail && $email !== '') {
                        try {
                            $mailer = new \Mailer();
                            $mailer->setSender('system');
                            $mail = $mailer->getMailer();
                            $mail->addAddress($email);
                            $mail->Subject = 'Accesso BOB';
                            $mail->Body    = "
                                <p>Ciao <strong>{$firstName} {$lastName}</strong>,</p>
                                <p>Il tuo account <strong>BOB</strong> è stato creato.</p>
                                <p>
                                    <strong>Username:</strong> {$username}<br>
                                    <strong>Password temporanea:</strong> {$rawPassword}
                                </p>
                                <p>Al primo accesso dovrai cambiare la password.</p>
                            ";
                            $mail->send();
                        } catch (\Exception $mailErr) {
                            // Email failed but user was created
                        }
                    }

                    $success = "Utente creato con successo (ID: {$newUserId}).";
                } catch (\Exception $e) {
                    $error = 'Errore: ' . $e->getMessage();
                }
            }
        }

        Response::view('users/create_bob_user.html.twig', $request, compact(
            'companies', 'success', 'error', 'tempPassword', 'post'
        ));
    }

    // ── GET|POST /users/notifications/send ───────────────────────────────────

    public function sendNotification(Request $request): void
    {
        $auth = $GLOBALS['authenticated_user'] ?? [];
        if ((int)($auth['user_id'] ?? 0) !== 1) {
            Response::error('Solo admin può inviare notifiche globali.', 403);
        }

        // Fetch all users for the notification form
        $stmt = $this->conn->query("
            SELECT id, first_name, last_name, username
            FROM bb_users
            WHERE id != 1  -- Exclude admin
            ORDER BY first_name, last_name
        ");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::view('users/send_notification.html.twig', $request, [
            'conn'  => $this->conn,
            'users' => $users,
        ]);
    }

    // ── GET|POST /profile ─────────────────────────────────────────────────────

    public function profile(Request $request): void
    {
        $auth   = $GLOBALS['authenticated_user'] ?? [];
        $userId = (int)($auth['user_id'] ?? 0);
        if (!$userId) {
            Response::redirect('/');
        }

        $stmt = $this->conn->prepare("
            SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.phone, u.photo,
                   u.role, u.type, u.company, u.company_id, u.created_at,
                   COALESCE(c.name, u.company, '') AS company_name
            FROM bb_users u
            LEFT JOIN bb_companies c ON c.id = u.company_id
            WHERE u.id = :uid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$userData) {
            Response::redirect('/');
        }

        $profileMsg = '';
        $profileErr = '';
        $pwdMsg     = '';
        $pwdErr     = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            if ($action === 'profile') {
                $isCompanyViewer = ($userData['role'] ?? '') === 'company_viewer';
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName  = trim($_POST['last_name']  ?? '');
                $email     = $isCompanyViewer
                    ? (string)($userData['email'] ?? '')
                    : trim($_POST['email'] ?? '');
                $phone     = trim($_POST['phone'] ?? '');

                if ($firstName === '' || $lastName === '') {
                    $profileErr = 'Nome e cognome sono obbligatori.';
                } else {
                    $upd = $this->conn->prepare("
                        UPDATE bb_users
                        SET first_name = :fn, last_name = :ln, email = :em, phone = :ph
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':fn' => $firstName,
                        ':ln' => $lastName,
                        ':em' => $email,
                        ':ph' => $phone,
                        ':id' => $userId,
                    ]);
                    $userData['first_name'] = $firstName;
                    $userData['last_name']  = $lastName;
                    $userData['phone']      = $phone;
                    $profileMsg = 'Profilo aggiornato con successo.';
                }
            }

            if ($action === 'photo' && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                $finfo   = new \finfo(FILEINFO_MIME_TYPE);
                $mime    = $finfo->file($_FILES['photo']['tmp_name']);

                if (!in_array($mime, $allowed)) {
                    $profileErr = 'Formato immagine non supportato. Usa JPG, PNG o WebP.';
                } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
                    $profileErr = "L'immagine non deve superare i 5 MB.";
                } else {
                    $ext  = match($mime) {
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        default      => 'jpg',
                    };
                    $name = 'profile_' . $userId . '_' . time() . '.' . $ext;

                    $cloudRoot = $_ENV['CLOUD_ROOT'] ?? getenv('CLOUD_ROOT');
                    if (!$cloudRoot) {
                        $cloudRoot = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
                    }
                    $cloudRoot = rtrim($cloudRoot, '/\\');
                    $uploadDir = $cloudRoot . DIRECTORY_SEPARATOR . 'Users' . DIRECTORY_SEPARATOR . $userId;
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;

                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                        $photoPath = 'Users/' . $userId . '/' . $name;
                        $upd       = $this->conn->prepare("UPDATE bb_users SET photo = :photo WHERE id = :id");
                        $upd->execute([':photo' => $photoPath, ':id' => $userId]);
                        $userData['photo'] = $photoPath;
                        $profileMsg = 'Foto profilo aggiornata.';
                    } else {
                        $profileErr = 'Errore nel salvataggio della foto.';
                    }
                }
            }

            if ($action === 'password') {
                $currentPwd = $_POST['current_password'] ?? '';
                $newPwd     = $_POST['new_password']     ?? '';
                $confirmPwd = $_POST['confirm_password'] ?? '';

                $hashStmt = $this->conn->prepare("SELECT password FROM bb_users WHERE id = :id");
                $hashStmt->execute([':id' => $userId]);
                $storedHash = $hashStmt->fetchColumn();

                if (!password_verify($currentPwd, $storedHash)) {
                    $pwdErr = 'La password attuale non è corretta.';
                } elseif (strlen($newPwd) < 8) {
                    $pwdErr = 'La nuova password deve avere almeno 8 caratteri.';
                } elseif ($newPwd !== $confirmPwd) {
                    $pwdErr = 'Le password non coincidono.';
                } elseif (isPasswordPwned($newPwd)) {
                    $pwdErr = "Questa password è presente in database di violazioni note. Scegline un'altra.";
                } else {
                    $hash = password_hash($newPwd, PASSWORD_DEFAULT);
                    $upd  = $this->conn->prepare("UPDATE bb_users SET password = :pwd WHERE id = :id");
                    $upd->execute([':pwd' => $hash, ':id' => $userId]);
                    $pwdMsg = 'Password aggiornata con successo.';
                }
            }
        }

        $fullName = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''));
        if ($fullName === '') $fullName = $userData['username'];
        $initials = implode('', array_map(
            fn($p) => mb_strtoupper(mb_substr($p, 0, 1)),
            array_slice(explode(' ', $fullName), 0, 2)
        ));

        $photo = (string)($userData['photo'] ?? '');
        if ($photo !== '' && str_starts_with($photo, 'Users/')) {
            $photo = '/users/' . $userId . '/user-photo';
        } elseif ($photo !== '' && !preg_match('#^https?://#i', $photo) && $photo[0] !== '/') {
            $photo = '/' . ltrim($photo, '/');
        }
        $hasPhoto = $photo !== '';

        $roleLabels = [
            'admin'            => 'Amministratore',
            'manager'          => 'Manager',
            'cantiere'         => 'Responsabile Cantiere',
            'document_manager' => 'Gestione Documenti',
            'offerte'          => 'Offerte',
            'company_viewer'   => 'Visualizzatore Aziendale',
        ];
        $roleLabel = $roleLabels[$userData['role'] ?? ''] ?? ucfirst($userData['role'] ?? 'Utente');

        $memberSince = '';
        if (!empty($userData['created_at'])) {
            try {
                $dt     = new \DateTime($userData['created_at']);
                $months = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                           'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
                $memberSince = $months[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
            } catch (\Exception $e) {}
        }

        Response::view('users/profile.html.twig', $request, compact(
            'userData', 'fullName', 'initials', 'photo', 'hasPhoto',
            'roleLabel', 'memberSince',
            'profileMsg', 'profileErr', 'pwdMsg', 'pwdErr'
        ));
    }

    // ── GET /users/{id}/worker-profile ───────────────────────────────────────

    public function workerProfile(Request $request): void
    {
        $workerId = $request->intParam('id');
        if (!$workerId) {
            Response::redirect('/users');
        }
        Response::redirect('/users/' . $workerId . '/edit');
    }

    private function buildPermissionGroups(): array
    {
        return [
            'generale' => [
                'label' => 'Generale',
                'icon'  => 'M4 6h16M4 12h16M4 18h16',
                'color' => '#475569',
                'perms' => [
                    'dashboard' => ['label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1', 'color' => '#1d4ed8'],
                    'users'     => ['label' => 'Utenti',    'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'color' => '#6366f1'],
                    'chat'      => ['label' => 'Chat',      'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z', 'color' => '#e11d48'],
                ],
            ],
            'contabilita' => [
                'label' => 'Contabilita e Clienti',
                'icon'  => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1',
                'color' => '#16a34a',
                'perms' => [
                    'billing'   => ['label' => 'Fatturazione',      'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z', 'color' => '#16a34a'],
                    'companies' => ['label' => 'Aziende',           'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'color' => '#7c3aed'],
                    'clients'   => ['label' => 'Clienti',           'icon' => 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'color' => '#0891b2'],
                    'offers'    => ['label' => 'Offerte',           'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z', 'color' => '#059669'],
                    'ordini'    => ['label' => 'Ordini Consorziata',  'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'color' => '#1d4ed8'],
                    'tickets'   => ['label' => 'Bigliettini Pasto', 'icon' => 'M15 5H9V3h6v2zm4 4H5a2 2 0 00-2 2v1h18v-1a2 2 0 00-2-2zM3 14v5a2 2 0 002 2h14a2 2 0 002-2v-5H3z', 'color' => '#059669'],
                ],
            ],
            'cantieri' => [
                'label' => 'Cantieri e Operai',
                'icon'  => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5',
                'color' => '#ea580c',
                'perms' => [
                    'worksites'      => ['label' => 'Cantieri',           'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'color' => '#ea580c'],
                    'attendance'     => ['label' => 'Presenze',           'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => '#0ea5e9'],
                    'presenze'       => ['label' => 'Presenze (vecchio)', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => '#94a3b8'],
                    'pianificazione' => ['label' => 'Squadre',            'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01', 'color' => '#3b82f6'],
                    'bookings'       => ['label' => 'Prenotazioni',       'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'color' => '#0d9488'],
                ],
            ],
            'documenti' => [
                'label' => 'Documenti e Files',
                'icon'  => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                'color' => '#2563eb',
                'perms' => [
                    'documents'       => ['label' => 'Documenti',                 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'color' => '#2563eb'],
                    'document_alerts' => ['label' => 'Avvisi Scadenza Documenti', 'icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'color' => '#dc2626'],
                    'files'           => ['label' => 'Files',                     'icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z', 'color' => '#8b5cf6'],
                    'share'           => ['label' => 'Doc Condivisi',             'icon' => 'M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z', 'color' => '#7c3aed'],
                ],
            ],
            'mezzi' => [
                'label' => 'Mezzi e Attrezzature',
                'icon'  => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1',
                'color' => '#b45309',
                'perms' => [
                    'equipment' => ['label' => 'Mezzi Sollevamento', 'icon' => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0', 'color' => '#b45309'],
                ],
            ],
            'programmazione' => [
                'label' => 'Programmazione',
                'icon'  => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                'color' => '#f59e0b',
                'perms' => [
                    'programmazione'           => ['label' => 'Accesso Pagina',                    'icon' => 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z', 'color' => '#f59e0b'],
                    'notif_mezzi_scrivere'     => ['label' => 'Notifica: Mezzi da scrivere',      'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'color' => '#b45309'],
                    'notif_mezzi_azione'       => ['label' => 'Notifica: Mezzi da gestire',       'icon' => 'M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0', 'color' => '#92400e'],
                    'notif_trasferta_scrivere' => ['label' => 'Notifica: Trasferta da scrivere',  'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'color' => '#0891b2'],
                    'notif_trasferta_azione'   => ['label' => 'Notifica: Trasferta da gestire',   'icon' => 'M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0', 'color' => '#0e7490'],
                    'notif_beppe_scrivere'     => ['label' => 'Notifica: Info Beppe da scrivere', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'color' => '#6d28d9'],
                    'notif_beppe_azione'       => ['label' => 'Notifica: Info Beppe da gestire',  'icon' => 'M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0', 'color' => '#5b21b6'],
                ],
            ],
            'anomaly' => [
                'label' => 'BOB AI - Anomalie',
                'icon'  => 'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z',
                'color' => '#6366f1',
                'perms' => [
                    'anomaly_presenze'       => ['label' => 'Anomalie Presenze',       'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => '#0ea5e9'],
                    'anomaly_mezzi'          => ['label' => 'Anomalie Mezzi',          'icon' => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1', 'color' => '#b45309'],
                    'anomaly_documenti'      => ['label' => 'Anomalie Documenti',      'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'color' => '#2563eb'],
                    'anomaly_fatturazione'   => ['label' => 'Anomalie Fatturazione',   'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 16l3.5-2 3.5 2 3.5-2 3.5 2z', 'color' => '#16a34a'],
                    'anomaly_cantieri'       => ['label' => 'Anomalie Cantieri',       'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'color' => '#ea580c'],
                    'anomaly_programmazione' => ['label' => 'Anomalie Programmazione', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'color' => '#f59e0b'],
                    'anomaly_squadre'        => ['label' => 'Anomalie Squadre',        'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'color' => '#3b82f6'],
                    'anomaly_statistiche'    => ['label' => 'Statistiche Cantiere',    'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'color' => '#7c3aed'],
                ],
            ],
        ];
    }

    private function applyWorkerSearch(string $baseQuery, string $search, array &$params): string
    {
        if ($search === '') return $baseQuery;
        $like       = '%' . $search . '%';
        $baseQuery .= " AND (
            first_name LIKE :search_first
            OR last_name LIKE :search_last
            OR CONCAT_WS(' ', first_name, last_name) LIKE :search_full_name
            OR CONCAT_WS(' ', last_name, first_name) LIKE :search_full_name_reverse
            OR company LIKE :search_company
            OR fiscal_code LIKE :search_fiscal
        )";
        $params[':search_first']             = $like;
        $params[':search_last']              = $like;
        $params[':search_full_name']         = $like;
        $params[':search_full_name_reverse'] = $like;
        $params[':search_company']           = $like;
        $params[':search_fiscal']            = $like;
        return $baseQuery;
    }

    private function bindWorkerSearchParams(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, \PDO::PARAM_STR);
        }
    }
}
