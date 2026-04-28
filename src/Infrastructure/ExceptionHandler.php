<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Service\ErrorNotificationService;
use Database;

/**
 * Global Exception Handler
 *
 * Wraps application execution, catches unhandled exceptions,
 * logs them, sends email notifications, and renders appropriate responses.
 */
final class ExceptionHandler
{
    private ErrorNotificationService $notificationService;
    private Config $config;

    public function __construct(\PDO $conn, Config $config)
    {
        $this->config = $config;
        $this->notificationService = new ErrorNotificationService($conn, $config);
    }

    /**
     * Handle an exception (static - can be called from global exception handler)
     *
     * @param \Throwable $exception
     * @param array $context Additional context for notification
     * @return void
     */
    public static function handle(\Throwable $exception, array $context = []): void
    {
        // Generate unique error ID for tracking
        $errorId = strtoupper(substr(md5(uniqid((string)time(), true)), 0, 12));

        // Get config and PDO connection
        $config = new Config();
        $db = new \Database();
        $conn = $db->connect();

        // Instantiate handler with dependencies
        $handler = new self($conn, $config);

        // Add error ID to context
        $context['error_id'] = $errorId;

        // Log the error
        $handler->logError($exception, $context);

        // Send email notification
        $handler->notificationService->notify($exception, $context);

        // Render response based on environment
        if ($config->isProduction()) {
            $handler->renderProductionError($context);
        } else {
            $handler->renderDevelopmentError($exception, $context);
        }

        exit(1);
    }

    /**
     * Log error to PHP error log
     */
    private function logError(\Throwable $exception, array $context): void
    {
        $message = sprintf(
            "[ERROR] %s in %s:%d\nContext: %s\nTrace: %s",
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            json_encode($context),
            $exception->getTraceAsString()
        );

        error_log($message);
    }

    /**
     * Render production error page (generic 500)
     */
    private function renderProductionError(array $context): void
    {
        http_response_code(500);

        $appUrl = $this->config->appUrl();
        $errorId = $context['error_id'] ?? 'UNKNOWN';

        // Check if AJAX request
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Si è verificato un errore interno. Contatta l\'amministrazione.',
                'error_id' => $errorId,
            ]);
        } else {
            // Render custom error page if exists, otherwise show generic
            $customError = dirname(__DIR__, 3) . '/bob500.html';
            if (file_exists($customError)) {
                // Inject error ID into the page
                $html = file_get_contents($customError);
                $html = str_replace('{{ERROR_ID}}', $errorId, $html);
                echo $html;
            } else {
                // Generic error page
                $environment = $this->config->appEnv();
                echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Errore - BOB</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1e1e2e 0%, #0f0f1a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            text-align: center;
            max-width: 550px;
            padding: 80px 50px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .error-logo {
            width: 120px;
            height: 120px;
            margin-bottom: 30px;
            animation: bounce 2s infinite;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        h1 {
            color: #ffffff;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .error-message {
            color: #a0a0b0;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        .error-submessage {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .error-code {
            display: inline-block;
            background: rgba(255, 0, 108, 0.1);
            color: #ff006c;
            padding: 8px 16px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 25px;
            border: 1px solid rgba(255, 0, 108, 0.3);
        }
        .btn {
            display: inline-block;
            padding: 16px 40px;
            background: #ff006c;
            color: #ffffff;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .btn:hover {
            background: #ff2a7a;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 0, 108, 0.3);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <img src="{$appUrl}/assets/img/err_logo.png" alt="" class="error-logo">
        <h1>Ops! Qualcosa è andato storto</h1>
        <p class="error-message">Siamo spiacenti, si è verificato un errore imprevisto.</p>
        <p class="error-submessage">Il nostro team è stato già avvisato e sta lavorando per risolvere il problema.</p>
        <div class="error-code">Codice errore: {$errorId}</div>
        <a href="{$appUrl}" class="btn">🏠 Torna alla Home</a>
    </div>
</body>
</html>
HTML;
            }
        }
    }

    /**
     * Render development error page (full details)
     */
    private function renderDevelopmentError(\Throwable $exception, array $context): void
    {
        http_response_code(500);

        // Check if AJAX request
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        } else {
            // Render detailed error page
            $appUrl = $this->config->appUrl();
            $environment = $this->config->appEnv();

            $traceHtml = '';
            foreach ($exception->getTrace() as $index => $frame) {
                $file = $frame['file'] ?? 'unknown';
                $line = $frame['line'] ?? 'unknown';
                $function = $frame['function'] ?? $frame['class'] . $frame['type'] . '(' . ($frame['args'] ?? '') . ')';
                $function = $function ?: 'unknown';

                $traceHtml .= "
                    <tr>
                        <td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;\">{$index}</td>
                        <td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-family:monospace;font-size:13px;\">
                            <span style=\"color:#dc2626;\">{$file}</span>:
                            <span style=\"color:#2563eb;\">{$line}</span>
                        </td>
                        <td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-family:monospace;font-size:12px;color:#6b7280;\">
                            {$function}
                        </td>
                    </tr>";
            }

            echo "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Error - BOB ({$environment})</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .error-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .error-header {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: #ffffff;
            padding: 24px 30px;
        }
        .error-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .error-header .subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        .error-body {
            padding: 24px 30px;
        }
        .error-section {
            margin-bottom: 24px;
        }
        .error-section:last-child {
            margin-bottom: 0;
        }
        .error-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .error-value {
            font-size: 16px;
            color: #1f2937;
            line-height: 1.5;
        }
        .error-value.code {
            background: #1f2937;
            color: #f3f4f6;
            padding: 16px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
        }
        .error-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }
        .error-table th {
            text-align: left;
            padding: 12px;
            background: #f9fafb;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }
        .error-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }
        .error-table tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            background: #dc2626;
            color: #ffffff;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>
    <div class=\"container\">
        <div class=\"error-card\">
            <div class=\"error-header\">
                <h1>🔥 Exception Caught</h1>
                <div class=\"subtitle\">{$environment} Environment — Full Details</div>
            </div>
            <div class=\"error-body\">

                <div class=\"error-section\">
                    <div class=\"error-label\">Exception Type</div>
                    <div class=\"error-value\">
                        <span class=\"badge\">" . htmlspecialchars($exception::class) . "</span>
                    </div>
                </div>

                <div class=\"error-section\">
                    <div class=\"error-label\">Message</div>
                    <div class=\"error-value\">" . htmlspecialchars($exception->getMessage()) . "</div>
                </div>

                <div class=\"error-section\">
                    <div class=\"error-label\">Location</div>
                    <div class=\"error-value\">" . htmlspecialchars($exception->getFile()) . " <strong style=\"color:#dc2626;\">:" . $exception->getLine() . "</strong></div>
                </div>

                <div class=\"error-section\">
                    <div class=\"error-label\">Stack Trace</div>
                    <table class=\"error-table\">
                        <thead>
                            <tr>
                                <th style=\"width:50px;\">#</th>
                                <th>File</th>
                                <th>Function</th>
                            </tr>
                        </thead>
                        <tbody>
                            " . $traceHtml . "
                        </tbody>
                    </table>
                </div>

                <div style=\"padding-top:20px;border-top:1px solid #e5e7eb;\">
                    <a href=\"" . $appUrl . "\" class=\"btn\">← Back to Application</a>
                </div>

            </div>
        </div>
    </div>
</body>
</html>";
        }
    }
}
