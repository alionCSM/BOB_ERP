<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Container\ContainerInterface;

/**
 * Minimal router.
 *
 * Register routes with ->get() / ->post(), then call ->dispatch().
 * If a route matches, the handler is called and dispatch() never returns.
 * If nothing matches, dispatch() returns false so the legacy fallback can run.
 *
 * Route patterns support {param} segments:
 *   /clients/{id}       → $request->params['id']
 *   /clients/{id}/edit  → $request->params['id']
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, keys: list<string>, handler: array{0:string,1:string}}> */
    private array $routes = [];

    // ── Registration ─────────────────────────────────────────────────────────

    /** @param array{0:string,1:string} $handler [ControllerClass::class, 'method'] */
    public function get(string $pattern, array $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    /** @param array{0:string,1:string} $handler */
    public function post(string $pattern, array $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, array $handler): self
    {
        [$regex, $keys] = $this->compile($pattern);
        $this->routes[] = compact('method', 'pattern', 'regex', 'keys', 'handler');
        return $this;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /**
     * Try to match the current request.
     *
     * - URI + method match  → resolve controller, call action, exit.
     * - URI match only      → 405 Method Not Allowed (with Allow header), exit.
     * - No URI match        → return false (caller falls through to legacy routing).
     */
    public function dispatch(Request $request, ContainerInterface $container): bool
    {
        $uriMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->uri, $matches)) {
                continue;
            }

            $uriMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            // Populate route params
            foreach ($route['keys'] as $key) {
                $request->params[$key] = $matches[$key] ?? '';
            }

            // Resolve controller from container and call action
            [$class, $action] = $route['handler'];
            $container->get($class)->{$action}($request);

            // Controllers that render views call exit themselves;
            // those that don't (e.g. return JSON inline) fall through here.
            exit;
        }

        if ($uriMatched) {
            // URI matched a registered pattern but the HTTP method is wrong.
            http_response_code(405);
            header('Allow: ' . implode(', ', $this->allowedMethods($request->uri)));
            exit;
        }

        return false;
    }

    /** Collect every HTTP method registered for a given URI pattern. */
    private function allowedMethods(string $uri): array
    {
        $methods = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $uri)) {
                $methods[] = $route['method'];
            }
        }
        return array_unique($methods);
    }

    // ── Pattern compiler ─────────────────────────────────────────────────────

    /**
     * Convert /clients/{id}/edit  →  regex + param key list
     *
     * @return array{0: string, 1: list<string>}
     */
    private function compile(string $pattern): array
    {
        $keys  = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$keys): string {
            $keys[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        return ['#^' . $regex . '$#', $keys];
    }
}
