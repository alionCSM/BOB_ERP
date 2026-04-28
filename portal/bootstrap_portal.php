<?php
/**
 * Shared bootstrap for all portal sub-pages.
 *
 * Sets up APP_ROOT, autoloader, .env, and $_assetBase.
 * Sourced by both index.php and attestato.php.
 */

declare(strict_types=1);

// Walk up the directory tree to find the repo root.
$_portalDir = realpath(__DIR__);
$repoRoot   = null;
for ($_up = $_portalDir, $_i = 0; $_i < 4; $_up = dirname($_up), $_i++) {
    if (file_exists($_up . '/includes/bootstrap.php')) {
        $repoRoot = $_up;
        break;
    }
}
unset($_portalDir, $_up, $_i);

if ($repoRoot === null) {
    http_response_code(500);
    exit('Portal bootstrap error: cannot locate repo root.');
}

defined('APP_ROOT') || define('APP_ROOT', $repoRoot);

require_once $repoRoot . '/includes/bootstrap.php';

// Base URL for static assets
$_assetBase = rtrim($_ENV['APP_URL'] ?? '', '/');
