<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Service\Documents\WorkerDocumentController;

final class DocumentsController
{
    public function __construct(private \PDO $conn) {}

    public function destroy(Request $request): never
    {
        $user  = $request->user();
        $docId = $request->intParam('id');

        $controller = new WorkerDocumentController($this->conn);

        $auditStmt = $this->conn->prepare(
            'SELECT d.tipo_documento, w.first_name, w.last_name
               FROM bb_worker_documents d
          LEFT JOIN bb_workers w ON w.id = d.worker_id
              WHERE d.id = :id LIMIT 1'
        );
        $auditStmt->execute([':id' => $docId]);
        $auditDoc = $auditStmt->fetch(\PDO::FETCH_ASSOC);

        try {
            $controller->deleteById($user, $docId);
            $label = $auditDoc
                ? trim(($auditDoc['first_name'] ?? '') . ' ' . ($auditDoc['last_name'] ?? '')) . ' — ' . ($auditDoc['tipo_documento'] ?? '')
                : (string)$docId;
            AuditLogger::log($this->conn, $user, 'document_delete', 'document', $docId, $label);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 404);
        }

        $redirect = $controller->resolveSafeRedirect((string)($_SERVER['HTTP_REFERER'] ?? ''), '/users');
        Response::redirect($redirect);
    }

    public function update(Request $request): never
    {
        $user = $request->user();

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new \RuntimeException('Richiesta non valida.');
            }

            $controller = new WorkerDocumentController($this->conn);
            $controller->updateFromRequest($user, (int)$user->id, $_POST, $_FILES);

            Response::json(['success' => true]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function upload(Request $request): never
    {
        $user = $request->user();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'error' => 'Metodo non consentito'], 405);
        }

        try {
            $controller = new WorkerDocumentController($this->conn);
            $controller->uploadFromRequest($user, $_POST, $_FILES);
            AuditLogger::log(
                $this->conn, $user, 'document_upload', 'document', null,
                $_POST['doc_type'] ?? null,
                ['worker_id' => (int)($_POST['worker_id'] ?? 0)]
            );
            Response::json(['success' => true]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    // ── GET /documents/check-mandatory?worker_id={id} ─────────────────────────

    public function checkMandatory(Request $request): void
    {

        $workerId = (int)($request->get('worker_id') ?? 0);
        if (!$workerId) {
            Response::error('ID lavoratore non specificato.', 400);
        }

        $user = $request->user();
        $conn = $this->conn;
        assertCompanyScopeWorkerAccess($conn, $user, $workerId);

        // Render the PHP partial as a fragment (no layout)
        include APP_ROOT . '/views/documents/check_mandatory.php';
        exit;
    }

    // ── GET /documents/check-mandatory-company?company_id={id} ────────────────

    public function checkMandatoryCompany(Request $request): void
    {

        $companyId = (int)($request->get('company_id') ?? 0);
        if (!$companyId) {
            Response::error('ID azienda non specificato.', 400);
        }

        $user = $request->user();
        $conn = $this->conn;
        assertCompanyScopeCompanyDocAccess($conn, $user, $companyId);

        // Render the PHP partial as a fragment (no layout)
        include APP_ROOT . '/views/documents/check_mandatory_company.php';
        exit;
    }

    // ── GET /documents/expired ────────────────────────────────────────────────

    public function expired(Request $request): void
    {
        $controller = new WorkerDocumentController($this->conn);
        $expiredData = $controller->getExpiredDocuments($request->user());
        $companyDocs = $expiredData['companyDocs'];
        $workerDocs  = $expiredData['workerDocs'];

        $totalExpired = count($companyDocs) + count($workerDocs);
        $companyCount = count($companyDocs);
        $workerCount  = count($workerDocs);

        $criticalCount = 0;
        $today = new \DateTime();
        foreach (array_merge($companyDocs, $workerDocs) as $d) {
            $exp = new \DateTime($d['scadenza_norm']);
            if ($today->diff($exp)->days > 30) {
                $criticalCount++;
            }
        }

        Response::view('documents/expired.html.twig', $request, [
            'companyDocs'  => $companyDocs,
            'workerDocs'   => $workerDocs,
            'totalExpired' => $totalExpired,
            'companyCount' => $companyCount,
            'workerCount'  => $workerCount,
            'criticalCount' => $criticalCount,
        ]);
    }

    // ── GET /documents/expired-cv ─────────────────────────────────────────────

    public function expiredCv(Request $request): void
    {

        if (!isCompanyScopedUserByContext($this->conn, $request->user())) {
            Response::error('Accesso negato', 403);
        }

        $controller   = new WorkerDocumentController($this->conn);
        $expiredData  = $controller->getExpiredDocuments($request->user());
        $expiringData = $controller->getExpiringDocuments($request->user(), 30);

        $expiredCompanyDocs  = $expiredData['companyDocs'];
        $expiredWorkerDocs   = $expiredData['workerDocs'];
        $expiringCompanyDocs = $expiringData['companyDocs'];
        $expiringWorkerDocs  = $expiringData['workerDocs'];

        $allExpired = array_merge(
            array_map(static fn($d) => array_merge($d, ['_type' => 'company']), $expiredCompanyDocs),
            array_map(static fn($d) => array_merge($d, ['_type' => 'worker']),  $expiredWorkerDocs)
        );
        $allExpiring = array_merge(
            array_map(static fn($d) => array_merge($d, ['_type' => 'company']), $expiringCompanyDocs),
            array_map(static fn($d) => array_merge($d, ['_type' => 'worker']),  $expiringWorkerDocs)
        );

        usort($allExpired,  static fn($a, $b) => strcmp($a['scadenza_norm'], $b['scadenza_norm']));
        usort($allExpiring, static fn($a, $b) => strcmp($a['scadenza_norm'], $b['scadenza_norm']));

        $totalExpired  = count($allExpired);
        $totalExpiring = count($allExpiring);

        $criticalCount = 0;
        $today = new \DateTime();
        foreach ($allExpired as $d) {
            $exp = new \DateTime($d['scadenza_norm']);
            if ($today->diff($exp)->days > 30) {
                $criticalCount++;
            }
        }

        Response::view('documents/expired_cv.html.twig', $request, [
            'allExpired'    => $allExpired,
            'allExpiring'   => $allExpiring,
            'totalExpired'  => $totalExpired,
            'totalExpiring' => $totalExpiring,
            'criticalCount' => $criticalCount,
        ]);
    }

    // ── GET /documents/serve?id={id} ──────────────────────────────────────────

    public function serve(Request $request): never
    {

        $docId = (int)($request->get('id') ?? 0);
        if (!$docId) {
            Response::error('Documento non specificato.', 400);
        }

        $stmt = $this->conn->prepare(
            'SELECT d.path, d.worker_id FROM bb_worker_documents d WHERE d.id = :id'
        );
        $stmt->bindParam(':id', $docId, \PDO::PARAM_INT);
        $stmt->execute();
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            render_not_found_and_exit();
        }

        assertCompanyScopeWorkerAccess($this->conn, $request->user(), (int)$doc['worker_id']);

        $cloudBasePath = realpath(dirname(APP_ROOT) . '/cloud');
        $filePath      = realpath($cloudBasePath . '/' . $doc['path']);

        if (!$filePath || !file_exists($filePath)) {
            render_not_found_and_exit();
        }

        if (strpos($filePath, $cloudBasePath) !== 0) {
            render_not_found_and_exit();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        readfile($filePath);
        exit;
    }
}
