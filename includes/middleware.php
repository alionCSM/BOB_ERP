<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers/csrf.php';

use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Infrastructure\Config;

define('BASE_URL', '/public/');

$db         = new Database();
$connection = $db->connect();

$config       = new Config();
$cookieDomain = parse_url($config->appUrl(), PHP_URL_HOST) ?: '';

// ── Authentication ────────────────────────────────────────────────────────────
// Sets $GLOBALS['user'] and $GLOBALS['authenticated_user'] or redirects to /login
(new AuthMiddleware($connection, $cookieDomain))->handle();

/** @var \User $user */
$user               = $GLOBALS['user'];
$authenticated_user = $GLOBALS['authenticated_user'];

// ── Security headers ──────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$onlyOfficeHost = parse_url($config->onlyOfficeUrl(), PHP_URL_HOST);
$frameAllowList = "'self'" . ($onlyOfficeHost ? " https://{$onlyOfficeHost}" : '');
$cspNonce       = csp_nonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.ckeditor.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.ckeditor.com; font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: blob:; frame-src {$frameAllowList}; object-src 'none'; base-uri 'self'; form-action 'self'; connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.ckeditor.com;");

// ── Force password change ─────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

if (!empty($user->must_change_password) && $uri !== '/change-password') {
    header('Location: /change-password');
    exit;
}

// ── Authorization services ────────────────────────────────────────────────────
$authorization   = new AuthorizationService(new AccessProfileResolver());
$capabilityService = new CapabilityService();
$routePolicyMap  = new RoutePolicyMap();
$scopeService    = new ScopeService();

// ── Company-scoped user restriction ──────────────────────────────────────────
$companyScopedIds = [];
$mapStmt          = $connection->query("SHOW TABLES LIKE 'bb_user_company_access'");
$hasCompanyMap    = $mapStmt && $mapStmt->fetch(PDO::FETCH_NUM);

if ($hasCompanyMap) {
    $cmpStmt = $connection->prepare('SELECT company_id FROM bb_user_company_access WHERE user_id = :uid');
    $cmpStmt->execute([':uid' => $user->id]);
    $companyScopedIds = array_map('intval', $cmpStmt->fetchAll(PDO::FETCH_COLUMN));
}

$isCompanyScopedUser = $authorization->isCompanyScopedUser($user, $companyScopedIds);

if ($isCompanyScopedUser) {
    $allowedCompanyIds = $authorization->allowedCompanyIds($user, $companyScopedIds);

    if (!$routePolicyMap->isCompanyScopedRouteAllowed($uri)) {
        http_response_code(403);
        exit('Access denied');
    }

    $isCompanyScopedPageRequest = preg_match('#^/(company_details\.php|views/companies/company_details\.php|companies/\d+)#', $uri)
        || isset($_GET['company_id']);

    $requestedCompanyId = $_GET['company_id'] ?? ($_GET['id'] ?? null);
    if ($requestedCompanyId === null && preg_match('#^/companies/(\d+)#', $uri, $m)) {
        $requestedCompanyId = $m[1];
    }
    if ($isCompanyScopedPageRequest && $requestedCompanyId !== null) {
        if (!$authorization->canAccessCompany($user, (int) $requestedCompanyId, $companyScopedIds)) {
            http_response_code(403);
            exit('Access denied to this company');
        }
    }

    if (in_array($uri, ['/', '/dashboard', '/dashboard.php', '/views/dashboard/dashboard.php'], true)) {
        if (count($allowedCompanyIds) === 1) {
            header('Location: /companies/' . (int) $allowedCompanyIds[0]);
        } else {
            header('Location: /companies/my');
        }
        exit;
    }
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
csrf_token(); // ensure token exists in session for all authenticated requests
(new CsrfMiddleware(['/api/analytics/heartbeat', '/ai/chat']))->handle();

// ── Activity log ──────────────────────────────────────────────────────────────
$activity = new UserActivity($connection);
$skipActivityLog = [
    '/notifications/unread',
    '/api/analytics/user-activity',
    '/api/analytics/heartbeat',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!in_array($currentPath, $skipActivityLog, true)) {
        $activity->log($user->id, 'page_view', $_SERVER['REQUEST_URI']);
    }
}

// ── Module permission check (MVC routes) ─────────────────────────────────────
// MVC controller routes never set $resolvedView, so the legacy check below
// would silently pass. We check them here by URI prefix using the same rules.
$mvcModuleMap = [
    '/offers'         => 'offers',
    '/ordini'         => 'worksites',
    '/billing'        => 'billing',
    '/attendance'     => 'attendance',
    '/bookings'       => 'bookings',
    '/tickets'        => 'tickets',
    '/share'          => 'share',
    '/equipment'      => 'equipment',
    '/programmazione' => 'programmazione',
    '/pianificazione' => 'pianificazione',
    '/clients'        => 'clients',
    '/users'          => 'users',
    '/documents'      => 'documents',
    '/companies'      => 'companies',
    '/worksites'      => 'worksites',
];

foreach ($mvcModuleMap as $prefix => $module) {
    if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
        if (!$authorization->canAccessModule($user, $module)) {
            header('Location: /dashboard?no_permission=1');
            exit;
        }
        break;
    }
}

// ── Module permission check (legacy PHP views) ────────────────────────────────
$resolvedView   = $GLOBALS['resolved_view'] ?? null;
$requiredModule = $capabilityService->resolveRequiredModule($resolvedView);

if ($requiredModule) {
    $bypass = $isCompanyScopedUser && $routePolicyMap->isCompanyScopedPermissionBypassRoute($uri);

    if (!$bypass && !$authorization->canAccessModule($user, $requiredModule)) {
        header('Location: /dashboard?no_permission=1');
        exit;
    }
}

// ── Worksite scope resolution ─────────────────────────────────────────────────
$allowedWorksites = [];
$worksiteRoles    = [];

if ($user->type === 'worker' && !$user->worker_id) {
    http_response_code(403);
    exit('Worker not linked');
}

if ($user->type === 'client' && !$user->client_id) {
    http_response_code(403);
    exit('Client user not linked to a client');
}

$allowedWorksites = $scopeService->resolveAllowedWorksites($connection, $user);

if ($user->type === 'worker' && !$routePolicyMap->isWorkerRouteAllowed($uri)) {
    http_response_code(403);
    exit('Access denied');
}

$GLOBALS['allowedWorksites'] = $allowedWorksites;
$GLOBALS['worksiteRoles']    = $worksiteRoles;

// ── Worksite access check ─────────────────────────────────────────────────────
$requestedWorksiteId =
    $_GET['worksite_id']
    ?? $_GET['cantiere_id']
    ?? $_POST['worksite_id']
    ?? $_POST['cantiere_id']
    ?? null;

if ($requestedWorksiteId !== null) {
    $requestedWorksiteId = (int) $requestedWorksiteId;
    if (!$scopeService->canAccessWorksite($allowedWorksites, $requestedWorksiteId)) {
        http_response_code(403);
        exit('Access denied to this worksite');
    }
}
