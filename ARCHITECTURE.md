# BOB — Architecture Guide

For developers joining the project. Read this before touching any code.

---

## 1. What BOB Is

BOB is an internal management system for a construction company. It handles:

- **Worksites (cantieri)** — creation, status tracking, billing, extras, drawings, worker assignments
- **Workers & companies** — profiles, documents, attendance (presenze), advances, fines, refunds
- **Billing** — da emettere / emesse tracking per worksite and client
- **Documents** — upload, expiry tracking, mandatory document compliance
- **Offers (offerte) & orders (ordini)** — quote lifecycle and supplier orders
- **Equipment (mezzi)** — inventory and rental tracking
- **Scheduling (programmazione/pianificazione)** — worker scheduling
- **Bookings** — accommodation bookings for workers
- **Tickets** — meal tickets
- **Yard integration** — read-only sync with an external Yard ERP via SQL Server

**Users come in three types** (covered in detail in §5):
- Internal staff (full or partial access depending on module permissions)
- Company-scoped viewers (subcontractor/partner companies seeing only their own data)
- Workers (very restricted; their own profile and worksites only)

---

## 2. Two Databases — Do Not Mix Them Up

This is the most important thing to understand before writing any query.

### MySQL — main application database

All application data lives here. Every table is prefixed `bb_`. Accessed via `App\Infrastructure\Database`, which returns a PDO connection.

```php
$db   = new \App\Infrastructure\Database();
$conn = $db->connect(); // returns PDO
```

Environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.

The PDO instance is bound in the container as `\PDO::class` and injected everywhere. Never instantiate `Database` inside a repository or service — receive `PDO` via constructor injection.

### SQL Server — Yard ERP integration

Read-only data from the external Yard system. Tables are under `dbo.CNT_*`. Accessed via `App\Infrastructure\SqlServerConnection` (aliased as `SQLServer`).

```php
// Inject it — do not extend or instantiate ad-hoc
public function __construct(private SqlServerConnection $yard) {}

$conn = $this->yard->connect(); // returns a separate PDO instance
```

Environment variables: `SQLSRV_HOST`, `SQLSRV_PORT`, `SQLSRV_DB`, `SQLSRV_USER`, `SQLSRV_PASS`, `SQLSRV_ENCRYPT`, `SQLSRV_TRUST_CERT`.

**Never pass the SQL Server PDO where MySQL PDO is expected, and vice versa.** The `\PDO::class` binding in the container is always MySQL. For Yard queries, inject `SqlServerConnection` explicitly.

---

## 3. Directory Structure

