<?php
/**
 * FCM test — verifica la configurazione push app Android dal server BOB.
 *
 * Uso (su Linux, da CLI, come utente che legge /etc/bob/...):
 *   php scripts/fcm_test.php                      # verifica configurazione
 *   php scripts/fcm_test.php <fcm_token>          # + invio notifica di prova
 *
 * Esce 0 se tutto ok, 1 in caso di errore. Non tocca il sito.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

use App\Infrastructure\Config;
use App\Service\Push\FcmService;

$db       = new Database();
$connection = $db->connect();
$config   = new Config();

$fails = 0;
$ok    = function (string $msg) use (&$fails) { echo "  [OK]   {$msg}\n"; };
$bad   = function (string $msg) use (&$fails) { $fails++; echo "  [FAIL] {$msg}\n"; };

echo "=== BOB FCM test ===\n";

// 1) Configurazione presente?
$file = $config->fcmServiceAccountFile();
$json = $config->fcmServiceAccountJson();

if ($file === '' && $json === '') {
    $bad('FCM non configurata: manca FCM_SERVICE_ACCOUNT_FILE (o FCM_SERVICE_ACCOUNT_JSON) nel .env');
    exit(1);
}
$ok('Configurazione presente (' . ($file !== '' ? "file: {$file}" : 'json inline') . ')');

// 2) File leggibile + JSON valido + campi FCM necessari
$raw = $json;
if ($file !== '') {
    if (!is_readable($file)) {
        $bad("File non leggibile: {$file} (controlla permessi: 640 root:www-data)");
        exit(1);
    }
    $raw = (string)file_get_contents($file);
    $ok('File leggibile');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    $bad('Il service account NON e\' JSON valido');
    exit(1);
}

$projectId   = (string)($data['project_id'] ?? '');
$clientEmail = (string)($data['client_email'] ?? '');
$privateKey  = (string)($data['private_key'] ?? '');

if ($projectId !== '') {
    $ok("project_id: {$projectId}");
} else {
    $bad('Manca project_id (e\' il file giusto? deve avere "type":"service_account")');
}
if ($clientEmail !== '') {
    $ok('client_email presente');
} else {
    $bad('Manca client_email');
}
if (str_contains($privateKey, 'BEGIN PRIVATE KEY')) {
    $ok('private_key presente (forma RSA)');
} else {
    $bad('private_key assente o malformata');
}

if ($fails > 0) {
    echo "\nConfigurazione NON ok: correggere i punti FAIL.\n";
    exit(1);
}

// 3) FcmService la accetta?
$fcm = new FcmService($connection, $config);
if (!$fcm->configured()) {
    $bad('FcmService::configured() = false');
    exit(1);
}
$ok('FcmService pronta');

// 4) Invio di prova (opzionale): passa l'FCM token di un telefono registrato
$token = $argv[1] ?? '';
if ($token === '') {
    echo "\nConfigurazione OK. Per un invio reale:\n";
    echo "  php scripts/fcm_test.php <fcm_token>\n";
    echo "  (token dai log FCM, o da bb_push_devices dopo il primo login app)\n";
    exit(0);
}

echo "\nInvio notifica di prova a {$token} ...\n";
$sent = $fcm->sendToDevice($token, 'BOB — test', 'Notifica di prova dal server BOB', [
    'test' => 'fcm_test',
]);

if ($sent) {
    $ok('Notifica inviata: controlla il banner sul telefono (o i log FCM in Firebase)');
} else {
    $bad('Invio fallito: vedi storage/logs (errore FCM, token non valido, o rete)');
    echo "     Se il telefono ha appena fatto login, riprova tra qualche secondo.\n";
}
exit($sent ? 0 : 1);
