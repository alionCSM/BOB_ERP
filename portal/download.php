<?php
/**
 * Portal — Individual File Download (Dynamic)
 *
 * New format:  download.php?source=worker|company|manual&doc={doc_id}&token={link_token}
 * Legacy:      download.php?file={file_id}&token={link_token}  (treated as manual)
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

// ── Validate params ──
$source = trim((string)($_GET['source'] ?? ''));
$docId  = (int)($_GET['doc'] ?? 0);
$token  = trim((string)($_GET['token'] ?? ''));

// Legacy compat: ?file=X&token=Y (old manual-only format)
if ($source === '' && isset($_GET['file'])) {
    $source = 'manual';
    $docId  = (int)$_GET['file'];
}

if ($token === '' || $docId <= 0 || !in_array($source, ['worker', 'company', 'manual'], true)) {
    http_response_code(400);
    exit('Richiesta non valida.');
}

$db = new Database();
$conn = $db->connect();
$repo = new SharedLinkRepository($conn);

// ── Validate link ──
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

// ── Check password session ──
if (!empty($link['password']) && !isset($_SESSION['verified_links'][$token])) {
    http_response_code(403);
    exit('Accesso non autorizzato.');
}

$linkId = (int)$link['id'];

// ── Resolve file path based on source ──
try {
    $cloudBase = CloudPath::getRoot();
} catch (\RuntimeException $e) {
    http_response_code(500);
    exit('Errore di configurazione: CLOUD_ROOT non definito.');
}

$filePath = null;
$downloadName = null;

switch ($source) {
    case 'worker':
        // Verify this worker is linked to this shared link
        $linkedWorkers = $repo->getLinkedWorkers($linkId);
        $doc = $repo->getWorkerDocumentById($docId);
        if (!$doc) {
            http_response_code(404);
            exit('Documento non trovato.');
        }
        // Check the worker is actually linked
        $workerLinked = false;
        foreach ($linkedWorkers as $lw) {
            if ((int)$lw['worker_id'] === (int)$doc['worker_id']) {
                $workerLinked = true;
                break;
            }
        }
        if (!$workerLinked) {
            http_response_code(403);
            exit('Accesso non autorizzato a questo documento.');
        }
        $filePath = $doc['path'];
        $downloadName = $doc['tipo_documento'];
        break;

    case 'company':
        // Verify this company is linked to this shared link
        $linkedCompanies = $repo->getLinkedCompanies($linkId);
        $doc = $repo->getCompanyDocumentById($docId);
        if (!$doc) {
            http_response_code(404);
            exit('Documento non trovato.');
        }
        $companyLinked = false;
        foreach ($linkedCompanies as $lc) {
            if ((int)$lc['company_id'] === (int)$doc['company_id']) {
                $companyLinked = true;
                break;
            }
        }
        if (!$companyLinked) {
            http_response_code(403);
            exit('Accesso non autorizzato a questo documento.');
        }
        $filePath = $doc['file_path'];
        $downloadName = $doc['tipo_documento'];
        break;

    case 'manual':
        // Find in bb_shared_link_files for this link
        $files = $repo->getFilesForLink($linkId);
        $targetFile = null;
        foreach ($files as $f) {
            if ($f['source'] === 'manual' && (int)$f['id'] === $docId) {
                $targetFile = $f;
                break;
            }
        }
        if (!$targetFile) {
            http_response_code(404);
            exit('File non trovato.');
        }
        $filePath = $targetFile['file_path'];
        $downloadName = $targetFile['original_name'];
        break;
}

if (!$filePath) {
    http_response_code(404);
    exit('File non trovato.');
}

// ── Resolve actual path & prevent traversal ──
$actualPath = realpath($cloudBase . '/' . $filePath);
if (!$actualPath || strpos($actualPath, $cloudBase) !== 0 || !is_file($actualPath)) {
    http_response_code(404);
    exit('File non trovato sul server.');
}

// ── Determine MIME and filename ──
$ext = strtolower(pathinfo($actualPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'zip' => 'application/zip',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

if (!str_contains($downloadName, '.')) {
    $downloadName .= '.' . $ext;
}

// ── Serve file ──
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($actualPath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($actualPath);

// ── Log download ──
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
    ?? $_SERVER['REMOTE_ADDR']
    ?? '0.0.0.0';

try {
    $repo->logDownload($linkId, $docId, $ip, $downloadName);
} catch (Throwable $e) {
    $logger = \App\Infrastructure\LoggerFactory::app();
    $logger->warning('Download log error: ' . $e->getMessage(), ['link_id' => $linkId, 'doc_id' => $docId]);
}
