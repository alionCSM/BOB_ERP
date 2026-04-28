<?php
/**
 * Portal — Download All Files as ZIP
 *
 * Usage: zip.php?token={link_token}
 * ZIP structure: Company / Documenti Aziendali + Operai / WorkerName / files
 */

declare(strict_types=1);

// ── Bootstrap: autoloader + env + class aliases ──
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

session_start();

// ── Validate ──
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Token mancante.');
}

$db = new Database();
$conn = $db->connect();
$repo = new SharedLinkRepository($conn);

$link = $repo->getLinkByToken($token);
if (!$link || !$link['is_active']) {
    http_response_code(404);
    exit('Link non trovato.');
}

if ($link['expires_at'] && strtotime($link['expires_at']) <= strtotime('today')) {
    $repo->deactivateLink((int)$link['id']);
    http_response_code(410);
    exit('Link scaduto.');
}

if (!empty($link['password']) && !isset($_SESSION['verified_links'][$token])) {
    http_response_code(403);
    exit('Accesso non autorizzato.');
}

// ── Get files (LIVE — dynamic from linked workers/companies + manual uploads) ──
$files = $repo->getLiveFilesForLink((int)$link['id']);
if (empty($files)) {
    http_response_code(404);
    exit('Nessun file disponibile.');
}

try {
    $cloudBase = CloudPath::getRoot();
} catch (\RuntimeException $e) {
    http_response_code(500);
    exit('Errore di configurazione: CLOUD_ROOT non definito.');
}

// ── Build temp directory with folder structure ──
$tmpDir = sys_get_temp_dir() . '/portal_zip_' . uniqid('', true);
mkdir($tmpDir, 0755, true);

$addedFiles = 0;

foreach ($files as $f) {
    $companyName = $f['company_name'] ?? $f['worker_company'] ?? 'Documenti';
    $companyName = preg_replace('/[\/\\\\:*?"<>|]/', '_', trim($companyName));
    if ($companyName === '') $companyName = 'Documenti';

    $companyDir = $tmpDir . '/' . $companyName;

    $actualPath = realpath($cloudBase . '/' . $f['file_path']);
    if (!$actualPath || strpos($actualPath, $cloudBase) !== 0 || !is_file($actualPath)) {
        continue;
    }

    $ext = strtolower(pathinfo($actualPath, PATHINFO_EXTENSION));
    $docName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $f['original_name']);

    if ($f['source'] === 'worker' && $f['worker_id']) {
        // Worker document → Company/Operai/WorkerName/DocType.ext
        $workerName = trim(($f['worker_first_name'] ?? '') . ' ' . ($f['worker_last_name'] ?? ''));
        $workerName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $workerName) ?: 'Operaio';

        $workerDir = $companyDir . '/Operai/' . $workerName;
        if (!is_dir($workerDir)) {
            mkdir($workerDir, 0755, true);
        }

        $fileName = $docName;
        if (!str_contains($fileName, '.')) {
            $fileName .= '.' . $ext;
        }

        copy($actualPath, $workerDir . '/' . $fileName);
    } else {
        // Company/manual document → Company/Documenti Aziendali/DocType.ext
        $aziendaliDir = $companyDir . '/Documenti Aziendali';
        if (!is_dir($aziendaliDir)) {
            mkdir($aziendaliDir, 0755, true);
        }

        $fileName = $docName;
        if (!str_contains($fileName, '.')) {
            $fileName .= '.' . $ext;
        }

        copy($actualPath, $aziendaliDir . '/' . $fileName);
    }

    $addedFiles++;
}

if ($addedFiles === 0) {
    // Cleanup
    array_map('unlink', glob($tmpDir . '/**/*') ?: []);
    @rmdir($tmpDir);
    http_response_code(404);
    exit('Nessun file trovato sul server.');
}

// ── Create ZIP ──
$tmpZip = tempnam(sys_get_temp_dir(), 'portal_zip_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Impossibile creare archivio ZIP.');
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $realPath = $file->getRealPath();
        $relativePath = substr($realPath, strlen($tmpDir) + 1);
        $zip->addFile($realPath, $relativePath);
    }
}

$zip->close();

// ── Cleanup temp directory ──
$cleanup = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanup as $item) {
    $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
}
rmdir($tmpDir);

// ── Serve ZIP ──
$zipName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $link['title']) . '.zip';

ob_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache, no-store, must-revalidate');

readfile($tmpZip);
unlink($tmpZip);

// ── Log download ──
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
    ?? $_SERVER['REMOTE_ADDR']
    ?? '0.0.0.0';

try {
    $repo->logDownload((int)$link['id'], null, $ip, $zipName);
} catch (Throwable $e) {
    $logger = \App\Infrastructure\LoggerFactory::app();
    $logger->warning('Download log error: ' . $e->getMessage(), ['link_id' => (int)$link['id']]);
}
