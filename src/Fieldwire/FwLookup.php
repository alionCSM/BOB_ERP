<?php
declare(strict_types=1);

namespace App\Fieldwire;

use RuntimeException;

/**
 * Risoluzione (con cache per-progetto) di utenti, stati e team Fieldwire.
 *
 * L'API v3 non accetta stringhe come "status" o "assignee_name": i task
 * referenziano status_id (UUID), team_id (UUID = categoria) e owner_user_id /
 * creator_user_id (interi). Questa classe fa da ponte tra il modello BOB
 * (stringhe) e gli id Fieldwire.
 */
class FwLookup
{
    /** @var array<string, array{users:?array, statuses:?array, teams:?array}> */
    private static array $cache = [];

    public function __construct(private FieldwireClient $client) {}

    // ── Users ──────────────────────────────────────────────────────────────────

    /** @return array<int, array> utenti del progetto, keyed by user id */
    public function users(string $projectId): array
    {
        if (!isset(self::$cache[$projectId]['users'])) {
            $rows = $this->client->getAll("/account/projects/{$projectId}/users");
            $map  = [];
            foreach ($rows as $u) {
                if (isset($u['id'])) $map[(int)$u['id']] = $u;
            }
            self::$cache[$projectId]['users'] = $map;
        }
        return self::$cache[$projectId]['users'];
    }

    /**
     * Utente Fieldwire da usare come creator/owner nei push BOB → Fieldwire.
     * Preferenza: FIELDWIRE_USER_EMAIL in .env, poi primo admin, poi primo utente.
     */
    public function defaultUserId(string $projectId): int
    {
        $users = $this->users($projectId);
        if (empty($users)) {
            throw new RuntimeException("Nessun utente nel progetto Fieldwire {$projectId}: impossibile creare entita'.");
        }

        $preferred = strtolower((string)($_ENV['FIELDWIRE_USER_EMAIL'] ?? ''));
        if ($preferred !== '') {
            foreach ($users as $id => $u) {
                if (strtolower((string)($u['email'] ?? '')) === $preferred) return $id;
            }
        }
        foreach ($users as $id => $u) {
            if (($u['role'] ?? '') === 'admin') return $id;
        }
        return array_key_first($users);
    }

    public function userName(string $projectId, ?int $userId): ?string
    {
        if (!$userId) return null;
        $u = $this->users($projectId)[$userId] ?? null;
        if (!$u) return null;
        $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        return $name !== '' ? $name : ($u['email'] ?? null);
    }

    // ── Statuses ───────────────────────────────────────────────────────────────

    /** @return array<string,string> status_id => name */
    public function statuses(string $projectId): array
    {
        if (!isset(self::$cache[$projectId]['statuses'])) {
            $rows = $this->client->getAll("/projects/{$projectId}/statuses");
            $map  = [];
            foreach ($rows as $s) {
                if (isset($s['id'])) $map[(string)$s['id']] = (string)($s['name'] ?? '');
            }
            self::$cache[$projectId]['statuses'] = $map;
        }
        return self::$cache[$projectId]['statuses'];
    }

    /** BOB status (open|in_progress|complete|verified) → Fieldwire status_id */
    public function statusIdFor(string $projectId, string $bobStatus): ?string
    {
        $want = self::normalizeStatus($bobStatus);
        foreach ($this->statuses($projectId) as $id => $name) {
            if (self::normalizeStatus($name) === $want) return $id;
        }
        return null; // stato senza corrispondenza: lasciamo decidere a Fieldwire il default
    }

    /** Fieldwire status_id → BOB status */
    public function bobStatusFor(string $projectId, ?string $statusId): ?string
    {
        if (!$statusId) return null;
        $name = $this->statuses($projectId)[$statusId] ?? null;
        if ($name === null) return null;
        $n = self::normalizeStatus($name);
        return in_array($n, ['open', 'in_progress', 'complete', 'verified'], true) ? $n : 'open';
    }

    private static function normalizeStatus(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace([' ', '-'], '_', $s);
        return $s === 'completed' ? 'complete' : $s;
    }

    // ── Teams (= categorie task in Fieldwire) ──────────────────────────────────

    /** @return array<string,string> team_id => name */
    public function teams(string $projectId): array
    {
        if (!isset(self::$cache[$projectId]['teams'])) {
            $rows = $this->client->getAll("/projects/{$projectId}/teams");
            $map  = [];
            foreach ($rows as $t) {
                if (isset($t['id'])) $map[(string)$t['id']] = (string)($t['name'] ?? '');
            }
            self::$cache[$projectId]['teams'] = $map;
        }
        return self::$cache[$projectId]['teams'];
    }

    public function teamName(string $projectId, ?string $teamId): ?string
    {
        if (!$teamId) return null;
        return $this->teams($projectId)[$teamId] ?? null;
    }
}
