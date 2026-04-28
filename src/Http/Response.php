<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Static helpers for sending HTTP responses.
 *
 * All methods send headers / output then call exit — they never return.
 *
 * Usage:
 *   Response::redirect('/dashboard');
 *   Response::json(['ok' => true]);
 *   Response::json(['error' => 'Not found'], 404);
 *   Response::view('clients/list.php', $request, compact('clients', 'pageTitle'));
 */
final class Response
{
    // ── Redirect ──────────────────────────────────────────────────────────────

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    // ── JSON ──────────────────────────────────────────────────────────────────

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── Error ────────────────────────────────────────────────────────────────

    /**
     * Return an error response.
     *
     * In production, shows generic messages to users. In development/AJAX, shows details.
     * Logs all errors via Monolog. Server errors (5xx) also trigger superadmin email.
     *
     * @param string $message Error message
     * @param int $status HTTP status code (default 400)
     * @param bool $isAjax Whether this is an AJAX request
     * @return void (exits)
     */
    public static function error(string $message, int $status = 400, bool $isAjax = null): never
    {
        $config = new \App\Infrastructure\Config();
        $isProduction = $config->isProduction();

        // Auto-detect AJAX if not specified
        if ($isAjax === null) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        }

        // Log the error with full details — wrapped so a log-write failure
        // (e.g. permission denied) never crashes the request itself.
        try {
            if ($status >= 500) {
                // Server error - log as error level
                $logger = \App\Infrastructure\LoggerFactory::app();
                $logger->error($message, [
                    'status' => $status,
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                ]);

                // Also trigger superadmin email notification for 5xx errors
                self::notifySuperadmin($message, $status);
            } else {
                // Client error - log as warning
                $logger = \App\Infrastructure\LoggerFactory::app();
                $logger->warning($message, [
                    'status' => $status,
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                ]);
            }
        } catch (\Throwable $logEx) {
            // Logging failed (e.g. permission denied on log file) — silently
            // ignore so the actual HTTP response is still sent to the client.
            error_log('BOB logger failed: ' . $logEx->getMessage());
        }

        // In production, sanitize message for non-AJAX requests
        $displayMessage = $isAjax || !$isProduction ? $message : self::sanitizeErrorMessage($message, $status);

        if ($isAjax) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => $displayMessage,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            http_response_code($status);
            echo "<p>Errore: {$displayMessage}</p>";
        }
        exit;
    }

    /**
     * Notify superadmin of server errors.
     *
     * @param string $message
     * @param int $status
     * @return void
     */
    private static function notifySuperadmin(string $message, int $status): void
    {
        try {
            $conn = $GLOBALS['connection'] ?? null;
            if (!$conn) {
                return;
            }

            // Get superadmin email (user with id=1)
            $stmt = $conn->prepare("SELECT email FROM bb_users WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $adminEmail = $row['email'] ?? null;

            if (empty($adminEmail)) {
                return;
            }

            // Send email notification
            $subject = sprintf('[BOB ERROR] %d %s - %s', $status, $_SERVER['REQUEST_URI'] ?? 'unknown', $message);

            $mailer = new \Mailer();
            $mailer->setSender('alerts');
            $mail = $mailer->getMailer();

            $mail->addAddress($adminEmail);
            $mail->Subject = $subject;
            $mail->Body = sprintf(
                "Error occurred:\n\nStatus: %d\nMessage: %s\nURI: %s\nTime: %s",
                $status,
                $message,
                $_SERVER['REQUEST_URI'] ?? 'unknown',
                date('Y-m-d H:i:s')
            );

            $mail->send();
        } catch (\Exception $e) {
            // Don't let notification failure break the app
            error_log('[Response] Failed to notify superadmin: ' . $e->getMessage());
        }
    }

    /**
     * Sanitize error message for production display.
     *
     * @param string $message
     * @param int $status
     * @return string
     */
    private static function sanitizeErrorMessage(string $message, int $status): string
    {
        // Map status codes to generic messages
        return match($status) {
            400 => 'Richiesta non valida.',
            401 => 'Non autorizzato. Effettua il login.',
            403 => 'Accesso negato. Non hai i permessi sufficienti.',
            404 => 'Risorsa non trovata.',
            405 => 'Metodo non consentito.',
            422 => 'Dati non validi.',
            429 => 'Troppe richieste. Attendi qualche momento.',
            default => 'Si è verificato un errore. Riprova più tardi.',
        };
    }

    // ── View ──────────────────────────────────────────────────────────────────

    /**
     * Render a view and exit.
     *
     * Supports two engines:
     *   - `.twig`  → Twig template from APP_ROOT/templates/
     *   - `.php`   → Legacy PHP include from APP_ROOT/views/
     *
     * PHP views receive:
     *   $user, $authenticated_user, $conn, $connection (from globals)
     *   plus every key in $data extracted into scope.
     */
    public static function view(string $view, Request $request, array $data = []): never
    {
        if (str_ends_with($view, '.twig')) {
            self::twig($view, $data);
        }

        // ── Legacy PHP view ───────────────────────────────────────────────
        $authenticated_user = $GLOBALS['authenticated_user'] ?? [];
        $user               = $request->user();
        $conn               = $GLOBALS['connection'] ?? null;
        $connection         = $GLOBALS['connection'] ?? null;
        extract($data);

        $viewFile = APP_ROOT . '/views/' . ltrim($view, '/');
        $oldCwd   = getcwd();
        chdir(dirname($viewFile));
        include $viewFile;
        chdir($oldCwd);
        exit;
    }

    // ── Twig (internal) ───────────────────────────────────────────────────────

    private static function twig(string $template, array $data): never
    {
        $conn     = $GLOBALS['connection']         ?? null;
        $authUser = $GLOBALS['authenticated_user'] ?? [];
        $user     = $GLOBALS['user']               ?? null;

        // Auth pages (login, change-password) are rendered before the user
        // is authenticated, so $conn / $user are not available yet.
        // In that case skip LayoutDataProvider and use a bare renderer.
        if ($conn === null || $user === null) {
            $renderer = new \App\View\TwigRenderer(null);
        } else {
            $ldp      = new \App\View\LayoutDataProvider(
                $conn,
                $authUser,
                $user,
                new \App\Infrastructure\Config()
            );
            $renderer = new \App\View\TwigRenderer($ldp);
        }

        echo $renderer->render($template, $data);
        exit;
    }
}
