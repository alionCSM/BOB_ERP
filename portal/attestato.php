<?php
/**
 * Worker Document Attestato
 *
 * Public page accessible at /attestati/{uid}
 * Shows a worker's name, company, and document expiry status.
 * Used for tesserino QR codes — scanned by site security.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_portal.php';

// ── Validate uid ──
// $_SERVER['REQUEST_URI'] can be /index.php when nginx try_files does
// an internal rewrite. Use REDIRECT_URL as fallback.
$uri = $_SERVER['REQUEST_URI'] ?? '';
if ($uri === '/index.php') {
    $uri = $_SERVER['REDIRECT_URL']
        ?? $_SERVER['REDIRECT_URI']
        ?? $_SERVER['ORIG_PATH_INFO']
        ?? $_SERVER['ORIG_SCRIPT_URL']
        ?? $_SERVER['REQUEST_URI']
        ?? '';
}
if (!preg_match('#^/attestati/([a-f0-9]{8})$#', $uri, $matches)) {
    http_response_code(404);
    attestatoHeader('ID Non Valido');
    echo '<div class="error-card">';
    echo '<h2>ID Non Valido</h2>';
    echo '<p>Il formato dell\'ID non e\' valido. Deve essere un codice alfanumerico di 8 caratteri.</p>';
    echo '</div>';
    attestatoFooter();
    exit;
}

$uid = $matches[1];

// ── Connect to database ──
$db = new Database();
$conn = $db->connect();

// ── Fetch worker (active only) ──
$stmt = $conn->prepare(
    "SELECT w.id, w.uid, w.first_name, w.last_name, w.company, w.type_worker, w.active
     FROM bb_workers w
     WHERE w.uid = ? AND w.active = 'Y' AND w.removed = 'N'
     LIMIT 1"
);
$stmt->execute([$uid]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    http_response_code(404);
    attestatoHeader('Worker Non Trovato');
    echo '<div class="error-card">';
    echo '<h2>Worker Non Trovato</h2>';
    echo '<p>Nessun worker attivo trovato con questo ID. Contatta il tuo responsabile.</p>';
    echo '</div>';
    attestatoFooter();
    exit;
}

// ── Fetch documents ──
$stmt = $conn->prepare(
    "SELECT d.id, d.tipo_documento, d.data_emissione, d.scadenza
     FROM bb_worker_documents d
     WHERE d.worker_id = ?
     ORDER BY d.scadenza ASC, d.tipo_documento ASC"
);
$stmt->execute([(int)$worker['id']]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');

// ── Status helpers ──
function docStatus(string $expiry, string $today): string
{
    if (empty($expiry)) {
        return 'no_expiry';
    }
    $normalized = $expiry;
    foreach (['%Y-%m-%d', '%d/%m/%Y', '%d-%m-%Y'] as $fmt) {
        $parsed = date_create_from_format($fmt, $expiry);
        if ($parsed) {
            $normalized = $parsed->format('Y-m-d');
            break;
        }
    }
    return $normalized < $today ? 'expired' : 'valid';
}

function docStatusLabel(string $status): string
{
    return match ($status) {
        'expired'   => 'SCADUTO',
        'no_expiry' => 'N/D',
        default     => 'REGOLARE',
    };
}

function formatDate(?string $date): string
{
    if (empty($date)) return '—';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $parts = explode('-', $date);
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    foreach (['%d/%m/%Y', '%d-%m-%Y'] as $fmt) {
        $parsed = date_create_from_format($fmt, $date);
        if ($parsed) {
            return $parsed->format('d/m/Y');
        }
    }
    return htmlspecialchars($date);
}

$typeLabels = [
    'OPERAIO'  => 'Operaio',
    'PREPOSTO' => 'Preposto',
    'LEGALE_RAPPRESENTANTE' => 'Legale Rappresentante',
];
$typeLabel = $typeLabels[$worker['type_worker']] ?? $worker['type_worker'];

attestatoHeader('Attestati — ' . $worker['first_name'] . ' ' . $worker['last_name']);
?>

<div class="container">

    <!-- Worker header -->
    <div class="worker-card">
        <div class="worker-avatar">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div class="worker-info">
            <div class="worker-name"><?= htmlspecialchars($worker['first_name']) ?> <?= htmlspecialchars($worker['last_name']) ?></div>
            <div class="worker-meta">
                <?= htmlspecialchars($worker['company'] ?: 'N/D') ?>
                &nbsp;&middot;&nbsp;
                <span class="worker-type"><?= $typeLabel ?></span>
                &nbsp;&middot;&nbsp;
                <span class="worker-badge <?= $worker['active'] === 'Y' ? 'active' : 'inactive' ?>">
                    <?= $worker['active'] === 'Y' ? 'ATTIVO' : 'DISATTIVATO' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Documents table -->
    <?php if (empty($docs)): ?>
        <div class="empty-card">
            <p>Nessun documento registrato</p>
        </div>
    <?php else: ?>
        <div class="doc-card">
            <div class="doc-header">
                <span>Tipo</span>
                <span>Emissione</span>
                <span>Scadenza</span>
                <span>Stato</span>
            </div>
            <?php foreach ($docs as $doc): ?>
                <?php $status = docStatus($doc['scadenza'], $today); ?>
                <div class="doc-row <?= $status === 'expired' ? 'doc-expired' : '' ?>">
                    <span class="doc-name"><?= htmlspecialchars($doc['tipo_documento']) ?></span>
                    <span class="doc-date"><?= formatDate($doc['data_emissione']) ?></span>
                    <span class="doc-date"><?= formatDate($doc['scadenza']) ?></span>
                    <span class="doc-status <?= $status === 'expired' ? 'doc-expired' : ($status === 'no_expiry' ? 'doc-na' : 'doc-valid') ?>">
                        <?= docStatusLabel($status) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php
attestatoFooter();

function attestatoHeader(string $title): void
{
    global $_assetBase;
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link rel="stylesheet" href="<?= htmlspecialchars($_assetBase) ?>/assets/css/portal/attestato.css">
    </head>
    <body>
        <div class="topbar">
            <div class="topbar-brand">
                Consorzio Soluzione Montaggi
                <span>Attestati</span>
            </div>
        </div>
    <?php
}

function attestatoFooter(): void
{
    ?>
        <footer class="attestato-footer">
            &copy; <?= date('Y') ?> Consorzio Soluzione Montaggi &mdash; Documento interno
        </footer>
    </body>
    </html>
    <?php
}
