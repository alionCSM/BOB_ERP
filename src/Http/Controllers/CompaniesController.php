<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Service\Companies\CompanyController;

final class CompaniesController
{
    private CompanyController $ctrl;
    private \PDO $conn;

    public function __construct(\PDO $conn)
    {
        $this->conn = $conn;
        $this->ctrl = new CompanyController($conn);
    }

    public function index(Request $request): void
    {
        $companies        = $this->ctrl->listCompanies();
        $totalCompanies   = count($companies);
        $consorziate      = count(array_filter($companies, fn($c) => (int)($c['consorziata'] ?? 0) === 1));
        $nonConsorziate   = $totalCompanies - $consorziate;
        $activeCompanies  = count(array_filter($companies, fn($c) => (int)($c['active'] ?? 1) === 1));
        $inactiveCompanies = $totalCompanies - $activeCompanies;
        $pageTitle        = 'Aziende';
        Response::view('companies/index.html.twig', $request, compact(
            'companies', 'totalCompanies', 'consorziate', 'nonConsorziate',
            'activeCompanies', 'inactiveCompanies', 'pageTitle'
        ));
    }

    public function my(Request $request): void
    {
        $companies = $this->ctrl->getMyCompanies($request->user());
        $pageTitle = 'Le mie aziende';
        Response::view('companies/my.html.twig', $request, compact('companies', 'pageTitle'));
    }

    public function create(Request $request): void
    {
        $errors = $_SESSION['errors'] ?? [];
        $old    = $_SESSION['old']    ?? [];
        unset($_SESSION['errors'], $_SESSION['old']);
        $pageTitle = 'Inserisci Nuova Azienda';
        Response::view('companies/create.html.twig', $request, compact('errors', 'old', 'pageTitle'));
    }

    public function store(Request $request): void
    {
        $data = [
            'name'        => trim($request->post('name', '')),
            'codice'      => trim($request->post('codice', '')) ?: null,
            'consorziata' => trim($request->post('consorziata', '')),
        ];

        try {
            $this->ctrl->createFromRequest($data);
            $_SESSION['success'] = 'Azienda creata con successo!';
            Response::redirect('/companies');
        } catch (\Exception $e) {
            $_SESSION['errors'] = [$e->getMessage()];
            $_SESSION['old']    = $data;
            Response::redirect('/companies/create');
        }
    }

    public function show(Request $request): void
    {
        $id = $request->intParam('id');
        if ($id === 0) {
            Response::redirect('/companies');
        }

        $details                = $this->ctrl->getCompanyDetails($request->user(), $id);
        $company                = $details['company'];
        $isCompanyViewer        = $details['isCompanyViewer']
                                  || isCompanyScopedUserByContext($GLOBALS['connection'], $request->user());
        $documents              = $details['documents'];
        $allCompanies           = $details['allCompanies'];
        $assignableCompanyUsers = $details['assignableCompanyUsers'];
        $workers                = $details['workers'];
        $createdUsername        = $request->get('username', '');
        $tempPassword           = $request->get('temp', '');
        $userCreated            = $request->get('user_created', '');
        $userError              = $request->get('user_error', '');
        $errCode                = $request->get('err_code', '');
        $accessAdded            = $request->get('access_added', '');
        $accessError            = $request->get('access_error', '');
        $workerDeleted          = $request->get('worker_deleted', '');
        $workerError            = $request->get('worker_error', '');
        $uploaded_by            = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $pageTitle              = 'Azienda - ' . htmlspecialchars($company['name']);

        // Avatar color and initials for hero
        $avatarColors = [
            'linear-gradient(135deg,#1d4ed8,#0ea5e9)',
            'linear-gradient(135deg,#7c3aed,#a855f7)',
            'linear-gradient(135deg,#0f766e,#14b8a6)',
            'linear-gradient(135deg,#b45309,#f59e0b)',
            'linear-gradient(135deg,#be185d,#ec4899)',
            'linear-gradient(135deg,#4338ca,#6366f1)',
            'linear-gradient(135deg,#15803d,#22c55e)',
            'linear-gradient(135deg,#9333ea,#c084fc)'
        ];
        $avatarColor = $avatarColors[$company['id'] % 8] ?? $avatarColors[0];
        $nameParts   = explode(' ', trim($company['name']));
        $initials    = strtoupper((isset($nameParts[0]) ? $nameParts[0][0] : '') . (isset($nameParts[1]) ? $nameParts[1][0] : ''));

        Response::view('companies/show.html.twig', $request, compact(
            'company', 'isCompanyViewer', 'documents', 'allCompanies',
            'assignableCompanyUsers', 'workers', 'createdUsername',
            'tempPassword', 'userCreated', 'userError', 'errCode',
            'accessAdded', 'accessError', 'workerDeleted', 'workerError',
            'uploaded_by', 'pageTitle', 'avatarColor', 'initials'
        ));
    }

