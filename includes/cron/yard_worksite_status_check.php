<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$logger = \App\Infrastructure\LoggerFactory::app();

$run = null;
try {
    $dbMy  = new Database();
    $dbYrd = new SQLServer(new Config());

    $connMy = $dbMy->connect();
    $run    = \App\Service\CronRun::start($connMy, 'yard_worksite_status_check');

    $service = new YardWorksiteStatusService(
        $connMy,
        $dbYrd->connect()
    );

    $service->run();
    $logger->info('yard_worksite_status_check: completed');
    $run->ok('Completato');
} catch (Throwable $e) {
    $run?->fail($e->getMessage());
    $logger->error('yard_worksite_status_check: fatal error', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
