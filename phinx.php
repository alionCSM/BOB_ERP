<?php
/**
 * Phinx configuration for BOB ERP.
 *
 * Loads the same .env the application uses (one level above the repo, as
 * established by includes/bootstrap.php). DB creds are NOT duplicated here —
 * we read from $_ENV so dev/staging/prod each get the right database via
 * their own .env file.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds'      => __DIR__ . '/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'production',
        'production' => [
            'adapter'    => 'mysql',
            'host'       => $_ENV['DB_HOST'] ?? 'localhost',
            'name'       => $_ENV['DB_NAME'] ?? '',
            'user'       => $_ENV['DB_USER'] ?? '',
            'pass'       => $_ENV['DB_PASS'] ?? '',
            'port'       => (int)($_ENV['DB_PORT'] ?? 3306),
            'charset'    => 'utf8mb4',
            'collation'  => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
