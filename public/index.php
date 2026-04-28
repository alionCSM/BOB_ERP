<?php
/**
 * BOB – Public entry point
 * Nginx document root points here. Nothing above this directory is web-accessible.
 */

define('APP_ROOT', dirname(__DIR__));

// Load bootstrap first to get autoloader and env
require_once APP_ROOT . '/includes/bootstrap.php';

// Register global exception handler (catches all unhandled exceptions)
set_exception_handler(function(\Throwable $e) {
    $context = [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
    ];

    // Add user info if logged in
    if (isset($GLOBALS['user']) && $GLOBALS['user'] instanceof \User) {
        $context['user_id'] = $GLOBALS['user']->id;
        $context['user_username'] = $GLOBALS['user']->username;
        $context['user_email'] = $GLOBALS['user']->email ?? 'N/A';
    }

    \App\Infrastructure\ExceptionHandler::handle($e, $context);
});

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

function render404(): void {
    http_response_code(404);
    $custom404 = APP_ROOT . '/../bob404.html';
    if (file_exists($custom404)) {
        require $custom404;
    } else {
        echo "404 Not Found";
    }
    exit;
}

function includeView(string $relativePath): void {
    $file = APP_ROOT . '/views/' . ltrim($relativePath, '/');

    if (!file_exists($file)) {
        render404();
    }

    // Protect against path traversal
    $realFile  = realpath($file);
    $realViews = realpath(APP_ROOT . '/views');
    if ($realFile === false || strpos($realFile, $realViews) !== 0) {
        render404();
    }

    $GLOBALS['resolved_view'] = ltrim($relativePath, '/');

    $oldCwd = getcwd();
    chdir(dirname($realFile));

    require $realFile;

    chdir($oldCwd);
    exit;
}

/**
 * Include a PHP file from APP_ROOT, confined to an allowed directory.
 * Used for /ajax/, /api/ which live outside public/.
 */
function includeAppFile(string $uri, string $allowedDir): void {
    $file = APP_ROOT . $uri;

    if (!file_exists($file)) {
        render404();
    }

    $realFile    = realpath($file);
    $realAllowed = realpath(APP_ROOT . '/' . trim($allowedDir, '/'));
    if ($realFile === false || $realAllowed === false || strpos($realFile, $realAllowed) !== 0) {
        render404();
    }

    $oldCwd = getcwd();
    chdir(dirname($realFile));

    require $realFile;

    chdir($oldCwd);
    exit;
}


/**
 * ── New MVC Router ────────────────────────────────────────────────────────────
 * Dispatches controller-based routes before falling through to legacy aliases.
 * Add one `if` block per domain as it gets migrated.
 */

// ── Auth (public — no middleware) ─────────────────────────────────────────────
if (in_array($uri, ['/login', '/logout', '/verify-login'], true)) {
    require_once APP_ROOT . '/includes/bootstrap.php';

    $db         = new \App\Infrastructure\Database();
    $connection = $db->connect();
    $container  = \App\Infrastructure\ContainerFactory::build($connection);
    $request    = new \App\Http\Request();
    $router     = new \App\Http\Router();

    $router->get('/login',          [AuthController::class, 'login'])
           ->post('/login',         [AuthController::class, 'login'])
           ->get('/logout',         [AuthController::class, 'logout'])
           ->get('/verify-login',   [AuthController::class, 'verifyLogin'])
           ->post('/verify-login',  [AuthController::class, 'verifyLogin']);

    $router->dispatch($request, $container);
}

// ── Auth (protected — middleware required) ────────────────────────────────────
if (in_array($uri, ['/change-password', '/confirm-email'], true)) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/change-password',   [AuthController::class, 'changePassword'])
           ->post('/change-password',  [AuthController::class, 'changePassword'])
           ->get('/confirm-email',     [AuthController::class, 'confirmEmail']);

    $router->dispatch($request, $container);
}