```
/
├── public/                 Web root (nginx document root)
│   └── index.php           Single entry point — router lives here
│
├── src/                    All application code (PSR-4: App\)
│   ├── Domain/             Domain model classes (data containers + behaviour)
│   ├── Http/
│   │   ├── Controllers/    HTTP handlers — one class per module
│   │   ├── Middleware/     AuthMiddleware, CsrfMiddleware
│   │   ├── Request.php     Thin wrapper around $_GET/$_POST/$_SERVER
│   │   ├── Response.php    Static helpers: ::view(), ::json(), ::redirect(), ::error()
│   │   └── Router.php      Minimal regex router
│   ├── Infrastructure/     Framework-level concerns
│   │   ├── Config.php      Typed env-var accessors
│   │   ├── ContainerFactory.php   PHP-DI bindings
│   │   ├── Database.php    MySQL PDO factory
│   │   ├── SqlServerConnection.php  Yard PDO factory
│   │   ├── ExceptionHandler.php
│   │   └── LoggerFactory.php  Monolog (app + database channels)
│   ├── Repository/         Database queries, one class per aggregate
│   │   ├── Contracts/      Repository interfaces (bound in ContainerFactory)
│   │   ├── Attendance/     AdvanceRepository, FineRepository, RefundRepository
│   │   ├── Billing/
│   │   ├── Bookings/
│   │   ├── Clients/
│   │   ├── Companies/
│   │   ├── Documents/
│   │   ├── Equipment/
│   │   ├── Offers/
│   │   ├── Ordini/
│   │   ├── Share/
│   │   ├── Tickets/
│   │   ├── Workers/
│   │   ├── Worksites/
│   │   └── AttendanceRepository.php  (top-level, predates the sub-namespaces)
│   ├── Security/
│   │   ├── AuthorizationService.php  canAccessModule(), isSuperAdmin()
│   │   ├── AccessProfileResolver.php Resolves user profile type
│   │   ├── CapabilityService.php     Maps legacy view paths to module names
│   │   ├── RoutePolicyMap.php        URI allowlists per user type
│   │   └── ScopeService.php          Worksite scope for workers/clients
│   ├── Service/            Business logic, one sub-namespace per domain
│   │   ├── AttendanceService.php
│   │   ├── AuditLogger.php
│   │   ├── Mailer.php
│   │   ├── WorksiteAIService.php
│   │   ├── WorksiteMarginService.php
│   │   ├── Bookings/, Clients/, Companies/, Documents/,
│   │   │   Offers/, Ordini/, Share/, Tickets/, Workers/
│   │   └── ...
│   ├── Support/            Utilities (CloudPath, etc.)
│   ├── Validator/          Input validation, one sub-namespace per domain
│   └── View/
│       ├── TwigRenderer.php       Wraps Twig\Environment
│       └── LayoutDataProvider.php Gathers globals for every layout template
│
├── templates/              Twig templates (*.html.twig)
│   └── <module>/
│
├── includes/               Bootstrap and global helpers
│   ├── bootstrap.php       Autoloader, dotenv, session, class aliases
│   ├── middleware.php      Auth + authorization gate (required by all protected routes)
│   ├── company_scope.php   Global functions for company-scoped access
│   ├── helpers/
│   │   ├── csrf.php        csrf_token(), csp_nonce()
│   │   ├── password_security.php
│   │   └── ...
│   └── version.php         getBobVersion()
│
├── views/                  Legacy PHP views (Excel exports, document partials)
│                           Intentionally kept — not being migrated
│
└── vendor/                 Composer dependencies
```

### Key `src/Domain/` classes

These are data-holding objects, not ActiveRecord — they do not contain SQL.

| Class | What it represents |
|---|---|
| `Worksite` | A construction site (cantiere) |
| `WorksiteStats` | Aggregated financial stats for a worksite |
| `Billing` / `Extra` | Billing records and extra cost records |
| `User` | Application user (all types) |
| `UserActivity` / `UserAnalytics` | Activity logging |
| `YardWorksite` / `YardWorksiteBilling` / `YardWorksiteExtra` | Yard ERP mirror objects (SQL Server data) |

---

## 4. Request Lifecycle

```
Browser request
    │
    ▼
nginx (bob-dev.local virtual host)
    │  document root: public/
    │  all URIs → public/index.php
    ▼
public/index.php
    │
    ├─ define APP_ROOT
    ├─ require includes/bootstrap.php
    │     ├─ vendor/autoload.php (PSR-4 + classmap + files)
    │     ├─ Dotenv::load()
    │     ├─ Config::validate() — hard fail on missing env vars
    │     ├─ session_start()
    │     └─ class_alias() — backward-compat short names
    │
    ├─ set_exception_handler(ExceptionHandler::handle)
    │
    ├─ parse URI from REQUEST_URI
    │
    ├─ [auth routes: /login, /logout, /verify-login]
    │     └─ build container → dispatch (NO middleware)
    │
    └─ [protected routes: /worksites, /billing, /users, ...]
          │
          ├─ require includes/middleware.php
          │     ├─ AuthMiddleware::handle()
          │     │     reads session / cookie → sets $GLOBALS['user']
          │     │     redirects to /login if unauthenticated
          │     ├─ set security headers (CSP nonce, HSTS, X-Frame-Options, ...)
          │     ├─ force /change-password if must_change_password flag set
          │     ├─ company-scope check (RoutePolicyMap::isCompanyScopedRouteAllowed)
          │     ├─ CsrfMiddleware::handle() (POST requests)
          │     ├─ activity log (GET page views)
          │     ├─ module permission check (AuthorizationService::canAccessModule)
          │     └─ worksite scope resolution (ScopeService)
          │
          ├─ ContainerFactory::build($connection) → PHP-DI container
          ├─ new Request()
          ├─ new Router() → register routes for this URI prefix
          └─ Router::dispatch($request, $container)
                │
                ├─ match URI + method against registered patterns
                ├─ extract {param} segments into $request->params
                ├─ $container->get(ControllerClass)->action($request)
                │
                └─ Controller
                      ├─ validate input
                      ├─ call Service (business logic)
                      │     └─ calls Repository (SQL)
                      └─ Response::view('template.html.twig', $request, $data)
                           or Response::json($data)
                           or Response::redirect('/somewhere')
```

