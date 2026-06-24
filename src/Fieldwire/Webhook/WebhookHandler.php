<?php
declare(strict_types=1);

namespace App\Fieldwire\Webhook;

use App\Repository\Fieldwire\FwBubbleRepository;
use App\Repository\Fieldwire\FwCheckItemRepository;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\FwTaskRepository;
use App\Repository\Worksites\WorksiteRepository;
use RuntimeException;

class WebhookHandler
{
    public function __construct(
        private WorksiteRepository    $worksiteRepo,
        private FwTaskRepository      $taskRepo,
        private FwCheckItemRepository $checkRepo,
        private FwBubbleRepository    $bubbleRepo,
        private FwFloorplanRepository $floorplanRepo
    ) {}

    public function handle(string $rawBody): array
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['event_type'])) {
            throw new RuntimeException('Invalid Fieldwire webhook payload');
        }
        return $event;
    }

    public function dispatch(array $event): string
    {
        $type = $event['event_type'] ?? '';

        return match (true) {
            str_starts_with($type, 'task.')       => $this->onTask($event),
            str_starts_with($type, 'check_item.') => $this->onCheckItem($event),
            str_starts_with($type, 'bubble.')     => $this->onBubble($event),
            str_starts_with($type, 'floorplan.')  => $this->onFloorplan($event),
            default                               => "unhandled:{$type}",
        };
    }

    // ── Resolves worksite_id from the Fieldwire project_id in the payload ──────

    private function worksiteId(array $event): ?int
    {
        $fwProjectId = $event['project_id'] ?? null;
        if (!$fwProjectId) return null;

        $stmt = $this->worksiteRepo->findByFieldwireProjectId($fwProjectId);
        return $stmt ? (int) $stmt['id'] : null;
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private function onTask(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        $wid  = $this->worksiteId($event);

        if (!$wid || empty($data['id'])) return "task:skip";

        if ($type === 'task.deleted') {
            $this->taskRepo->deleteByFwId($data['id']);
            return 'task:deleted';
        }

        $this->taskRepo->upsert($wid, $data);
        return "task:{$type}";
    }

    private function onCheckItem(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        $wid  = $this->worksiteId($event);

        if (!$wid || empty($data['id'])) return "check_item:skip";

        if ($type === 'check_item.deleted') {
            $this->checkRepo->deleteByFwId($data['id']);
            return 'check_item:deleted';
        }

        $fwTaskId = $data['task_id'] ?? '';
        $this->checkRepo->upsert($wid, $fwTaskId, $data);
        return "check_item:{$type}";
    }

    private function onBubble(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        $wid  = $this->worksiteId($event);

        if (!$wid || empty($data['id'])) return "bubble:skip";

        if ($type === 'bubble.deleted') {
            $this->bubbleRepo->deleteByFwId($data['id']);
            return 'bubble:deleted';
        }

        $fwTaskId = $data['task_id'] ?? '';
        $this->bubbleRepo->upsert($wid, $fwTaskId, $data);
        return "bubble:{$type}";
    }

    private function onFloorplan(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        $wid  = $this->worksiteId($event);

        if (!$wid || empty($data['id'])) return "floorplan:skip";

        if ($type === 'floorplan.deleted') {
            $this->floorplanRepo->deleteByFwId($data['id']);
            return 'floorplan:deleted';
        }

        $this->floorplanRepo->upsert($wid, $data);
        return "floorplan:{$type}";
    }
}
