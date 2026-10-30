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

const DSN = 'mysql:host=127.0.0.1;port=3307;dbname=bob_test;charset=utf8mb4';

function db(): PDO
{
    $pdo = new PDO(DSN, 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,   // come in produzione
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function costruisciSchema(PDO $pdo): void
{
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
