<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;
use App\Fieldwire\FwLookup;

/**
 * Bubbles API v3 (commenti/foto/allegati dei task).
 *
 * NB payload: body FLAT. "kind" e' un intero:
 *   1=TEXT, 2=LOG, 10=PHOTO, 11=PHOTO+ANNOT, 12=DRAWING+ANNOT,
 *   13=PHOTO SPHERE, 20=ATTACHMENT, 21=VIDEO
 * Il testo del commento sta in "content" (non "text"); user_id obbligatorio.
 */
class BubblesApi
{
    public const KIND_TEXT = 1;

    private FwLookup $lookup;

    public function __construct(private FieldwireClient $client)
    {
        $this->lookup = new FwLookup($client);
    }

    /** All bubbles (comments/photos/attachments) for a task. */
    public function allForTask(string $projectId, string $taskId): array
    {
        return $this->client->getAll("/projects/{$projectId}/tasks/{$taskId}/bubbles");
    }

    public function find(string $projectId, string $bubbleId): array
    {
        return $this->client->get("/projects/{$projectId}/bubbles/{$bubbleId}");
    }

    /**
     * Post a text comment on a task.
     * $text: the message body
     */
    public function postComment(string $projectId, string $taskId, string $text): array
    {
        $now = FieldwireClient::nowIso();

        return $this->client->post("/projects/{$projectId}/tasks/{$taskId}/bubbles", [
            'id'                => FieldwireClient::uuid(),
            'kind'              => self::KIND_TEXT,
            'content'           => $text,
            'user_id'           => $this->lookup->defaultUserId($projectId),
            'device_created_at' => $now,
            'device_updated_at' => $now,
        ]);
    }

    public function update(string $projectId, string $bubbleId, array $fields): array
    {
        $body = $fields;
        if (isset($body['text'])) { // alias BOB → campo reale
            $body['content'] = $body['text'];
            unset($body['text']);
        }
        $body['device_updated_at'] = FieldwireClient::nowIso();

        return $this->client->patch("/projects/{$projectId}/bubbles/{$bubbleId}", $body);
    }

    public function delete(string $projectId, string $bubbleId): array
    {
        return $this->client->delete("/projects/{$projectId}/bubbles/{$bubbleId}");
    }
}
