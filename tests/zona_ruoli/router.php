<?php
// Banco di prova della Zone: i controller veri, un database di prova, un
// utente scelto con l'intestazione X-Test-User. Le email non partono:
// finiscono nella tabella test_email.
declare(strict_types=1);
const ERP = __DIR__ . '/../..';
define('APP_ROOT', ERP);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/bob_zona_errori.log');

$_ENV['APP_URL'] = 'https://bob.test';
$_ENV['CLOUD_ROOT'] = sys_get_temp_dir() . '/bob_zona_cloud';
// la cartella cloud dei documenti di operai e aziende (Doc condivisi): di prova
define('BOB_CLOUD_DOCUMENTI', sys_get_temp_dir() . '/bob_zona_docs');
foreach (['az/durc.pdf', 'az/visura.pdf', 'op/quota.pdf', 'shared_uploads/pos.pdf'] as $f) {
    $p = BOB_CLOUD_DOCUMENTI . '/' . $f;
    if (!is_file($p)) { @mkdir(dirname($p), 0775, true); file_put_contents($p, "%PDF-1.4 documento di prova $f"); }
}

require __DIR__ . '/pagina.php';
spl_autoload_register(function ($c) {
    $f = ERP . '/src/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($f)) require $f;
});
class_alias(\App\Domain\User::class, 'User');
if (!class_exists('CloudPath')) {
    // la cartella dei file: quella di prova
    class CloudPath {
        static function root(): string { return sys_get_temp_dir() . '/bob_zona_cloud'; }
        static function getRoot(): string { return self::root(); }
        static function ensureZoneFilesDir(int $w): string { $p = self::root() . "/BOBZone/$w/files"; @mkdir($p, 0775, true); return $p; }
        static function relativeToRoot(string $p): string { return ltrim(substr($p, strlen(self::root())), '/'); }
        static function ensureZonePhotosDir(int $w): string { $p = self::root() . "/BOBZone/$w/photos"; @mkdir($p, 0775, true); return $p; }
    }
}

// L'email: si scrive in tabella invece di partire
eval('namespace App\Service; class Mailer {
    private $m;
    function __construct() { $this->m = new \FakeMail(); }
    function setSender(string $t): void {}
    function getMailer() { return $this->m; }
}');
class FakeMail {
    public $Subject = ''; public $Body = ''; public $AltBody = ''; private $a = [];
    function addAddress($x) { $this->a[] = $x; }
    function send() {
        $GLOBALS['db_connection']->prepare('INSERT INTO test_email (a, oggetto, corpo) VALUES (?,?,?)')
            ->execute([implode(',', $this->a), $this->Subject, $this->Body]);
        return true;
    }
}

require ERP . '/src/Http/Controllers/FieldwireController.php';
require ERP . '/src/Http/Controllers/ZoneDialogoController.php';
error_reporting(E_ALL & ~E_DEPRECATED);
$pdo = new PDO('mysql:unix_socket=/tmp/mysql.sock;dbname=zonatest;charset=utf8mb4', 'alion', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['db_connection'] = $pdo;

// Dompdf qui non c'e': al suo posto uno che restituisce l'HTML, cosi' il
// rapporto si legge come testo
eval('namespace Dompdf; class Options { function set($k, $v) {} }
class Dompdf { private $h = ""; function __construct($o = null) {} function loadHtml($h, $e = null) { $this->h = $h; }
  function setPaper($a, $b) {} function render() {} function output() { return $this->h; } }');

// Chi sta chiamando. Il modulo Zone di BOB ce l'hanno solo 5 e 6 (ufficio).
class TestUser extends \App\Domain\User {
    public function __construct() {}
    public function canAccess(string $module): bool { return in_array((int)$this->id, [5, 6], true) && $module === 'zone'; }
}
// chi apre la pagina nel browser sceglie l'utente con ?u=, che resta in un cookie
if (isset($_GET['u'])) { setcookie('u', (string)(int)$_GET['u'], 0, '/'); $_SERVER['HTTP_X_TEST_USER'] = (string)(int)$_GET['u']; }
elseif (!isset($_SERVER['HTTP_X_TEST_USER']) && isset($_COOKIE['u'])) { $_SERVER['HTTP_X_TEST_USER'] = $_COOKIE['u']; }
$uid = (int)($_SERVER['HTTP_X_TEST_USER'] ?? 0);
if ($uid) {
    $u = new TestUser();
    $r = $pdo->query('SELECT * FROM bb_users WHERE id = ' . $uid)->fetch(PDO::FETCH_ASSOC);
    foreach ($r as $k => $v) { try { $u->$k = $v; } catch (\Throwable $e) {} }
    $GLOBALS['user'] = $u;
}

// Le rotte della Zone, lette da public/index.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// "un documento e' stato caricato in BOB" (Documenti o Aziende): chiude le richieste del link
if ($path === '/__prova/documento-caricato') {
    \App\Service\Zone\RichiesteLink::dopoDocumento($pdo, (string)$_GET['chi'], (int)$_GET['id'], null, (int)($_GET['da'] ?? 5));
    echo 'ok'; exit;
}
if ($path === '/__prova/contrasto.js') { header('Content-Type: application/javascript'); readfile(__DIR__ . '/contrasto.js'); exit; }
// i file statici della Zone (js, css) vengono dal progetto
if (str_starts_with($path, '/assets/')) {
    $f = ERP . '/public' . $path;
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: ' . (str_ends_with($f, '.css') ? 'text/css' : 'application/javascript'));
    readfile($f); exit;
}
// il file di un disegno: nel banco di prova una pianta finta, per provare il visore nel browser
if (preg_match('#^/worksites/\d+/disegni/\d+/view$#', $path)) { header('Content-Type: application/pdf'); readfile(__DIR__ . '/disegno_prova.pdf'); exit; }
$verbo = strtolower($_SERVER['REQUEST_METHOD']);
preg_match_all("#->(get|post)\s*\(\s*'(/worksites/\{id\}/zone[^']*)',\s*\[(\w+)::class,\s*'(\w+)'\]#", file_get_contents(ERP . '/public/index.php'), $m, PREG_SET_ORDER);
foreach ($m as [, $v, $rotta, $classe, $metodo]) {
    if ($v !== $verbo) continue;
    $re = '#^' . preg_replace('#\\\\\{(\w+)\\\\\}#', '(?P<$1>[^/]+)', preg_quote($rotta, '#')) . '$#';
    if (!preg_match($re, $path, $p)) continue;
    $req = new \App\Http\Request();
    $req->params = array_filter($p, 'is_string', ARRAY_FILTER_USE_KEY);
    $config = new \App\Infrastructure\Config(['APP_URL' => 'https://bob.test']);
    $c = $classe === 'FieldwireController'
        ? new \FieldwireController($config, new \App\Repository\Worksites\WorksiteRepository($pdo), $pdo)
        : new \ZoneDialogoController($config, $pdo);
    $c->$metodo($req);
    exit;
}
http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'rotta di prova non trovata: ' . $verbo . ' ' . $path]);
