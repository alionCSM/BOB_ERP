<?php
declare(strict_types=1);

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\ProjectsApi;
use App\Fieldwire\Api\TasksApi;
use App\Fieldwire\FieldwireClient;
use App\Fieldwire\Sync\FloorplanSync;
use App\Fieldwire\Sync\InitialSyncService;
use App\Fieldwire\Sync\OutboundSyncService;
use App\Fieldwire\Sync\ProjectSync;
use App\Fieldwire\Webhook\WebhookHandler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\ZoneAnnotationRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;
use App\Repository\Worksites\WorksiteRepository;

/**
 * BOB Zone + integrazione Fieldwire.
 *
 * Architettura:
 *  - bb_zone_* sono la SoT (source of truth). Funzionano per OGNI cantiere.
 *  - Se il cantiere ha fieldwire_project_id, ogni mutazione locale viene
 *    pushata su Fieldwire e il fw_id ritornato viene salvato. La sync
 *    inversa (Fieldwire → BOB) e' gestita dai webhook.
 *  - Le bb_fw_* tables sono deprecate; non vengono piu' scritte (eccetto
 *    bb_fw_floorplans che resta come cache metadata).
 */
final class FieldwireController
{
    private ZoneTaskRepository       $zoneRepo;
    private FwFloorplanRepository    $fwFloorplanRepo;
    private ZoneAnnotationRepository $annRepo;

    public function __construct(
        private Config             $config,
        private WorksiteRepository $worksiteRepo,
        private \PDO               $conn
    ) {
        $this->zoneRepo        = new ZoneTaskRepository($conn);
        $this->fwFloorplanRepo = new FwFloorplanRepository($conn);
        $this->annRepo         = new ZoneAnnotationRepository($conn);
    }

    // ─── Pagina BOB Zone ──────────────────────────────────────────────────────

    public function page(Request $request): void
    {
        $worksiteId = (int) ($request->param('id') ?? 0);
        $worksite   = $this->worksiteRepo->findById($worksiteId);
        if (!$worksite) { http_response_code(404); exit; }

        Response::view('worksites/fieldwire.html.twig', $request, [
            'worksite_id'          => $worksiteId,
            'worksite'             => $worksite,
            'fieldwire_project_id' => $worksite['fieldwire_project_id'] ?? null,
            'fieldwire_enabled'    => $this->config->fieldwireEnabled(),
        ]);
    }

    // ─── Tasks ────────────────────────────────────────────────────────────────