`Router::dispatch()` calls `exit` after a successful match, so nothing executes after it. If no route matches the URI prefix block, execution falls through to the next `if` block in `index.php`. If nothing matches at all, `render404()` is called at the bottom of `index.php`.

---

## 5. Authentication & Authorization

### User types

| Type | `bb_users.type` value | What they can see |
|---|---|---|
| Internal staff | `staff` (or null) | Everything their module permissions allow |
| Company-scoped viewer | has rows in `bb_user_company_access` | Only their assigned companies and related workers/documents |
| Worker | `worker` | Own profile and assigned worksites only |
| Client | `client` | Assigned worksites via `bb_client_worksites` |

### AuthMiddleware

`App\Http\Middleware\AuthMiddleware::handle()` runs on every protected route. It:

1. Reads the session (or a remember-me cookie) to find `user_id`
2. Loads the user row from `bb_users` into a `User` domain object
3. Sets `$GLOBALS['user']` and `$GLOBALS['authenticated_user']`
4. Redirects to `/login` if no valid session exists

### RoutePolicyMap — URI allowlists

`App\Security\RoutePolicyMap` holds three regex lists:

- `companyScopedAllowedPatterns` — URIs a company-scoped user may visit at all
- `companyScopedPermissionBypassPatterns` — URIs where the normal module-permission check is skipped for company-scoped users
- `workerAllowedPatterns` — URIs a worker may visit

If a company-scoped user hits a URI not in their list, `middleware.php` returns HTTP 403 immediately. Same for workers.

### AuthorizationService — module permissions

`App\Security\AuthorizationService::canAccessModule(User $user, string $module)` is the gate for every module.

- **SuperAdmin** (user `id = 1`) bypasses all checks — always returns `true`.
- Everyone else requires an explicit `true` entry from `$user->canAccess($module)`, which reads the user's permission record from `bb_user_permissions`.
- Default is **deny** — a new module is invisible to all non-SuperAdmin users until you grant them access in the permissions UI.

Module names used in the check (from `middleware.php`): `offers`, `worksites`, `billing`, `attendance`, `bookings`, `tickets`, `share`, `equipment`, `programmazione`, `pianificazione`, `clients`, `users`, `documents`, `companies`.

---

## 6. Adding a New Module

The step-by-step process with code examples is in **`CONTRIBUTING.md`**. The summary checklist:

1. Create a repository interface in `src/Repository/Contracts/`
2. Implement the repository in `src/Repository/YourModule/`
3. Bind the interface to the implementation in `src/Infrastructure/ContainerFactory.php`
4. Create a service in `src/Service/YourModule/` (business logic, no SQL)
5. Create a validator in `src/Validator/YourModule/`
6. Create a controller in `src/Http/Controllers/YourModuleController.php`
7. Add Twig templates in `templates/your-module/`
8. Register routes in `public/index.php` (copy an existing `if ($uri === '/your-module' || str_starts_with(...))` block)
9. Add the URI prefix → module name mapping in the `$mvcModuleMap` array in `includes/middleware.php`
10. Grant permissions to users via the `/users/permissions/{id}` UI

