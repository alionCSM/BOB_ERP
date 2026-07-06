<?php
declare(strict_types=1);

namespace App\Fieldwire\Webhook;

use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;
use App\Repository\Worksites\WorksiteRepository;
use RuntimeException;

/**
 * Aggiorna le tabelle BOB-native (bb_zone_*) ricevendo eventi Fieldwire.
 *
 * Formato payload webhook (doc "webhook-event-types"):
 * {
 *   "schema_version": "1.0.0",
 *   "event_category": "project",
 *   "event_id": "...", "event_timestamp": 123, "account_id": 1,
 *   "project_id": "<uuid>", "user_id": 42,
 *   "event": { "entity_type": "task", "action": "created", "attributes": [...] },
 *   "entity_data": { ...entita' modificata... }
 * }
 *
 * Resolution chain per ogni evento:
 *   project_id (Fieldwire) → worksite_id (BOB) via WorksiteRepo
 *   task fw_id              → bb_zone_tasks.id   via ZoneTaskRepo::findByFwId()
 *
 * Per i floorplans usiamo ancora bb_fw_floorplans perche' non hanno
 * controparte BOB-native (sono metadata link).
 */
class WebhookHandler
{
    public function __construct(
        private WorksiteRepository    $worksiteRepo,
        private ZoneTaskRepository    $zoneRepo,
        private FwFloorplanRepository $floorplanRepo
    ) {}

    public function handle(string $rawBody): array
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new RuntimeException('Invalid Fieldwire webhook payload');
        }

        // Normalizza al formato interno "entity_type.action" + data.
        if (!empty($event['event']['entity_type'])) {
            $entity = strtolower((string)$event['event']['entity_type']);
            $action = strtolower((string)($event['event']['action'] ?? ''));
            $event['event_type'] = "{$entity}.{$action}";
            $event['data']       = $event['entity_data'] ?? [];
        }

        if (empty($event['event_type'])) {
            throw new RuntimeException('Invalid Fieldwire webhook payload (missing event)');
        }
        return $event;
    }

    public function dispatch(array $event): string
    {
        $type = $event['event_type'] ?? '';

        return match (true) {
            str_starts_with($type, 'task.')            => $this->onTask($event),
            str_starts_with($type, 'task_check_item.'),
            str_starts_with($type, 'check_item.')      => $this->onCheckItem($event),
            str_starts_with($type, 'bubble.')          => $this->onBubble($event),
            str_starts_with($type, 'floorplan.'),
            str_starts_with($type, 'sheet.')           => $this->onFloorplan($event),
            default                                    => "unhandled:{$type}",
        };
    }

    private function worksiteId(array $event): ?int
    {
        $fwProjectId = $event['project_id'] ?? null;
        if (!$fwProjectId) return null;
        $row = $this->worksiteRepo->findByFieldwireProjectId($fwProjectId);
        return $row ? (int)$row['id'] : null;
    }

    /** Risolve l'id BOB del task dato il fw_id (per check items/bubbles). */
    private function bobTaskId(string $fwTaskId): ?int
    {
        if ($fwTaskId === '') return null;
        $row = $this->zoneRepo->findByFwId($fwTaskId);
        return $row ? (int)$row['id'] : null;
    }

    private static function isDeleted(string $type, array $data): bool
    {
        return str_ends_with($type, '.deleted') || !empty($data['deleted_at']);
    }

    // ─── Tasks ────────────────────────────────────────────────────────────────

    private function onTask(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        $wid  = $this->worksiteId($event);
        if (!$wid || empty($data['id'])) return "task:skip";

        if (self::isDeleted($type, $data)) {
            $this->zoneRepo->deleteByFwId((string)$data['id']);
            return 'task:deleted';
        }
        // NB: status_id/team_id/owner_user_id non vengono risolti qui (niente
        // client API nel webhook): il repo aggiorna solo i campi presenti.
        $this->zoneRepo->upsertFromFieldwire($wid, $data);
        return "task:{$type}";
    }

    // ─── Check items ──────────────────────────────────────────────────────────

    private function onCheckItem(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        if (empty($data['id'])) return "check_item:skip";

        if (self::isDeleted($type, $data)) {
            $this->zoneRepo->deleteChecklistItemByFwId((string)$data['id']);
            return 'check_item:deleted';
        }

        $bobTaskId = $this->bobTaskId((string)($data['task_id'] ?? ''));
        if (!$bobTaskId) return "check_item:no-task";

        $this->zoneRepo->upsertChecklistItemFromFieldwire($bobTaskId, $data);
        return "check_item:{$type}";
    }

    // ─── Bubbles (comments) ───────────────────────────────────────────────────

    private function onBubble(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        if (empty($data['id'])) return "bubble:skip";

        if (self::isDeleted($type, $data)) {
            $this->zoneRepo->deleteCommentByFwId((string)$data['id']);
            return 'bubble:deleted';
        }

        // Solo i commenti di testo (kind=1) vengono importati come comment BOB.
        // Photo/attachment li lasciamo come future enhancement (servono i file
        // URL gestiti).
        if ((int)($data['kind'] ?? 0) !== 1) return "bubble:skip-kind";

        $bobTaskId = $this->bobTaskId((string)($data['task_id'] ?? ''));
        if (!$bobTaskId) return "bubble:no-task";

        $this->zoneRepo->upsertCommentFromFieldwire($bobTaskId, $data);
        return "bubble:{$type}";
    }

    // ─── Floorplans (cache locale) ────────────────────────────────────────────

    private function onFloorplan(array $event): string
    {
        $type = $event['event_type'];
        $data = $event['data'] ?? [];
        $wid  = $this->worksiteId($event);
        if (!$wid || empty($data['id'])) return "floorplan:skip";

        if (self::isDeleted($type, $data)) {
            $this->floorplanRepo->deleteByFwId((string)$data['id']);
            return 'floorplan:deleted';
        }
        $this->floorplanRepo->upsert($wid, $data);
        return "floorplan:{$type}";
    }
}