    public function tasks(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            return $this->zoneRepo->allForWorksite($worksiteId);
        });
    }

    public function createTask(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $user       = $request->user();
            $body       = $this->jsonBody();

            if (empty($body['name'])) {
                throw new \RuntimeException('Il nome del task è obbligatorio');
            }

            $taskId = $this->zoneRepo->create($worksiteId, $body, (int)($user?->id ?? 0));
            $this->pushTaskToFieldwire($worksiteId, $taskId, $body);

            // notifica all'assegnatario
            if (!empty($body['assignee_user_id'])) {
                $this->notifyAssignee($worksiteId, (int)$body['assignee_user_id'],
                    $body['name'] ?? 'Task', (int)($user?->id ?? 0));
            }

            return $this->zoneRepo->find($taskId);
        });
    }

    public function updateTask(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();

            $existing = $this->zoneRepo->find($taskId);
            if (!$existing) throw new \RuntimeException('Task non trovato');

            $merged = array_merge($existing, $body);
            $this->zoneRepo->update($taskId, $merged);

            // notifica se l'assegnatario e' cambiato
            $newAssignee = !empty($body['assignee_user_id']) ? (int)$body['assignee_user_id'] : null;
            $oldAssignee = !empty($existing['assignee_user_id']) ? (int)$existing['assignee_user_id'] : null;
            if ($newAssignee && $newAssignee !== $oldAssignee) {
                $this->notifyAssignee($worksiteId, $newAssignee,
                    $merged['name'] ?? 'Task', (int)($request->user()?->id ?? 0));
            }

            // push update su Fieldwire se collegato
            if (!empty($existing['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new TasksApi($this->makeClient()))->update(
                            $worksite['fieldwire_project_id'],
                            (string)$existing['fw_id'],
                            $this->taskFieldsForFieldwire($merged)
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push update task] ' . $e->getMessage());
                    }
                }
            }
            return $this->zoneRepo->find($taskId);
        });
    }

    public function updateTaskStatus(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();
            $status     = $body['status'] ?? 'open';

            $this->zoneRepo->updateStatus($taskId, $status);

            // push status su Fieldwire
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    (new TasksApi($this->makeClient()))->update(
                        $worksite['fieldwire_project_id'],
                        (string)$task['fw_id'],
                        ['status' => $status]
                    );
                } catch (\Throwable $e) {
                    error_log('[FW push status] ' . $e->getMessage());
                }
            }
            return ['updated' => true, 'status' => $status];
        });
    }

    public function deleteTask(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $task       = $this->zoneRepo->find($taskId);
            if (!$task) throw new \RuntimeException('Task non trovato');

            // delete su Fieldwire prima di cancellare in locale
            if (!empty($task['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new TasksApi($this->makeClient()))->delete(
                            $worksite['fieldwire_project_id'],
                            (string)$task['fw_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push delete task] ' . $e->getMessage());
                    }
                }
            }
            $this->zoneRepo->delete($taskId);
            return ['deleted' => true];
        });
    }

    // ─── Comments ─────────────────────────────────────────────────────────────

    public function comments(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            return $this->zoneRepo->commentsForTask($taskId);
        });
    }

    public function postComment(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $user       = $request->user();
            $body       = $this->jsonBody();
            $text       = trim($body['text'] ?? '');

            if ($text === '') throw new \RuntimeException('Il messaggio non può essere vuoto');

            $authorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                         ?: ($user->username ?? 'Utente');

            $id = $this->zoneRepo->addComment($taskId, $text, $authorName);

            // push su Fieldwire
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    $fw = (new BubblesApi($this->makeClient()))->postComment(
                        $worksite['fieldwire_project_id'], $task['fw_id'], $text
                    );
                    if (!empty($fw['id'])) $this->zoneRepo->setCommentFwId($id, (string)$fw['id']);
                } catch (\Throwable $e) {
                    error_log('[FW push comment] ' . $e->getMessage());
                }
            }
            return ['id' => $id, 'text' => $text, 'author_name' => $authorName, 'created_at' => date('Y-m-d H:i:s')];
        });
    }

    /** Upload foto su un task → crea un commento con file_url. Multipart. */
    public function postPhoto(Request $request): void
    {
        // NB: multipart, non JSON. Risponde JSON.
        header('Content-Type: application/json');
        try {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $user       = $request->user();

            if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Nessuna foto ricevuta');
            }
            $f = $_FILES['photo'];
            if (($f['size'] ?? 0) > 25 * 1024 * 1024) {
                throw new \RuntimeException('Foto troppo grande (max 25 MB)');
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
                throw new \RuntimeException('Formato immagine non consentito');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
            if (strpos((string)$mime, 'image/') !== 0 && $mime !== 'application/octet-stream') {
                throw new \RuntimeException('Il file non è un\'immagine');
            }

            $dir  = \CloudPath::ensureZonePhotosDir($worksiteId);
            $name = 't' . $taskId . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
            $dest = $dir . DIRECTORY_SEPARATOR . $name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new \RuntimeException('Salvataggio foto fallito');
            }
            $rel = \CloudPath::relativeToRoot($dest);

            $authorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                         ?: ($user->username ?? 'Utente');
            $text = trim($_POST['text'] ?? '');
            $fileUrl = "/worksites/{$worksiteId}/zone/photo?f=" . rawurlencode($rel);

            $id = $this->zoneRepo->addComment($taskId, $text, $authorName, $fileUrl);

            echo json_encode(['ok' => true, 'data' => [
                'id' => $id, 'text' => $text, 'author_name' => $authorName,
                'file_url' => $fileUrl, 'created_at' => date('Y-m-d H:i:s'),
            ]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** Stream di una foto BOB Zone (path relativo in ?f=). */
    public function zonePhoto(Request $request): void
    {
        $worksiteId = (int) $request->param('id');
        $rel = (string) ($_GET['f'] ?? '');
        // sicurezza: deve stare sotto BOBZone/<worksiteId>/ e niente traversal
        $expectedPrefix = 'BOBZone/' . $worksiteId . '/';
        $relNorm = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($relNorm, '..') !== false || strpos($relNorm, $expectedPrefix) !== 0) {
            http_response_code(403); exit('Accesso negato');
        }
        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $rel;
        $real = realpath($abs);
        $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0 || !is_file($real)) {
            http_response_code(404); exit('Foto non trovata');
        }
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','heic'=>'image/heic'];
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        header('X-Frame-Options: SAMEORIGIN');
        readfile($real);
        exit;
    }

    public function deleteComment(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $commentId  = (int) $request->param('commentId');

            $comment = $this->zoneRepo->findComment($commentId);
            if (!$comment) throw new \RuntimeException('Commento non trovato');

            if (!empty($comment['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new BubblesApi($this->makeClient()))->delete(
                            $worksite['fieldwire_project_id'], (string)$comment['fw_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push delete bubble] ' . $e->getMessage());
                    }
                }
            }
            $this->zoneRepo->deleteComment($commentId);
            return ['deleted' => true];
        });
    }

    // ─── Checklist ────────────────────────────────────────────────────────────

    public function checklist(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            return $this->zoneRepo->checklistForTask($taskId);
        });
    }

    public function addChecklistItem(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();
            $name       = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome elemento obbligatorio');

            $id = $this->zoneRepo->addChecklistItem($taskId, $name);

            // push su Fieldwire
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    $fw = (new CheckItemsApi($this->makeClient()))->create(
                        $worksite['fieldwire_project_id'], (string)$task['fw_id'], $name
                    );
                    if (!empty($fw['id'])) $this->zoneRepo->setChecklistItemFwId($id, (string)$fw['id']);
                } catch (\Throwable $e) {
                    error_log('[FW push check_item create] ' . $e->getMessage());
                }
            }
            return ['id' => $id, 'name' => $name, 'completed' => false];
        });
    }

    public function completeChecklistItem(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $itemId     = (int) $request->param('itemId');
            $body       = $this->jsonBody();
            $done       = (bool) ($body['completed'] ?? true);

            $this->zoneRepo->completeChecklistItem($itemId, $done);

            // push su Fieldwire
            $item     = $this->zoneRepo->findChecklistItem($itemId);
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($item['fw_id']) && !empty($task['fw_id'])
                && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    (new CheckItemsApi($this->makeClient()))->update(
                        $worksite['fieldwire_project_id'],
                        (string)$task['fw_id'],
                        (string)$item['fw_id'],
                        ['completed' => $done]
                    );
                } catch (\Throwable $e) {
                    error_log('[FW push check_item update] ' . $e->getMessage());
                }
            }
            return ['completed' => $done];
        });
    }

    public function deleteChecklistItem(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $itemId     = (int) $request->param('itemId');

            $item = $this->zoneRepo->findChecklistItem($itemId);
            if (!$item) throw new \RuntimeException('Elemento checklist non trovato');

            $task = $this->zoneRepo->find($taskId);
            if (!empty($item['fw_id']) && !empty($task['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new CheckItemsApi($this->makeClient()))->delete(
                            $worksite['fieldwire_project_id'],
                            (string)$task['fw_id'],
                            (string)$item['fw_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push check_item delete] ' . $e->getMessage());
                    }
                }
            }
            $this->zoneRepo->deleteChecklistItem($itemId);
            return ['deleted' => true];
        });
    }

    // ─── Lookup utenti BOB (per dropdown assignee) ────────────────────────────

    public function bobUsers(Request $request): void
    {
        $this->jsonResponse(function () {
            $stmt = $this->conn->query("
                SELECT id, username,
                       TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) AS full_name
                FROM bb_users
                WHERE active = 'Y'
                ORDER BY username ASC
            ");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map(fn($r) => [
                'id'    => (int)$r['id'],
                'label' => $r['full_name'] !== '' ? $r['full_name'] : $r['username'],
            ], $rows);
        });
    }

    // ─── Floorplans (Fieldwire only) ──────────────────────────────────────────

    public function floorplans(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            return $this->fwFloorplanRepo->allForWorksite($worksiteId);
        });
    }

    // ─── Disegni / Tavole (BOB-native + Fieldwire) ────────────────────────────

    /**
     * Lista disegni BOB del cantiere (categoria Disegni) con stato sync FW,
     * piu' le floorplan Fieldwire che non hanno corrispondenza BOB.
     */
    public function disegni(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            $fwProjectId = $worksite['fieldwire_project_id'] ?? null;

            // disegni BOB (sorgente di verita') + stato sync
            $stmt = $this->conn->prepare("
                SELECT d.id, d.file_name, d.file_type, d.note, d.subcategory,
                       d.created_at,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS uploader,
                       s.fw_sheet_upload_id, s.pushed_at,
                       r.status AS dwg_status
                FROM bb_worksite_documents d
                LEFT JOIN bb_users u             ON u.id = d.created_by
                LEFT JOIN bb_zone_disegno_sync s ON s.document_id = d.id
                LEFT JOIN bb_zone_dwg_render r   ON r.document_id = d.id
                WHERE d.worksite_id = :wid AND d.is_deleted = 0
                  AND d.file_path LIKE '%/Disegni/%'
                ORDER BY d.created_at DESC
            ");
            $stmt->execute([':wid' => $worksiteId]);
            $bob = array_map(function ($r) use ($worksiteId) {
                $type = strtolower($r['file_type'] ?? '');
                $isDwg = in_array($type, ['dwg', 'dxf'], true); // entrambi passano dal render vettoriale
                return [
                    'id'           => (int)$r['id'],
                    'file_name'    => $r['file_name'],
                    'file_type'    => $r['file_type'],
                    'note'         => $r['note'],
                    'folder'       => $r['subcategory'] ?: 'altri',
                    'uploader'     => trim($r['uploader']) ?: '—',
                    'created_at'   => $r['created_at'],
                    'view_url'     => "/worksites/{$worksiteId}/disegni/{$r['id']}/view",
                    'fw_synced'    => !empty($r['pushed_at']),
                    'fw_pushed_at' => $r['pushed_at'],
                    // DWG: stato della conversione vettoriale
                    'is_dwg'       => $isDwg,
                    'dwg_status'   => $isDwg ? ($r['dwg_status'] ?? 'pending') : null,
                    // annotabile se immagine/pdf, oppure dwg convertito con successo
                    'annotatable'  => in_array($type, ['pdf','png','jpg','jpeg'], true)
                                      || ($isDwg && ($r['dwg_status'] ?? '') === 'ok'),
                ];
            }, $stmt->fetchAll(\PDO::FETCH_ASSOC));

            // floorplan Fieldwire (sola lettura, con deep link)
            $fw = [];
            foreach ($this->fwFloorplanRepo->allForWorksite($worksiteId) as $fp) {
                $fw[] = [
                    'fw_id'        => $fp['fw_id'],
                    'name'         => $fp['name'],
                    'sheets_count' => $fp['sheets_count'],
                    'open_url'     => $fwProjectId
                        ? "https://app.fieldwire.com/projects/{$fwProjectId}/sheets/{$fp['fw_id']}"
                        : null,
                ];
            }

            return [
                'bob'             => $bob,
                'fieldwire'       => $fw,
                'fieldwire_ready' => !empty($fwProjectId) && $this->config->fieldwireEnabled(),
            ];
        });
    }

    /** Push di un disegno BOB su Fieldwire come sheet (flusso S3). */
    public function pushDisegno(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $user       = $request->user();

            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (empty($worksite['fieldwire_project_id'])) {
                throw new \RuntimeException('Cantiere non collegato a Fieldwire');
            }
            if (!$this->config->fieldwireEnabled()) {
                throw new \RuntimeException('Fieldwire non configurato (FIELDWIRE_API_TOKEN)');
            }

            // recupera il disegno + path assoluto
            $stmt = $this->conn->prepare("
                SELECT id, file_name, file_path
                FROM bb_worksite_documents
                WHERE id = :id AND worksite_id = :wid AND is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([':id' => $docId, ':wid' => $worksiteId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$doc) throw new \RuntimeException('Disegno non trovato');

            $absolutePath = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $doc['file_path'];

            $sync   = new FloorplanSync(new FloorplansApi($this->makeClient()));
            $upload = $sync->pushFile($worksite['fieldwire_project_id'], $absolutePath, $doc['file_name']);

            // registra/aggiorna lo stato sync
            $this->conn->prepare("
                INSERT INTO bb_zone_disegno_sync (document_id, worksite_id, fw_sheet_upload_id, pushed_at, pushed_by)
                VALUES (:doc, :wid, :su, NOW(), :uid)
                ON DUPLICATE KEY UPDATE
                    fw_sheet_upload_id = VALUES(fw_sheet_upload_id),
                    pushed_at          = NOW(),
                    pushed_by          = VALUES(pushed_by)
            ")->execute([
                ':doc' => $docId,
                ':wid' => $worksiteId,
                ':su'  => $upload,
                ':uid' => (int)($user?->id ?? 0),
            ]);

            return ['pushed' => true, 'sheet_upload_id' => $upload];
        });
    }

    // ─── Annotazioni disegni (pin / misure / markup) ──────────────────────────

    /** Lista annotazioni + calibrazione di un documento (pagina opzionale). */
    public function annotations(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            $page  = (int) ($_GET['page'] ?? 1);
            return [
                'annotations' => $this->annRepo->allForDocument($docId, $page),
                'calibration' => $this->annRepo->getCalibration($docId, $page),
            ];
        });
    }

    /** Crea o aggiorna un'annotazione. Se type=pin con create_task, crea anche il task. */
    public function saveAnnotation(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $user       = $request->user();
            $body       = $this->jsonBody();

            $type = $body['type'] ?? '';
            if ($type === '') throw new \RuntimeException('Tipo annotazione mancante');
            if (empty($body['geom'])) throw new \RuntimeException('Geometria mancante');

            $taskId = !empty($body['task_id']) ? (int)$body['task_id'] : null;

            // pin che deve creare un task nuovo "qui"
            if ($type === 'pin' && !empty($body['create_task']) && !$taskId) {
                $taskName = trim($body['task_name'] ?? '') ?: ($body['text'] ?? 'Task da disegno');
                $taskId = $this->zoneRepo->create($worksiteId, [
                    'name'          => $taskName,
                    'assignee_name' => $body['assignee_name'] ?? null,
                    'status'        => 'open',
                ], (int)($user?->id ?? 0));
                // push del task su Fieldwire se collegato
                $this->pushTaskToFieldwire($worksiteId, $taskId, []);
            }

            $data = [
                'worksite_id' => $worksiteId,
                'document_id' => $docId,
                'page'        => (int)($body['page'] ?? 1),
                'type'        => $type,
                'geom'        => $body['geom'],
                'task_id'     => $taskId,
                'text'        => $body['text'] ?? null,
                'color'       => $body['color'] ?? '#ef4444',
                'created_by'  => (int)($user?->id ?? 0),
            ];

            if (!empty($body['id'])) {
                $this->annRepo->update((int)$body['id'], $data);
                $id = (int)$body['id'];
            } else {
                $id = $this->annRepo->create($data);
            }
            return $this->annRepo->find($id);
        });
    }

    public function deleteAnnotation(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $annId = (int) $request->param('annId');
            $this->annRepo->delete($annId);
            return ['deleted' => true];
        });
    }

    // ─── DWG render (SVG vettoriale + meta per misure esatte) ─────────────────

    /** Stato + meta del render DWG (svg url, extents, meters_per_unit). */
    public function dwgMeta(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $conv = new \App\Service\Fieldwire\DwgConverter($this->conn);
            $row  = $conv->status($docId);
            if (!$row) return ['status' => 'none'];
            return [
                'status'          => $row['status'],
                'error'           => $row['error'],
                'svg_url'         => $row['status'] === 'ok'
                    ? "/worksites/{$worksiteId}/zone/disegni/{$docId}/dwg-svg" : null,
                'minx'            => (float)$row['minx'],
                'miny'            => (float)$row['miny'],
                'maxx'            => (float)$row['maxx'],
                'maxy'            => (float)$row['maxy'],
                'insunits'        => $row['insunits'] !== null ? (int)$row['insunits'] : null,
                'meters_per_unit' => $row['meters_per_unit'] !== null ? (float)$row['meters_per_unit'] : null,
            ];
        });
    }

    /** Rilancia la conversione DWG→SVG (retry manuale). */
    public function dwgConvert(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            return (new \App\Service\Fieldwire\DwgConverter($this->conn))->convert($docId);
        });
    }

    /** Stream dell'SVG generato dal DWG. */
    public function dwgSvg(Request $request): void
    {
        $docId = (int) $request->param('docId');
        $row = (new \App\Service\Fieldwire\DwgConverter($this->conn))->status($docId);
        if (!$row || $row['status'] !== 'ok' || empty($row['svg_path'])) {
            http_response_code(404);
            exit('SVG non disponibile');
        }
        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $row['svg_path'];
        if (!is_file($abs)) {
            http_response_code(404);
            exit('File SVG non trovato');
        }
        header('Content-Type: image/svg+xml');
        header('Content-Length: ' . filesize($abs));
        header('X-Frame-Options: SAMEORIGIN');
        readfile($abs);
        exit;
    }

    /** Salva la calibrazione scala (metri per frazione-larghezza). */
    public function setCalibration(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            $user  = $request->user();
            $body  = $this->jsonBody();
            $page  = (int)($body['page'] ?? 1);
            $scale = (float)($body['m_per_wfrac'] ?? 0);
            if ($scale <= 0) throw new \RuntimeException('Scala non valida');
            $this->annRepo->setCalibration($docId, $page, $scale, (int)($user?->id ?? 0));
            return ['ok' => true, 'm_per_wfrac' => $scale];
        });
    }

    // ─── Enable / Disable Fieldwire ───────────────────────────────────────────

    public function enable(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $user       = $request->user();
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite) throw new \RuntimeException('Cantiere non trovato');

            $client = $this->makeClient();
            $sync   = new ProjectSync(new ProjectsApi($client), $this->conn);
            $fwId   = $sync->enable($worksite, $user->id);

            // pull Fieldwire → BOB
            $initial = new InitialSyncService(
                new TasksApi($client), new CheckItemsApi($client),
                new BubblesApi($client), new FloorplansApi($client),
                $this->zoneRepo, $this->fwFloorplanRepo
            );
            $pullStats = $initial->run($worksiteId, $fwId);

            // push BOB → Fieldwire (task BOB-only)
            $outbound = new OutboundSyncService(
                new TasksApi($client), new CheckItemsApi($client),
                new BubblesApi($client), $this->zoneRepo
            );
            $pushStats = $outbound->run($worksiteId, $fwId);

            return [
                'fieldwire_project_id' => $fwId,
                'pulled'               => $pullStats,
                'pushed'               => $pushStats,
            ];
        });
    }

    public function disable(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite) throw new \RuntimeException('Cantiere non trovato');
            (new ProjectSync(new ProjectsApi($this->makeClient()), $this->conn))->disable($worksite);
            return ['disabled' => true];
        });
    }

    // ─── Webhook ──────────────────────────────────────────────────────────────

    public function webhook(Request $request): void
    {
        $raw = (string) file_get_contents('php://input');
        try {
            $handler = new WebhookHandler(
                $this->worksiteRepo, $this->zoneRepo, $this->fwFloorplanRepo
            );
            $result = $handler->dispatch($handler->handle($raw));
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'action' => $result]);
        } catch (\Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Push di un task BOB appena creato verso Fieldwire (best-effort). */
    private function pushTaskToFieldwire(int $worksiteId, int $taskId, array $bodyFromForm): void
    {
        $worksite = $this->worksiteRepo->findById($worksiteId);
        if (empty($worksite['fieldwire_project_id']) || !$this->config->fieldwireEnabled()) return;

        try {
            $task = $this->zoneRepo->find($taskId);
            $fw   = (new TasksApi($this->makeClient()))->create(
                $worksite['fieldwire_project_id'],
                $this->taskFieldsForFieldwire($task)
            );
            if (!empty($fw['id'])) {
                $this->zoneRepo->setFwId($taskId, (string)$fw['id']);
            }
        } catch (\Throwable $e) {
            error_log('[FW push task] ' . $e->getMessage());
        }
    }

    /** Mappa un task BOB nel payload accettato da Fieldwire. */
    private function taskFieldsForFieldwire(array $bob): array
    {
        return array_filter([
            'name'          => $bob['name']          ?? '',
            'description'   => $bob['description']   ?? '',
            'status'        => $bob['status']        ?? 'open',
            'category_name' => $bob['category']      ?? null,
            'assignee_name' => $bob['assignee_name'] ?? null,
            'start_date'    => $bob['start_date']    ?? null,
            'due_date'      => $bob['due_date']      ?? null,
            'priority'      => (int)($bob['priority'] ?? 0),
        ], fn($v) => $v !== null);
    }

    /** Inserisce una notifica BOB per l'operaio/utente assegnato a un task. */
    private function notifyAssignee(int $worksiteId, int $assigneeUserId, string $taskName, int $byUserId): void
    {
        if ($assigneeUserId <= 0 || $assigneeUserId === $byUserId) return; // non notificare se stessi
        try {
            $ws = $this->worksiteRepo->findById($worksiteId);
            $wsName = $ws['name'] ?? ('Cantiere #' . $worksiteId);
            $stmt = $this->conn->prepare("
                INSERT INTO bb_notifications (user_id, title, message, link, category, priority, created_by, is_read, created_at)
                VALUES (:uid, :title, :msg, :link, 'bob_zone', 'normal', :cb, 0, NOW())
            ");
            $stmt->execute([
                ':uid'   => $assigneeUserId,
                ':title' => 'Nuovo task assegnato — BOB Zone',
                ':msg'   => 'Ti è stato assegnato "' . mb_substr($taskName, 0, 120) . '" nel cantiere ' . $wsName,
                ':link'  => '/worksites/' . $worksiteId . '/zone',
                ':cb'    => $byUserId ?: null,
            ]);
        } catch (\Throwable $e) {
            error_log('[FW notifyAssignee] ' . $e->getMessage());
        }
    }

    private function makeClient(): FieldwireClient
    {
        if (!$this->config->fieldwireEnabled()) {
            throw new \RuntimeException('FIELDWIRE_API_TOKEN non configurato in .env');
        }
        return new FieldwireClient($this->config->fieldwireToken(), $this->config->fieldwireRegion());
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Wrap controller action in JSON response.
     *
     * Output buffer trick: cattura qualsiasi PHP warning/notice (o output
     * accidentale di altre includes) PRIMA che venga emesso. Senza questo,
     * un warning silenzioso bombarda il JSON e il frontend mostra
     * "Risposta non valida dal server".
     */
    private function jsonResponse(callable $fn): void
    {
        // pulisce eventuali buffer ereditati
        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_start();

        try {
            $data = $fn();
            $stray = ob_get_clean();
            if ($stray !== '') {
                error_log('[FieldwireController] stray output before JSON: ' . substr($stray, 0, 500));
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[FieldwireController] ' . $e::class . ': ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'    => false,
                'error' => $e->getMessage(),
                'type'  => $e::class,
                'where' => basename($e->getFile()) . ':' . $e->getLine(),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