    public function edit(Request $request): void
    {
        $id      = $request->intParam('id');
        $company = $id ? $this->ctrl->getCompanyById($id) : null;
        if (!$company) {
            Response::redirect('/companies');
        }

        $errors    = $_SESSION['errors'] ?? [];
        unset($_SESSION['errors']);
        $pageTitle = 'Modifica Azienda';
        Response::view('companies/edit.html.twig', $request, compact('company', 'errors', 'pageTitle'));
    }

    public function update(Request $request): void
    {
        $id   = $request->intParam('id');
        $data = [
            'name'        => $request->post('name', ''),
            'codice'      => $request->post('codice', ''),
            'consorziata' => $request->post('consorziata', ''),
        ];
        $this->ctrl->updateFromRequest($id, $data);
        $_SESSION['success'] = 'Azienda aggiornata con successo!';
        Response::redirect('/companies');
    }

    public function destroy(Request $request): void
    {
        $id = $request->intParam('id');
        try {
            // Get company name before deletion
            $nameStmt = $this->conn->prepare('SELECT name FROM bb_companies WHERE id = :id LIMIT 1');
            $nameStmt->execute([':id' => $id]);
            $nameRow = $nameStmt->fetch(\PDO::FETCH_ASSOC);
            $companyLabel = $nameRow ? $nameRow['name'] : "Azienda #{$id}";

            $this->ctrl->deleteCompany($request->user(), $id);
            $_SESSION['success'] = 'Azienda eliminata con successo.';
            AuditLogger::log($this->conn, $request->user(), 'company_delete', 'company', $id, $companyLabel);
        } catch (\RuntimeException $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        Response::redirect('/companies');
    }

    // ── Company documents ─────────────────────────────────────────────────────

    public function destroyDocument(Request $request): never
    {
        $user = $request->user();
        $id   = $request->intParam('id');

        $doc = $this->ctrl->getDocumentById($id);
        if (!$doc) {
            Response::error('Documento non trovato', 404);
        }

        $canManageAll = (int)$user->id === 1 || !empty($user->permissions['companies']);
        if (!$canManageAll) {
            assertCompanyScopeCompanyDocAccess($this->conn, $user, (int)$doc['company_id']);
        }

        $this->ctrl->deleteDocument($id);

        AuditLogger::log(
            $this->conn, $user, 'company_doc_delete', 'company_document', $id,
            ($doc['tipo_documento'] ?? (string)$id) . ' (company #' . (int)$doc['company_id'] . ')',
            ['company_id' => (int)$doc['company_id']]
        );

        Response::redirect('/companies/' . (int)$doc['company_id']);
    }

    public function updateDocument(Request $request): never
    {
        $user = $request->user();

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['document_id'])) {
                throw new \Exception('Richiesta non valida.');
            }

