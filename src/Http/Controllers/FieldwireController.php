<?php
declare(strict_types=1);

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\ProjectsApi;
use App\Fieldwire\Api\TasksApi;
use App\Fieldwire\FieldwireClient;
use App\Fieldwire\Sync\InitialSyncService;
use App\Fieldwire\Sync\ProjectSync;
use App\Fieldwire\Webhook\WebhookHandler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Repository\Fieldwire\FwBubbleRepository;
use App\Repository\Fieldwire\FwCheckItemRepository;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\FwTaskRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;
use App\Repository\Worksites\WorksiteRepository;

final class FieldwireController
{
    private ZoneTaskRepository    $zoneRepo;
    private FwTaskRepository      $fwTaskRepo;
    private FwCheckItemRepository $fwCheckRepo;
    private FwBubbleRepository    $fwBubbleRepo;
    private FwFloorplanRepository $fwFloorplanRepo;

    public function __construct(
        private Config             $config,
        private WorksiteRepository $worksiteRepo,
        private \PDO               $conn
    ) {
        $this->zoneRepo        = new ZoneTaskRepository($conn);
        $this->fwTaskRepo      = new FwTaskRepository($conn);
        $this->fwCheckRepo     = new FwCheckItemRepository($conn);
        $this->fwBubbleRepo    = new FwBubbleRepository($conn);
        $this->fwFloorplanRepo = new FwFloorplanRepository($conn);
    }

    // ── BOB Zone page (all cantieri) ──────────────────────────────────────────

    public function page(Request $request): void
    {
        $worksiteId = (int) ($request->param('id') ?? 0);
        $worksite   = $this->worksiteRepo->findById($worksiteId);

        if (!$worksite) {
            http_response_code(404);
            exit;
        }

        Response::view('worksites/fieldwire.html.twig', $request, [
            'worksite_id'          => $worksiteId,
            'worksite'             => $worksite,
            'fieldwire_project_id' => $worksite['fieldwire_project_id'] ?? null,
            'fieldwire_enabled'    => $this->config->fieldwireEnabled(),
        ]);
    }

    // ── BOB-native tasks (always available) ───────────────────────────────────

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
            $body       = json_decode(file_get_contents('php://input'), true) ?? [];

            if (empty($body['name'])) {
                throw new \RuntimeException('Il nome del task è obbligatorio');
            }

            $taskId = $this->zoneRepo->create($worksiteId, $body, $user->id ?? 0);
            $task   = $this->zoneRepo->find($taskId);

            // If connected to Fieldwire, push there too
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    $fw = (new TasksApi($this->makeClient()))->create(
                        $worksite['fieldwire_project_id'],
                        ['name' => $body['name'], 'description' => $body['description'] ?? '']
                    );
                    if (!empty($fw['id'])) {
                        $this->zoneRepo->setFwId($taskId, $fw['id']);
                        $task['fw_id'] = $fw['id'];
                    }
                } catch (\Throwable) {
                    // Fieldwire push failed — task still saved in BOB
                }
            }

            return $task;
        });
    }

    public function updateTaskStatus(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            $body   = json_decode(file_get_contents('php://input'), true) ?? [];
            $status = $body['status'] ?? 'open';
            $this->zoneRepo->updateStatus($taskId, $status);
            return ['updated' => true];
        });
    }

    public function deleteTask(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            $this->zoneRepo->delete($taskId);
            return ['deleted' => true];
        });
    }

    // ── Comments ──────────────────────────────────────────────────────────────

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
            $body       = json_decode(file_get_contents('php://input'), true) ?? [];
            $text       = trim($body['text'] ?? '');

            if ($text === '') throw new \RuntimeException('Il messaggio non può essere vuoto');

            $authorName = ($user->first_name ?? '') . ' ' . ($user->last_name ?? '');
            $authorName = trim($authorName) ?: ($user->username ?? 'Utente');

            $id = $this->zoneRepo->addComment($taskId, $text, $authorName);

            // Push to Fieldwire if connected
            $task = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    (new BubblesApi($this->makeClient()))->postComment(
                        $worksite['fieldwire_project_id'], $task['fw_id'], $text
                    );
                } catch (\Throwable) {}
            }

            return ['id' => $id, 'text' => $text, 'author_name' => $authorName, 'created_at' => date('Y-m-d H:i:s')];
        });
    }

    // ── Checklist ─────────────────────────────────────────────────────────────

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
            $taskId = (int) $request->param('taskId');
            $body   = json_decode(file_get_contents('php://input'), true) ?? [];
            $name   = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome elemento obbligatorio');
            $id = $this->zoneRepo->addChecklistItem($taskId, $name);
            return ['id' => $id, 'name' => $name, 'completed' => false];
        });
    }

    public function completeChecklistItem(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $itemId = (int) $request->param('itemId');
            $body   = json_decode(file_get_contents('php://input'), true) ?? [];
            $done   = (bool) ($body['completed'] ?? true);
            $this->zoneRepo->completeChecklistItem($itemId, $done);
            return ['completed' => $done];
        });
    }

    // ── Floorplans (Fieldwire only) ────────────────────────────────────────────

    public function floorplans(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            return $this->fwFloorplanRepo->allForWorksite($worksiteId);
        });
    }

    // ── Enable / Disable Fieldwire ────────────────────────────────────────────

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

            $svc = new InitialSyncService(
                new TasksApi($client), new CheckItemsApi($client),
                new BubblesApi($client), new FloorplansApi($client),
                $this->fwTaskRepo, $this->fwCheckRepo,
                $this->fwBubbleRepo, $this->fwFloorplanRepo
            );
            $svc->run($worksiteId, $fwId);

            return ['fieldwire_project_id' => $fwId];
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

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function webhook(Request $request): void
    {
        $raw = (string) file_get_contents('php://input');
        try {
            $handler = new WebhookHandler(
                $this->worksiteRepo, $this->fwTaskRepo,
                $this->fwCheckRepo, $this->fwBubbleRepo, $this->fwFloorplanRepo
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeClient(): FieldwireClient
    {
        if (!$this->config->fieldwireEnabled()) {
            throw new \RuntimeException('FIELDWIRE_API_TOKEN non configurato in .env');
        }
        return new FieldwireClient($this->config->fieldwireToken(), $this->config->fieldwireRegion());
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
