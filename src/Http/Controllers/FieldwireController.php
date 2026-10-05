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
use App\Repository\Fieldwire\ZoneFileRepository;
use App\Repository\Fieldwire\ZoneFormRepository;
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
    private ZoneFileRepository       $fileRepo;
    private ZoneFormRepository       $formRepo;
    private \App\Service\Zone\Accesso $accesso;

    /** La guardia e' passata per questa richiesta? La controlla assertZone. */
    private bool $guardiaPassata = false;

    /**
     * Chi puo' chiamare cosa.
     *
     * Una mappa sola invece del controllo ripetuto in quaranta metodi: web e
     * app passano tutti di qui, e un controllo copiato quaranta volte e' un
     * controllo che in un punto prima o poi manca.
     *
     * Quello che NON e' elencato viene rifiutato. Un metodo nuovo aggiunto
     * senza pensare ai permessi nasce chiuso e se ne accorge subito chi lo
     * scrive; nasce aperto e se ne accorge qualcuno fuori, piu' tardi.
     *
     * 'ufficio' vuol dire che l'assegnazione al cantiere non basta: accendere
     * Zone o creare il modello di un modulo sono cose da chi ha il modulo in
     * BOB, non da chi lavora li'.
     *
     * @var array<string, array{0:string,1:int}|string>
     */
    private const ACCESSI = [
        'page'                  => 'pagina',
        'tasks'                 => ['attivita', 1],
        'createTask'            => ['attivita', 2],
        'updateTask'            => ['attivita', 2],
        'updateTaskStatus'      => ['attivita', 2],
        // cancellare e' dell'ufficio: un'attivita' sparita non lascia traccia
        'deleteTask'            => 'ufficio',
        'comments'              => ['attivita', 1],
        'postComment'           => ['attivita', 2],
        'deleteComment'         => ['attivita', 2],
        'postPhoto'             => ['attivita', 2],
        'checklist'             => ['attivita', 1],
        'addChecklistItem'      => ['attivita', 2],
        'completeChecklistItem' => ['attivita', 2],
        'deleteChecklistItem'   => ['attivita', 2],
        'bobUsers'              => ['attivita', 1],

        'files'                 => ['file', 1],
        'downloadFile'          => ['file', 1],
        'fileComments'          => ['file', 1],
        'uploadFile'            => ['file', 2],
        'createFolder'          => ['file', 2],
        'deleteFolder'          => 'ufficio',
        'deleteFile'            => 'ufficio',
        'postFileComment'       => ['file', 2],

        'formTemplates'         => ['moduli', 1],
        'formTemplate'          => ['moduli', 1],
        'formSubmissions'       => ['moduli', 1],
        'formSubmission'        => ['moduli', 1],
        'formFile'              => ['moduli', 1],
        'submitForm'            => ['moduli', 2],
        // il modello del modulo lo disegna l'ufficio: chi compila in
        // cantiere riempie quello che trova, non se lo riscrive
        'saveFormTemplate'      => 'ufficio',
        'deleteFormTemplate'    => 'ufficio',

        'disegni'               => ['disegni', 1],
        'fileDisegno'           => ['disegni', 1],
        'floorplans'            => ['disegni', 1],
        'annotations'           => ['disegni', 1],
        'dwgMeta'               => ['disegni', 1],
        'dwgSvg'                => ['disegni', 1],
        'saveAnnotation'        => ['disegni', 2],
        'deleteAnnotation'      => ['disegni', 2],
        'dwgConvert'            => ['disegni', 2],
        'pushDisegno'           => 'ufficio',
        'setCalibration'        => 'ufficio',

        'media'                 => ['foto', 1],
        'zonePhoto'             => ['foto', 1],

        'report'                => ['report', 1],

        'enable'                => 'ufficio',
        'disable'               => 'ufficio',

        // Decidere chi entra e' dell'ufficio per definizione: un capo
        // squadra che puo' assegnare se stesso non e' un permesso, e'
        // una formalita'.
        'accessi'               => 'ufficio',
        // chi vede cosa e chi compila cosa lo decide l'ufficio
        'condividiCliente'      => 'ufficio',
        'cambiaVisibilita'      => 'ufficio',
        'assegnazioniModuli'    => ['moduli', 1],
        'salvaAssegnazione'     => 'ufficio',
        'disattivaAssegnazione' => 'ufficio',
        'salvaAccesso'          => 'ufficio',
        'eliminaAccesso'        => 'ufficio',
    ];

    public function __construct(
        private Config             $config,
        private WorksiteRepository $worksiteRepo,
        private \PDO               $conn
    ) {
        $this->zoneRepo        = new ZoneTaskRepository($conn);
        $this->fwFloorplanRepo = new FwFloorplanRepository($conn);
        $this->annRepo         = new ZoneAnnotationRepository($conn);
        $this->fileRepo        = new ZoneFileRepository($conn);
        $this->formRepo        = new ZoneFormRepository($conn);
        $this->accesso         = new \App\Service\Zone\Accesso($conn);
    }

    /**
     * Il controllo, prima di qualunque cosa.
     *
     * Si chiama con __FUNCTION__ dalla prima riga di ogni metodo: cosi' la
     * regola sta nella mappa e qui c'e' un posto solo dove sbagliarla.
     */
    private function guardia(string $metodo, Request $request): void
    {
        $regola = self::ACCESSI[$metodo] ?? null;

        if ($regola === null) {
            Response::json(['success' => false, 'message' => 'Non consentito'], 403);
        }

        $utente = $request->user();

        if ($regola === 'ufficio' || $regola === 'pagina') {
            $daUfficio = $this->accesso->daUfficio($utente);

            if ($regola === 'pagina') {
                // Chi arriva qui sta navigando col browser: senza accesso
                // merita un rimando alla dashboard, non un blocco di JSON
                // in faccia, che sembra un guasto.
                $w = (int)($request->param('id') ?? 0);
                if (!$daUfficio && !$this->accesso->qualcosa($utente, $w)) {
                    Response::redirect('/dashboard?no_permission=1');
                }
                $this->guardiaPassata = true;
                return;
            }

            if (!$daUfficio) {
                Response::json([
                    'success' => false,
                    'message' => 'Serve il permesso Zone di BOB per questa operazione',
                ], 403);
            }
            $this->guardiaPassata = true;
            return;
        }

        [$famiglia, $minimo] = $regola;
        $this->accesso->pretende($utente, (int)($request->param('id') ?? 0), $famiglia, $minimo);

        $this->guardiaPassata = true;
    }

    // ─── Pagina BOB Zone ──────────────────────────────────────────────────────

    public function page(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        // il permesso l'ha gia' guardato la guardia, che per questa pagina
        // rimanda alla dashboard invece di rispondere in JSON
        $worksiteId = (int) ($request->param('id') ?? 0);
        $worksite   = $this->worksiteRepo->findById($worksiteId);
        if (!$worksite) { http_response_code(404); exit; }

        // nome cliente
        $clientName = null;
        if (!empty($worksite['client_id'])) {
            $cs = $this->conn->prepare("SELECT name FROM bb_clients WHERE id = :id");
            $cs->execute([':id' => $worksite['client_id']]);
            $clientName = $cs->fetchColumn() ?: null;
        }

        // conteggi task per stato
        $counts = ['open' => 0, 'in_progress' => 0, 'complete' => 0, 'verified' => 0, 'total' => 0];
        $cstmt = $this->conn->prepare("SELECT status, COUNT(*) n FROM bb_zone_tasks WHERE worksite_id = :w GROUP BY status");
        $cstmt->execute([':w' => $worksiteId]);
        foreach ($cstmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['n'];
            $counts['total'] += (int)$r['n'];
        }

        Response::view('worksites/fieldwire.html.twig', $request, [
            'worksite_id'          => $worksiteId,
            'worksite'             => $worksite,
            'clientName'           => $clientName,
            'taskCounts'           => $counts,
            'fieldwire_project_id' => $worksite['fieldwire_project_id'] ?? null,
            'fieldwire_enabled'    => $this->config->fieldwireEnabled(),
            // le sezioni che questa persona puo' vedere: nascondere una
            // scheda che risponderebbe 403 e' meglio che farcela sbattere
            'accessi'              => $this->accesso->tutti($request->user(), $worksiteId),
            'daUfficio'            => $this->accesso->daUfficio($request->user()),
        ]);
    }

    // ─── Tasks ────────────────────────────────────────────────────────────────

    public function tasks(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $ruolo = $this->ruoloQui($request);
            return array_values(array_filter(
                $this->zoneRepo->allForWorksite($worksiteId),
                fn(array $t) => \App\Service\Zone\Accesso::vedeVisibilita($ruolo, $t['visibilita'] ?? null)
            ));
        });
    }

    public function createTask(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $user       = $request->user();
            $body       = $this->jsonBody();

            if (empty($body['name'])) {
                throw new \RuntimeException('Il nome del task è obbligatorio');
            }
            // creare attivita' e' del capo e dell'ufficio
            $ruolo = $this->accesso->pretendeRuolo($user, $worksiteId, [\App\Service\Zone\Accesso::CAPO]);

            $taskId = $this->zoneRepo->create($worksiteId, $body, (int)($user?->id ?? 0));
            $this->scriviVisibilitaTask($taskId, $ruolo, $body, true);
            $this->pushTaskToFieldwire($worksiteId, $taskId, $body);

            // avvisa a chi tocca: la persona, la squadra, i capi, il cliente
            $this->avvisa(fn($a) => $a->attivita($taskId, (int)($user?->id ?? 0)));

            return $this->zoneRepo->find($taskId);
        });
    }

    public function updateTask(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();

            $existing = $this->taskVisibile($request, $taskId);
            $ruolo = $this->accesso->pretendeRuolo($request->user(), $worksiteId, [\App\Service\Zone\Accesso::CAPO]);

            $merged = array_merge($existing, $body);
            $this->zoneRepo->update($taskId, $merged);
            $this->scriviVisibilitaTask($taskId, $ruolo, $body, false);

            // avvisa se e' cambiato a chi tocca
            $dopo = $this->zoneRepo->find($taskId) ?? [];
            if ((int)($dopo['assignee_user_id'] ?? 0) !== (int)($existing['assignee_user_id'] ?? 0)
                || ($dopo['assegnata_a'] ?? '') !== ($existing['assegnata_a'] ?? '')) {
                $this->avvisa(fn($a) => $a->attivita($taskId, (int)($request->user()?->id ?? 0)));
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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();
            $status     = $body['status'] ?? 'open';

            $this->pretendeStato($request, $this->taskVisibile($request, $taskId), (string)$status);
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
        $this->guardia(__FUNCTION__, $request);

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            $this->taskVisibile($request, $taskId);
            return $this->filtraCommenti(
                $this->zoneRepo->commentsForTask($taskId),
                $this->ruoloQui($request),
                (int)($request->user()->id ?? 0)
            );
        });
    }

    public function postComment(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $user       = $request->user();
            $body       = $this->jsonBody();
            $text       = trim($body['text'] ?? '');

            if ($text === '') throw new \RuntimeException('Il messaggio non può essere vuoto');
            $this->taskVisibile($request, $taskId);

            $authorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                         ?: ($user->username ?? 'Utente');

            $id = $this->zoneRepo->addComment($taskId, $text, $authorName);
            $this->scriviCommento($id, $request, $body);

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
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        // NB: multipart, non JSON. Risponde JSON.
        header('Content-Type: application/json');
        try {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $user       = $request->user();
            $this->taskVisibile($request, $taskId);

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
            $this->scriviCommento($id, $request, $_POST);

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
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $commentId  = (int) $request->param('commentId');

            $comment = $this->zoneRepo->findComment($commentId);
            if (!$comment) throw new \RuntimeException('Commento non trovato');
            $this->taskVisibile($request, (int)$comment['task_id']);
            if ($this->ruoloQui($request) !== \App\Service\Zone\Accesso::UFFICIO
                && (int)($comment['author_user_id'] ?? 0) !== (int)($request->user()->id ?? -1)) {
                \App\Service\Zone\Accesso::nega('Puoi cancellare solo i tuoi messaggi');
            }

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            $this->taskVisibile($request, $taskId);
            return $this->zoneRepo->checklistForTask($taskId);
        });
    }

    public function addChecklistItem(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();
            $name       = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome elemento obbligatorio');
            $this->taskVisibile($request, $taskId);
            $this->accesso->pretendeRuolo($request->user(), $worksiteId, [\App\Service\Zone\Accesso::CAPO]);

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $itemId     = (int) $request->param('itemId');
            $body       = $this->jsonBody();
            $done       = (bool) ($body['completed'] ?? true);
            // spuntare e' di chi ci lavora: il cliente guarda
            $this->taskVisibile($request, $taskId);
            $this->accesso->pretendeRuolo($request->user(), $worksiteId,
                [\App\Service\Zone\Accesso::CAPO, \App\Service\Zone\Accesso::OPERAIO]);
            $this->voceDelTask($itemId, $taskId);

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $itemId     = (int) $request->param('itemId');

            $this->taskVisibile($request, $taskId);
            $this->accesso->pretendeRuolo($request->user(), $worksiteId, [\App\Service\Zone\Accesso::CAPO]);
            $this->voceDelTask($itemId, $taskId);
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

    /** Report PDF (punch list) dei task del cantiere. */
    public function report(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $worksite   = $this->worksiteRepo->findById($worksiteId);
        if (!$worksite) { http_response_code(404); exit('Cantiere non trovato'); }

        try {
            $pdf = (new \App\Service\Fieldwire\ZoneReportService($this->conn))->generate($worksiteId, $worksite);
        } catch (\Throwable $e) {
            error_log('[FW report] ' . $e->getMessage());
            http_response_code(500);
            exit('Errore generazione report: ' . $e->getMessage());
        }

        $fname = 'punchlist_' . ($worksite['worksite_code'] ?? $worksiteId) . '_' . date('Ymd') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ─── Moduli / Form builder ────────────────────────────────────────────────

    public function formTemplates(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            return $this->formRepo->templatesFor((int)$request->param('id'));
        });
    }

    public function formTemplate(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $t = $this->formRepo->find((int)$request->param('tplId'));
            if (!$t) throw new \RuntimeException('Modulo non trovato');
            return $t;
        });
    }

    public function saveFormTemplate(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $user = $request->user();
            $body = $this->jsonBody();
            $name = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome modulo obbligatorio');
            $fields = $body['fields'] ?? [];
            if (!is_array($fields) || !count($fields)) throw new \RuntimeException('Aggiungi almeno un campo');

            // universale (worksite_id null) o di questo cantiere
            $wsId = !empty($body['universal']) ? null : $worksiteId;

            $data = [
                'worksite_id' => $wsId,
                'name'        => mb_substr($name, 0, 200),
                'description' => trim($body['description'] ?? '') ?: null,
                'fields'      => $fields,
                'created_by'  => (int)($user?->id ?? 0),
            ];
            if (!empty($body['id'])) {
                $this->formRepo->update((int)$body['id'], $data);
                return ['id' => (int)$body['id']];
            }
            return ['id' => $this->formRepo->create($data)];
        });
    }

    public function deleteFormTemplate(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $this->formRepo->delete((int)$request->param('tplId'));
            return ['deleted' => true];
        });
    }

    public function submitForm(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $tplId      = (int) $request->param('tplId');
            $user       = $request->user();
            $body       = $this->jsonBody();

            $tpl = $this->formRepo->find($tplId);
            if (!$tpl) throw new \RuntimeException('Modulo non trovato');

            $values = is_array($body['values'] ?? null) ? $body['values'] : [];

            // converti firme/foto (data URI) in file salvati su disco
            foreach ($tpl['fields'] as $f) {
                $fid  = $f['id'] ?? '';
                $type = $f['type'] ?? '';
                if (!in_array($type, ['signature', 'photo'], true)) continue;
                if (empty($values[$fid]) || !is_string($values[$fid])) continue;
                if (str_starts_with($values[$fid], 'data:')) {
                    $values[$fid] = $this->saveFormDataUri($worksiteId, $values[$fid], $type . '_' . $fid);
                }
            }

            $submitterName = trim($body['submitter_name'] ?? '');
            if ($submitterName === '' && $user) {
                $submitterName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? '');
            }

            $assegnazione = $this->assegnazioneDi($worksiteId, (int)($body['assegnazione_id'] ?? 0));

            $id = $this->formRepo->createSubmission([
                'template_id'    => $tplId,
                'worksite_id'    => $worksiteId,
                'template_name'  => $tpl['name'],
                'values'         => $values,
                'submitter_name' => $submitterName ?: null,
                'submitted_by'   => (int)($user?->id ?? 0) ?: null,
                'source'         => 'internal',
            ]);
            // chi la legge: quello deciso nell'assegnazione; senza, l'ufficio
            $this->conn->prepare('UPDATE bb_zone_form_submissions SET visibilita = :v, assegnazione_id = :a WHERE id = :id')
                ->execute([
                    ':v'  => $assegnazione['visibilita'] ?? 'ufficio',
                    ':a'  => $assegnazione ? (int)$assegnazione['id'] : null,
                    ':id' => $id,
                ]);
            return ['id' => $id];
        });
    }

    public function formSubmissions(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $tplId = isset($_GET['template']) && $_GET['template'] !== '' ? (int)$_GET['template'] : null;
            $ruolo = $this->ruoloQui($request);
            $io    = (int)($request->user()->id ?? 0);
            // le proprie si vedono sempre; le altre secondo la visibilita'
            return array_values(array_filter(
                $this->formRepo->submissions($worksiteId, $tplId),
                fn(array $c) => (int)($c['submitted_by'] ?? 0) === $io
                    || \App\Service\Zone\Accesso::vedeVisibilita($ruolo, $c['visibilita'] ?? 'ufficio')
            ));
        });
    }

    public function formSubmission(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $sub = $this->formRepo->findSubmission((int)$request->param('subId'));
            if (!$sub || (int)$sub['worksite_id'] !== (int)$request->param('id')) {
                throw new \RuntimeException('Compilazione non trovata');
            }
            if ((int)($sub['submitted_by'] ?? 0) !== (int)($request->user()->id ?? 0)
                && !\App\Service\Zone\Accesso::vedeVisibilita($this->ruoloQui($request), $sub['visibilita'] ?? 'ufficio')) {
                \App\Service\Zone\Accesso::nega('Non hai accesso a questa compilazione');
            }
            $tpl = $this->formRepo->find((int)$sub['template_id']);
            $sub['fields'] = $tpl['fields'] ?? [];
            return $sub;
        });
    }

    /** Stream firma/foto modulo (?f=). */
    public function formFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $rel = (string)($_GET['f'] ?? '');
        $prefix = 'BOBZone/' . $worksiteId . '/forms/';
        $relNorm = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($relNorm, '..') !== false || strpos($relNorm, $prefix) !== 0) {
            http_response_code(403); exit('Accesso negato');
        }
        $real = realpath(\CloudPath::getRoot() . DIRECTORY_SEPARATOR . $rel);
        $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || strpos($real, $rootReal) !== 0 || !is_file($real)) { http_response_code(404); exit('Non trovato'); }
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mm = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
        header('Content-Type: ' . ($mm[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        readfile($real); exit;
    }

    /** Salva un data URI (firma/foto modulo) su disco, ritorna l'URL servito. */
    private function saveFormDataUri(int $worksiteId, string $dataUri, string $prefix): string
    {
        if (!preg_match('/^data:(image\/[a-z]+);base64,(.+)$/s', $dataUri, $m)) {
            throw new \RuntimeException('Dato immagine non valido');
        }
        $ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$m[1]] ?? 'png';
        $bin = base64_decode($m[2], true);
        if ($bin === false) throw new \RuntimeException('Decodifica immagine fallita');
        if (strlen($bin) > 15 * 1024 * 1024) throw new \RuntimeException('Immagine troppo grande');

        $dir  = \CloudPath::ensureZoneFormsDir($worksiteId);
        $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($dest, $bin);
        return "/worksites/{$worksiteId}/zone/form-file?f=" . rawurlencode(\CloudPath::relativeToRoot($dest));
    }

    // ─── Sezione File (repository documenti con cartelle) ─────────────────────

    public function files(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $folderId   = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;
            $ruolo   = $this->ruoloQui($request);
            $cartelle = $this->cartelleVisibili($worksiteId, $ruolo);
            if ($folderId !== null && !isset($cartelle[$folderId])) {
                \App\Service\Zone\Accesso::nega('Non hai accesso a questa cartella');
            }
            $files = array_values(array_filter(
                $this->fileRepo->files($worksiteId, $folderId),
                fn(array $f) => \App\Service\Zone\Accesso::vedeVisibilita($ruolo, $this->visibilitaFile($f, $cartelle))
            ));
            // arricchisci con url download/preview
            foreach ($files as &$f) {
                $f['download_url'] = "/worksites/{$worksiteId}/zone/files/{$f['id']}/download";
                $f['is_image'] = in_array(strtolower($f['file_type'] ?? ''), ['jpg','jpeg','png','webp','gif'], true);
            }
            return [
                'folders'        => array_values($cartelle),
                'files'          => $files,
                'current_folder' => $folderId,
            ];
        });
    }

    public function createFolder(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $body = $this->jsonBody();
            $name = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome cartella obbligatorio');
            $id = $this->fileRepo->createFolder($worksiteId, mb_substr($name, 0, 255), (int)($request->user()?->id ?? 0));
            $vis = \App\Service\Zone\Accesso::visibilitaPer($this->ruoloQui($request), $body['visibilita'] ?? null);
            $this->conn->prepare('UPDATE bb_zone_folders SET visibilita = :v WHERE id = :id')
                ->execute([':v' => $vis, ':id' => $id]);
            return ['id' => $id, 'name' => $name, 'visibilita' => $vis];
        });
    }

    public function deleteFolder(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $folderId   = (int) $request->param('folderId');
            $this->fileRepo->deleteFolder($worksiteId, $folderId);
            return ['deleted' => true];
        });
    }

    public function uploadFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        header('Content-Type: application/json');
        try {
            $worksiteId = (int) $request->param('id');
            $user       = $request->user();
            $folderId   = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
            // si carica solo dove si vede
            if ($folderId !== null && !isset($this->cartelleVisibili($worksiteId, $this->ruoloQui($request))[$folderId])) {
                \App\Service\Zone\Accesso::nega('Non hai accesso a questa cartella');
            }

            if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Nessun file ricevuto');
            }
            $f = $_FILES['file'];
            if (($f['size'] ?? 0) > 50 * 1024 * 1024) throw new \RuntimeException('File troppo grande (max 50 MB)');

            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            // blocca eseguibili/script
            $blocked = ['php','phtml','exe','sh','bat','cmd','js','jar','com','msi','dll','app','scr'];
            if ($ext === '' || in_array($ext, $blocked, true)) {
                throw new \RuntimeException('Tipo di file non consentito');
            }

            $dir  = \CloudPath::ensureZoneFilesDir($worksiteId);
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
            $stored = $safe . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
            $dest = $dir . DIRECTORY_SEPARATOR . $stored;
            if (!move_uploaded_file($f['tmp_name'], $dest)) throw new \RuntimeException('Salvataggio fallito');

            $id = $this->fileRepo->create([
                'worksite_id' => $worksiteId,
                'folder_id'   => $folderId,
                'file_name'   => mb_substr($f['name'], 0, 255),
                'file_path'   => \CloudPath::relativeToRoot($dest),
                'file_type'   => $ext,
                'size_bytes'  => (int)($f['size'] ?? 0),
                'uploaded_by' => (int)($user?->id ?? 0),
            ]);
            echo json_encode(['ok' => true, 'data' => ['id' => $id]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function deleteFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $fileId     = (int) $request->param('fileId');
            $row = $this->fileRepo->delete($worksiteId, $fileId);
            // rimuovi anche il file fisico
            if ($row && !empty($row['file_path'])) {
                $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $row['file_path'];
                if (is_file($abs)) @unlink($abs);
            }
            return ['deleted' => true];
        });
    }

    public function downloadFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $fileId     = (int) $request->param('fileId');
        $file = $this->fileRepo->find($fileId);
        if (!$file || (int)$file['worksite_id'] !== $worksiteId) { http_response_code(404); exit('File non trovato'); }
        $this->fileVisibile($request, $file);

        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $file['file_path'];
        $real = realpath($abs); $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0 || !is_file($real)) {
            http_response_code(404); exit('File non trovato su disco');
        }
        $ext = strtolower($file['file_type'] ?? '');
        $inlineTypes = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        if (isset($inlineTypes[$ext])) {
            header('Content-Type: ' . $inlineTypes[$ext]);
            header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
        }
        header('Content-Length: ' . filesize($real));
        header('X-Frame-Options: SAMEORIGIN');
        readfile($real);
        exit;
    }

    public function fileComments(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $fileId = (int)$request->param('fileId');
            $this->fileVisibile($request, $this->fileRepo->find($fileId));
            return $this->fileRepo->comments($fileId);
        });
    }

    public function postFileComment(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $fileId = (int) $request->param('fileId');
            $user   = $request->user();
            $body   = $this->jsonBody();
            $text   = trim($body['text'] ?? '');
            if ($text === '') throw new \RuntimeException('Commento vuoto');
            $this->fileVisibile($request, $this->fileRepo->find($fileId));
            $author = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? 'Utente');
            $id = $this->fileRepo->addComment($fileId, $text, $author, (int)($user?->id ?? 0));
            return ['id' => $id, 'text' => $text, 'author_name' => $author, 'created_at' => date('Y-m-d H:i:s')];
        });
    }

    /** Galleria: tutte le foto caricate sui task del cantiere. */
    public function media(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $stmt = $this->conn->prepare("
                SELECT c.id, c.task_id, c.file_url, c.text, c.author_name, c.created_at,
                       c.interna, c.per_cliente, c.author_user_id,
                       t.name AS task_name, t.status AS task_status, t.visibilita
                FROM bb_zone_task_comments c
                JOIN bb_zone_tasks t ON t.id = c.task_id
                WHERE t.worksite_id = :w
                  AND c.file_url IS NOT NULL AND c.file_url <> ''
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([':w' => $worksiteId]);
            $ruolo = $this->ruoloQui($request);
            $foto  = array_filter(
                $stmt->fetchAll(\PDO::FETCH_ASSOC),
                fn(array $f) => \App\Service\Zone\Accesso::vedeVisibilita($ruolo, $f['visibilita'] ?? null)
            );
            return $this->filtraCommenti(array_values($foto), $ruolo, (int)($request->user()->id ?? 0));
        });
    }

    // ─── Lookup utenti BOB (per dropdown assignee) ────────────────────────────

    public function bobUsers(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

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

    // ─── Chi entra nel cantiere ───────────────────────────────────────────────

    /** Gli assegnati di questo cantiere, coi loro livelli. */
    public function accessi(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)$request->param('id');
            $st = $this->conn->prepare('SELECT zone_cliente FROM bb_worksites WHERE id = :w');
            $st->execute([':w' => $w]);
            return [
                'famiglie'     => \App\Service\Zone\Accesso::FAMIGLIE,
                'persone'      => (new \App\Repository\Zone\AccessoRepository($this->conn))
                                      ->perCantiere($w),
                'zone_cliente' => (bool)$st->fetchColumn(),
            ];
        });
    }

    /** Assegna una persona, o cambia i suoi livelli. */
    public function salvaAccesso(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w      = (int)$request->param('id');
            $userId = (int)($_POST['user_id'] ?? 0);

            if (!$userId) {
                throw new \RuntimeException('Serve la persona da assegnare');
            }

            $livelli = [];
            foreach (array_keys(\App\Service\Zone\Accesso::FAMIGLIE) as $f) {
                $livelli[$f] = (int)($_POST[$f] ?? \App\Service\Zone\Accesso::VEDE);
            }

            $c = $this->conn->prepare('SELECT 1 FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u');
            $c->execute([':w' => $w, ':u' => $userId]);
            $nuovo = !$c->fetchColumn();

            (new \App\Repository\Zone\AccessoRepository($this->conn))->salva(
                $w, $userId, $livelli, (int)($request->user()->id ?? 0),
                isset($_POST['ruolo']) ? (string)$_POST['ruolo'] : null
            );
            if ($nuovo) {
                $this->avvisa(fn($a) => $a->accesso($w, $userId, (int)($request->user()->id ?? 0)));
            }

            return ['ok' => true];
        });
    }

    /** Toglie una persona dal cantiere. */
    public function eliminaAccesso(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $tolto = (new \App\Repository\Zone\AccessoRepository($this->conn))->elimina(
                (int)$request->param('id'),
                (int)($_POST['user_id'] ?? 0)
            );
            return ['ok' => $tolto];
        });
    }

    // ─── Floorplans (Fieldwire only) ──────────────────────────────────────────

    public function floorplans(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            $fwProjectId = $worksite['fieldwire_project_id'] ?? null;

            // disegni BOB (sorgente di verita') + stato sync
            $stmt = $this->conn->prepare("
                SELECT d.id, d.file_name, d.file_type, d.note, d.subcategory,
                       d.created_at, d.zone_visibilita,
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
            $ruolo = $this->ruoloQui($request);
            $righe = array_filter(
                $stmt->fetchAll(\PDO::FETCH_ASSOC),
                fn(array $d) => \App\Service\Zone\Accesso::vedeVisibilita($ruolo, $d['zone_visibilita'] ?? null)
            );
            $bob = array_map(function ($r) use ($worksiteId) {
                $type = strtolower($r['file_type'] ?? '');
                $isDwg = in_array($type, ['dwg', 'dxf'], true); // entrambi passano dal render vettoriale
                return [
                    'id'           => (int)$r['id'],
                    'file_name'    => $r['file_name'],
                    'file_type'    => $r['file_type'],
                    'note'         => $r['note'],
                    'zone_visibilita' => $r['zone_visibilita'] ?? 'squadra',
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
            }, array_values($righe));

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
        $this->guardia(__FUNCTION__, $request);

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            $this->documentoVisibile($request, $docId);
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
        $this->guardia(__FUNCTION__, $request);

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
        $this->guardia(__FUNCTION__, $request);

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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $this->documentoVisibile($request, $docId);
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
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            return (new \App\Service\Fieldwire\DwgConverter($this->conn))->convert($docId);
        });
    }

    /** Stream dell'SVG generato dal DWG. */
    public function dwgSvg(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $docId = (int) $request->param('docId');
        $this->documentoVisibile($request, $docId);
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
        $this->guardia(__FUNCTION__, $request);

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
        $this->guardia(__FUNCTION__, $request);

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
                $this->zoneRepo, $this->fwFloorplanRepo,
                new \App\Fieldwire\FwLookup($client)
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
        $this->guardia(__FUNCTION__, $request);

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
    /**
     * Chi puo' usare BOB Zone.
     *
     * Fino a ora il controllo non c'era affatto: bastava il permesso
     * 'worksites', perche' le rotte stanno sotto /worksites e il middleware
     * del sito controlla quello. Adesso Zone ha un permesso suo, e serve un
     * controllo qui dentro perche' l'app arriva da /api/v1, dove quel
     * middleware non passa.
     *
     * L'utente si legge da $GLOBALS: lo riempiono entrambi i middleware, web
     * e API, quindi il metodo funziona identico da tutte e due le parti.
     */
    /**
     * L'ultima rete, sotto a jsonResponse.
     *
     * Prima chiedeva il modulo `zone` e basta, e con quello solo l'ufficio
     * entrava: un capo squadra assegnato al suo cantiere si sarebbe preso
     * 403 qui, prima ancora che qualcuno guardasse i suoi livelli.
     *
     * Ora chiede che la guardia sia passata. E' piu' stretto di prima, non
     * piu' largo: la guardia decide caso per caso — famiglia, livello,
     * cantiere — e un metodo che arrivasse a rispondere senza esserci
     * passato viene fermato qui invece di scivolare fuori.
     */
    private function assertZone(): void
    {
        if ($this->guardiaPassata) {
            return;
        }

        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok'    => false,
            'error' => 'Non hai accesso a questa parte del cantiere',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── Ufficio: condivisione, visibilita', moduli da compilare ──────────────

    /** "Condividi col cliente": senza, un account cliente non vede niente. */
    public function condividiCliente(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $attivo = !empty($this->jsonBody()['attivo']);
            $w = (int)$request->param('id');
            $prima = $this->conn->prepare('SELECT zone_cliente FROM bb_worksites WHERE id = :w');
            $prima->execute([':w' => $w]);
            $eraAcceso = (bool)$prima->fetchColumn();

            $this->conn->prepare('UPDATE bb_worksites SET zone_cliente = :a WHERE id = :w')
                ->execute([':a' => $attivo ? 1 : 0, ':w' => $w]);

            if ($attivo && !$eraAcceso) {
                $cl = $this->conn->prepare("SELECT user_id FROM bb_zone_accessi WHERE worksite_id = :w AND ruolo = 'cliente'");
                $cl->execute([':w' => $w]);
                foreach ($cl->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
                    $this->avvisa(fn($a) => $a->accesso($w, (int)$uid, (int)($request->user()->id ?? 0)));
                }
            }
            return ['zone_cliente' => $attivo];
        });
    }

    /**
     * Cambia chi vede una cosa: cartella, file, disegno, attivita', o un
     * messaggio (nota interna / per il cliente). Un punto solo, perche' e'
     * la stessa decisione su oggetti diversi, e sempre dell'ufficio.
     */
    public function cambiaVisibilita(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $body = $this->jsonBody();
            $tipo = (string)($body['tipo'] ?? '');
            $id   = (int)($body['id'] ?? 0);
            $vis  = $body['visibilita'] ?? null;

            // file: null vuol dire "come la cartella"
            $ammessa = in_array($vis, \App\Service\Zone\Accesso::VISIBILITA, true)
                || ($tipo === 'file' && $vis === null);

            [$tabella, $colonna, $cantiere] = match ($tipo) {
                'cartella' => ['bb_zone_folders', 'visibilita', 'worksite_id'],
                'file'     => ['bb_zone_files', 'visibilita', 'worksite_id'],
                'disegno'  => ['bb_worksite_documents', 'zone_visibilita', 'worksite_id'],
                'attivita' => ['bb_zone_tasks', 'visibilita', 'worksite_id'],
                'interna', 'per_cliente' => ['bb_zone_task_comments', $tipo, null],
                default    => throw new \RuntimeException('Tipo non valido'),
            };

            if ($cantiere === null) {
                // un messaggio: si controlla che sia di un'attivita' di qui
                $st = $this->conn->prepare("
                    SELECT c.id FROM bb_zone_task_comments c
                    JOIN bb_zone_tasks t ON t.id = c.task_id
                    WHERE c.id = :id AND t.worksite_id = :w
                ");
                $st->execute([':id' => $id, ':w' => $w]);
                if (!$st->fetchColumn()) throw new \RuntimeException('Messaggio non trovato');
                $this->conn->prepare("UPDATE bb_zone_task_comments SET `$colonna` = :v WHERE id = :id")
                    ->execute([':v' => !empty($body['valore']) ? 1 : 0, ':id' => $id]);
                return ['ok' => true];
            }

            if (!$ammessa) throw new \RuntimeException('Visibilita non valida');
            $st = $this->conn->prepare("UPDATE `$tabella` SET `$colonna` = :v WHERE id = :id AND `$cantiere` = :w");
            $st->execute([':v' => $vis, ':id' => $id, ':w' => $w]);
            if ($st->rowCount() === 0) {
                // nessuna riga: o non e' di questo cantiere, o era gia' cosi'
                $c = $this->conn->prepare("SELECT 1 FROM `$tabella` WHERE id = :id AND `$cantiere` = :w");
                $c->execute([':id' => $id, ':w' => $w]);
                if (!$c->fetchColumn()) throw new \RuntimeException('Non trovato in questo cantiere');
            }
            return ['visibilita' => $vis];
        });
    }

    /**
     * I moduli da compilare del cantiere. L'ufficio li vede tutti; gli altri
     * solo quelli che toccano a loro (per nome o per ruolo).
     */
    public function assegnazioniModuli(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w     = (int)$request->param('id');
            $ruolo = $this->ruoloQui($request);
            $io    = (int)($request->user()->id ?? 0);

            $st = $this->conn->prepare("
                SELECT a.*, t.name AS modulo,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS persona,
                       (SELECT MAX(s.created_at) FROM bb_zone_form_submissions s
                         WHERE s.assegnazione_id = a.id) AS ultima_compilazione
                FROM   bb_zone_form_assegnazioni a
                JOIN   bb_zone_form_templates t ON t.id = a.template_id
                LEFT JOIN bb_users u ON u.id = a.a_user_id
                WHERE  a.worksite_id = :w AND a.attiva = 1
                ORDER BY t.name
            ");
            $st->execute([':w' => $w]);
            $tutte = $st->fetchAll(\PDO::FETCH_ASSOC);

            if ($ruolo === \App\Service\Zone\Accesso::UFFICIO) {
                return $tutte;
            }
            return array_values(array_filter($tutte, fn(array $a) =>
                (int)($a['a_user_id'] ?? 0) === $io || ($a['a_ruolo'] ?? null) === $ruolo));
        });
    }

    /** Assegna un modulo: a una persona o a un ruolo, una volta o a cadenza. */
    public function salvaAssegnazione(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $body = $this->jsonBody();

            $tpl = $this->formRepo->find((int)($body['template_id'] ?? 0));
            if (!$tpl || ($tpl['worksite_id'] !== null && (int)$tpl['worksite_id'] !== $w)) {
                throw new \RuntimeException('Modulo non trovato');
            }

            $aUser  = (int)($body['a_user_id'] ?? 0) ?: null;
            $aRuolo = in_array($body['a_ruolo'] ?? null, ['capo', 'operaio', 'cliente', 'ufficio'], true)
                ? $body['a_ruolo'] : null;
            if (!$aUser && !$aRuolo) throw new \RuntimeException('Scegli a chi tocca compilarlo');

            $frequenza = in_array($body['frequenza'] ?? '', ['una_volta', 'giornaliera', 'settimanale'], true)
                ? $body['frequenza'] : 'una_volta';
            $scadenza = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($body['scadenza'] ?? ''))
                ? $body['scadenza'] : null;
            $vis = in_array($body['visibilita'] ?? '', \App\Service\Zone\Accesso::VISIBILITA, true)
                ? $body['visibilita'] : 'ufficio';

            $this->conn->prepare("
                INSERT INTO bb_zone_form_assegnazioni
                    (worksite_id, template_id, a_ruolo, a_user_id, frequenza, scadenza, visibilita, created_by)
                VALUES (:w, :t, :r, :u, :f, :s, :v, :by)
            ")->execute([
                ':w' => $w, ':t' => (int)$tpl['id'], ':r' => $aUser ? null : $aRuolo, ':u' => $aUser,
                ':f' => $frequenza, ':s' => $scadenza, ':v' => $vis,
                ':by' => (int)($request->user()->id ?? 0) ?: null,
            ]);
            $id = (int)$this->conn->lastInsertId();
            $this->avvisa(fn($a) => $a->modulo($id, (int)($request->user()->id ?? 0)));
            return ['id' => $id];
        });
    }

    /** Toglie un "da compilare". Le compilazioni gia' fatte restano. */
    public function disattivaAssegnazione(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $this->conn->prepare('UPDATE bb_zone_form_assegnazioni SET attiva = 0 WHERE id = :a AND worksite_id = :w')
                ->execute([':a' => (int)$request->param('aId'), ':w' => (int)$request->param('id')]);
            return ['ok' => true];
        });
    }

    /**
     * Manda un avviso della Zone senza poter rompere quello che si e'
     * appena salvato: se il push fallisce, il dato resta.
     *
     * @param callable(\App\Service\Zone\AvvisiZona): void $cosa
     */
    private function avvisa(callable $cosa): void
    {
        try {
            $cosa(new \App\Service\Zone\AvvisiZona(
                $this->conn,
                new \App\Service\Notifications\NotificationService($this->conn, $this->config)
            ));
        } catch (\Throwable $e) {
            error_log('[Zone avviso] ' . $e->getMessage());
        }
    }

    /** L'assegnazione, se e' di questo cantiere e tocca a chi chiede. */
    private function assegnazioneDi(int $worksiteId, int $assegnazioneId): ?array
    {
        if ($assegnazioneId <= 0) {
            return null;
        }
        $st = $this->conn->prepare('SELECT * FROM bb_zone_form_assegnazioni WHERE id = :a AND worksite_id = :w AND attiva = 1');
        $st->execute([':a' => $assegnazioneId, ':w' => $worksiteId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ─── Chi vede cosa ────────────────────────────────────────────────────────
    //
    // Le regole stanno in App\Service\Zone\Accesso; qui si applicano agli
    // oggetti della Zone. Ogni oggetto chiesto per id si controlla che sia
    // DI QUESTO CANTIERE e VISIBILE a chi chiede: senza il primo controllo,
    // chi entra in un cantiere potrebbe toccare le attivita' di un altro
    // cambiando un numero nell'indirizzo.

    /** Il ruolo di chi chiede su questo cantiere (la guardia e' gia' passata). */
    private function ruoloQui(Request $request): string
    {
        return $this->accesso->ruolo($request->user(), (int)$request->param('id'))
            ?? \App\Service\Zone\Accesso::nega('Non hai accesso a questo cantiere');
    }

    /** L'attivita', se e' di questo cantiere e chi chiede la vede. */
    private function taskVisibile(Request $request, int $taskId): array
    {
        $task = $this->zoneRepo->find($taskId);
        if (!$task || (int)$task['worksite_id'] !== (int)$request->param('id')
            || !\App\Service\Zone\Accesso::vedeVisibilita($this->ruoloQui($request), $task['visibilita'] ?? null)) {
            Response::json(['ok' => false, 'error' => 'Attivita non trovata'], 404);
        }
        return $task;
    }

    /** La voce di checklist deve essere di quell'attivita'. */
    private function voceDelTask(int $itemId, int $taskId): void
    {
        $voce = $this->zoneRepo->findChecklistItem($itemId);
        if (!$voce || (int)$voce['task_id'] !== $taskId) {
            Response::json(['ok' => false, 'error' => 'Elemento non trovato'], 404);
        }
    }

    /**
     * Chi puo' spostare un'attivita' e dove.
     *
     * - "Verificato" lo mette solo l'ufficio: e' il collaudo, non il "fatto".
     * - Il cliente guarda e commenta, non sposta.
     * - L'operaio sposta solo le attivita' sue o della squadra.
     */
    private function pretendeStato(Request $request, array $task, string $stato): void
    {
        $ruolo = $this->ruoloQui($request);
        if ($ruolo === \App\Service\Zone\Accesso::UFFICIO) {
            return;
        }
        if ($stato === 'verified') {
            \App\Service\Zone\Accesso::nega("Solo l'ufficio puo' verificare");
        }
        if ($ruolo === \App\Service\Zone\Accesso::CLIENTE) {
            \App\Service\Zone\Accesso::nega('Puoi guardare ma non modificare');
        }
        if ($ruolo === \App\Service\Zone\Accesso::OPERAIO) {
            $mia = ($task['assegnata_a'] ?? 'squadra') === 'squadra'
                || (int)($task['assignee_user_id'] ?? 0) === (int)($request->user()->id ?? -1);
            if (!$mia) {
                \App\Service\Zone\Accesso::nega('Questa attivita non e assegnata a te');
            }
        }
    }

    /**
     * Visibilita' e assegnazione di un'attivita', nei limiti del ruolo: il
     * capo non mette una cosa "solo ufficio" ne' la mostra al cliente.
     */
    private function scriviVisibilitaTask(int $taskId, string $ruolo, array $body, bool $nuova): void
    {
        $campi = [];
        if ($nuova || array_key_exists('visibilita', $body)) {
            $campi['visibilita'] = \App\Service\Zone\Accesso::visibilitaPer($ruolo, $body['visibilita'] ?? null);
        }
        if ($nuova || array_key_exists('assegnata_a', $body)) {
            $a = (string)($body['assegnata_a'] ?? '');
            if (!in_array($a, ['persona', 'squadra', 'capi', 'ufficio', 'cliente'], true)) {
                $a = !empty($body['assignee_user_id']) ? 'persona' : 'squadra';
            }
            // assegnare all'ufficio o al cliente e' una decisione dell'ufficio
            if ($ruolo !== \App\Service\Zone\Accesso::UFFICIO && in_array($a, ['ufficio', 'cliente'], true)) {
                $a = 'squadra';
            }
            $campi['assegnata_a'] = $a;
        }
        if (!$campi) {
            return;
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($campi)));
        $par = [':id' => $taskId];
        foreach ($campi as $k => $v) {
            $par[":$k"] = $v;
        }
        $this->conn->prepare("UPDATE bb_zone_tasks SET $set WHERE id = :id")->execute($par);
    }

    /**
     * Chi ha scritto, e chi lo legge.
     *
     * - "nota interna" la scrive solo l'ufficio: la leggono solo loro.
     * - "per il cliente" lo decide l'ufficio; quello che scrive un cliente
     *   e' per forza tra lui e l'ufficio.
     */
    private function scriviCommento(int $commentId, Request $request, array $body): void
    {
        $ruolo   = $this->ruoloQui($request);
        $ufficio = $ruolo === \App\Service\Zone\Accesso::UFFICIO;
        $cliente = $ruolo === \App\Service\Zone\Accesso::CLIENTE;

        $this->conn->prepare("
            UPDATE bb_zone_task_comments
            SET author_user_id = :u, interna = :i, per_cliente = :c
            WHERE id = :id
        ")->execute([
            ':u'  => (int)($request->user()->id ?? 0) ?: null,
            ':i'  => $ufficio && !empty($body['interna']) ? 1 : 0,
            ':c'  => $cliente || ($ufficio && !empty($body['per_cliente'])) ? 1 : 0,
            ':id' => $commentId,
        ]);
    }

    /**
     * I messaggi (e le foto) che chi chiede puo' leggere.
     *
     * - ufficio: tutto
     * - capi e operai: niente note interne, niente messaggi dei clienti
     * - cliente: quello segnato "per il cliente" e i messaggi dei clienti
     *
     * I messaggi vecchi, scritti prima che si sapesse chi li ha scritti,
     * valgono come scritti dalla squadra.
     */
    private function filtraCommenti(array $commenti, string $ruolo, int $io): array
    {
        if ($ruolo === \App\Service\Zone\Accesso::UFFICIO) {
            return $commenti;
        }
        $autori = array_values(array_unique(array_filter(array_map(
            fn($c) => (int)($c['author_user_id'] ?? 0), $commenti
        ))));
        $clienti = [];
        if ($autori) {
            $in = implode(',', array_fill(0, count($autori), '?'));
            $st = $this->conn->prepare("SELECT id FROM bb_users WHERE type = 'client' AND id IN ($in)");
            $st->execute($autori);
            $clienti = array_flip(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
        }

        return array_values(array_filter($commenti, function (array $c) use ($ruolo, $clienti, $io) {
            $autore    = (int)($c['author_user_id'] ?? 0);
            $diCliente = isset($clienti[$autore]);
            if ($ruolo === \App\Service\Zone\Accesso::CLIENTE) {
                return !empty($c['per_cliente']) || $diCliente || $autore === $io;
            }
            return empty($c['interna']) && !$diCliente;
        }));
    }

    /**
     * Le cartelle che chi chiede vede, per id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartelleVisibili(int $worksiteId, string $ruolo): array
    {
        $fuori = [];
        foreach ($this->fileRepo->folders($worksiteId) as $c) {
            if (\App\Service\Zone\Accesso::vedeVisibilita($ruolo, $c['visibilita'] ?? null)) {
                $fuori[(int)$c['id']] = $c;
            }
        }
        return $fuori;
    }

    /**
     * La visibilita' vera di un file: la sua, se l'ha; se no quella della
     * cartella; nella radice "squadra". Una cartella che chi chiede non vede
     * nasconde anche i suoi file: per questo $cartelle sono quelle VISIBILI,
     * e un file in una cartella che non c'e' li' resta all'ufficio.
     */
    private function visibilitaFile(array $file, array $cartelleVisibili): string
    {
        if (!empty($file['visibilita'])) {
            return (string)$file['visibilita'];
        }
        $cartella = $file['folder_id'] ?? null;
        if ($cartella === null || $cartella === '') {
            return 'squadra';
        }
        return (string)($cartelleVisibili[(int)$cartella]['visibilita'] ?? 'ufficio');
    }

    private function fileVisibile(Request $request, ?array $file): void
    {
        $w = (int)$request->param('id');
        $ruolo = $this->ruoloQui($request);
        if (!$file || (int)$file['worksite_id'] !== $w
            || !\App\Service\Zone\Accesso::vedeVisibilita($ruolo, $this->visibilitaFile($file, $this->cartelleVisibili($w, $ruolo)))) {
            Response::json(['ok' => false, 'error' => 'File non trovato'], 404);
        }
    }

    /**
     * Il file di un disegno, per l'app: la pagina web lo prende da
     * /worksites/{id}/disegni/{doc}/view, che va con la sessione del browser
     * e non col token. Stesse regole della Zone: lo apre chi lo vede.
     */
    public function fileDisegno(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);
        $docId = (int)$request->param('docId');
        $this->documentoVisibile($request, $docId);

        $st = $this->conn->prepare('SELECT file_path, file_name FROM bb_worksite_documents WHERE id = :id AND is_deleted = 0');
        $st->execute([':id' => $docId]);
        $d = $st->fetch(\PDO::FETCH_ASSOC);

        $root = realpath(\CloudPath::getRoot());
        $real = $d ? realpath(\CloudPath::getRoot() . DIRECTORY_SEPARATOR . $d['file_path']) : false;
        if (!$d || $real === false || $root === false || strpos($real, $root) !== 0 || !is_file($real)) {
            http_response_code(404);
            exit('Disegno non trovato');
        }

        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext]
            ?? 'application/octet-stream';
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename((string)$d['file_name']) . '"');
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, no-store');
        readfile($real);
        exit;
    }

    /** Un disegno: di questo cantiere, e visibile a chi chiede. */
    private function documentoVisibile(Request $request, int $docId): void
    {
        $st = $this->conn->prepare('SELECT worksite_id, zone_visibilita FROM bb_worksite_documents WHERE id = :id');
        $st->execute([':id' => $docId]);
        $d = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$d || (int)$d['worksite_id'] !== (int)$request->param('id')
            || !\App\Service\Zone\Accesso::vedeVisibilita($this->ruoloQui($request), $d['zone_visibilita'] ?? null)) {
            Response::json(['ok' => false, 'error' => 'Disegno non trovato'], 404);
        }
    }

    private function jsonResponse(callable $fn): void
    {
        // Il controllo sta qui e non ripetuto in ogni metodo: gli endpoint
        // che passano di qua sono trentanove, e ne basta uno dimenticato per
        // lasciare la porta aperta. Chi ne aggiunge un altro domani se lo
        // ritrova gia' protetto senza doverci pensare.
        $this->assertZone();

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
