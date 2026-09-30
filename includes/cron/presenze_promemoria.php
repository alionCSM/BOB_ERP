<?php
/**
 * Promemoria serale: "non hai segnato la presenza".
 *
 * Due passaggi nella stessa sera, TUTTI i giorni:
 *   0 20 * * *  /usr/bin/php .../includes/cron/presenze_promemoria.php primo
 *   0 21 * * *  /usr/bin/php .../includes/cron/presenze_promemoria.php secondo
 *
 * Il secondo ricontrolla chi manca davvero: chi ha compilato alle venti e
 * cinque non riceve niente. Si puo' quindi far girare senza preoccuparsi di
 * mandare due volte la stessa cosa alla stessa persona.
 *
 * Tutti i giorni e non 1-5: il sabato e la domenica si reggono da soli,
 * perche' si avvisa solo chi era pianificato. Se non c'e' nessuno in
 * pianificazione non parte niente, e se invece quel sabato qualcuno lavorava
 * riceve il promemoria come ogni altro giorno — che con 1-5 nel crontab non
 * succederebbe.
 *
 * Si puo' passare una data come secondo argomento per rimandarlo su un
 * giorno passato, che serve quando il server e' stato fermo la sera prima:
 *   ... presenze_promemoria.php primo 2026-09-29
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

$quando = in_array($argv[1] ?? '', ['primo', 'secondo'], true) ? $argv[1] : 'primo';

$giorno = $argv[2] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno)) {
    $giorno = date('Y-m-d');
}

$logger = \App\Infrastructure\LoggerFactory::app();
$run    = null;

try {
    $db   = new Database();
    $conn = $db->connect();
    $run  = \App\Service\CronRun::start($conn, 'presenze_promemoria_' . $quando);

    $servizio = new \App\Service\Attendance\PromemoriaPresenze(
        $conn,
        new \App\Service\Notifications\NotificationService(
            $conn,
            new \App\Infrastructure\Config()
        )
    );

    $esito = $servizio->invia($giorno, $quando);

    $messaggio = sprintf(
        'promemoria %s del %s: avvisati %d per %d giornate mancanti',
        $quando, $giorno, $esito['avvisati'], $esito['giorni']
    );

    $logger->info('presenze_promemoria: ' . $messaggio);
    $run->ok($messaggio);
    echo $messaggio . "\n";
} catch (Throwable $e) {
    $run?->fail($e->getMessage());
    $logger->error('presenze_promemoria: errore', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERRORE: {$e->getMessage()}\n";
    exit(1);
}
