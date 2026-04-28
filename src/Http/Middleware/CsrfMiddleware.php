<?php

declare(strict_types=1);

namespace App\Http\Middleware;

/**
 * CSRF protection for all authenticated POST requests.
 *
 * Exempted routes (e.g. API webhooks) can be passed to the constructor.
 *
 * Usage:
 *   (new CsrfMiddleware(['/api/analytics/heartbeat']))->handle();
 */
final class CsrfMiddleware
{
    /** @param list<string> $exempt URI paths that skip CSRF verification */
    public function __construct(private readonly array $exempt = []) {}

    /**
     * Verify CSRF token on POST requests. Responds 403 and exits on failure.
     */
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        if (in_array($uri, $this->exempt, true)) {
            return;
        }

        if (!csrf_verify()) {
            http_response_code(403);
            exit('CSRF token missing or invalid');
        }
    }
}
