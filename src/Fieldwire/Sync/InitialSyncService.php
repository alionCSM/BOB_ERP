<?php
declare(strict_types=1);

namespace App\Fieldwire\Sync;

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\TasksApi;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;

/**
 * Pull dei dati Fieldwire dentro le tabelle BOB-native (bb_zone_*).
 * Eseguito una sola volta quando un cantiere viene collegato a Fieldwire.
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
        private FwFloorplanRepository $floorplanRepo
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
                $bobTaskId = $this->zoneRepo->upsertFromFieldwire($worksiteId, $task);
                $stats['tasks_imported']++;

                // check items
                foreach ($this->checkItemsApi->allForTask($fwProjectId, $task['id']) as $ci) {
                    $this->zoneRepo->upsertChecklistItemFromFieldwire($bobTaskId, $ci);
                    $stats['checks_imported']++;
                }

                // bubbles → solo i comment (kind=comment), photo/attachment li lasciamo
                // ai webhook successivi per ora
                foreach ($this->bubblesApi->allForTask($fwProjectId, $task['id']) as $b) {
                    if (($b['kind'] ?? 'comment') !== 'comment') continue;
                    $this->zoneRepo->upsertCommentFromFieldwire($bobTaskId, $b);
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
}
