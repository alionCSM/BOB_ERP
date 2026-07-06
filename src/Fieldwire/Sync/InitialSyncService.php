<?php
declare(strict_types=1);

namespace App\Fieldwire\Sync;

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\TasksApi;
use App\Fieldwire\FwLookup;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;

/**
 * Pull dei dati Fieldwire dentro le tabelle BOB-native (bb_zone_*).
 * Eseguito una sola volta quando un cantiere viene collegato a Fieldwire.
 *
 * I task v3 arrivano con status_id/team_id/owner_user_id: prima dell'upsert
 * vengono tradotti in stringhe BOB (status/category_name/assignee_name)
 * tramite FwLookup.
 *
 * NB: i floorplans restano su bb_fw_floorplans perche' non hanno una
 * controparte BOB-native (sono solo metadati per linkare ai sheet Fieldwire).
 */
class InitialSyncService
{
    public function __construct(
        private TasksApi              $tasksApi,
        private CheckItemsApi         $checkItemsApi,
        private BubblesApi            $bubblesApi,
        private FloorplansApi         $floorplansApi,
        private ZoneTaskRepository    $zoneRepo,
        private FwFloorplanRepository $floorplanRepo,
        private FwLookup              $lookup
    ) {}

    /**
     * @return array{tasks_imported:int, checks_imported:int, comments_imported:int, floorplans_imported:int}
     */
    public function run(int $worksiteId, string $fwProjectId): array
    {
        $stats = ['tasks_imported' => 0, 'checks_imported' => 0, 'comments_imported' => 0, 'floorplans_imported' => 0];

        // tasks + figli
        $tasks = $this->tasksApi->all($fwProjectId);
        foreach ($tasks as $task) {
            try {
                $bobTaskId = $this->zoneRepo->upsertFromFieldwire(
                    $worksiteId,
                    $this->translateTask($fwProjectId, $task)
                );
                $stats['tasks_imported']++;

                // check items
                foreach ($this->checkItemsApi->allForTask($fwProjectId, $task['id']) as $ci) {
                    $this->zoneRepo->upsertChecklistItemFromFieldwire($bobTaskId, $ci);
                    $stats['checks_imported']++;
                }

                // bubbles → solo i commenti di testo (kind=1); photo/attachment
                // li lasciamo ai webhook successivi per ora
                foreach ($this->bubblesApi->allForTask($fwProjectId, $task['id']) as $b) {
                    if ((int)($b['kind'] ?? 0) !== BubblesApi::KIND_TEXT) continue;
                    $this->zoneRepo->upsertCommentFromFieldwire(
                        $bobTaskId,
                        $this->translateBubble($fwProjectId, $b)
                    );
                    $stats['comments_imported']++;
                }
            } catch (\Throwable $e) {
                // un singolo task rotto non deve bloccare l'intera sync
                error_log("[Fieldwire InitialSync] task {$task['id']} skipped: " . $e->getMessage());
            }
        }

        // floorplans (cache locale, non BOB-native)
        foreach ($this->floorplansApi->all($fwProjectId) as $fp) {
            try {
                $this->floorplanRepo->upsert($worksiteId, $fp);
                $stats['floorplans_imported']++;
            } catch (\Throwable $e) {
                error_log("[Fieldwire InitialSync] floorplan {$fp['id']} skipped: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /** Risolve gli id Fieldwire in stringhe BOB prima dell'upsert. */
    private function translateTask(string $projectId, array $task): array
    {
        try {
            if (!empty($task['status_id']) && !isset($task['status'])) {
                $s = $this->lookup->bobStatusFor($projectId, (string)$task['status_id']);
                if ($s !== null) $task['status'] = $s;
            }
            if (!empty($task['team_id']) && !isset($task['category_name'])) {
                $task['category_name'] = $this->lookup->teamName($projectId, (string)$task['team_id']);
            }
            if (!empty($task['owner_user_id']) && !isset($task['assignee_name'])) {
                $task['assignee_name'] = $this->lookup->userName($projectId, (int)$task['owner_user_id']);
            }
        } catch (\Throwable $e) {
            // lookup fallito (permessi/endpoint): importiamo comunque il resto
            error_log('[Fieldwire InitialSync] lookup failed: ' . $e->getMessage());
        }
        return $task;
    }

    private function translateBubble(string $projectId, array $bubble): array
    {
        try {
            if (!empty($bubble['user_id']) && !isset($bubble['creator_name'])) {
                $bubble['creator_name'] = $this->lookup->userName($projectId, (int)$bubble['user_id']);
            }
        } catch (\Throwable $e) {
            // il nome autore e' cosmetico: non bloccare l'import
        }
        return $bubble;
    }
}
