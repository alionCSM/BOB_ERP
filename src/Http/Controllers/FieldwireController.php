<?php
declare(strict_types=1);

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\ProjectsApi;
use App\Fieldwire\Api\TasksApi;
use App\Fieldwire\FieldwireClient;
use App\Fieldwire\Sync\InitialSyncService;
use App\Fieldwire\Sync\OutboundSyncService;
use App\Fieldwire\Sync\ProjectSync;
use App\Fieldwire\Webhook\WebhookHandler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Repository\Fieldwire\FwFloorplanRepository;
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
    private ZoneTaskRepository    $zoneRepo;
    private FwFloorplanRepository $fwFloorplanRepo;

    public function __construct(
        private Config             $config,
        private WorksiteRepository $worksiteRepo,
        private \PDO               $conn
    ) {
        $this->zoneRepo        = new ZoneTaskRepository($conn);
        $this->fwFloorplanRepo = new FwFloorplanRepository($conn);
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

            $taskId = $this->zoneRepo->create($worksiteId, $body, (int)($user->id ?? 0));
            $this->pushTaskToFieldwire($worksiteId, $taskId, $body);

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

    private function jsonResponse(callable $fn): void
    {
        header('Content-Type: application/json');
        try {
            echo json_encode(['ok' => true, 'data' => $fn()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
