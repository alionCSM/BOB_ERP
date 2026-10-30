<?php
declare(strict_types=1);

/**
 * Costruisce il database di prova: le tabelle vecchie a mano (non esistono
 * migrazioni per quelle, sono nate col gestionale), le tabelle della Zone
 * facendo girare le migrazioni VERE con il mini-Phinx.
 *
 * Cosi' lo schema della Zone — quello che i test interrogano — e' lo stesso
 * che va in produzione, e se una migrazione cambia i test lo vedono.
 */

require __DIR__ . '/miniphinx.php';

/**
 * Dove si collegano le prove.
 *
 * Host, utente e password arrivano dal `.env` dell'installazione: sul
 * server di collaudo c'e' gia' un MySQL che funziona, e chiedere un secondo
 * database a mano e' un passaggio in cui ci si sbaglia.
 *
 * Il NOME del database no: quello e' sempre un altro, e lo creano le prove
 * se non c'e'. Cosi' non puo' succedere che puntino al database
 * dell'applicazione — che verrebbe svuotato alla prima riga.
 *
 * Si puo' forzare con ZONE_TEST_DB, purche' il nome contenga "test".
 *
 * @return array{dsn: string, utente: string, password: string, nome: string}
 */
function connessione(): array
{
    // Il .env sta "un livello sopra il repo", ma dov'e' il repo cambia da
    // un'installazione all'altra: si risale finche' non si trova.
    $env = [];
    for ($su = 2; $su <= 4 && !$env; $su++) {
        $env = leggiEnv(dirname(__DIR__, $su) . '/.env');
    }

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $porta = (int)($env['DB_PORT'] ?? 3306);
    $utente = $env['DB_USER'] ?? 'root';
    $password = $env['DB_PASS'] ?? '';

    // In locale non c'e' nessun .env: li' il MySQL di prova sta sulla 3307
    if (!$env) {
        $porta = 3307;
    }

    $nome = getenv('ZONE_TEST_DB') ?: 'bob_zone_test';

    if ($nome === ($env['DB_NAME'] ?? null)) {
        throw new RuntimeException(
            "Mi fermo: '$nome' e' il database dell'applicazione. "
            . 'Le prove svuotano quello a cui si collegano.'
        );
    }

    return [
        'dsn'      => "mysql:host=$host;port=$porta;charset=utf8mb4",
        'utente'   => $utente,
        'password' => $password,
        'nome'     => $nome,
    ];
}

/**
 * Legge un .env senza Dotenv: qui vendor/ non c'e' e non serve.
 *
 * @return array<string, string>
 */
function leggiEnv(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }
    $fuori = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $riga) {
        $riga = trim($riga);
        if ($riga === '' || $riga[0] === '#' || !str_contains($riga, '=')) {
            continue;
        }
        [$chiave, $valore] = explode('=', $riga, 2);
        $fuori[trim($chiave)] = trim(trim(trim($valore), '"'), "'");
    }
    return $fuori;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = connessione();
    $opzioni = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,   // come in produzione
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
        // prima senza database: se non c'e' lo si crea
        $radice = new PDO($c['dsn'], $c['utente'], $c['password'], $opzioni);
        $radice->exec("CREATE DATABASE IF NOT EXISTS `{$c['nome']}` CHARACTER SET utf8mb4");
    } catch (PDOException $e) {
        throw new RuntimeException(
            "Non riesco a collegarmi a MySQL ({$c['dsn']}, utente {$c['utente']}): "
            . $e->getMessage() . "\n"
            . "Le prove usano l'accesso del .env, su un database a parte ({$c['nome']}), "
            . "e provano a crearselo. Se quell'utente non puo' creare database, "
            . "si fa una volta sola da un utente che puo':

"
            . "  CREATE DATABASE {$c['nome']} CHARACTER SET utf8mb4;
"
            . "  GRANT ALL ON {$c['nome']}.* TO '{$c['utente']}'@'%';
"
            . "  FLUSH PRIVILEGES;
",
            0,
            $e
        );
    }

    return $pdo = new PDO($c['dsn'] . ";dbname={$c['nome']}", $c['utente'], $c['password'], $opzioni);
}

