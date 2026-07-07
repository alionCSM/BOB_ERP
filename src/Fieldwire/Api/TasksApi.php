<?php
declare(strict_types=1);

namespace App\Fieldwire\Api;

use App\Fieldwire\FieldwireClient;
use App\Fieldwire\FwLookup;

/**
 * Tasks API v3.
 *
 * NB payload: l'API v3 vuole il body FLAT (non wrappato in "task") e richiede
 * id (UUID generato dal client), creator_user_id, last_editor_user_id,
 * owner_user_id, priority (1-3), is_local, device_created_at/updated_at.
 * Lo stato e' status_id (UUID per-progetto), la categoria e' team_id.
 *
 * I metodi create/update accettano il formato "BOB" (name, status, priority,
 * start_date, due_date, ...) e traducono qui. Campi BOB senza controparte
 * Fieldwire (description, assignee_name, category testuale) vengono scartati.
 */
class TasksApi
{
    private FwLookup $lookup;

    public function __construct(private FieldwireClient $client)
    {
        $this->lookup = new FwLookup($client);
    }

    public function all(string $projectId, array $filters = []): array
    {
        return $this->client->getAll("/projects/{$projectId}/tasks", $filters);
    }

    public function find(string $projectId, string $taskId): array
    {
        return $this->client->get("/projects/{$projectId}/tasks/{$taskId}");
    }

    /** $fields: formato BOB (name, status, priority, start_date, due_date, ...) */
    public function create(string $projectId, array $fields): array
    {
        $uid = $this->lookup->defaultUserId($projectId);
        $now = FieldwireClient::nowIso();

        $body = [
            'id'                  => FieldwireClient::uuid(),
            'creator_user_id'     => $uid,
            'last_editor_user_id' => $uid,
            'owner_user_id'       => $uid,
            'priority'            => self::fwPriority($fields['priority'] ?? null),
            'is_local'            => false,
            'device_created_at'   => $now,
            'device_updated_at'   => $now,
        ] + $this->translate($projectId, $fields);

        return $this->client->post("/projects/{$projectId}/tasks", $body);
    }

    /** $fields: formato BOB — traduce solo i campi presenti */
    public function update(string $projectId, string $taskId, array $fields): array
    {
        $body = $this->translate($projectId, $fields);
        if (array_key_exists('priority', $fields)) {
            $body['priority'] = self::fwPriority($fields['priority']);
        }
        $body['last_editor_user_id'] = $this->lookup->defaultUserId($projectId);
        $body['device_updated_at']   = FieldwireClient::nowIso();

        return $this->client->patch("/projects/{$projectId}/tasks/{$taskId}", $body);
    }

    public function delete(string $projectId, string $taskId): array
    {
        return $this->client->delete("/projects/{$projectId}/tasks/{$taskId}");
    }

    public function filterByStatus(string $projectId, string $status): array
    {
        // filtro applicato client-side: l'API espone status_id, non stringhe
        $wantId = $this->lookup->statusIdFor($projectId, $status);
        $tasks  = $this->all($projectId);
        if ($wantId === null) return $tasks;
        return array_values(array_filter($tasks, fn($t) => ($t['status_id'] ?? null) === $wantId));
    }

    // ── Traduzione BOB → Fieldwire ─────────────────────────────────────────────

    private function translate(string $projectId, array $fields): array
    {
        $out = [];

        if (isset($fields['name']) && $fields['name'] !== '') {
            $out['name'] = (string)$fields['name'];
        }
        if (!empty($fields['status'])) {
            $sid = $this->lookup->statusIdFor($projectId, (string)$fields['status']);
            if ($sid !== null) $out['status_id'] = $sid;
        }
        if (!empty($fields['start_date'])) {
            $out['start_at'] = self::iso($fields['start_date']);
        }
        if (!empty($fields['due_date'])) {
            $out['due_at'] = self::iso($fields['due_date']);
        }

        return $out;
    }

    /** BOB priority (0 = non impostata) → Fieldwire 1..3 (obbligatoria) */
    private static function fwPriority($p): int
    {
        $p = (int)($p ?? 0);
        return ($p >= 1 && $p <= 3) ? $p : 2;
    }

    private static function iso(string $date): string
    {
        $ts = strtotime($date) ?: time();
        return gmdate('Y-m-d\TH:i:s.v\Z', $ts);
    }
}
