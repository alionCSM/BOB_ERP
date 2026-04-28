<?php
/**
 * BOB AI Anomaly Checker — Daily Cron Job
 *
 * Scans all modules for anomalies, uses local LLM for analysis,
 * sends emails to relevant users (with Excel attachments for large reports).
 *
 * Run daily via cron (e.g. 9:00 AM):
 *   0 9 * * * /usr/bin/php /path/to/includes/cron/ai_anomaly_check.php
 *
 * Environment:
 *   OLLAMA_URL  — LLM endpoint (e.g. http://192.168.1.10:8000/v1/chat/completions)
 *   MODEL       — Model name (e.g. Qwen3-30B-A3B-Q4_K_M.gguf)
 *   MAIL_*      — SMTP configuration
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$logger = \App\Infrastructure\LoggerFactory::app();

try {
    $db   = new Database();
    $conn = $db->connect();

    // Initialize AI client (optional — works without it too)
    $ai = null;
    $ollamaUrl = $_ENV['OLLAMA_URL'] ?? '';
    $model     = $_ENV['MODEL'] ?? '';
    if ($ollamaUrl && $model) {
        $ai = new OllamaClient($ollamaUrl, $model);
        echo "AI enabled: {$model}\n";
    } else {
        echo "AI disabled (no OLLAMA_URL/MODEL in env). Running SQL checks only.\n";
    }

    // Initialize mailer (optional — works without it too)
    $mailer = null;
    try {
        if (!empty($_ENV['MAIL_HOST'])) {
            $mailer = new Mailer();
            echo "Email enabled\n";
        }
    } catch (Exception $e) {
        echo "Email disabled: {$e->getMessage()}\n";
        $logger->warning('ai_anomaly_check: mailer init failed', ['error' => $e->getMessage()]);
    }

    echo "\n";

    // Run the anomaly checker
    $service  = new AnomalyCheckerService($conn, $ai, $mailer);
    $findings = $service->run();

    $logger->info('ai_anomaly_check: completed', ['findings' => count($findings)]);
    exit(empty($findings) ? 0 : 1);
} catch (Throwable $e) {
    $logger->error('ai_anomaly_check: fatal error', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