function costruisciSchema(PDO $pdo): void
{
    // ── La sicura ───────────────────────────────────────────────────────────
    // Qui sotto si fa DROP TABLE su tutto. Se qualcuno punta il DSN al
    // database di lavoro — o lancia le prove sul server — questa riga e'
    // l'unica cosa fra lui e un pomeriggio molto brutto. Il nome deve dire
    // che e' un database di prova.
    $nome = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/test/i', $nome)) {
        throw new RuntimeException(
            "Mi fermo: '$nome' non sembra un database di prova. "
            . 'Queste prove cancellano tutte le tabelle del database a cui si collegano, '
            . 'e il nome deve contenere "test". Con ZONE_TEST_DB se ne sceglie un altro.'
        );
    }

    // ── Via tutto ───────────────────────────────────────────────────────────
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query("SELECT table_name FROM information_schema.tables
                          WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // ── Le tabelle vecchie, solo le colonne che la Zone tocca ───────────────
    // bb_users e' copiata dallo scatto vero in db/schema/bb_users.sql: i due
    // ENUM (type, role) rifiutano valori fuori elenco, ed e' il genere di
    // cosa che un test deve incontrare.
    $pdo->exec("
        CREATE TABLE bb_users (
            id INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NULL,
            password VARCHAR(255) NULL,
            first_name VARCHAR(255) NOT NULL DEFAULT '',
            last_name VARCHAR(255) NOT NULL DEFAULT '',
            company VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(255) NULL,
            type ENUM('user','worker','client') NOT NULL DEFAULT 'worker',
            active ENUM('Y','N') NOT NULL DEFAULT 'Y',
            removed ENUM('Y','N') NOT NULL DEFAULT 'N',
            role ENUM('admin','manager','user','offerte','document_manager','company_viewer')
                 NOT NULL DEFAULT 'user',
            access_profile ENUM('INTERNAL','CLIENT','COMPANY','WORKER') NULL,
            company_id INT NULL,
            worker_id INT NULL,
            client_id INT NULL,
            lingua VARCHAR(5) NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE bb_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE bb_worksites (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            worksite_code VARCHAR(64) NULL,
            name VARCHAR(255) NOT NULL,
            location VARCHAR(255) NULL,
            client_id INT NULL,
            company_id INT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'In corso',
            is_draft TINYINT(1) NOT NULL DEFAULT 0,
            total_offer DECIMAL(14,2) NULL,
            ext_total_offer DECIMAL(14,2) NULL,
            order_number VARCHAR(64) NULL,
            start_date DATE NULL,
            yard_worksite_id INT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE bb_worksite_users (
            worksite_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (worksite_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE bb_worksite_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            worksite_id INT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(16) NULL,
            category VARCHAR(64) NULL,
            subcategory VARCHAR(128) NULL,
            note TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // I permessi: due tabelle, una per utente e una per societa'. Chi le
    // guarda deve guardarle tutte e due — e' gia' stata fonte di errori.
    foreach (['bb_user_permissions', 'bb_user_company_permissions'] as $t) {
        $extra = $t === 'bb_user_company_permissions' ? 'group_company_id INT NULL,' : '';
        $pdo->exec("
            CREATE TABLE $t (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                $extra
                module VARCHAR(64) NOT NULL,
                allowed TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_u (user_id, module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // i moduli accesi per societa': la migrazione del permesso Zone ci scrive
    // i dispositivi per il push: NotificationService li cerca sempre
    $pdo->exec("
        CREATE TABLE bb_push_devices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            fcm_token VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE bb_group_companies (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            tutti_moduli TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE bb_company_modules (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_company_id INT UNSIGNED NOT NULL,
            modulo VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mod (group_company_id, modulo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE bb_user_companies (
            user_id INT UNSIGNED NOT NULL,
            group_company_id INT UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, group_company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE bb_notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            link VARCHAR(500) NULL,
            category VARCHAR(64) NULL,
            priority VARCHAR(16) NULL,
            created_by INT UNSIGNED NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── Le tabelle della Zone: le migrazioni vere ──────────────────────────
    $migrazioni = [];
    foreach (glob(dirname(__DIR__, 1) . '/*') as $x) { /* niente */ }
    $dir = dirname(__DIR__, 2) . '/db/migrations';
    foreach (scandir($dir) as $f) {
        if (!preg_match('/^(\d+)_([a-z0-9_]+)\.php$/', $f, $m)) {
            continue;
        }
        // solo quelle della Zone: le altre toccano tabelle che qui non ci sono
        if (!preg_match('/zone|fieldwire/i', $f)) {
            continue;
        }
        $migrazioni[$m[1]] = $dir . '/' . $f;
    }
    ksort($migrazioni);

    foreach ($migrazioni as $file) {
        $prima = get_declared_classes();
        require_once $file;
        $nuove = array_diff(get_declared_classes(), $prima);
        foreach ($nuove as $classe) {
            if (!is_subclass_of($classe, \Phinx\Migration\AbstractMigration::class)) {
                continue;
            }
            $m = new $classe();
            $m->pdo = $pdo;
            $m->up();
        }
    }
}