---

## 7. Twig Templates

Templates live in `templates/`. The Twig environment is configured by `TwigRenderer`:

- **Autoescape** is on (`html`) — all variables are HTML-escaped by default
- **Cache** is enabled in production (`storage/twig-cache/`), disabled in development
- **Template path** is `APP_ROOT/templates`

### Layout globals

`LayoutDataProvider::getData()` injects these globals into every authenticated Twig render: `user`, `currentPath`, `csrfToken`, `cspNonce`, `flash`, `notifications`, `unreadCount`, `appUrl`, `bobVersion`, and a few more for the topbar.

You do not pass these manually — they are always available in templates.

### Flash messages

Set a flash message in a controller before redirecting:

```php
$_SESSION['success'] = 'Record saved.';
$_SESSION['error']   = 'Something went wrong.';
$_SESSION['info']    = 'Note: ...';
Response::redirect('/some-page');
```

The base layout reads and clears these automatically via `LayoutDataProvider::flash()`. In Twig:

```twig
{% if flash %}
  <div class="alert alert-{{ flash.type }}">{{ flash.message }}</div>
{% endif %}
```

### CSP nonce

All inline `<script>` tags must carry the nonce. Use the `cspNonce` global:

```twig
<script nonce="{{ cspNonce }}">
  // your inline JS
</script>
```

The nonce is generated once per request by `csp_nonce()` in `includes/helpers/csrf.php` and is included in the `Content-Security-Policy` header set by `middleware.php`.

### Custom functions and filters

Registered in `TwigRenderer::registerFunctions()`:

| Name | Type | Purpose |
|---|---|---|
| `asset(path)` | function | Prepends `appUrl` to a path |
| `date_fmt(dt, fmt)` | function | Formats a datetime string (default: `d/m/Y H:i`) |
| `friendly_page` | filter | Humanises a URI for the activity log |
| `friendly_action` | filter | Humanises an action string (`page_view` → `ha visitato`) |

---

## 8. Composer `autoload.files`

These three files are loaded unconditionally on every request via Composer's `autoload.files` mechanism — they are not required manually anywhere.

| File | What it provides |
|---|---|
| `includes/company_scope.php` | Global helper functions for company-scoped access checks |
| `includes/helpers/password_security.php` | Password strength and security helpers |
| `includes/version.php` | `getBobVersion()` — reads the current BOB version |

If you add a new global-function file that needs to be available everywhere, register it in the `autoload.files` array in `composer.json` and run `composer dump-autoload`.

---

## 9. What Not To Do

These rules exist because of bugs and regressions in the project's history.

**Do not extend `SqlServerConnection`.**
Extending it to add query methods creates tight coupling and makes testing impossible. Inject it as a dependency and call `->connect()` to get the PDO instance.

**Do not put SQL in Domain classes.**
`Worksite`, `User`, etc. are plain data objects. Queries belong in Repositories. A Domain class that runs its own queries cannot be tested without a database and cannot be reused in contexts where the data comes from elsewhere.

**Do not use `$_FILES[...]['type']` for MIME validation.**
The browser-supplied MIME type is untrusted and trivially spoofed. Always detect MIME server-side:

```php
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($_FILES['file']['tmp_name']);
```

**Do not use `?success=` query parameters for flash messages.**
`$_SESSION['success']` / `$_SESSION['error']` / `$_SESSION['info']` is the correct mechanism (see §7). Query-string flash survives in browser history, can be bookmarked, and leaks message text in server logs.

**Do not use `->query()` with string interpolation.**
All database queries must use prepared statements:

```php
// Wrong — SQL injection risk
$conn->query("SELECT * FROM bb_users WHERE id = $id");

// Correct
$stmt = $conn->prepare("SELECT * FROM bb_users WHERE id = :id");
$stmt->execute([':id' => $id]);
```

This applies to both MySQL and SQL Server connections.
