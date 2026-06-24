<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;

class BubblesApi
{
    public function __construct(private FieldwireClient $client) {}

    /** All bubbles (comments/photos/attachments) for a task. */
    public function allForTask(string $projectId, string $taskId): array
    {
        return $this->client->get("/projects/{$projectId}/tasks/{$taskId}/bubbles");
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
        return $this->client->post("/projects/{$projectId}/tasks/{$taskId}/bubbles", [
            'bubble' => [
                'kind' => 'comment',
                'text' => $text,
            ],
        ]);
    }

    public function update(string $projectId, string $bubbleId, array $fields): array
    {
        return $this->client->patch("/projects/{$projectId}/bubbles/{$bubbleId}", [
            'bubble' => $fields,
        ]);
    }

    public function delete(string $projectId, string $bubbleId): array
    {
        return $this->client->delete("/projects/{$projectId}/bubbles/{$bubbleId}");
    }
}
