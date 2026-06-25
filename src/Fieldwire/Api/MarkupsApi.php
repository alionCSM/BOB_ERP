<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;

class MarkupsApi
{
    // Supported markup kinds in Fieldwire
    public const KINDS = ['arrow', 'cloud', 'drawing', 'ellipse', 'highlighter', 'measurement', 'polygon', 'rectangle', 'text'];

    public function __construct(private FieldwireClient $client) {}

    public function allForSheet(string $projectId, string $sheetId): array
    {
        return $this->client->get("/projects/{$projectId}/sheets/{$sheetId}/markups");
    }

    public function allForProject(string $projectId): array
    {
        return $this->client->get("/projects/{$projectId}/markups");
    }

    public function find(string $projectId, string $sheetId, string $markupId): array
    {
        return $this->client->get("/projects/{$projectId}/sheets/{$sheetId}/markups/{$markupId}");
    }

    /**
     * Create a markup annotation on a sheet.
     * $geometry: GeoJSON geometry array (type + coordinates)
     * $kind: one of self::KINDS
     */
    public function create(string $projectId, string $sheetId, string $kind, array $geometry, array $extra = []): array
    {
        return $this->client->post("/projects/{$projectId}/sheets/{$sheetId}/markups", [
            'markup' => array_merge([
                'kind'     => $kind,
                'geometry' => $geometry,
            ], $extra),
        ]);
    }

    public function update(string $projectId, string $sheetId, string $markupId, array $fields): array
    {
        return $this->client->patch("/projects/{$projectId}/sheets/{$sheetId}/markups/{$markupId}", [
            'markup' => $fields,
        ]);
    }

    public function delete(string $projectId, string $sheetId, string $markupId): array
    {
        return $this->client->delete("/projects/{$projectId}/sheets/{$sheetId}/markups/{$markupId}");
    }
}
