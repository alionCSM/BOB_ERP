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
        // NB: a differenza delle altre risorse v3, il create dei progetti E'
        // wrappato in "project". Lo schema non ha un campo description:
        // il codice cantiere BOB viene incluso nel nome.
        $now = \App\Fieldwire\FieldwireClient::nowIso();
        return $this->client->post('/projects', [
            'project' => [
                'name'              => $code ? "{$code} - {$name}" : $name,
                'measurement_units' => 'metric',
                'currency'          => 'EUR',
                'device_created_at' => $now,
                'device_updated_at' => $now,
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
