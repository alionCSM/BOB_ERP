<?php
/**
 * BOB — Sync flag "emessa" da Yard (cron notturno)
 *
 * Aggiorna bb_billing.emessa leggendo lo stato reale delle fatture da Yard
 * (SQL Server, CNT_cantieri_brogliacci) per TUTTE le righe con yard_id.
 *
 * Senza questo cron il flag viene rinfrescato solo quando qualcuno apre la
 * pagina /billing del cliente: BOB AI e le dashboard risponderebbero con
 * dati vecchi a domande tipo "quali fatture non sono ancora emesse?".
 *
 * Run nightly via cron (es. 5:30):
 *   30 5 * * * /usr/bin/php /var/www/bob.csmontaggi.it/public/includes/cron/sync_emessa_yard.php
 *
 * Env richiesto: SQLSRV_* in .env (connessione Yard).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

use App\Domain\YardWorksiteBilling;
use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\LoggerFactory;
use App\Infrastructure\SqlServerConnection;
use App\Repository\Billing\BillingRepository;

echo "=== BOB Sync emessa da Yard — " . date('Y-m-d H:i:s') . " ===\n";

$conn = null;
$run  = null;
try {
    $conn = (new Database())->connect();
    $run  = \App\Service\CronRun::start($conn, 'sync_emessa_yard');

    $yardBilling = new YardWorksiteBilling(new SqlServerConnection(new Config()));
    $repo        = new BillingRepository($conn);

    $result = $repo->syncEmessaAll($yardBilling);

    echo "Righe controllate: {$result['checked']}\n";
    echo "Righe aggiornate:  {$result['updated']}\n";
    $run->ok("Righe controllate: {$result['checked']}, aggiornate: {$result['updated']}");
} catch (\Throwable $e) {
    $run?->fail($e->getMessage());
    // il log su file puo' fallire (permessi: cron lanciato con utente diverso
    // da www-data) — non deve mascherare l'errore vero
    try {
        LoggerFactory::app()->error('[SyncEmessaYard] ' . $e->getMessage());
    } catch (\Throwable $logErr) {
        error_log('[SyncEmessaYard] log fallito: ' . $logErr->getMessage());
    }
    echo "ERRORE: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
