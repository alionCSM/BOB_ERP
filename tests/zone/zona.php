<?php
declare(strict_types=1);

/**
 * Il banco di prova della Zone: un cantiere, sei persone, i tre tipi di
 * utente che ci lavorano dentro.
 *
 * Gira su MariaDB vero (porta 3307, datadir separato) con lo schema
 * costruito dalle migrazioni vere. Niente SQLite: meta' delle query della
 * Zone usano cose che SQLite non ha (INSERT IGNORE, ON DUPLICATE KEY,
 * ENUM, FIELD()), e un test che non le esegue non prova che funzionino.
 */

require __DIR__ . '/schema.php';

$repo = dirname(__DIR__, 2);

// Config legge $_ENV: senza, FCM e il resto restano spenti (che e' quello
// che vogliamo: qui non si manda niente fuori).
$_ENV['APP_ENV'] = 'test';

require $repo . '/src/Infrastructure/Config.php';
require $repo . '/src/Http/Response.php';
require $repo . '/src/Security/AccessControl.php';
require $repo . '/src/Service/Lingua.php';
require $repo . '/src/Service/Zone/Accesso.php';
require $repo . '/src/Service/Zone/Condivisione.php';
require $repo . '/src/Service/Zone/Messaggi.php';
require $repo . '/src/Service/Zone/Segnalazioni.php';
require $repo . '/src/Service/Zone/AvvisiZona.php';
require $repo . '/src/Service/Zone/ScadenzeModuli.php';
require $repo . '/src/Service/Zone/LeMie.php';
require $repo . '/src/Repository/Zone/AccessoRepository.php';
require $repo . '/src/Repository/Fieldwire/ZoneTaskRepository.php';
require $repo . '/src/Repository/Fieldwire/ZoneFileRepository.php';
require $repo . '/src/Repository/Fieldwire/ZoneFormRepository.php';

use App\Security\AccessControl;
use App\Service\Zone\Accesso;

// ── I numeri delle persone e dei cantieri, una volta sola ──────────────────
const SUPER    = 1;   // superadmin
const ANNA     = 2;   // ufficio, dentro al cantiere
const LUCA     = 3;   // ufficio, FUORI dal cantiere (ha solo il modulo)
const MARCO    = 4;   // capo squadra
const ILIR     = 5;   // operaio
const NERI     = 6;   // cliente (geometra del committente)
const ESTRANEO = 7;   // operaio di un altro cantiere

const CANTIERE = 10;  // "nostro", col cliente condiviso
const ALTRO    = 20;  // un altro cantiere, per i confronti

/**
 * Un utente come lo vedono i servizi della Zone: id e canAccess().
 *
 * canAccess passa dal vero AccessControl, che legge le due tabelle dei
 * permessi: cosi' il test non si inventa come funziona un permesso.
 */
final class Utente
{
    public function __construct(
        public int $id,
        private PDO $conn,
        public string $type = 'user',
        public ?int $client_id = null,
    ) {}

    public function canAccess(string $modulo): bool
    {
        // societa' 1: l'unica di prova, con tutti i moduli accesi
        return (new AccessControl($this->conn))->puo($this->id, 1, $modulo);
    }
}

