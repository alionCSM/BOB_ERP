<?php

declare(strict_types=1);

namespace App\Http;
use PDO;

/**
 * Wraps the current HTTP request.
 * Populated once per request; route params are injected by the Router.
 */
final class Request
{
    /** HTTP verb in uppercase (GET, POST, …) */
    public readonly string $method;

    /** Normalised URI path, no trailing slash (except root "/") */
    public readonly string $uri;

    /** Captured route segments, e.g. ['id' => '42'] for /clients/{id} */
    public array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $raw          = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->uri    = $raw === '/' ? '/' : rtrim($raw, '/');
    }

    // ── Query string ──────────────────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    // ── POST body ─────────────────────────────────────────────────────────────

    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    // ── Either (POST wins) ────────────────────────────────────────────────────

    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    // ── Route parameter ───────────────────────────────────────────────────────

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function intParam(string $key, int $default = 0): int
    {
        return isset($this->params[$key]) ? (int) $this->params[$key] : $default;
    }

    /** Returns the entire $_POST array */
    public function allPost(): array
    {
        return $_POST;
    }

    /** Returns the entire $_FILES array */
    public function allFiles(): array
    {
        return $_FILES;
    }

    // ── Convenience ───────────────────────────────────────────────────────────

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** Returns the authenticated User object set by middleware, or null if not logged in */
    public function user(): ?\User
    {
        return $GLOBALS['user'] ?? null;
    }

    /** Returns the PDO connection set by middleware */
    public function db(): \PDO
    {
        return $GLOBALS['db_connection'];
    }
}
