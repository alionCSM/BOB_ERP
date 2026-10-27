<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

// Dove Yard non c'e' questo lavoro non ha senso: si esce prima di provare
// una connessione a un server che non esiste, invece di riempire il log di
// errori ogni notte.
if (!(new \App\Infrastructure\Config())->yardConfigured()) {
    echo "Yard non e' collegato su questa installazione: niente da fare" . PHP_EOL;
    exit(0);
}

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
