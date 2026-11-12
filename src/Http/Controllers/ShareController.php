<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Workers\WorkerRepository;
use App\Service\Share\SharedLinkController;

final class ShareController
{
    public function __construct(
        private \PDO $conn,
        private WorkerRepository $workerRepo
    ) {}

    // ── GET /share ────────────────────────────────────────────────────────────

    public function index(Request $request): never
    {
        $userId    = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $ctrl      = new SharedLinkController($this->conn, $userId);
        $links     = $ctrl->getAllLinks();
        // i cantieri a cui e' collegato ogni link (si vede nella BOB Zone)
        $collegati = $this->cantieriPerLink();
        foreach ($links as &$l) {
            $l['cantieri'] = $collegati[(int)$l['id']] ?? [];
        }
        unset($l);
        $pageTitle = 'Link Condivisi';
        $now         = date('Y-m-d H:i:s');
        $totalLinks  = count($links);
        $activeLinks = count(array_filter($links, fn($l) => !($l['expires_at'] && $l['expires_at'] < $now)));
        Response::view('share/index.html.twig', $request, compact('links', 'pageTitle', 'totalLinks', 'activeLinks', 'now'));
    }

    // ── GET|POST /share/create ────────────────────────────────────────────────

    public function create(Request $request): never
    {
        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $ctrl   = new SharedLinkController($this->conn, $userId);

        $successMessage = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $linkId = $ctrl->createFromRequest($_POST, $_FILES);
            $this->collegaCantieri($linkId, $_POST, $userId);

            $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            if ($isAjax) {
                $repo        = new SharedLinkRepository($this->conn);
                $createdLink = $repo->getLinkById($linkId);
                $linkToken   = $createdLink ? $createdLink['link_token'] : '';
                Response::json(['ok' => true, 'message' => 'Link creato con successo!', 'redirect' => '/share', 'link_token' => $linkToken]);
            }
            $successMessage = 'Link creato con successo!';
        }

