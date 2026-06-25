<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;

class ProjectsApi
{
    public function __construct(private FieldwireClient $client) {}

    public function all(): array
    {
        return $this->client->get('/projects');
    }

    public function find(string $projectId): array
    {
        return $this->client->get("/projects/{$projectId}");
    }

    public function create(string $name, string $code = ''): array
    {
        return $this->client->post('/projects', [
            'project' => [
                'name'        => $name,
                'description' => $code ? "BOB: {$code}" : '',
            ],
        ]);
    }

    public function update(string $projectId, array $fields): array
    {
        return $this->client->patch("/projects/{$projectId}", [
            'project' => $fields,
        ]);
    }

    public function delete(string $projectId): array
    {
        return $this->client->delete("/projects/{$projectId}");
    }
}
