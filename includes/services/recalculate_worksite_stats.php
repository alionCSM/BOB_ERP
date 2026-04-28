<?php
declare(strict_types=1);

/**
 * Runner for WorksiteMarginService.
 * Called via shell_exec() from WorksitesController::recalculateMargin().
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envDir = dirname(__DIR__, 3);
if (file_exists($envDir . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($envDir);
    $dotenv->load();
}

\App\Infrastructure\Config::validate();

$conn   = (new \App\Infrastructure\Database())->connect();
$mailer = new \App\Service\Mailer();
$appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');

$service = new \App\Service\WorksiteMarginService($conn, $mailer, $appUrl);
$service->run();

exit(0);
