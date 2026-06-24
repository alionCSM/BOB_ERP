<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;

class TasksApi
{
    public function __construct(private FieldwireClient $client) {}

    public function all(string $projectId, array $filters = []): array
    {
        return $this->client->get("/projects/{$projectId}/tasks", $filters);
    }

    public function find(string $projectId, string $taskId): array
    {
        return $this->client->get("/projects/{$projectId}/tasks/{$taskId}");
    }

    public function create(string $projectId, array $fields): array
    {
        return $this->client->post("/projects/{$projectId}/tasks", [
            'task' => $fields,
        ]);
    }

    public function update(string $projectId, string $taskId, array $fields): array
    {
        return $this->client->patch("/projects/{$projectId}/tasks/{$taskId}", [
            'task' => $fields,
        ]);
    }

    public function delete(string $projectId, string $taskId): array
    {
        return $this->client->delete("/projects/{$projectId}/tasks/{$taskId}");
    }

    public function filterByStatus(string $projectId, string $status): array
    {
        return $this->client->get("/projects/{$projectId}/tasks", [
            'status' => $status,
        ]);
    }
}
