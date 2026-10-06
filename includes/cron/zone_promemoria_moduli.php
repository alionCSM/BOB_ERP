<?php
/**
 * Promemoria del pomeriggio: i moduli della Zone che uno deve ancora compilare.
 *
 * Tutti i giorni, una volta:
 *   0 16 * * *  /usr/bin/php .../includes/cron/zone_promemoria_moduli.php
 *
 * Alle sedici: abbastanza tardi da non disturbare chi lo fa la mattina,
 * abbastanza presto da farlo prima di staccare. Chi avvisare e quando lo
 * decide ScadenzeModuli (solo chi oggi e' su quel cantiere, ecc.), quindi va
 * messo tutti i giorni e non 1-5.
 *
 * Si puo' passare una data per rifarlo su un giorno preciso:
 *   ... zone_promemoria_moduli.php 2026-10-09
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$giorno = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno)) {
    $giorno = date('Y-m-d');
}

$logger = \App\Infrastructure\LoggerFactory::app();
$run    = null;

try {
    $conn = (new Database())->connect();
    $run  = \App\Service\CronRun::start($conn, 'zone_promemoria_moduli');

    $esito = (new \App\Service\Zone\ScadenzeModuli($conn))->ricorda(
        new \App\Service\Notifications\NotificationService($conn, new \App\Infrastructure\Config()),
        $giorno
    );

    $messaggio = sprintf('moduli Zone del %s: avvisati %d per %d moduli', $giorno, $esito['avvisati'], $esito['moduli']);
    $logger->info('zone_promemoria_moduli: ' . $messaggio);
    $run->ok($messaggio);
    echo $messaggio . "\n";
} catch (Throwable $e) {
    $run?->fail($e->getMessage());
    $logger->error('zone_promemoria_moduli: errore', ['error' => $e->getMessage()]);
    echo "ERRORE: {$e->getMessage()}\n";
    exit(1);
}
