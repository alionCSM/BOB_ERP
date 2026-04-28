<?php
/**
 * BOB migration runner.
 *
 * Discovers .sql files in db/migrations/ (sorted by filename), tracks which
 * have been applied in the bb_migrations table, and runs the rest in a
 * transaction each.
 *
 * Usage:
 *   php bin/migrate.php           # apply pending migrations
 *   php bin/migrate.php status    # list applied + pending, run nothing
 *
 * Convention: name files <YYYY_MM_DD>_<NNN>_<short_description>.sql so
 * lexicographic sort = chronological sort.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

$command = $argv[1] ?? 'migrate';
if (!in_array($command, ['migrate', 'status'], true)) {
    fwrite(STDERR, "Unknown command: {$command}\nUsage: php bin/migrate.php [migrate|status]\n");
    exit(2);
}

$migrationsDir = APP_ROOT . '/db/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(2);
}

try {
    $db   = new App\Infrastructure\Database();
    $conn = $db->connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: {$e->getMessage()}\n");
    exit(2);
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure tracking table exists
$conn->exec("
    CREATE TABLE IF NOT EXISTS bb_migrations (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        filename    VARCHAR(255) NOT NULL UNIQUE,
        applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Already-applied set
$applied = [];
foreach ($conn->query("SELECT filename FROM bb_migrations")->fetchAll(PDO::FETCH_COLUMN) as $f) {
    $applied[$f] = true;
}

// Discover files
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pending = [];
foreach ($files as $path) {
    $name = basename($path);
    if (!isset($applied[$name])) {
        $pending[] = $path;
    }
}

if ($command === 'status') {
    echo "Applied (" . count($applied) . "):\n";
    foreach (array_keys($applied) as $name) {
        echo "  ✓ {$name}\n";
    }
    echo "\nPending (" . count($pending) . "):\n";
    foreach ($pending as $path) {
        echo "  · " . basename($path) . "\n";
    }
    exit(0);
}

if (empty($pending)) {
    echo "No pending migrations.\n";
    exit(0);
}

$insert = $conn->prepare("INSERT INTO bb_migrations (filename, applied_at) VALUES (:f, NOW())");

foreach ($pending as $path) {
    $name = basename($path);
    echo "→ {$name} ... ";

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        echo "SKIP (empty)\n";
        continue;
    }

    try {
        // Each migration runs in its own transaction. Note: MySQL DDL is
        // implicitly committed, so for ALTER/CREATE statements the rollback
        // semantics are best-effort. We still wrap in a transaction so that
        // any DML in the same file rolls back on error.
        $conn->beginTransaction();
        $conn->exec($sql);
        $insert->execute([':f' => $name]);
        $conn->commit();
        echo "OK\n";
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo "FAIL\n";
        fwrite(STDERR, "Migration {$name} failed: {$e->getMessage()}\n");
        fwrite(STDERR, "Stopping. Fix the migration and re-run.\n");
        exit(1);
    }
}

echo "\nDone. Applied " . count($pending) . " migration(s).\n";