            $canManageAll = (int)$user->id === 1 || !empty($user->permissions['companies']);
            if (!$canManageAll && isCompanyScopedUserByContext($this->conn, $user)) {
                $doc = $this->ctrl->getDocumentById((int)$_POST['document_id']);
                if (!$doc) throw new \Exception('Documento non trovato.');
                $allowedIds = getCompanyScopeAllowedIds($this->conn, $user);
                if (!in_array((int)$doc['company_id'], $allowedIds, true)) {
                    throw new \Exception('Accesso negato.');
                }
            }

            $this->ctrl->updateDocumentFromRequest($_POST, $_FILES, (int)$user->id);
            Response::json(['success' => true]);
        } catch (\Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function uploadDocument(Request $request): never
    {
        $user = $request->user();

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['company_id'])) {
                throw new \Exception('Richiesta non valida.');
            }

            $canManageAll = (int)$user->id === 1 || !empty($user->permissions['companies']);
            if (!$canManageAll && isCompanyScopedUserByContext($this->conn, $user)) {
                $allowedIds = getCompanyScopeAllowedIds($this->conn, $user);
                if (!in_array((int)$_POST['company_id'], $allowedIds, true)) {
                    throw new \Exception('Accesso negato.');
                }
            }

            $this->ctrl->uploadDocumentFromRequest($_POST, $_FILES, (int)$user->id);
            AuditLogger::log(
                $this->conn, $user, 'company_doc_upload', 'company_document', null,
                $_POST['doc_type'] ?? null,
                ['company_id' => (int)$_POST['company_id']]
            );
            Response::json(['success' => true]);
        } catch (\Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Company access / users ────────────────────────────────────────────────

    public function assignAccess(Request $request): never
    {
        $user = $request->user();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo non consentito', 405);
        }

        if ((int)$user->id !== 1 && !($user->permissions['companies'] ?? false)) {
            Response::error('Accesso negato', 403);
        }

        $companyId = (int)($_POST['company_id'] ?? 0);
        $userId    = (int)($_POST['user_id']    ?? 0);

        try {
            $this->ctrl->assignCompanyAccess($companyId, $userId);
            Response::redirect('/companies/' . $companyId . '?access_added=1');
        } catch (\InvalidArgumentException $e) {
            Response::redirect('/companies/' . $companyId . '?access_error=' . urlencode($e->getMessage()));
        } catch (\Exception $e) {
            $msg = (($_ENV['APP_ENV'] ?? 'production') !== 'production') ? $e->getMessage() : 'generic';
            Response::redirect('/companies/' . $companyId . '?access_error=' . urlencode($msg));
        }
    }

    public function createCompanyUser(Request $request): never
    {
        $user = $request->user();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo non consentito', 405);
        }

        if ((int)$user->id !== 1 && !($user->permissions['companies'] ?? false)) {
            Response::error('Accesso negato', 403);
        }

        $companyId = (int)($_POST['company_id'] ?? 0);

        try {
            $created = $this->ctrl->createCompanyUser((int)$user->id, $_POST);
            Response::redirect('/companies/' . $companyId . '?user_created=1&username=' . urlencode($created['username']) . '&temp=' . urlencode($created['temp_password']));
        } catch (\InvalidArgumentException $e) {
            Response::redirect('/companies/' . $companyId . '?user_error=' . urlencode($e->getMessage()));
        } catch (\Exception $e) {
            $msg = (($_ENV['APP_ENV'] ?? 'production') !== 'production') ? $e->getMessage() : 'generic';
            Response::redirect('/companies/' . $companyId . '?user_error=' . urlencode($msg));
        }
    }

    // ── GET /companies/documents/serve?id={id} ───────────────────────────────

    public function serveCompanyDocument(Request $request): never
    {
        $docId = (int)($request->get('id') ?? 0);
        if (!$docId) {
            Response::error('Documento non specificato.', 400);
        }

        $ctrl = new CompanyController($this->conn);
        $doc  = $ctrl->getDocumentById($docId);
        if (!$doc) {
            Response::error('Documento non trovato.', 404);
        }

        assertCompanyScopeCompanyDocAccess($this->conn, $request->user(), (int)$doc['company_id']);

        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        $filePath  = realpath($cloudBase . '/' . $doc['file_path']);

        if (!$filePath || !file_exists($filePath)) {
            Response::error('File non trovato.', 404);
        }

        if (strpos($filePath, $cloudBase) !== 0) {
            Response::error('Percorso non valido.', 403);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Cache-Control: private, no-store');
        readfile($filePath);
        exit;
    }

    // ── POST /companies/{id}/toggle-active ───────────────────────────────────

    public function toggleActive(Request $request): never
    {
        $user = $request->user();

        if ((int)$user->id !== 1 && !($user->permissions['companies'] ?? false)) {
            Response::json(['ok' => false, 'error' => 'Accesso negato'], 403);
        }

        $id = $request->intParam('id');
        if ($id <= 0) {
            Response::json(['ok' => false, 'error' => 'ID non valido'], 400);
        }

        $wantsJson = !empty($_SERVER['HTTP_X_FETCH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        try {
            $result    = $this->ctrl->toggleActive($id);
            $newActive = $result['active'];
            $deactivatedWorkers = $result['deactivated_workers'] ?? 0;

            AuditLogger::log(
                $this->conn, $user,
                $newActive ? 'company_activated' : 'company_deactivated',
                'company', $id,
                $newActive ? 'attivata' : 'disattivata'
            );

            if ($wantsJson) {
                Response::json([
                    'ok'                  => true,
                    'active'              => $newActive ? 1 : 0,
                    'deactivated_workers' => $deactivatedWorkers,
                ]);
            }

            if ($newActive) {
                $_SESSION['success'] = 'Azienda attivata.';
            } elseif ($deactivatedWorkers > 0) {
                $_SESSION['success'] = 'Azienda disattivata. ' . $deactivatedWorkers . ' lavorator' . ($deactivatedWorkers === 1 ? 'e disattivato' : 'i disattivati') . '.';
            } else {
                $_SESSION['success'] = 'Azienda disattivata.';
            }
            Response::redirect('/companies/' . $id);
        } catch (\RuntimeException $e) {
            if ($wantsJson) {
                Response::json(['ok' => false, 'error' => $e->getMessage()], 404);
            }
            $_SESSION['error'] = $e->getMessage();
            Response::redirect('/companies/' . $id);
        }
    }

    // ── GET /companies/export/consorziata ─────────────────────────────────────

    public function exportConsorziata(Request $request): never
    {
        Response::view('companies/export_excel_consorziata.php', $request, ['conn' => $this->conn]);
    }


    // ── DELETE worker ────────────────────────────────────────────────────────

    public function deleteWorker(Request $request): never
    {
        $user = $request->user();
        $companyId = $request->intParam('id');
        $workerId = (int)($_POST['worker_id'] ?? 0);
        $providedUid = (string)($_POST['worker_uid'] ?? '');

        if ($companyId <= 0 || $workerId <= 0) {
            Response::error('Richiesta non valida.', 400);
        }

        // Validate uid parameter for security
        if (!validateWorkerUid($this->conn, $workerId, $providedUid)) {
            Response::redirect('/companies/' . $companyId . '?worker_error=invalid_worker');
        }

        try {
            // Get worker name before deletion for audit log
            $worker = $this->ctrl->getWorkerById($workerId);
            if (!$worker) {
                Response::redirect('/companies/' . $companyId . '?worker_error=non_trovato');
            }
            $workerName = htmlspecialchars((string)($worker['first_name'] ?? '') . ' ' . (string)($worker['last_name'] ?? ''));

            $this->ctrl->deleteWorker($user, $companyId, $workerId);

            AuditLogger::log(
                $this->conn, $user, 'worker_delete', 'worker', $workerId,
                $workerName . ' (company #' . $companyId . ')',
                ['company_id' => $companyId]
            );

            Response::redirect('/companies/' . $companyId . '?worker_deleted=1');
        } catch (\RuntimeException $e) {
            Response::redirect('/companies/' . $companyId . '?worker_error=' . urlencode($e->getMessage()));
        }
    }
}
