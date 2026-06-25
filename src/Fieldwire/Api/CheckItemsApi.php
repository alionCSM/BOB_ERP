<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;

class CheckItemsApi
{
    public function __construct(private FieldwireClient $client) {}

    public function allForTask(string $projectId, string $taskId): array
    {
        return $this->client->get("/projects/{$projectId}/tasks/{$taskId}/check_items");
    }

    public function allForProject(string $projectId): array
    {
        return $this->client->get("/projects/{$projectId}/check_items");
    }

    public function create(string $projectId, string $taskId, string $name): array
    {
        return $this->client->post("/projects/{$projectId}/tasks/{$taskId}/check_items", [
            'check_item' => ['name' => $name],
        ]);
    }

    public function update(string $projectId, string $taskId, string $checkItemId, array $fields): array
    {
        return $this->client->patch(
            "/projects/{$projectId}/tasks/{$taskId}/check_items/{$checkItemId}",
            ['check_item' => $fields]
        );
    }

    public function complete(string $projectId, string $taskId, string $checkItemId): array
    {
        return $this->update($projectId, $taskId, $checkItemId, ['completed' => true]);
    }

    public function delete(string $projectId, string $taskId, string $checkItemId): array
    {
        return $this->client->delete(
            "/projects/{$projectId}/tasks/{$taskId}/check_items/{$checkItemId}"
        );
    }
}
