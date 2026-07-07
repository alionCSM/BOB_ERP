<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;

class FloorplansApi
{
    public function __construct(private FieldwireClient $client) {}

    public function all(string $projectId): array
    {
        return $this->client->getAll("/projects/{$projectId}/floorplans");
    }

    public function find(string $projectId, string $floorplanId): array
    {
        return $this->client->get("/projects/{$projectId}/floorplans/{$floorplanId}");
    }

    public function sheets(string $projectId, string $floorplanId): array
    {
        return $this->client->get("/projects/{$projectId}/floorplans/{$floorplanId}/sheets");
    }

    public function update(string $projectId, string $floorplanId, array $fields): array
    {
        return $this->client->patch("/projects/{$projectId}/floorplans/{$floorplanId}", [
            'floorplan' => $fields,
        ]);
    }

    public function delete(string $projectId, string $floorplanId): array
    {
        return $this->client->delete("/projects/{$projectId}/floorplans/{$floorplanId}");
    }

    /**
     * Initiate an upload — returns S3 upload params.
     * After uploading to S3, call confirmUpload() with the sheet_upload_id.
     */
    public function createUpload(string $projectId, string $filename, string $mimeType = 'application/pdf'): array
    {
        return $this->client->post("/projects/{$projectId}/sheet_uploads", [
            'sheet_upload' => [
                'filename'  => $filename,
                'file_type' => $mimeType,
            ],
        ]);
    }

    public function confirmUpload(string $projectId, string $sheetUploadId): array
    {
        return $this->client->patch("/projects/{$projectId}/sheet_uploads/{$sheetUploadId}", [
            'sheet_upload' => ['status' => 'uploaded'],
        ]);
    }

    public function exportPdf(string $projectId, string $floorplanId): array
    {
        return $this->client->post("/projects/{$projectId}/floorplans/{$floorplanId}/exports");
    }
}