if ($uri === '/offers' || str_starts_with($uri, '/offers/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/offers',               [OffersController::class, 'index'])
           ->get('/offers/create',        [OffersController::class, 'create'])
           ->post('/offers/create',       [OffersController::class, 'store'])
           ->get('/offers/search',        [OffersController::class, 'search'])
           ->get('/offers/{id}',          [OffersController::class, 'show'])
           ->get('/offers/{id}/edit',     [OffersController::class, 'edit'])
           ->post('/offers/{id}/edit',    [OffersController::class, 'update'])
           ->get('/offers/{id}/revise',   [OffersController::class, 'revise'])
           ->post('/offers/{id}/revise',  [OffersController::class, 'reviseStore'])
           ->get('/offers/{id}/pdf',                        [OffersController::class, 'pdf'])
           ->get('/offers/{id}/doc',                        [OffersController::class, 'serveDoc'])
           ->post('/offers/{id}/status',                    [OffersController::class, 'updateStatus'])
           ->post('/offers/{id}/followups',                 [OffersController::class, 'addFollowup'])
           ->post('/offers/{id}/followups/{followupId}/delete', [OffersController::class, 'deleteFollowup']);

    $router->dispatch($request, $container);
}

if ($uri === '/ordini' || str_starts_with($uri, '/ordini/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    require_once APP_ROOT . '/src/Http/Controllers/OrdiniController.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/ordini',              [OrdiniController::class, 'index'])
           ->get('/ordini/create',       [OrdiniController::class, 'create'])
           ->post('/ordini/create',      [OrdiniController::class, 'store'])
           ->get('/ordini/{id}',         [OrdiniController::class, 'show'])
           ->get('/ordini/{id}/edit',    [OrdiniController::class, 'edit'])
           ->post('/ordini/{id}/edit',   [OrdiniController::class, 'update'])
           ->post('/ordini/{id}/delete', [OrdiniController::class, 'delete'])
           ->get('/ordini/{id}/pdf',     [OrdiniController::class, 'pdf'])
           ->post('/ordini/{id}/status', [OrdiniController::class, 'updateStatus']);

    $router->dispatch($request, $container);
}

if ($uri === '/users' || str_starts_with($uri, '/users/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/users',                          [UsersController::class, 'index'])
           ->get('/users/workers',                  [UsersController::class, 'workers'])
           ->get('/users/create',                   [UsersController::class, 'create'])
           ->get('/users/search-workers',           [UsersController::class, 'searchWorkers'])
           ->get('/users/audit-log',                [UsersController::class, 'auditLog'])
           ->get('/users/permissions',              [UsersController::class, 'permissionsList'])
           ->get('/users/bob/create',               [UsersController::class, 'createBobUser'])
           ->post('/users/bob/create',              [UsersController::class, 'createBobUser'])
           ->get('/users/notifications/send',       [UsersController::class, 'sendNotification'])
           ->post('/users/notifications/send',      [UsersController::class, 'sendNotification'])
           ->get('/users/permissions/{id}',         [UsersController::class, 'permissionsEdit'])
           ->post('/users/permissions/{id}',        [UsersController::class, 'permissionsEdit'])
           ->post('/users',                         [UsersController::class, 'store'])
           ->get('/users/{id}/edit',                [UsersController::class, 'show'])
           ->get('/users/{id}/worker-profile',      [UsersController::class, 'workerProfile'])
           ->post('/users/{id}/update',             [UsersController::class, 'update'])
           ->post('/users/{id}/photo',              [UsersController::class, 'updatePhoto'])
           ->post('/users/{id}/toggle',             [UsersController::class, 'toggleActive'])
           ->post('/users/{id}/company',            [UsersController::class, 'changeCompany'])
           ->post('/users/{id}/account',            [UsersController::class, 'createAccount'])
           ->post('/users/{id}/delete',             [UsersController::class, 'destroy'])
           ->get('/users/{id}/worker-photo',        [UsersController::class, 'serveWorkerPhoto'])
           ->get('/users/{id}/user-photo',          [UsersController::class, 'serveUserPhoto'])
           ->get('/users/{id}/badge',               [UsersController::class, 'badge']);

    $router->dispatch($request, $container);
}

if ($uri === '/profile') {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/profile',  [UsersController::class, 'profile'])
           ->post('/profile', [UsersController::class, 'profile']);

    $router->dispatch($request, $container);
}

if ($uri === '/companies' || str_starts_with($uri, '/companies/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/companies',                              [CompaniesController::class, 'index'])
           ->get('/companies/create',                       [CompaniesController::class, 'create'])
           ->get('/companies/my',                           [CompaniesController::class, 'my'])
           ->post('/companies',                             [CompaniesController::class, 'store'])
           ->get('/companies/{id}/edit',                    [CompaniesController::class, 'edit'])
           ->post('/companies/{id}/update',                 [CompaniesController::class, 'update'])
           // ── More specific routes must come BEFORE /{id}/delete ──────────────
           ->post('/companies/{companyId}/document/{id}/delete', [CompaniesController::class, 'destroyDocument'])
           ->post('/companies/{id}/document/update',        [CompaniesController::class, 'updateDocument'])
           ->post('/companies/{id}/document/upload',        [CompaniesController::class, 'uploadDocument'])
           ->post('/companies/{id}/worker/delete',          [CompaniesController::class, 'deleteWorker'])
           ->post('/companies/{id}/access',                 [CompaniesController::class, 'assignAccess'])
           ->post('/companies/{id}/user',                   [CompaniesController::class, 'createCompanyUser'])
           ->post('/companies/{id}/toggle-active',          [CompaniesController::class, 'toggleActive'])
           ->post('/companies/{id}/delete',                 [CompaniesController::class, 'destroy'])
           ->get('/companies/{id}',                         [CompaniesController::class, 'show'])
           ->get('/companies/documents/serve',              [CompaniesController::class, 'serveCompanyDocument'])
           ->get('/companies/export/consorziata',           [CompaniesController::class, 'exportConsorziata']);

    $router->dispatch($request, $container);
}

if ($uri === '/documents' || str_starts_with($uri, '/documents/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->post('/documents/{id}/delete',   [DocumentsController::class, 'destroy'])
           ->post('/documents/{id}/update',  [DocumentsController::class, 'update'])
           ->post('/documents/upload',       [DocumentsController::class, 'upload'])
           ->get('/documents/check-mandatory', [DocumentsController::class, 'checkMandatory'])
           ->get('/documents/check-mandatory-company', [DocumentsController::class, 'checkMandatoryCompany'])
           ->get('/documents/expired',       [DocumentsController::class, 'expired'])
           ->get('/documents/expired-cv',    [DocumentsController::class, 'expiredCv'])
           ->get('/documents/serve',         [DocumentsController::class, 'serve']);

    $router->dispatch($request, $container);
}

if ($uri === '/worksites' || str_starts_with($uri, '/worksites/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/worksites',                                  [WorksitesController::class, 'index'])
           ->get('/worksites/my',                             [WorksitesController::class, 'my'])
           ->get('/worksites/drafts',                         [WorksitesController::class, 'drafts'])
           ->get('/worksites/create',                         [WorksitesController::class, 'create'])
           ->get('/worksites/export-presenze',                [WorksitesController::class, 'exportPresenze'])
           ->get('/worksites/load-companies',                 [WorksitesController::class, 'loadCompanies'])
           ->post('/worksites',                               [WorksitesController::class, 'store'])
           // ── Literal POST routes must come BEFORE the /{id} wildcard ──────────
           ->post('/worksites/ask-ai',                                          [WorksitesController::class, 'askAi'])
           ->post('/worksites/recalculate-margin',                              [WorksitesController::class, 'recalculateMargin'])
           ->post('/worksites/yard-status',                                     [WorksitesController::class, 'updateYardStatus'])
           ->post('/worksites/documents/upload',                                [WorksitesController::class, 'uploadDocument'])
           ->post('/worksites/documents/callback',                              [WorksitesController::class, 'documentCallback'])
           // ── Parametric routes ─────────────────────────────────────────────────
           ->post('/worksites/{id}/disegni/upload',           [WorksitesController::class, 'uploadDisegno'])
           ->get('/worksites/{id}/disegni/{docId}/view',      [WorksitesController::class, 'viewDisegno'])
           ->get('/worksites/{id}/disegni/{docId}/versions',  [WorksitesController::class, 'getVersions'])
           ->get('/worksites/{id}/disegni/{docId}/delete',    [WorksitesController::class, 'deleteDisegno'])
           ->post('/worksites/{id}/disegni/share',            [WorksitesController::class, 'shareDisegno'])
           ->get('/worksites/{id}',                           [WorksitesController::class, 'show'])
           ->post('/worksites/{id}',                          [WorksitesController::class, 'show'])
           ->get('/worksites/{id}/edit',                                        [WorksitesController::class, 'edit'])
           ->post('/worksites/{id}/edit',                                       [WorksitesController::class, 'update'])
           ->post('/worksites/{id}/billing',                                    [WorksitesController::class, 'saveBilling'])
           ->post('/worksites/{id}/billing/{billingId}/delete',                 [WorksitesController::class, 'destroyBilling'])
           ->post('/worksites/{id}/extra',                                      [WorksitesController::class, 'saveExtra'])
           ->post('/worksites/{id}/extra/{extraId}/delete',                     [WorksitesController::class, 'destroyExtra'])
           ->post('/worksites/{id}/attivita',                                              [WorksitesController::class, 'saveAttivita'])
           ->post('/worksites/{id}/attivita/{attivitaId}/delete',                          [WorksitesController::class, 'destroyAttivita'])
           ->post('/worksites/{id}/attivita/{attivitaId}/photos/upload',                   [WorksitesController::class, 'uploadAttivitaPhoto'])
           ->post('/worksites/{id}/attivita/{attivitaId}/photos/{photoId}/delete',         [WorksitesController::class, 'destroyAttivitaPhoto'])
           ->get( '/worksites/{id}/attivita/photos/{photoId}/serve',                       [WorksitesController::class, 'serveAttivitaPhoto'])
           ->post('/worksites/{id}/ordine',                                     [WorksitesController::class, 'saveOrdine'])
           ->post('/worksites/{id}/ordine/{ordineId}/delete',                   [WorksitesController::class, 'destroyOrdine'])
           ->post('/worksites/{id}/presenza/{presenzaId}/delete',               [WorksitesController::class, 'destroyPresenza'])
           ->post('/worksites/{id}/presenza-consorziata/{presenzaId}/delete',   [WorksitesController::class, 'destroyPresenzaConsorziata'])
           ->post('/worksites/{id}/assign-user',                                [WorksitesController::class, 'assignUser'])
           ->post('/worksites/{id}/remove-user',                                [WorksitesController::class, 'removeUser'])
           ->post('/worksites/{id}/delete',                                     [WorksitesController::class, 'destroy'])
           ->get('/worksites/{id}/activate',                                    [WorksitesController::class, 'activateDraft'])
           ->get('/worksites/documents/{id}/open',                              [WorksitesController::class, 'openDocument'])
           ->get('/worksites/documents/{id}/download',                         [WorksitesController::class, 'downloadDocument']);

    $router->dispatch($request, $container);
}

if ($uri === '/fatturazione/consorziate' || str_starts_with($uri, '/fatturazione/consorziate/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get( '/fatturazione/consorziate',                                      [ConsorziataFatturazioneController::class, 'index'])
           ->get( '/fatturazione/consorziate/{id}',                                 [ConsorziataFatturazioneController::class, 'show'])
           ->get( '/fatturazione/consorziate/{id}/export',                          [ConsorziataFatturazioneController::class, 'export'])
           ->post('/fatturazione/consorziate/{id}/pay',                             [ConsorziataFatturazioneController::class, 'storePayments'])
           ->post('/fatturazione/consorziate/{id}/payment/{pid}/delete',            [ConsorziataFatturazioneController::class, 'deletePayment']);

    $router->dispatch($request, $container);
}

if ($uri === '/billing' || str_starts_with($uri, '/billing/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/billing',                          [BillingController::class, 'activeWorksites'])
           ->get('/billing/fetch',                   [BillingController::class, 'fetch'])
           ->get('/billing/export',                  [BillingController::class, 'export'])
           ->get('/billing/clients',                 [BillingController::class, 'clientList'])
           ->get('/billing/client/{id}',             [BillingController::class, 'clientDetail'])
           ->get('/billing/client/{id}/emesse',      [BillingController::class, 'clientEmesse'])
           ->get('/billing/client/{id}/export',      [BillingController::class, 'exportDaEmettere']);

    $router->dispatch($request, $container);
}

if ($uri === '/attendance' || str_starts_with($uri, '/attendance/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/attendance',                   [AttendanceController::class, 'index'])
           ->get('/attendance/create',            [AttendanceController::class, 'create'])
           ->get('/attendance/edit',              [AttendanceController::class, 'edit'])
           ->post('/attendance/delete',           [AttendanceController::class, 'destroy'])
           ->post('/attendance/save-bulk',        [AttendanceController::class, 'saveBulk'])
           ->get('/attendance/advances',          [AttendanceController::class, 'advances'])
           ->post('/attendance/advances/save',    [AttendanceController::class, 'saveAdvance'])
           ->get('/attendance/fines',             [AttendanceController::class, 'fines'])
           ->post('/attendance/fines/save',       [AttendanceController::class, 'saveFine'])
           ->get('/attendance/refunds',           [AttendanceController::class, 'refunds'])
           ->post('/attendance/refunds/save',     [AttendanceController::class, 'saveRefund'])
           ->get('/attendance/export/worker',     [AttendanceController::class, 'exportWorker'])
           ->get('/attendance/export/company',    [AttendanceController::class, 'exportCompany'])
           ->get('/attendance/export/client',     [AttendanceController::class, 'exportClient'])
           ->post('/attendance/export/client',    [AttendanceController::class, 'exportClient'])
           ->get('/attendance/export/bulk',       [AttendanceController::class, 'exportBulk']);

    $router->dispatch($request, $container);
}

if ($uri === '/dashboard') {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->dispatch($request, $container);
}

if ($uri === '/ai' || str_starts_with($uri, '/ai/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $ollamaClient = new \App\Service\OllamaClient(
        $_ENV['OLLAMA_URL'] ?? 'http://192.168.1.10:8000/v1/chat/completions',
        $_ENV['MODEL'] ?? 'Qwen3-30B-A3B-Q4_K_M.gguf'
    );
    $controller = new \App\Http\Controllers\AiSqlController($connection, $ollamaClient);
    $container->set(\App\Http\Controllers\AiSqlController::class, fn() => $controller);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();
    $router->get('/ai/chat',         [\App\Http\Controllers\AiSqlController::class, 'chatPage'])
           ->post('/ai/chat',        [\App\Http\Controllers\AiSqlController::class, 'chat'])
           ->post('/ai/export-table',[\App\Http\Controllers\AiSqlController::class, 'exportTable']);
    $router->dispatch($request, $container);
}

if (str_starts_with($uri, '/notifications/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/notifications/unread',              [NotificationsController::class, 'unread'])
           ->get('/notifications/history',             [NotificationsController::class, 'history'])
           ->post('/notifications/action',             [NotificationsController::class, 'action'])
           ->post('/notifications/push-subscription',  [NotificationsController::class, 'savePushSubscription']);

    $router->dispatch($request, $container);
}

if (str_starts_with($uri, '/api/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/api/search-company',              [ApiController::class, 'searchCompany'])
           ->get('/api/attendance/workers',          [ApiController::class, 'loadWorkers'])
           ->get('/api/attendance/worksites',        [ApiController::class, 'loadWorksites'])
           ->get('/api/attendance/companies',        [ApiController::class, 'loadCompanies'])
           ->get('/api/attendance/clients',          [ApiController::class, 'loadClients'])
           ->get('/api/attendance/last-day',         [ApiController::class, 'loadLastDay'])
           ->get('/api/worksites/search',            [ApiController::class, 'searchWorksites'])
           ->get('/api/analytics/user-activity',     [ApiController::class, 'userAnalytics'])
           ->post('/api/analytics/heartbeat',        [ApiController::class, 'heartbeat']);

    $router->dispatch($request, $container);
}

if ($uri === '/equipment' || str_starts_with($uri, '/equipment/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/equipment',                                [EquipmentController::class, 'index'])
           ->post('/equipment',                               [EquipmentController::class, 'index'])
           ->get('/equipment/manage',                         [EquipmentController::class, 'manage'])
           ->post('/equipment/manage',                        [EquipmentController::class, 'manage'])
           ->get('/equipment/assign',                         [EquipmentController::class, 'assign'])
           ->post('/equipment/assign',                        [EquipmentController::class, 'assign'])
           ->get('/equipment/rentals',                        [EquipmentController::class, 'rentals'])
           ->get('/equipment/rentals/{worksite_id}/edit',     [EquipmentController::class, 'editRentals'])
           ->post('/equipment/rentals/{worksite_id}/edit',    [EquipmentController::class, 'editRentals'])
           ->get('/equipment/rentals/{worksite_id}/complete', [EquipmentController::class, 'markComplete'])
           ->post('/equipment/rentals/{worksite_id}/complete',[EquipmentController::class, 'markComplete'])
           ->get('/equipment/search-worksites',               [EquipmentController::class, 'searchWorksites']);

    $router->dispatch($request, $container);
}

if ($uri === '/programmazione' || str_starts_with($uri, '/programmazione/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/programmazione',      [ProgrammazioneController::class, 'index'])
           ->get('/programmazione/api',  [ProgrammazioneController::class, 'api'])
           ->post('/programmazione/api', [ProgrammazioneController::class, 'api']);

    $router->dispatch($request, $container);
}

if ($uri === '/pianificazione' || str_starts_with($uri, '/pianificazione/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/pianificazione',          [ProgrammazioneController::class, 'pianificazione'])
           ->post('/pianificazione/save',    [ProgrammazioneController::class, 'save'])
           ->post('/pianificazione/copy',    [ProgrammazioneController::class, 'copy'])
           ->get('/pianificazione/get',      [ProgrammazioneController::class, 'get'])
           ->get('/pianificazione/print',    [ProgrammazioneController::class, 'print']);

    $router->dispatch($request, $container);
}

if ($uri === '/share' || str_starts_with($uri, '/share/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/share',                                    [\ShareController::class, 'index'])
           ->get('/share/create',                            [\ShareController::class, 'create'])
           ->post('/share/create',                           [\ShareController::class, 'create'])
           ->get('/share/{id}/edit',                         [\ShareController::class, 'edit'])
           ->post('/share/{id}/edit',                        [\ShareController::class, 'edit'])
           ->post('/share/delete',                           [\ShareController::class, 'destroy'])
           ->post('/share/toggle-active',                    [\ShareController::class, 'toggleActive'])
           ->post('/share/update-password',                  [\ShareController::class, 'updatePassword'])
           ->get('/share/fetch-companies',                   [\ShareController::class, 'fetchCompanies'])
           ->get('/share/fetch-company-documents',           [\ShareController::class, 'fetchCompanyDocuments'])
           ->get('/share/fetch-worker-documents',            [\ShareController::class, 'fetchWorkerDocuments'])
           ->get('/share/fetch-worker-documents-multiple',   [\ShareController::class, 'fetchWorkerDocumentsMultiple'])
           ->post('/share/upload-chunk',                     [\ShareController::class, 'uploadChunk']);

    $router->dispatch($request, $container);
}

if ($uri === '/tickets' || str_starts_with($uri, '/tickets/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/tickets',                [TicketsController::class, 'index'])
           ->post('/tickets/add',           [TicketsController::class, 'add'])
           ->post('/tickets/update',        [TicketsController::class, 'update'])
           ->post('/tickets/delete',        [TicketsController::class, 'delete'])
           ->get('/tickets/fetch-workers',  [TicketsController::class, 'fetchWorkers'])
           ->get('/tickets/print',          [TicketsController::class, 'printTicket'])
           ->get('/tickets/report',         [TicketsController::class, 'report']);

    $router->dispatch($request, $container);
}

if ($uri === '/bookings' || str_starts_with($uri, '/bookings/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/bookings',                                  [BookingsController::class, 'index'])
           ->get('/bookings/create',                           [BookingsController::class, 'create'])
           ->post('/bookings/create',                          [BookingsController::class, 'create'])
           ->get('/bookings/search-strutture',                 [BookingsController::class, 'searchStrutture'])
           ->get('/bookings/get-struttura',                    [BookingsController::class, 'getStruttura'])
           ->get('/bookings/{id}/edit',                        [BookingsController::class, 'edit'])
           ->post('/bookings/{id}/edit',                       [BookingsController::class, 'edit'])
           ->post('/bookings/{id}/delete',                     [BookingsController::class, 'destroy'])
           ->post('/bookings/{id}/fattura',                    [BookingsController::class, 'addFattura'])
           ->get('/bookings/fattura/{fattura_id}/delete',      [BookingsController::class, 'deleteFattura'])
           ->get('/bookings/fattura/{fattura_id}/serve',       [BookingsController::class, 'serveFattura'])
           ->post('/bookings/{id}/overrides',                  [BookingsController::class, 'addOverride'])
           ->post('/bookings/override/{override_id}/delete',   [BookingsController::class, 'deleteOverride'])
           ->post('/bookings/fattura/{fattura_id}/pagato',     [BookingsController::class, 'toggleFatturaPagato']);

    $router->dispatch($request, $container);
}

if ($uri === '/clients' || str_starts_with($uri, '/clients/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/clients',              [ClientsController::class, 'index'])
           ->get('/clients/create',       [ClientsController::class, 'create'])
           ->post('/clients',             [ClientsController::class, 'store'])
           ->get('/clients/search',       [ClientsController::class, 'search'])
           ->get('/clients/{id}',         [ClientsController::class, 'show'])
           ->get('/clients/{id}/edit',         [ClientsController::class, 'edit'])
           ->post('/clients/{id}/update',      [ClientsController::class, 'update'])
           ->get('/clients/{id}/check-delete', [ClientsController::class, 'checkDelete'])
           ->post('/clients/{id}/delete',      [ClientsController::class, 'destroy']);

    $router->dispatch($request, $container);
    // dispatch() exits on match; if we reach here no route matched → fall through
}

if ($uri === '/support' || str_starts_with($uri, '/support/')) {
    require_once APP_ROOT . '/includes/middleware.php';
    $container = \App\Infrastructure\ContainerFactory::build($connection);

    $request = new \App\Http\Request();
    $router  = new \App\Http\Router();

    $router->get('/support/tickets/create',  [SupportController::class, 'createTicket'])
           ->post('/support/tickets/create', [SupportController::class, 'createTicket']);

    $router->dispatch($request, $container);
}

/**
 * Default / → redirect to dashboard
 */
if ($uri === '/') {
    header('Location: /dashboard', true, 302);
    exit;
}


/**
 * Fallback 404
 */
render404();
