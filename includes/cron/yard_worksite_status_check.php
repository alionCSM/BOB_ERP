<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$logger = \App\Infrastructure\LoggerFactory::app();

try {
    $dbMy  = new Database();
    $dbYrd = new SQLServer(new Config());

    $service = new YardWorksiteStatusService(
        $dbMy->connect(),
        $dbYrd->connect()
    );

    $service->run();
    $logger->info('yard_worksite_status_check: completed');
} catch (Throwable $e) {
    $logger->error('yard_worksite_status_check: fatal error', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