        $workers   = $this->workerRepo->getAllActive();
        $companies = $ctrl->getAllCompanies();
        $cantieri  = $this->cantieriPerScelta();
        $cantieriCollegati = [];
        $pageTitle = 'Crea Link Condiviso';
        Response::view('share/create.html.twig', $request, compact('workers', 'companies', 'pageTitle', 'successMessage', 'cantieri', 'cantieriCollegati'));
    }

    // ── GET|POST /share/{id}/edit ─────────────────────────────────────────────

    public function edit(Request $request): never
    {
        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $linkId = $request->intParam('id');

        if ($linkId <= 0) {
            Response::redirect('/share');
        }

        $ctrl = new SharedLinkController($this->conn, $userId);
        $link = $ctrl->getLinkById($linkId);

        if (!$link) {
            Response::redirect('/share');
        }

        $linkedWorkers   = $ctrl->getLinkedWorkers($linkId);
        $linkedCompanies = $ctrl->getLinkedCompanies($linkId);
        $allFiles        = $ctrl->getFilesForLink($linkId);
        $manualFiles     = array_filter($allFiles, fn($f) => $f['source'] === 'manual');

        $successMessage = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ctrl->updateFromRequest($linkId, $_POST, $_FILES);
            $this->collegaCantieri($linkId, $_POST, $userId);

            $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            if ($isAjax) {
                Response::json(['ok' => true, 'message' => 'Link aggiornato con successo!', 'redirect' => '/share']);
            }
            $successMessage = 'Link aggiornato con successo!';
        }

        $workers   = $this->workerRepo->getAllActive();
        $companies = $ctrl->getAllCompanies();
        $cantieri  = $this->cantieriPerScelta();
        $cantieriCollegati = array_column($this->cantieriPerLink()[$linkId] ?? [], 'id');
        $pageTitle = 'Modifica Link Condiviso';
        Response::view('share/edit.html.twig', $request, compact(
            'link', 'linkedWorkers', 'linkedCompanies', 'manualFiles',
            'workers', 'companies', 'pageTitle', 'successMessage', 'cantieri', 'cantieriCollegati'
        ));
    }

    // ── POST /share/delete ────────────────────────────────────────────────────

    public function destroy(Request $request): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
            Response::redirect('/share');
        }

        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $linkId = (int)$_POST['id'];

        // Get link title before deletion
        $titleStmt = $this->conn->prepare('SELECT title FROM bb_shared_links WHERE id = :id LIMIT 1');
        $titleStmt->execute([':id' => $linkId]);
        $titleRow = $titleStmt->fetch(\PDO::FETCH_ASSOC);
        $linkLabel = $titleRow ? $titleRow['title'] : "Link condiviso #{$linkId}";

        $ctrl = new SharedLinkController($this->conn, $userId);
        $ctrl->deleteById($linkId);

        AuditLogger::log($this->conn, $request->user(), 'shared_link_delete', 'shared_link', $linkId, $linkLabel);

        Response::redirect('/share');
    }

    // ── POST /share/toggle-active ─────────────────────────────────────────────

    public function toggleActive(Request $request): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['ok' => false, 'error' => 'Metodo non consentito']);
        }

        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId <= 0) {
            Response::json(['ok' => false, 'error' => 'ID link mancante']);
        }

        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $ctrl   = new SharedLinkController($this->conn, $userId);

        try {
            $isNowActive = $ctrl->toggleActive($linkId);
            Response::json(['ok' => true, 'is_active' => $isNowActive]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── POST /share/update-password ───────────────────────────────────────────

    public function updatePassword(Request $request): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
        }

        $linkId   = (int)($_POST['link_id'] ?? 0);
        $password = trim((string)($_POST['password'] ?? ''));

        if ($linkId <= 0) {
            Response::json(['ok' => false, 'error' => 'ID link mancante'], 400);
        }

        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $ctrl   = new SharedLinkController($this->conn, $userId);

        try {
            $ctrl->updatePassword($linkId, $password !== '' ? $password : null);
            Response::json(['ok' => true, 'has_password' => $password !== '']);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Errore server: ' . $e->getMessage()], 500);
        }
    }

    // ── GET /share/fetch-companies ────────────────────────────────────────────

    public function fetchCompanies(Request $request): never
    {
        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $ctrl   = new SharedLinkController($this->conn, $userId);
        Response::json($ctrl->getAllCompanies());
    }

    // ── GET /share/fetch-company-documents ────────────────────────────────────

    public function fetchCompanyDocuments(Request $request): never
    {
        $userId     = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $companyIds = json_decode((string)($request->get('ids') ?? '[]'), true);
        $ctrl       = new SharedLinkController($this->conn, $userId);
        Response::json($ctrl->getCompanyDocumentsForIds((array)$companyIds));
    }

    // ── GET /share/fetch-worker-documents ─────────────────────────────────────

    public function fetchWorkerDocuments(Request $request): never
    {
        $userId   = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $workerId = (int)($request->get('worker_id') ?? 0);
        $ctrl     = new SharedLinkController($this->conn, $userId);
        Response::json($ctrl->getWorkerDocuments($workerId));
    }

    // ── GET /share/fetch-worker-documents-multiple ────────────────────────────

    public function fetchWorkerDocumentsMultiple(Request $request): never
    {
        $ids = json_decode((string)($request->get('ids') ?? '[]'), true);
        if (empty($ids)) {
            Response::json([]);
        }
        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        $ctrl   = new SharedLinkController($this->conn, $userId);
        Response::json($ctrl->getWorkerDocumentsMultiple((array)$ids));
    }

    // ── POST /share/upload-chunk ──────────────────────────────────────────────

    public function uploadChunk(Request $request): never
    {
        $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
        if ($userId <= 0) {
            Response::json(['ok' => false, 'message' => 'Utente non autenticato'], 401);
        }

        $uploadId    = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['upload_id']    ?? ''));
        $chunkIndex  = (int)($_POST['chunk_index']  ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);
        $origName    = basename((string)($_POST['filename'] ?? 'file.bin'));
        $chunk       = $_FILES['chunk'] ?? null;

        if ($uploadId === '' || $chunkIndex < 0 || $totalChunks <= 0
            || !$chunk || !is_uploaded_file($chunk['tmp_name'] ?? '')) {
            Response::json(['ok' => false, 'message' => 'Payload chunk non valido'], 422);
        }

        // Validate chunk size (50 MB max per chunk; frontend chunks at 20 MB,
        // this is just an anti-abuse upper bound)
        if (($chunk['size'] ?? 0) > 50 * 1024 * 1024) {
            Response::json(['ok' => false, 'message' => 'Chunk troppo grande (max 50 MB).'], 422);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName) ?: 'upload.bin';

        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud') ?: (dirname(APP_ROOT) . '/cloud');
        $tmpDir    = rtrim($cloudBase, '/\\') . '/shared_uploads_tmp/';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $token      = sprintf('u%d_%s_%s', $userId, $uploadId, $safeName);
        $targetPath = $tmpDir . $token;

        // Start fresh if this is the first chunk
        if ($chunkIndex === 0 && file_exists($targetPath)) {
            @unlink($targetPath);
        }

        $data = file_get_contents($chunk['tmp_name']);
        if ($data === false || file_put_contents($targetPath, $data, FILE_APPEND | LOCK_EX) === false) {
            Response::json(['ok' => false, 'message' => 'Errore salvataggio chunk'], 500);
        }

        $completed = ($chunkIndex + 1) >= $totalChunks;

        // On final chunk, validate the assembled file MIME type
        if ($completed) {
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($targetPath);
            $blocked  = [
                'application/x-php', 'text/x-php', 'application/php',
                'application/x-httpd-php', 'application/x-sh', 'text/x-sh',
                'application/x-executable', 'application/x-elf',
                'application/x-msdownload', 'application/x-dosexec',
            ];
            if (in_array($mimeType, $blocked, true)) {
                @unlink($targetPath);
                Response::json(['ok' => false, 'message' => 'Tipo di file non consentito.'], 422);
            }
        }

        Response::json([
            'ok'             => true,
            'completed'      => $completed,
            'uploaded_token' => $completed ? $token : null,
        ]);
    }

    // ── Cantieri collegati (BOB Zone) ─────────────────────────────────────────

    /** I cantieri fra cui scegliere: prima quelli in corso. */
    private function cantieriPerScelta(): array
    {
        try {
            return $this->conn->query("
                SELECT id, name, worksite_code, status, zone_share_link_id
                FROM bb_worksites WHERE is_draft = 0
                ORDER BY status = 'In corso' DESC, name ASC
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];   // colonna del link non ancora creata
        }
    }

    /** @return array<int, array<int, array{id:int, name:string, worksite_code:?string}>> link => cantieri */
    private function cantieriPerLink(): array
    {
        $fuori = [];
        try {
            foreach ($this->conn->query('
                SELECT id, name, worksite_code, zone_share_link_id FROM bb_worksites WHERE zone_share_link_id IS NOT NULL ORDER BY name
            ')->fetchAll(\PDO::FETCH_ASSOC) as $w) {
                $fuori[(int)$w['zone_share_link_id']][] = ['id' => (int)$w['id'], 'name' => $w['name'], 'worksite_code' => $w['worksite_code']];
            }
        } catch (\PDOException $e) {
            // colonna del link non ancora creata
        }
        return $fuori;
    }

    /**
     * Collega il link ai cantieri scelti (e lo scollega da quelli tolti). Nella
     * BOB Zone di ogni cantiere compaiono le cartelle "Documenti azienda" e
     * "Documenti operai". Un cantiere ha un link solo: sceglierlo qui lo
     * sposta da un eventuale altro link.
     */
    private function collegaCantieri(int $linkId, array $post, int $userId): void
    {
        if (empty($post['cantieri_inviati']) || $linkId <= 0) {
            return;
        }
        $voluti = array_values(array_unique(array_filter(array_map('intval', (array)($post['cantieri'] ?? [])))));
        try {
            $link = new \App\Service\Zone\LinkDocumenti(
                $this->conn,
                new \App\Service\Zone\Condivisione($this->conn, new \App\Service\Zone\Accesso($this->conn))
            );
            $prima = array_column($this->cantieriPerLink()[$linkId] ?? [], 'id');
            foreach (array_diff($prima, $voluti) as $w) {
                $link->collega((int)$w, null, $userId);
            }
            foreach (array_diff($voluti, $prima) as $w) {
                $link->collega((int)$w, $linkId, $userId);
            }
        } catch (\Throwable $e) {
            error_log('[Share cantieri] ' . $e->getMessage());
        }
    }
}
