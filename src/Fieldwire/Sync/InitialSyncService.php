<?php
declare(strict_types=1);

namespace App\Fieldwire\Sync;

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\TasksApi;
use App\Repository\Fieldwire\FwBubbleRepository;
use App\Repository\Fieldwire\FwCheckItemRepository;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\FwTaskRepository;

/**
 * Pulls all existing Fieldwire data into BOB's local tables.
 * Called once when a worksite is first connected to Fieldwire.
 */
class InitialSyncService
{
    public function __construct(
        private TasksApi              $tasksApi,
        private CheckItemsApi         $checkItemsApi,
        private BubblesApi            $bubblesApi,
        private FloorplansApi         $floorplansApi,
        private FwTaskRepository      $taskRepo,
        private FwCheckItemRepository $checkRepo,
        private FwBubbleRepository    $bubbleRepo,
        private FwFloorplanRepository $floorplanRepo
    ) {}

    public function run(int $worksiteId, string $fwProjectId): void
    {
        $this->syncTasks($worksiteId, $fwProjectId);
        $this->syncFloorplans($worksiteId, $fwProjectId);
    }

    private function syncTasks(int $worksiteId, string $fwProjectId): void
    {
        $tasks = $this->tasksApi->all($fwProjectId);

        foreach ($tasks as $task) {
            $this->taskRepo->upsert($worksiteId, $task);

            $checkItems = $this->checkItemsApi->allForTask($fwProjectId, $task['id']);
            foreach ($checkItems as $ci) {
                $this->checkRepo->upsert($worksiteId, $task['id'], $ci);
            }

            $bubbles = $this->bubblesApi->allForTask($fwProjectId, $task['id']);
            foreach ($bubbles as $b) {
                $this->bubbleRepo->upsert($worksiteId, $task['id'], $b);
            }
        }
    }

    private function syncFloorplans(int $worksiteId, string $fwProjectId): void
    {
        $floorplans = $this->floorplansApi->all($fwProjectId);
        foreach ($floorplans as $fp) {
            $this->floorplanRepo->upsert($worksiteId, $fp);
        }
    }
}
