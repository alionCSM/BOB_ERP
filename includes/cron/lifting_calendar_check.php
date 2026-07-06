<?php
/**
 * BOB — Controllo calendario noleggi mezzi (cron giornaliero)
 *
 * Rileva presenze (nostri + consorziate) registrate in giorni che i noleggi
 * Giornalieri NON stanno conteggiando (es. sabato con calendario Lun-Ven,
 * o un festivo con "festivi esclusi") e invia una mail digest agli utenti
 * con permesso 'equipment_alerts'. Chi riceve puo' ignorare oppure
 * aggiungere i giorni extra dalla pagina Modifica noleggi.
 *
 * Run daily via cron (es. 7:00):
 *   0 7 * * * /usr/bin/php /var/www/bob.csmontaggi.it/public/includes/cron/lifting_calendar_check.php
 *
 * Env opzionale:
 *   LIFTING_ALERT_LOOKBACK_DAYS — finestra di controllo (default 30)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

use App\Infrastructure\Database;
use App\Infrastructure\LoggerFactory;
use App\Service\LiftingCalendarAlertService;

echo "=== BOB Lifting Calendar Check — " . date('Y-m-d H:i:s') . " ===\n";

try {
    $conn    = (new Database())->connect();
    $service = new LiftingCalendarAlertService($conn);
    $result  = $service->run();

    echo "Segnalazioni trovate: {$result['findings']}\n";
    echo "Email inviate:        {$result['emails_sent']}\n";
} catch (\Throwable $e) {
    LoggerFactory::app()->error('[LiftingCalendarCheck] ' . $e->getMessage());
    echo "ERRORE: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
