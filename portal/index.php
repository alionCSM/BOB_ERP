<?php
/**
 * Public Document Sharing Portal
 *
 * Accessible at docs.csmontaggi.it
 * No BOB authentication required — uses link token + optional password.
 * Also serves the worker attestato page at /attestati/{uid}.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_portal.php';

// ── Dispatch: attestato page ──
// $_SERVER['REQUEST_URI'] can be /index.php when nginx try_files does
// an internal rewrite. Use REDIRECT_URL as fallback for the original URI.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
if ($uri === '/index.php') {
    $uri = $_SERVER['REDIRECT_URL']
        ?? $_SERVER['REDIRECT_URI']
        ?? $_SERVER['ORIG_PATH_INFO']
        ?? $_SERVER['ORIG_SCRIPT_URL']
        ?? $_SERVER['REQUEST_URI']
        ?? '/';
}
if (preg_match('#^/attestati/([a-f0-9]{8})$#', $uri)) {
    require_once __DIR__ . '/attestato.php';
    exit;
}

// ── Helper functions ──

function getClientIp(): string
{
    return $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

function portalError(string $title, string $message, int $status = 404): void
{
    http_response_code($status);
    portalHeader($title);
    ?>
        <div class="wrap">
            <div class="card" style="max-width:460px;">
                <div class="card-body" style="text-align:center;padding:48px 32px;">
                    <div style="width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <h2 style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:10px;"><?= htmlspecialchars($title) ?></h2>
                    <p style="font-size:14px;color:#64748b;line-height:1.6;"><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        </div>
    <?php
    portalFooter();
    exit;
}

function verifyRecaptcha(string $token): bool
{
    $secret = $_ENV['RECAPTCHA_SECRET_KEY'] ?? '';
    if ($secret === '') return true;

    $response = @file_get_contents(
        'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secret) . '&response=' . urlencode($token)
    );
    if (!$response) return false;

    $data = json_decode($response, true);
    return isset($data['success']) && $data['success'] && isset($data['score']) && $data['score'] >= 0.5;
}

function portalHeader(string $pageTitle): void
{
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($pageTitle) ?> - Consorzio Soluzione Montaggi</title>
        <link rel="stylesheet" href="<?= htmlspecialchars($GLOBALS['_assetBase']) ?>/assets/css/portal/index.css">
    </head>
    <body>
        <div class="topbar">
            <img src="https://bob.csmontaggi.it/includes/template/dist/images/logo.png" alt="Bob Logo" class="topbar-logo">
            <div class="topbar-divider"></div>
            <div class="topbar-brand">
                Consorzio Soluzione Montaggi
                <span>Portale Documenti</span>
            </div>
        </div>
    <?php
}

function portalFooter(): void
{
    ?>
        <footer class="portal-footer">
            &copy; <?= date('Y') ?> Consorzio Soluzione Montaggi &mdash; Tutti i diritti riservati
            <div class="footer-links">
                <a href="privacy.php">Privacy Policy</a>
            </div>
        </footer>
    </body>
    </html>
    <?php
}

// ── Validate token ──
$token = trim((string)($_GET['id'] ?? ''));
if ($token === '') {
    portalError('Link Non Trovato', 'Nessun link specificato. Contatta il mittente per ricevere il link corretto.');
}

$db = new Database();
$conn = $db->connect();
$repo = new SharedLinkRepository($conn);

$link = $repo->getLinkByToken($token);
if (!$link || !$link['is_active']) {
    portalError('Link Non Trovato', "Il link richiesto non esiste o non e' piu' attivo. Contatta il mittente.");
}

// ── Check expiry ──
if ($link['expires_at'] && strtotime($link['expires_at']) <= strtotime('today')) {
    $repo->deactivateLink((int)$link['id']);
    portalError('Link Scaduto', "Questo link e' scaduto. Se hai ancora bisogno di accedere ai documenti, contatta il mittente.", 410);
}

// ── Password verification ──
$requiresPassword = !empty($link['password']);
$isVerified = isset($_SESSION['verified_links'][$token]);

if ($requiresPassword && !$isVerified) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $enteredPassword = (string)($_POST['password'] ?? '');
        $recaptchaToken = (string)($_POST['recaptchaToken'] ?? '');

        if (!verifyRecaptcha($recaptchaToken)) {
            $errorMessage = 'Verifica captcha fallita. Riprova.';
        } elseif (!password_verify($enteredPassword, $link['password'])) {
            $errorMessage = 'Password non corretta. Riprova.';
        } else {
            $_SESSION['verified_links'][$token] = true;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    $siteKey = $_ENV['RECAPTCHA_SITE_KEY'] ?? '';
    portalHeader('Password Richiesta');
    ?>
        <div class="wrap">
            <div class="card" style="max-width:440px;">
                <div style="height:4px;background:linear-gradient(90deg,#1a237e,#3f51b5,#7c4dff);"></div>
                <div class="card-body" style="padding:44px 32px;text-align:center;">
                    <div style="width:64px;height:64px;background:#e0e7ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3f51b5" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                    <h2 style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:6px;">Documenti Condivisi</h2>
                    <p style="font-size:13px;color:#64748b;margin-bottom:24px;">Questo link richiede una password per accedere ai documenti.</p>

                    <?php if (!empty($errorMessage)): ?>
                        <p style="color:#dc2626;font-size:13px;margin-bottom:16px;padding:10px;background:#fef2f2;border-radius:8px;"><?= htmlspecialchars($errorMessage) ?></p>
                    <?php endif; ?>

                    <form id="passwordForm" method="POST">
                        <div style="margin-bottom:16px;text-align:left;">
                            <label style="display:block;font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">Password</label>
                            <input type="password" name="password" placeholder="Inserisci la password" required autofocus
                                   style="width:100%;height:44px;border:1.5px solid #e2e8f0;border-radius:10px;padding:0 14px;font-size:14px;color:#1e293b;outline:none;transition:border-color .15s,box-shadow .15s;"
                                   onfocus="this.style.borderColor='#3f51b5';this.style.boxShadow='0 0 0 3px rgba(63,81,181,0.12)'"
                                   onblur="this.style.borderColor='#e2e8f0';this.style.boxShadow='none'">
                        </div>
                        <input type="hidden" name="recaptchaToken" id="recaptchaToken">
                        <button type="submit" style="width:100%;height:44px;border:none;border-radius:10px;font-size:14px;font-weight:700;color:#fff;background:linear-gradient(135deg,#1a237e,#3f51b5);cursor:pointer;transition:opacity .15s;"
                                onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                            Accedi
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($siteKey): ?>
        <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($siteKey) ?>"></script>
        <script src="<?= htmlspecialchars($GLOBALS['_assetBase']) ?>/assets/js/portal/index.js"></script>
        <?php endif; ?>
    <?php
    portalFooter();
    exit;
}

// ── Fetch files LIVE (dynamic from linked workers/companies + manual uploads) ──
$files = $repo->getLiveFilesForLink((int)$link['id']);

$companies = [];
foreach ($files as $f) {
    $companyName = $f['company_name'] ?? $f['worker_company'] ?? 'Documenti';
    $companyName = strtoupper(trim($companyName));

    if (!isset($companies[$companyName])) {
        $companies[$companyName] = ['company_docs' => [], 'workers' => []];
    }

    if ($f['source'] === 'worker' && $f['worker_id']) {
        $workerName = trim(($f['worker_first_name'] ?? '') . ' ' . ($f['worker_last_name'] ?? ''));
        if ($workerName === '') $workerName = 'Operaio';
        if (!isset($companies[$companyName]['workers'][$workerName])) {
            $companies[$companyName]['workers'][$workerName] = [];
        }
        $companies[$companyName]['workers'][$workerName][] = $f;
    } else {
        $companies[$companyName]['company_docs'][] = $f;
    }
}

ksort($companies);

$linkTitle = htmlspecialchars($link['title']);
$totalFiles = count($files);
$expiresFormatted = $link['expires_at']
    ? date('d/m/Y', strtotime($link['expires_at']))
    : null;

portalHeader($link['title']);
?>

    <div class="container">

        <!-- Header card -->
        <div class="card header-card">
            <div class="header-accent"></div>
            <div class="header-body">
                <div class="header-left">
                    <div class="header-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <div class="header-title"><?= $linkTitle ?></div>
                        <div class="header-sub">
                            <?= $totalFiles ?> document<?= $totalFiles !== 1 ? 'i' : 'o' ?>
                            <?php if ($expiresFormatted): ?>
                                &nbsp;&middot;&nbsp; Scadenza: <?= $expiresFormatted ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($totalFiles > 0): ?>
                    <a href="zip.php?token=<?= urlencode($token) ?>" class="btn-zip">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Scarica Tutto
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- File tree -->
        <?php if (empty($companies)): ?>
            <div style="background:#fff;border-radius:12px;padding:48px 24px;text-align:center;border:2px dashed #e2e8f0;">
                <p style="font-size:15px;font-weight:600;color:#64748b;">Nessun documento disponibile</p>
            </div>
        <?php else: ?>
            <?php foreach ($companies as $companyName => $data): ?>
                <div class="company-block">
                    <button class="folder-toggle" onclick="this.classList.toggle('open')">
                        <svg class="folder-icon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                        <?= htmlspecialchars($companyName) ?>
                        <svg class="folder-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <div class="folder-content">

                        <?php if (!empty($data['company_docs'])): ?>
                            <button class="subfolder-toggle" onclick="this.classList.toggle('open')">
                                <svg class="subfolder-icon" width="16" height="16" viewBox="0 0 24 24" fill="#3b82f6" stroke="none"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                                Documenti Aziendali
                                <span style="font-size:11px;color:#94a3b8;font-weight:400;">(<?= count($data['company_docs']) ?>)</span>
                                <svg class="subfolder-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                            <div class="subfolder-content">
                                <?php foreach ($data['company_docs'] as $doc): ?>
                                    <div class="file-row">
                                        <svg class="file-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span class="file-name"><?= htmlspecialchars($doc['original_name']) ?></span>
                                        <a class="file-dl" href="download.php?source=<?= urlencode($doc['source']) ?>&doc=<?= (int)$doc['doc_id'] ?>&token=<?= urlencode($token) ?>">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                            Scarica
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($data['workers'])): ?>
                            <button class="subfolder-toggle" onclick="this.classList.toggle('open')">
                                <svg class="subfolder-icon" width="16" height="16" viewBox="0 0 24 24" fill="#8b5cf6" stroke="none"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                                Operai
                                <span style="font-size:11px;color:#94a3b8;font-weight:400;">(<?= count($data['workers']) ?>)</span>
                                <svg class="subfolder-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                            <div class="subfolder-content">
                                <?php foreach ($data['workers'] as $workerName => $workerDocs): ?>
                                    <button class="subfolder-toggle" onclick="this.classList.toggle('open')" style="padding-left:54px;">
                                        <svg class="subfolder-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <?= htmlspecialchars($workerName) ?>
                                        <span style="font-size:11px;color:#94a3b8;font-weight:400;">(<?= count($workerDocs) ?>)</span>
                                        <svg class="subfolder-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                    </button>
                                    <div class="subfolder-content">
                                        <?php foreach ($workerDocs as $doc): ?>
                                            <div class="file-row" style="padding-left:72px;">
                                                <svg class="file-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                <span class="file-name"><?= htmlspecialchars($doc['original_name']) ?></span>
                                                <a class="file-dl" href="download.php?source=<?= urlencode($doc['source']) ?>&doc=<?= (int)$doc['doc_id'] ?>&token=<?= urlencode($token) ?>">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                    Scarica
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

<?php
portalFooter();
