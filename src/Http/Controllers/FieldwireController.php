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
use App\Repository\Worksites\WorksiteRepository;

final class FieldwireController
{
    private FwTaskRepository      $taskRepo;
    private FwCheckItemRepository $checkRepo;
    private FwBubbleRepository    $bubbleRepo;
    private FwFloorplanRepository $floorplanRepo;

    public function __construct(
        private Config             $config,
        private WorksiteRepository $worksiteRepo,
        private \PDO               $conn
    ) {
        $this->taskRepo      = new FwTaskRepository($conn);
        $this->checkRepo     = new FwCheckItemRepository($conn);
        $this->bubbleRepo    = new FwBubbleRepository($conn);
        $this->floorplanRepo = new FwFloorplanRepository($conn);
    }

    // ── BOB Zone page ─────────────────────────────────────────────────────────

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

    // ── Enable / Disable ──────────────────────────────────────────────────────

    public function enable(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $user       = $request->user();
            $worksiteId = (int) ($request->param('id') ?? 0);
            $worksite   = $this->worksiteRepo->findById($worksiteId);

            if (!$worksite) throw new \RuntimeException('Cantiere non trovato');

            $client = $this->makeClient();
            $sync   = new ProjectSync(new ProjectsApi($client), $this->conn);
            $fwId   = $sync->enable($worksite, $user->id);

            // Pull all existing Fieldwire data into local DB
            $this->initialSync($worksiteId, $fwId, $client);

            return ['fieldwire_project_id' => $fwId];
        });
    }

    public function disable(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) ($request->param('id') ?? 0);
            $worksite   = $this->worksiteRepo->findById($worksiteId);

            if (!$worksite) throw new \RuntimeException('Cantiere non trovato');

            $sync = new ProjectSync(new ProjectsApi($this->makeClient()), $this->conn);
            $sync->disable($worksite);

            return ['disabled' => true];
        });
    }

    // ── Tasks — reads from local DB ───────────────────────────────────────────

    public function tasks(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) ($request->param('id') ?? 0);
            return $this->taskRepo->allForWorksite($worksiteId);
        });
    }

    public function createTask(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) ($request->param('id') ?? 0);
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite || empty($worksite['fieldwire_project_id'])) {
                throw new \RuntimeException('Cantiere non collegato a Fieldwire');
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $fw   = (new TasksApi($this->makeClient()))->create(
                $worksite['fieldwire_project_id'],
                ['name' => $body['name'] ?? '', 'description' => $body['description'] ?? '']
            );
            // Save locally immediately
            $this->taskRepo->upsert($worksiteId, $fw);
            return $fw;
        });
    }

    public function updateTask(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) ($request->param('id') ?? 0);
            $taskId     = $request->param('taskId') ?? '';
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite || empty($worksite['fieldwire_project_id'])) {
                throw new \RuntimeException('Cantiere non collegato a Fieldwire');
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $fw   = (new TasksApi($this->makeClient()))->update(
                $worksite['fieldwire_project_id'], $taskId, $body
            );
            $this->taskRepo->upsert($worksiteId, $fw);
            return $fw;
        });
    }

    // ── Bubbles — reads from local DB ─────────────────────────────────────────

    public function bubbles(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $taskId = $request->param('taskId') ?? '';
            return $this->bubbleRepo->allForTask($taskId);
        });
    }

    public function postBubble(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) ($request->param('id') ?? 0);
            $taskId     = $request->param('taskId') ?? '';
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite || empty($worksite['fieldwire_project_id'])) {
                throw new \RuntimeException('Cantiere non collegato a Fieldwire');
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $text = trim($body['text'] ?? '');
            if ($text === '') throw new \RuntimeException('Il messaggio non può essere vuoto');

            $fw = (new BubblesApi($this->makeClient()))->postComment(
                $worksite['fieldwire_project_id'], $taskId, $text
            );
            // Save locally immediately
            $this->bubbleRepo->insert($worksiteId, $taskId, $fw);
            return $fw;
        });
    }

    // ── Check items — reads from local DB ────────────────────────────────────

    public function checkItems(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $taskId = $request->param('taskId') ?? '';
            return $this->checkRepo->allForTask($taskId);
        });
    }

    public function completeCheckItem(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId  = (int) ($request->param('id') ?? 0);
            $taskId      = $request->param('taskId') ?? '';
            $checkItemId = $request->param('checkItemId') ?? '';
            $worksite    = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite || empty($worksite['fieldwire_project_id'])) {
                throw new \RuntimeException('Cantiere non collegato a Fieldwire');
            }
            $fw = (new CheckItemsApi($this->makeClient()))->complete(
                $worksite['fieldwire_project_id'], $taskId, $checkItemId
            );
            // Save locally
            $this->checkRepo->markComplete($checkItemId);
            return $fw;
        });
    }

    // ── Floorplans — reads from local DB ─────────────────────────────────────

    public function floorplans(Request $request): void
    {
        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) ($request->param('id') ?? 0);
            return $this->floorplanRepo->allForWorksite($worksiteId);
        });
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function webhook(Request $request): void
    {
        $raw = (string) file_get_contents('php://input');

        try {
            $handler = new WebhookHandler(
                $this->worksiteRepo,
                $this->taskRepo,
                $this->checkRepo,
                $this->bubbleRepo,
                $this->floorplanRepo
            );
            $event  = $handler->handle($raw);
            $result = $handler->dispatch($event);

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

    private function initialSync(int $worksiteId, string $fwProjectId, FieldwireClient $client): void
    {
        $svc = new InitialSyncService(
            new TasksApi($client),
            new CheckItemsApi($client),
            new BubblesApi($client),
            new FloorplansApi($client),
            $this->taskRepo,
            $this->checkRepo,
            $this->bubbleRepo,
            $this->floorplanRepo
        );
        $svc->run($worksiteId, $fwProjectId);
    }

    private function makeClient(): FieldwireClient
    {
        if (!$this->config->fieldwireEnabled()) {
            throw new \RuntimeException('Fieldwire non configurato: manca FIELDWIRE_API_TOKEN in .env');
        }
        return new FieldwireClient($this->config->fieldwireToken(), $this->config->fieldwireRegion());
    }

    private function jsonResponse(callable $fn): void
    {
        header('Content-Type: application/json');
        try {
            $data = $fn();
            echo json_encode(['ok' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
