<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;
use App\Fieldwire\FwLookup;

/**
 * Task check items API v3.
 *
 * NB: la risorsa si chiama "task_check_items" (non "check_items").
 * Create:  POST  /projects/{p}/tasks/{t}/task_check_items   (body flat)
 * Update:  PATCH /projects/{p}/task_check_items/{id}         (NON annidato nel task)
 * Lo stato di completamento e' "state": empty|yes|no|not_applicable
 * (il boolean "checked" e' deprecato).
 */
class CheckItemsApi
{
    private FwLookup $lookup;

    public function __construct(private FieldwireClient $client)
    {
        $this->lookup = new FwLookup($client);
    }

    public function allForTask(string $projectId, string $taskId): array
    {
        return $this->client->getAll("/projects/{$projectId}/tasks/{$taskId}/task_check_items");
    }

    public function allForProject(string $projectId): array
    {
        return $this->client->getAll("/projects/{$projectId}/task_check_items");
    }

    public function create(string $projectId, string $taskId, string $name): array
    {
        $uid = $this->lookup->defaultUserId($projectId);
        $now = FieldwireClient::nowIso();

        return $this->client->post("/projects/{$projectId}/tasks/{$taskId}/task_check_items", [
            'id'                  => FieldwireClient::uuid(),
            'name'                => $name,
            'creator_user_id'     => $uid,
            'last_editor_user_id' => $uid,
            'state'               => 'empty',
            'device_created_at'   => $now,
            'device_updated_at'   => $now,
        ]);
    }

    /**
     * $fields formato BOB: ['name' => ..., 'completed' => bool].
     * $taskId mantenuto in firma per compatibilita' (l'endpoint update non lo usa).
     */
    public function update(string $projectId, string $taskId, string $checkItemId, array $fields): array
    {
        $body = [];
        if (isset($fields['name'])) {
            $body['name'] = (string)$fields['name'];
        }
        if (array_key_exists('completed', $fields)) {
            $body['state'] = !empty($fields['completed']) ? 'yes' : 'empty';
        }
        if (isset($fields['state'])) { // passaggio diretto se gia' in formato FW
            $body['state'] = (string)$fields['state'];
        }
        $body['last_editor_user_id'] = $this->lookup->defaultUserId($projectId);
        $body['device_updated_at']   = FieldwireClient::nowIso();

        return $this->client->patch(
            "/projects/{$projectId}/task_check_items/{$checkItemId}",
            $body
        );
    }

    public function complete(string $projectId, string $taskId, string $checkItemId): array
    {
        return $this->update($projectId, $taskId, $checkItemId, ['completed' => true]);
    }

    public function delete(string $projectId, string $taskId, string $checkItemId): array
    {
        return $this->client->delete(
            "/projects/{$projectId}/task_check_items/{$checkItemId}"
        );
    }
}
