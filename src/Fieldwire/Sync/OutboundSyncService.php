<?php
declare(strict_types=1);

namespace App\Fieldwire\Sync;

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\TasksApi;
use App\Repository\Fieldwire\ZoneTaskRepository;

/**
 * Push iniziale BOB → Fieldwire al primo collegamento del cantiere.
 *
 * Quando un cantiere BOB con task gia' esistenti viene collegato a un
 * progetto Fieldwire (vuoto o nuovo), questi task locali vanno spinti
 * su Fieldwire e va salvato il fw_id ritornato.
 *
 * Per ogni task BOB senza fw_id:
 *   1. POST /tasks su Fieldwire
 *   2. Salva fw_id sulla riga bb_zone_tasks
 *   3. Push checklist items (POST /check_items) + setChecklistItemFwId
 *   4. Push comments (POST /bubbles) + setCommentFwId
 *
 * Idempotente: chiamabile piu' volte, salta i task gia' sincronizzati.
 */
class OutboundSyncService
{
    public function __construct(
        private TasksApi           $tasksApi,
        private CheckItemsApi      $checkItemsApi,
        private BubblesApi         $bubblesApi,
        private ZoneTaskRepository $zoneRepo
    ) {}

    /**
     * @return array{tasks_pushed:int, checks_pushed:int, comments_pushed:int, errors:array}
     */
    public function run(int $worksiteId, string $fwProjectId): array
    {
        $stats = ['tasks_pushed' => 0, 'checks_pushed' => 0, 'comments_pushed' => 0, 'errors' => []];

        $unsynced = $this->zoneRepo->findUnsynced($worksiteId);

        foreach ($unsynced as $task) {
            try {
                $bobTaskId = (int)$task['id'];

                $fwTask = $this->tasksApi->create($fwProjectId, [
                    'name'          => $task['name'],
                    'description'   => $task['description'] ?? '',
                    'status'        => $task['status']      ?? 'open',
                    'category_name' => $task['category']    ?? null,
                    'assignee_name' => $task['assignee_name'] ?? null,
                    'start_date'    => $task['start_date']  ?? null,
                    'due_date'      => $task['due_date']    ?? null,
                    'priority'      => (int)($task['priority'] ?? 0),
                ]);

                $fwTaskId = (string)($fwTask['id'] ?? '');
                if ($fwTaskId === '') {
                    $stats['errors'][] = "task #{$bobTaskId}: Fieldwire non ha restituito un id";
                    continue;
                }
                $this->zoneRepo->setFwId($bobTaskId, $fwTaskId);
                $stats['tasks_pushed']++;

                // checklist
                foreach ($this->zoneRepo->checklistForTask($bobTaskId) as $ci) {
                    if (!empty($ci['fw_id'])) continue;
                    try {
                        $fwCi = $this->checkItemsApi->create($fwProjectId, $fwTaskId, $ci['name']);
                        if (!empty($fwCi['id'])) {
                            $this->zoneRepo->setChecklistItemFwId((int)$ci['id'], (string)$fwCi['id']);
                            if (!empty($ci['completed'])) {
                                $this->checkItemsApi->complete($fwProjectId, $fwTaskId, (string)$fwCi['id']);
                            }
                            $stats['checks_pushed']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors'][] = "check {$ci['id']}: " . $e->getMessage();
                    }
                }

                // comments
                foreach ($this->zoneRepo->commentsForTask($bobTaskId) as $c) {
                    if (!empty($c['fw_id'])) continue;
                    try {
                        $fwB = $this->bubblesApi->postComment($fwProjectId, $fwTaskId, (string)($c['text'] ?? ''));
                        if (!empty($fwB['id'])) {
                            $this->zoneRepo->setCommentFwId((int)$c['id'], (string)$fwB['id']);
                            $stats['comments_pushed']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors'][] = "comment {$c['id']}: " . $e->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "task #{$task['id']}: " . $e->getMessage();
            }
        }

        return $stats;
    }
}