function semina(PDO $pdo): void
{
    // AccessControl tiene i permessi in cache statica per tutta la
    // richiesta: fra due semine va svuotata, se no la seconda legge la prima
    foreach (['permessi', 'moduliSocieta'] as $p) {
        $r = new ReflectionProperty(AccessControl::class, $p);
        $r->setAccessible(true);
        $r->setValue([]);
    }

    foreach (['bb_zone_condivisioni', 'bb_zone_accessi', 'bb_worksite_users',
              'bb_zone_task_comments', 'bb_zone_tasks', 'bb_zone_files',
              'bb_zone_folders', 'bb_zone_form_submissions', 'bb_zone_form_assegnazioni',
              'bb_zone_form_templates', 'bb_worksite_documents', 'bb_notifications',
              'bb_zone_messaggi', 'bb_zone_segnalazioni', 'bb_zone_letture',
              'bb_users', 'bb_worksites', 'bb_clients', 'bb_user_permissions',
              'bb_company_modules', 'bb_group_companies'] as $t) {
        $pdo->exec("DELETE FROM `$t`");
    }

    $pdo->exec("INSERT INTO bb_group_companies (id, nome, tutti_moduli) VALUES (1, 'Consorzio', 1)");
    $pdo->exec("INSERT INTO bb_clients (id, name) VALUES (1, 'Committente spa'), (2, 'Altro committente')");

    $pdo->exec("
        INSERT INTO bb_worksites (id, worksite_code, name, location, client_id, status, zone_cliente)
        VALUES (" . CANTIERE . ", 'C-412', 'Capannone Nord', 'Brescia', 1, 'In corso', 1),
               (" . ALTRO . ",    'C-500', 'Ponte Sud',      'Verona',  2, 'In corso', 1)
    ");

    $utenti = [
        [SUPER,    'super', 'Super',  'Admin',   'user',   null],
        [ANNA,     'anna',  'Anna',   'Bianchi', 'user',   null],
        [LUCA,     'luca',  'Luca',   'Conti',   'user',   null],
        [MARCO,    'marco', 'Marco',  'Rossi',   'user',   null],
        [ILIR,     'ilir',  'Ilir',   'Bequiri', 'worker', null],
        [NERI,     'neri',  'Geom.',  'Neri',    'client', 1],
        [ESTRANEO, 'tizio', 'Tizio',  'Altrove', 'worker', null],
    ];
    $st = $pdo->prepare("INSERT INTO bb_users (id, username, first_name, last_name, type, client_id, lingua)
                         VALUES (?, ?, ?, ?, ?, ?, 'it')");
    foreach ($utenti as $u) {
        $st->execute($u);
    }

    // Il modulo Zone ce l'hanno Anna e Luca. Luca pero' in questo cantiere
    // non c'e': e' tutto il punto della prova.
    //
    // Si passa da salvaPermessi(), non da una INSERT a mano: i permessi
    // vivono in bb_user_company_permissions (per societa') e vengono
    // proiettati in bb_user_permissions. Scriverne una sola delle due
    // significa provare una situazione che in BOB non esiste — ed e'
    // esattamente l'errore che avevo fatto la prima volta.
    $ac = new AccessControl($pdo);
    foreach ([ANNA, LUCA] as $uid) {
        $ac->salvaPermessi($uid, 1, ['zone' => true, 'worksites' => true]);
    }

    // Chi accede al cantiere, coi ruoli
    $repo = new \App\Repository\Zone\AccessoRepository($pdo);
    $repo->salva(CANTIERE, ANNA,  SUPER, Accesso::UFFICIO);
    $repo->salva(CANTIERE, MARCO, SUPER, Accesso::CANTIERE);
    $repo->salva(CANTIERE, ILIR,  SUPER, Accesso::CANTIERE);
    $repo->salva(CANTIERE, NERI,  SUPER, Accesso::CLIENTE);
    $repo->salva(ALTRO,    ESTRANEO, SUPER, Accesso::CANTIERE);
}

/** Le sei persone, pronte da passare ai servizi. */
function utenti(PDO $pdo): array
{
    return [
        'super'    => new Utente(SUPER, $pdo),
        'ufficio'  => new Utente(ANNA, $pdo),
        'fuori'    => new Utente(LUCA, $pdo),
        'capo'     => new Utente(MARCO, $pdo),
        'operaio'  => new Utente(ILIR, $pdo, 'worker'),
        'cliente'  => new Utente(NERI, $pdo, 'client', 1),
        'estraneo' => new Utente(ESTRANEO, $pdo, 'worker'),
    ];
}

// ── Il minimo per contare i risultati ──────────────────────────────────────
final class Prove
{
    public int $ok = 0;
    public int $ko = 0;
    private array $falliti = [];

    public function check(string $cosa, bool $esito): void
    {
        if ($esito) { $this->ok++; return; }
        $this->ko++;
        $this->falliti[] = $cosa;
        echo "  FALLITO: $cosa\n";
    }

    public function uguale(string $cosa, $atteso, $avuto): void
    {
        $this->check($cosa . ' (atteso ' . json_encode($atteso) . ', avuto ' . json_encode($avuto) . ')',
                     $atteso === $avuto);
    }

    public function esito(string $titolo): int
    {
        echo "\n$titolo: {$this->ok} passati, {$this->ko} falliti\n";
        return $this->ko === 0 ? 0 : 1;
    }
}
