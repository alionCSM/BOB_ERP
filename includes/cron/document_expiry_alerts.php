<?php
/**
 * Document Expiry Alert Cron Job
 *
 * Sends email alerts for expired and about-to-expire documents.
 * Run daily via cron:
 *   0 7 * * * /usr/bin/php /path/to/includes/cron/document_expiry_alerts.php
 *
 * Safe to re-run: duplicate alerts are prevented by bb_document_alert_log table.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$logger = \App\Infrastructure\LoggerFactory::app();

try {
    $db      = new Database();
    $conn    = $db->connect();
    $service = new DocumentExpiryAlertService($conn);
    $service->run();
    $logger->info('document_expiry_alerts: completed');
} catch (Throwable $e) {
    $logger->error('document_expiry_alerts: fatal error', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
