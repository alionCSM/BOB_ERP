<?php
declare(strict_types=1);

use App\Domain\User;
use App\Http\Request;
use App\Http\Response;
use App\Service\AuditLogger;
use App\Service\CurrentCompany;
use App\Service\Mailer;

/**
 * ApiV1Controller — API JSON per l'app Android (rotte /api/v1/*).
 *
 * L'app e' un client in piu' di BOB: non crea utenti ne' permessi nuovi.
 * Il token punta a un bb_users.id esistente e i moduli concessi sono
 * quelli di bb_user_permissions, letti allo stesso modo del web.
 * L'auth replica il flusso del sito, incluso il codice via email per IP
 * non conosciuti.
 *
 *   POST /api/v1/auth/login                 (pubblica)
 *   POST /api/v1/auth/verify                (pubblica)
 *   POST /api/v1/auth/logout                (Bearer)
 *   GET  /api/v1/me                         (Bearer)
 *   GET  /api/v1/notifications?limit=50     (Bearer)
 *   POST /api/v1/notifications/{id}/read    (Bearer)
 *   POST /api/v1/devices/fcm                (Bearer)
 *   POST /api/v1/switch-company             (Bearer)
 *   GET  /api/v1/dashboard                  (Bearer)
 *   POST /api/v1/cron/run                   (Bearer, admin)
 *   GET  /api/v1/cron/history?job=xxx       (Bearer, admin)
 *   GET  /api/v1/noleggi/giornata            (Bearer, permessi Poti)
 *   POST /api/v1/noleggi/giornata/segna      (Bearer, permessi Poti)
 */
final class ApiV1Controller
{
    private const TOKEN_TTL_DAYS = 90;

    public function __construct(private \PDO $conn) {}

    // ── AUTH (pubblica) ──────────────────────────────────────────────────────

    /** POST /api/v1/auth/login — {username, password, device_name} */
    public function login(Request $request): never
    {
        $body     = $this->jsonBody();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $device   = $this->deviceName($body);

        if ($username === '' || $password === '') {
            Response::json(['success' => false, 'message' => 'Username e password obbligatori'], 400);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $this->deviceUa($device);

        // Rate limit: stesse regole del login web (5/IP/15min, 10/user/15min)
        if (!$this->loginRateLimitOk($ip, $username)) {
            Response::json(['success' => false, 'message' => 'Troppi tentativi di accesso. Riprova tra 15 minuti.'], 429);
        }

        $user           = new User($this->conn);
        $user->username = $username;
        $user->password = $password;

        if (!$user->login()) {
            $this->recordLoginAttempt($ip, $username);
            AuditLogger::log($this->conn, null, 'api_login_failure', 'user', null, $username, ['source' => 'app']);
            Response::json(['success' => false, 'message' => 'Nome utente o password non corretti.'], 401);
        }

        $user->loadById($user->id);
        $user->loadPermissions();
        $user->loadCompany();

        $row = $this->userRow((int)$user->id);
        if ($row === null
            || (string)($row['active'] ?? 'Y') !== 'Y'
            || (string)($row['removed'] ?? 'N') === 'Y') {
            Response::json(['success' => false, 'message' => 'Account disattivato. Contatta l\'amministratore.'], 403);
        }

        if (!empty($row['must_change_password'])) {
            Response::json([
                'success' => false,
                'code'    => 'must_change_password',
                'message' => "La password e' scaduta: devi cambiarla dal browser prima di usare l'app.",
                'url'     => rtrim((string)($_ENV['APP_URL'] ?? ''), '/') . '/change-password',
            ], 403);
        }

        // IP gia' fidato: token subito. Altrimenti codice via email (come web)
        if ($user->isKnownLogin($ip)) {
            Response::json($this->issueToken($user, $row, $device, $ip, $ua));
        }

        $code        = (string)random_int(100000, 999999);
        $verifyToken = $user->createLoginVerification($ip, $code);
        $this->sendVerificationEmail($user, $row, $code, $ip, $ua);

        AuditLogger::log($this->conn, $user, 'api_login_verification_sent', 'user', (int)$user->id, (string)$user->username, ['ip' => $ip]);

        Response::json([
            'success'               => true,
            'requires_verification' => true,
            'verify_token'          => $verifyToken,
            'masked_email'          => $this->maskedEmail((string)($row['email'] ?? '')),
            'message'               => 'Abbiamo inviato un codice di verifica alla tua email.',
        ]);
    }

    /** POST /api/v1/auth/verify — {verify_token, code, device_name} */
    public function verify(Request $request): never
    {
        $body        = $this->jsonBody();
        $verifyToken = trim((string)($body['verify_token'] ?? ''));
        $code        = trim((string)($body['code'] ?? ''));
        $device      = $this->deviceName($body);

        if ($verifyToken === '' || $code === '') {
            Response::json(['success' => false, 'message' => 'Token e codice obbligatori'], 400);
        }

        $stmt = $this->conn->prepare("
            SELECT user_id, ip_address
            FROM   bb_login_verifications
            WHERE  token = :token AND used = 'N' AND expires_at > NOW()
            LIMIT  1
        ");
        $stmt->execute([':token' => $verifyToken]);
        $verification = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$verification) {
            Response::json(['success' => false, 'message' => 'Verifica scaduta: riprova il login.'], 410);
        }

        $user = new User($this->conn, (int)$verification['user_id']);
        $user->loadPermissions();
        $user->loadCompany();

        if (!$user->verifyLoginCode($verifyToken, $code)) {
            Response::json(['success' => false, 'message' => 'Codice non valido. Controlla la email e riprova.'], 422);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $this->deviceUa($device);

        // Da questo IP l'utente e' fidato: login successivi senza codice
        $user->trustLoginIp($ip, $ua);

        $row = $this->userRow((int)$user->id);
        if ($row === null) {
            Response::json(['success' => false, 'message' => 'Utente non piu\' disponibile.'], 403);
        }

        Response::json($this->issueToken($user, $row, $device, $ip, $ua));
    }

    // ── SESSIONE (Bearer) ────────────────────────────────────────────────────

    /** POST /api/v1/auth/logout — revoca il token in uso */
    public function logout(Request $request): never
    {
        $token = $this->bearerToken();
        if ($token !== '') {
            $this->conn->prepare("
                UPDATE bb_api_tokens
                SET    revoked_at = NOW()
                WHERE  token_hash = :hash AND revoked_at IS NULL
            ")->execute([':hash' => hash('sha256', $token)]);
        }

        $user = $request->user();
        AuditLogger::log($this->conn, $user, 'api_logout', 'user', (int)$user->id, (string)$user->username);

        Response::json(['success' => true]);
    }

    /** GET /api/v1/me — profilo, moduli, societa' (per la UI dell'app) */
    public function me(Request $request): never
    {
        $user = $request->user();
        $row  = $this->userRow((int)$user->id);
        if ($row === null) {
            Response::json(['success' => false, 'message' => 'Utente non trovato'], 404);
        }

        Response::json([
            'success'                 => true,
            'user'                    => $this->userPayload($row),
            'modules'                 => array_keys(array_filter($user->getPermissions())),
            'can_see_prices'          => $user->canSeePrices(),
            'companies'               => $this->currentCompany()->availableFor((int)$user->id),
            'active_company_id'       => $this->currentCompany()->id(),
            'needs_company_selection' => false,
        ]);
    }

    // ── NOTIFICHE ────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/notifications?limit=50
     * Ultime notifiche (lette e non) della societa' attiva,
     * stesso filtro del campanellino web.
     */
    public function notifications(Request $request): never
    {
        $user  = $request->user();
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        [$sql, $args] = $this->companyFilter();

        $stmt = $this->conn->prepare("
            SELECT n.id, n.title, n.message, n.link, n.category, n.priority,
                   n.is_read, n.created_at
            FROM   bb_notifications n
            WHERE  n.user_id = :uid{$sql}
            ORDER  BY COALESCE(n.read_at, n.created_at) DESC
            LIMIT  :limit
        ");
        $stmt->bindValue(':uid', (int)$user->id, \PDO::PARAM_INT);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $countStmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM   bb_notifications n
            WHERE  n.user_id = :uid AND n.is_read = 0{$sql}
        ");
        $countStmt->bindValue(':uid', (int)$user->id, \PDO::PARAM_INT);
        foreach ($args as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $unreadCount = (int)$countStmt->fetchColumn();

        $notifications = array_map(function (array $n): array {
            return [
                'id'         => (int)$n['id'],
                'title'      => (string)$n['title'],
                'message'    => (string)$n['message'],
                'link'       => (string)($n['link'] ?? ''),
                'category'   => (string)($n['category'] ?? 'info'),
                'priority'   => (string)($n['priority'] ?? 'normal'),
                'is_read'    => (bool)$n['is_read'],
                'created_at' => (string)$n['created_at'],
            ];
        }, $rows);

        Response::json([
            'success'       => true,
            'unread_count'  => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    /** POST /api/v1/notifications/{id}/read */
    public function markRead(Request $request): never
    {
        $user    = $request->user();
        $notifId = $request->intParam('id');
        if ($notifId <= 0) {
            Response::json(['success' => false, 'message' => 'Id notifica non valido'], 422);
        }

        $stmt = $this->conn->prepare("
            UPDATE bb_notifications
            SET    is_read = 1, read_at = NOW()
            WHERE  id = :id AND user_id = :uid
        ");
        $stmt->execute([':id' => $notifId, ':uid' => (int)$user->id]);

        Response::json(['success' => true, 'marked' => $stmt->rowCount() > 0]);
    }

    // ── DASHBOARD (Bearer) ───────────────────────────────────────────────────

    /**
     * GET /api/v1/dashboard — pagina principale dell'app.
     *
     * Specchia la dashboard web (DashboardController) in JSON: stesse
     * verifiche, stessi numeri, senza il layer Twig.
     *
     *   admin  → stato sistema + risorse server + job schedulati
     *   altri  → contatori moduli + scorciatoie (dashboard dinamica)
     *
     * Le query sono ricalcate dai controller web a proposito: l'app e' un
     * client in piu' e non deve toccare il codice del sito.
     */
    public function dashboard(Request $request): never
    {
        $user = $request->user();
        $role = (string)($user->role ?? '');

        if ($role === 'admin') {
            Response::json([
                'success' => true,
                'variant' => 'admin',
                'system'  => $this->systemStatusData(),
                'server'  => $this->serverResourcesData(),
                'cron'    => $this->cronData(),
            ]);
        }

        Response::json([
            'success'   => true,
            'variant'   => 'dynamic',
            'stats'     => $this->dynamicStats($user),
            'shortcuts' => $this->dynamicShortcuts($user),
        ]);
    }

    /** POST /api/v1/cron/run — {job} avvio manuale (stesse regole del web) */
    public function cronRun(Request $request): never
    {
        $user = $request->user();
        // avvio manuale: fa girare codice server-side, solo chi amministra
        if ((string)($user->role ?? '') !== 'admin' && (int)$user->id !== 1) {
            Response::json(['success' => false, 'message' => 'Accesso negato'], 403);
        }

        $body = $this->jsonBody();
        $job  = (string)($body['job'] ?? '');
        if (!isset(\App\Service\CronRun::JOBS[$job])) {
            Response::json(['success' => false, 'message' => 'Job sconosciuto'], 400);
        }

        $script = APP_ROOT . '/' . \App\Service\CronRun::JOBS[$job]['script'];
        if (!is_file($script)) {
            Response::json(['success' => false, 'message' => 'Script non trovato sul server'], 500);
        }

        // gia' in esecuzione? (stesso controllo del web)
        try {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM bb_cron_runs
                WHERE job = :j AND status = 'running'
                  AND started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ");
            $stmt->execute([':j' => $job]);
            if ((int)$stmt->fetchColumn() > 0) {
                Response::json(['success' => false, 'message' => 'Job gia\' in esecuzione'], 409);
            }
        } catch (\Throwable $e) {
            // tabella assente: si prosegue comunque
        }

        $cmd = 'php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        @shell_exec($cmd);

        AuditLogger::log($this->conn, $user, 'api_cron_run', 'job', null, $job, ['source' => 'app']);

        Response::json(['success' => true, 'message' => 'Job avviato']);
    }

    /** GET /api/v1/cron/history?job=xxx — ultime esecuzioni (solo admin) */
    public function cronHistory(Request $request): never
    {
        $user = $request->user();
        if ((string)($user->role ?? '') !== 'admin' && (int)$user->id !== 1) {
            Response::json(['success' => false, 'message' => 'Accesso negato'], 403);
        }

        $job = (string)($_GET['job'] ?? '');
        if (!isset(\App\Service\CronRun::JOBS[$job])) {
            Response::json(['success' => false, 'message' => 'Job sconosciuto'], 400);
        }
        $meta = \App\Service\CronRun::JOBS[$job];

        $runs = [];
        try {
            $stmt = $this->conn->prepare("
                SELECT status, started_at, duration_ms, message
                FROM bb_cron_runs
                WHERE job = :j
                ORDER BY id DESC
                LIMIT 20
            ");
            $stmt->execute([':j' => $job]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $runs[] = [
                    'status'    => $r['status'],
                    'data'      => $r['started_at'] ? date('d/m/Y', strtotime($r['started_at'])) : null,
                    'ora'       => $r['started_at'] ? date('H:i:s', strtotime($r['started_at'])) : null,
                    'durata_ms' => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
                    'message'   => $r['message'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ApiV1 cronHistory] ' . $e->getMessage());
        }

        Response::json([
            'success' => true,
            'job'     => $job,
            'label'   => $meta['label'],
            'descr'   => $meta['descr'],
            'runs'    => $runs,
        ]);
    }

    // ── DISPOSITIVO / SOCIETA' ───────────────────────────────────────────────

    /** POST /api/v1/devices/fcm — registra/aggiorna il token FCM del telefono */
    public function registerDevice(Request $request): never
    {
        $user = $request->user();
        $body = $this->jsonBody();
        $fcm  = trim((string)($body['fcm_token'] ?? ''));
        $name = substr(trim((string)($body['device_name'] ?? '')), 0, 120);
        $ver  = substr(trim((string)($body['app_version'] ?? '')), 0, 30);

        if ($fcm === '' || strlen($fcm) > 255) {
            Response::json(['success' => false, 'message' => 'Token FCM non valido'], 422);
        }

        $this->conn->prepare("
            INSERT INTO bb_push_devices
                (user_id, fcm_token, platform, device_name, app_version, is_active, last_seen_at, created_at, updated_at)
            VALUES
                (:uid, :fcm, 'android', :name, :ver, 1, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                user_id      = VALUES(user_id),
                device_name  = VALUES(device_name),
                app_version  = VALUES(app_version),
                is_active    = 1,
                last_seen_at = NOW(),
                updated_at   = NOW()
        ")->execute([
            ':uid'  => (int)$user->id,
            ':fcm'  => $fcm,
            ':name' => $name !== '' ? $name : null,
            ':ver'  => $ver !== '' ? $ver : null,
        ]);

        Response::json(['success' => true]);
    }

    /** POST /api/v1/switch-company — {group_company_id} (stesso /switch-company web) */
    public function switchCompany(Request $request): never
    {
        $user = $request->user();
        $body = $this->jsonBody();
        $cid  = (int)($body['group_company_id'] ?? 0);

        if ($cid <= 0 || !$this->currentCompany()->select((int)$user->id, $cid)) {
            Response::json(['success' => false, 'message' => 'Societa\' non disponibile per il tuo utente'], 422);
        }

        // Persisto la scelta SUL TOKEN: il client non conserva la sessione,
        // senza questo ogni richiesta successiva tornerebbe a 409.
        $token = $this->bearerToken();
        if ($token !== '') {
            $this->conn->prepare("
                UPDATE bb_api_tokens
                SET    group_company_id = :gc
                WHERE  token_hash = :hash AND user_id = :uid
            ")->execute([
                ':gc'   => $cid,
                ':hash' => hash('sha256', $token),
                ':uid'  => (int)$user->id,
            ]);
        }

        $row = $this->userRow((int)$user->id);
        Response::json([
            'success'                 => true,
            'user'                    => $this->userPayload($row),
            'modules'                 => array_keys(array_filter($user->getPermissions())),
            'can_see_prices'          => $user->canSeePrices(),
            'companies'               => $this->currentCompany()->availableFor((int)$user->id),
            'active_company_id'       => $this->currentCompany()->id(),
            'needs_company_selection' => false,
        ]);
    }

    // ── NOLEGGI / GIORNATA TECNICI (Bearer) ──────────────────────────────────

    /**
     * GET /api/v1/noleggi/giornata?tipo=autocarrate|macchina&data=YYYY-MM-DD
     *
     * La giornata dei tecnici (stessa pagina web): cosa esce, cosa rientra,
     * cosa e' fuori, cosa e' in ritardo. Permessi identici al web
     * (assertGiornata dei due controller). Riusa le repository e il service
     * Giornata del sito: stessi dati, stessa normalizzazione.
     */
    public function noleggiGiornata(Request $request): never
    {
        $user = $request->user();
        $tipo = (string)($_GET['tipo'] ?? 'autocarrate');

        [$ok, $repo, $msg] = $this->noleggiAccesso($user, $tipo);
        if (!$ok) {
            Response::json(['success' => false, 'message' => $msg], 403);
        }

        $cid     = (int)$this->currentCompany()->id();
        $data    = \App\Service\Poti\VistaImpegni::data($_GET['data'] ?? '', date('Y-m-d'));
        $blocco  = $tipo === 'macchina' ? \App\Service\Poti\Giornata::MACCHINA : \App\Service\Poti\Giornata::AUTOCARRATA;
        $blocchi = \App\Service\Poti\Giornata::blocchi($repo->giornata($cid, $data), $blocco);

        Response::json([
            'success'   => true,
            'tipo'      => $tipo,
            'data'      => $data,
            'oggi'      => date('Y-m-d'),
            'blocchi'   => $blocchi,
            'riepilogo' => \App\Service\Poti\Giornata::riepilogo($blocchi),
            'prossime'  => \App\Service\Poti\Giornata::prossime($repo->prossimeConsegne($cid, $data, 14), $blocco),
        ]);
    }

    /**
     * POST /api/v1/noleggi/giornata/segna
     * {tipo, cosa, id?, riga_id?, noleggio_id?} — cosa: consegnato|rientrato|firma
     *
     * Toggle come nel web: un tocco segna, il tocco successivo annulla.
     * Stesse repository e stesso registro di modifica (Audit Poti).
     */
    public function noleggiSegna(Request $request): never
    {
        $user = $request->user();
        $body = $this->jsonBody();
        $tipo = (string)($body['tipo'] ?? 'autocarrate');

        [$ok, $repo, $msg] = $this->noleggiAccesso($user, $tipo);
        if (!$ok) {
            Response::json(['success' => false, 'message' => $msg], 403);
        }

        $cosa = (string)($body['cosa'] ?? '');
        if (!in_array($cosa, ['consegnato', 'rientrato', 'firma'], true)) {
            Response::json(['success' => false, 'message' => 'Azione non valida'], 422);
        }

        $cid = (int)$this->currentCompany()->id();
        $uid = (int)$user->id;

        if ($tipo === 'macchina') {
            $rigaId     = (int)($body['riga_id'] ?? 0);
            $noleggioId = (int)($body['noleggio_id'] ?? 0);
            $prima      = $noleggioId ? $repo->noleggio($cid, $noleggioId) : null;

            if ($prima) {
                if ($cosa === 'firma') {
                    $repo->segnaContrattoFirmato($cid, $noleggioId, empty($prima['contratto_firmato']));
                } elseif ($rigaId && in_array($cosa, ['consegnato', 'rientrato'], true)) {
                    $repo->segnaMomento($cid, $rigaId, $cosa, $uid);
                }
                (new \App\Service\Poti\Audit($this->conn))->registra(
                    $cid, 'noleggio', $noleggioId, 'modificato',
                    $prima, $repo->noleggio($cid, $noleggioId),
                    $uid, (string)$prima['cliente']
                );
            }
            Response::json(['success' => $prima !== null]);
        }

        $id    = (int)($body['id'] ?? 0);
        $prima = $id ? $repo->prenotazione($cid, $id) : null;

        if ($prima) {
            if ($cosa === 'firma') {
                $repo->segnaContrattoFirmato($cid, $id, empty($prima['contratto_firmato']));
            } elseif (in_array($cosa, ['consegnato', 'rientrato'], true)) {
                $repo->segnaMomento($cid, $id, $cosa, $uid);
            }
            (new \App\Service\Poti\Audit($this->conn))->registra(
                $cid, 'prenotazione', $id, 'modificato',
                $prima, $repo->prenotazione($cid, $id),
                $uid, (string)$prima['cliente']
            );
        }
        Response::json(['success' => $prima !== null]);
    }

    /**
     * Accesso alla giornata noleggi: stesse regole dei web (assertGiornata).
     *
     * @return array{0:bool,1:\App\Repository\Poti\AutocarrataRepository|\App\Repository\Poti\MacchinaRepository|null,2:string}
     */
    private function noleggiAccesso(User $user, string $tipo): array
    {
        $super = (int)$user->id === 1;

        if ($tipo === 'macchina') {
            if ($super || $user->canAccess('pn_noleggi') || $user->canAccess('pn_noleggi_giornata')) {
                return [true, new \App\Repository\Poti\MacchinaRepository($this->conn), ''];
            }
            return [false, null, "Permesso 'pn_noleggi_giornata' richiesto"];
        }

        if ($super || $user->canAccess('pn_autocarrate') || $user->canAccess('pn_autocarrate_giornata')) {
            return [true, new \App\Repository\Poti\AutocarrataRepository($this->conn), ''];
        }
        return [false, null, "Permesso 'pn_autocarrate_giornata' richiesto"];
    }

    // ── DATI DASHBOARD (ricalcati dal web) ───────────────────────────────────

    /**
     * Stato del sistema: stessi controlli della dashboard admin web
     * (database, email, storage cloud NFS).
     */
    private function systemStatusData(): array
    {
        $conn = $this->conn;

        $dbStatus = 'Online';
        try { $conn->query('SELECT 1'); } catch (\Exception $e) { $dbStatus = 'Offline'; }

        $mailStatus = 'Non configurato';
        if (!empty($_ENV['MAIL_HOST']) && !empty($_ENV['MAIL_PORT'])) {
            $sock = @fsockopen($_ENV['MAIL_HOST'], (int)$_ENV['MAIL_PORT'], $errno, $errstr, 2);
            if ($sock) {
                $mailStatus = 'Operativo';
                fclose($sock);
            } else {
                $mailStatus = 'Non raggiungibile';
            }
        }

        $storage     = ['status' => 'N/D', 'latency_ms' => null, 'percent' => null, 'used_gb' => null, 'total_gb' => null];
        $storagePath = $_ENV['CLOUD_ROOT'] ?? null;
        if ($storagePath && is_dir($storagePath)) {
            $start   = microtime(true);
            $files   = @scandir($storagePath);
            $latency = (int)round((microtime(true) - $start) * 1000);

            if ($files === false) {
                $storage['status'] = 'NFS non accessibile';
            } else {
                $testFile = $storagePath . '/.healthcheck';
                $writeOk  = @file_put_contents($testFile, 'test') !== false;
                if ($writeOk) {
                    @unlink($testFile);
                }
                $storage['status']     = $writeOk ? 'Online' : 'Sola lettura';
                $storage['latency_ms'] = $latency;

                $cloudTotal = @disk_total_space($storagePath);
                $cloudFree  = @disk_free_space($storagePath);
                if ($cloudTotal > 0) {
                    $cloudUsed    = $cloudTotal - $cloudFree;
                    $storage['percent']  = (int)round(($cloudUsed / $cloudTotal) * 100);
                    $storage['used_gb']  = round($cloudUsed  / 1073741824, 1);
                    $storage['total_gb'] = round($cloudTotal / 1073741824, 1);
                }
            }
        }

        return [
            'db'      => $dbStatus,
            'mail'    => $mailStatus,
            'storage' => $storage,
        ];
    }

    /**
     * Risorse del server: stesse letture della dashboard admin web
     * (disco, RAM, load, PHP).
     */
    private function serverResourcesData(): array
    {
        $diskTotal = disk_total_space(__DIR__);
        $diskFree  = disk_free_space(__DIR__);
        $diskUsed  = $diskTotal - $diskFree;

        $load    = sys_getloadavg();
        $cpuLoad = $load ? round($load[0], 2) : null;

        $memInfo  = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+)/',     $memInfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $avail);
        $memTotal = isset($total[1]) ? (int)$total[1] * 1024 : 0;
        $memAvail = isset($avail[1]) ? (int)$avail[1] * 1024 : 0;

        return [
            'disk' => [
                'percent'  => $diskTotal > 0 ? (int)round(($diskUsed / $diskTotal) * 100) : 0,
                'used_gb'  => round($diskUsed  / 1073741824, 1),
                'total_gb' => round($diskTotal / 1073741824, 1),
            ],
            'ram' => [
                'percent'  => $memTotal > 0 ? (int)round((($memTotal - $memAvail) / $memTotal) * 100) : 0,
                'used_gb'  => $memTotal > 0 ? round(($memTotal - $memAvail) / 1073741824, 1) : 0.0,
                'total_gb' => round($memTotal / 1073741824, 1),
            ],
            'cpu' => ['load' => $cpuLoad],
            'php' => [
                'memory_limit' => (string)ini_get('memory_limit'),
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 1) . ' MB',
            ],
        ];
    }

    /**
     * Job schedulati: stessi dati di /services/cron-status web (pannello
     * "Servizi" della top bar), da bb_cron_runs e registro CronRun::JOBS.
     */
    private function cronData(): array
    {
        $today = date('Y-m-d');
        $runs  = [];
        $lastEver = [];

        try {
            // ultima esecuzione di oggi per ciascun job
            $stmt = $this->conn->prepare("
                SELECT r.job, r.status, r.started_at, r.duration_ms, r.message
                FROM bb_cron_runs r
                JOIN (
                    SELECT job, MAX(id) AS max_id
                    FROM bb_cron_runs
                    WHERE DATE(started_at) = :d
                    GROUP BY job
                ) last ON last.max_id = r.id
            ");
            $stmt->execute([':d' => $today]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $runs[$row['job']] = $row;
            }

            // ultima esecuzione in assoluto, per i job non partiti oggi
            $stmtPrev = $this->conn->prepare("
                SELECT r.job, r.started_at
                FROM bb_cron_runs r
                JOIN (SELECT job, MAX(id) AS max_id FROM bb_cron_runs GROUP BY job) last
                  ON last.max_id = r.id
            ");
            $stmtPrev->execute();
            foreach ($stmtPrev->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $lastEver[$row['job']] = $row;
            }
        } catch (\Throwable $e) {
            // tabella non ancora creata (migration da applicare): tutto "mai"
            error_log('[ApiV1 cronData] ' . $e->getMessage());
        }

        $jobs = [];
        foreach (\App\Service\CronRun::JOBS as $key => $meta) {
            $r    = $runs[$key] ?? null;
            $prev = $lastEver[$key] ?? null;

            $jobs[] = [
                'job'       => $key,
                'label'     => $meta['label'],
                'descr'     => $meta['descr'],
                'status'    => $r['status'] ?? 'mai',
                'ora'       => ($r && $r['started_at']) ? date('H:i', strtotime($r['started_at'])) : null,
                'durata_ms' => ($r && $r['duration_ms'] !== null) ? (int)$r['duration_ms'] : null,
                'message'   => $r['message'] ?? null,
                'ultima'    => (!$r && $prev && !empty($prev['started_at']))
                                   ? date('d/m/Y H:i', strtotime($prev['started_at']))
                                   : null,
            ];
        }

        return ['date' => date('d/m/Y'), 'jobs' => $jobs];
    }

    /**
     * Moduli concessi all'utente, filtrati dalla societa' attiva
     * (stessa logica della dashboard dinamica web).
     *
     * @return array<string,bool>
     */
    private function modulePermissions(User $user): array
    {
        $mods = array_filter($user->getPermissions());

        return array_filter(
            $mods,
            static fn(string $m): bool => $user->canAccess($m),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Dashboard dinamica: contatori dei moduli utilizzabili.
     * Stesse query di DashboardController::contatoriModuli web.
     */
    private function dynamicStats(User $user): array
    {
        $conn  = $this->conn;
        $stats = [];
        $today = date('Y-m-d');
        $mods  = $this->modulePermissions($user);
        $has   = static fn(string ...$m): bool => (bool)array_intersect($m, array_keys($mods));

        if ($has('worksites')) {
            $n = (int)$conn->query("SELECT COUNT(*) FROM bb_worksites WHERE status = 'In corso' AND is_draft = 0")->fetchColumn();
            $stats[] = ['num' => $n, 'label' => 'Cantieri attivi', 'sub' => 'in corso', 'color' => '#ea580c', 'bg' => '#fff7ed',
                        'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5', 'href' => '/worksites'];
        }

        if ($has('attendance', 'presenze')) {
            $s = $conn->prepare("
                SELECT (SELECT COUNT(*) FROM bb_presenze WHERE data = :d1)
                     + (SELECT COALESCE(SUM(quantita),0) FROM bb_presenze_consorziate WHERE data_presenza = :d2)
            ");
            $s->execute([':d1' => $today, ':d2' => $today]);
            $stats[] = ['num' => (int)$s->fetchColumn(), 'label' => 'Presenze oggi', 'sub' => 'nostri + consorziate', 'color' => '#0ea5e9', 'bg' => '#f0f9ff',
                        'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'href' => '/attendance'];

            $s = $conn->prepare("SELECT COUNT(*) FROM bb_ferie_permessi WHERE data_inizio <= :d1 AND data_fine >= :d2");
            $s->execute([':d1' => $today, ':d2' => $today]);
            $stats[] = ['num' => (int)$s->fetchColumn(), 'label' => 'Assenti oggi', 'sub' => 'ferie e permessi', 'color' => '#b45309', 'bg' => '#fffbeb',
                        'icon' => 'M12 7v5l3 3M12 21a9 9 0 100-18 9 9 0 000 18z', 'href' => '/attendance/leaves'];
        }

        if ($has('equipment')) {
            $n = (int)$conn->query("
                SELECT COALESCE(SUM(quantita),0) FROM bb_worksite_lifting
                WHERE stato IN ('Attivo','attivo') AND tipo_noleggio <> 'Una Tantum'
            ")->fetchColumn();
            $stats[] = ['num' => $n, 'label' => 'Mezzi a noleggio', 'sub' => 'attivi ora', 'color' => '#b45309', 'bg' => '#fffbeb',
                        'icon' => 'M3 21h18M6 21V8l12-5v18', 'href' => '/equipment/rentals'];
        }

        if ($has('pn_autocarrate')) {
            $stats = array_merge($stats, $this->statsAutocarrateData());
        }

        if ($has('pn_noleggi')) {
            $stats = array_merge($stats, $this->statsMacchineData());
        }

        if ($has('documents', 'document_alerts')) {
            try {
                $docCtrl    = new \App\Service\Documents\WorkerDocumentController($conn);
                $expired    = $docCtrl->getExpiredDocuments($user);
                $expiring30 = $docCtrl->getExpiringDocuments($user, 30);
                $stats[] = ['num' => count($expired['workerDocs']) + count($expired['companyDocs']), 'label' => 'Documenti scaduti', 'sub' => 'operai + aziende', 'color' => '#dc2626', 'bg' => '#fef2f2',
                            'icon' => 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M12 18v-6M12 9h.01', 'href' => '/documents/expired'];
                $stats[] = ['num' => count($expiring30['workerDocs']) + count($expiring30['companyDocs']), 'label' => 'Scadono in 30gg', 'sub' => 'da monitorare', 'color' => '#d97706', 'bg' => '#fffbeb',
                            'icon' => 'M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01', 'href' => '/documents/expiring'];
            } catch (\Throwable $e) {
                error_log('[ApiV1 dynamicStats] documenti: ' . $e->getMessage());
            }
        }

        if ($has('billing') && $user->canSeePrices()) {
            $row = $conn->query("
                SELECT COUNT(*) AS n, COALESCE(SUM(totale_imponibile),0) AS tot
                FROM bb_billing WHERE emessa = 0
            ")->fetch(\PDO::FETCH_ASSOC) ?: [];
            $stats[] = ['num' => (int)($row['n'] ?? 0), 'label' => 'Fatture da emettere',
                        'sub' => '€ ' . number_format((float)($row['tot'] ?? 0), 0, ',', '.'), 'color' => '#16a34a', 'bg' => '#f0fdf4',
                        'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z', 'href' => '/billing'];
        }

        return $stats;
    }

    /** Contatori autocarrate (stessi di DashboardController web). */
    private function statsAutocarrateData(): array
    {
        $cid  = (int)$this->currentCompany()->id();
        $oggi = date('Y-m-d');

        try {
            $s = $this->conn->prepare("
                SELECT COUNT(*) FROM pn_autocarrate
                WHERE group_company_id = :cid AND stato = 'attiva'
            ");
            $s->execute([':cid' => $cid]);
            $totali = (int)$s->fetchColumn();

            $s = $this->conn->prepare("
                SELECT COUNT(DISTINCT autocarrata_id) FROM pn_prenotazioni
                WHERE group_company_id = :cid AND stato <> 'annullata'
                  AND data_inizio <= :d1 AND data_fine >= :d2
            ");
            $s->execute([':cid' => $cid, ':d1' => $oggi, ':d2' => $oggi]);
            $impegnate = (int)$s->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ApiV1 statsAutocarrate] ' . $e->getMessage());
            return [];
        }

        return [
            ['num' => max(0, $totali - $impegnate), 'label' => 'Autocarrate libere', 'sub' => 'oggi',
             'color' => '#16a34a', 'bg' => '#f0fdf4', 'href' => '/autocarrate',
             'icon' => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1'],
            ['num' => $impegnate, 'label' => 'Autocarrate impegnate', 'sub' => 'oggi',
             'color' => '#0369a1', 'bg' => '#f0f9ff', 'href' => '/autocarrate/prenotazioni',
             'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
        ];
    }

    /** Contatori mezzi a noleggio (stessi di DashboardController web). */
    private function statsMacchineData(): array
    {
        $cid  = (int)$this->currentCompany()->id();
        $oggi = date('Y-m-d');

        try {
            $s = $this->conn->prepare("
                SELECT COUNT(*) FROM pn_macchine
                WHERE group_company_id = :cid AND stato = 'attiva'
            ");
            $s->execute([':cid' => $cid]);
            $totali = (int)$s->fetchColumn();

            $s = $this->conn->prepare("
                SELECT COUNT(DISTINCT r.macchina_id)
                FROM   pn_noleggi_righe r
                JOIN   pn_noleggi n ON n.id = r.noleggio_id
                WHERE  n.group_company_id = :cid AND n.stato <> 'annullato'
                  AND  r.data_inizio <= :d1 AND r.data_fine >= :d2
            ");
            $s->execute([':cid' => $cid, ':d1' => $oggi, ':d2' => $oggi]);
            $impegnate = (int)$s->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ApiV1 statsMacchine] ' . $e->getMessage());
            return [];
        }

        return [
            ['num' => max(0, $totali - $impegnate), 'label' => 'Mezzi liberi', 'sub' => 'oggi',
             'color' => '#16a34a', 'bg' => '#f0fdf4', 'href' => '/noleggi',
             'icon' => 'M3 21h18M6 21V8l12-5v18M10 12h4'],
            ['num' => $impegnate, 'label' => 'Mezzi a noleggio', 'sub' => 'oggi',
             'color' => '#7c3aed', 'bg' => '#f5f3ff', 'href' => '/noleggi/elenco',
             'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
        ];
    }

    /**
     * Dashboard dinamica: scorciatoie dei moduli utilizzabili.
     * Stesso catalogo della dashboard web (href aperti nel browser).
     */
    private function dynamicShortcuts(User $user): array
    {
        $mods = $this->modulePermissions($user);
        $has  = static fn(string ...$m): bool => (bool)array_intersect($m, array_keys($mods));

        $catalog = [
            [['worksites'],              'Cantieri',          'Elenco e gestione cantieri',    '/worksites',          '#ea580c', '#fff7ed', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1'],
            [['worksites_drafts'],       'Cantieri in Bozza', 'Bozze da completare e attivare','/worksites/drafts',   '#dc2626', '#fef2f2', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            [['attendance','presenze'],  'Presenze',          'Cerca e inserisci presenze',    '/attendance',         '#0ea5e9', '#f0f9ff', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            [['attendance','presenze'],  'Ferie e Permessi',  'Registra le assenze',           '/attendance/leaves',  '#38bdf8', '#f0f9ff', 'M12 7v5l3 3M12 21a9 9 0 100-18 9 9 0 000 18z'],
            [['pianificazione'],         'Squadre',           'Pianificazione squadre',        '/pianificazione',     '#3b82f6', '#eff6ff', 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            [['programmazione'],         'Programmazione',    'Programma settimanale',         '/programmazione',     '#f59e0b', '#fffbeb', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            [['equipment'],              'Noleggio Mezzi',    'Mezzi di sollevamento a noleggio','/equipment/rentals', '#b45309', '#fffbeb', 'M3 21h18M6 21V8l12-5v18M10 12h4'],
            [['fleet_view','fleet_manage'],'Flotta',          'Auto aziendali e assegnazioni', '/fleet',              '#0369a1', '#f0f9ff', 'M9 17a2 2 0 11-4 0 2 2 0 114 0zm0 0h6m4 0a2 2 0 11-4 0 2 2 0 114 0zM7 9h10l2 4H5l2-4z'],
            [['bookings'],               'Prenotazioni',      'Alloggi e strutture',           '/bookings',           '#0d9488', '#f0fdfa', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            [['billing'],                'Fatturazione',      'Bozze e fatture cantieri',      '/billing',            '#16a34a', '#f0fdf4', 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
            [['offers'],                 'Offerte',           'Preventivi e revisioni',        '/offers',             '#059669', '#ecfdf5', 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
            [['clients'],                'Clienti',           'Anagrafica committenti',        '/clients',            '#0891b2', '#f0f9ff', 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
            [['companies'],              'Aziende',           'Aziende e consorziate',         '/companies',          '#7c3aed', '#f5f3ff', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5'],
            [['ordini'],                 'Ordini Consorziate','Ordini verso consorziate',      '/ordini',             '#1d4ed8', '#eff6ff', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2m-6 9l2 2 4-4'],
            [['ordini_aziende'],         'Ordini Aziende',    'Ordini verso aziende',          '/ordini-aziende',     '#0d9488', '#f0fdfa', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
            [['tickets'],                'Bigliettini Pasto', 'Buoni pasto operai',            '/tickets',            '#059669', '#ecfdf5', 'M15 5H9V3h6v2zm4 4H5a2 2 0 00-2 2v1h18v-1a2 2 0 00-2-2zM3 14v5a2 2 0 002 2h14a2 2 0 002-2v-5H3z'],
            [['documents'],              'Documenti Scaduti', 'Documenti operai e aziende',    '/documents/expired',  '#dc2626', '#fef2f2', 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M12 18v-6M12 9h.01'],
            [['share'],                  'Doc Condivisi',     'Link di condivisione documenti','/share',              '#2563eb', '#eff6ff', 'M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71'],
            [['ai_chat'],                'BOB AI',            'Chiedi ai dati in linguaggio naturale','/ai/chat',       '#6366f1', '#f5f3ff', 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z'],
            [['pn_autocarrate'],         'Autocarrate',       'Disponibilita\' e prenotazioni','/autocarrate',        '#0369a1', '#f0f9ff', 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6 0a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0'],
            [['pn_noleggi'],             'Mezzi sollevamento','Piattaforme, carrelli, telescopici','/noleggi',         '#7c3aed', '#f5f3ff', 'M3 21h18M6 21V8l12-5v18M9 12h4M10 16h4'],
        ];

        $shortcuts = [];
        foreach ($catalog as [$perms, $label, $sub, $href, $color, $bg, $icon]) {
            if ($has(...$perms)) {
                $shortcuts[] = compact('label', 'sub', 'href', 'color', 'bg', 'icon');
            }
        }
        return $shortcuts;
    }

    // ── HELPER ───────────────────────────────────────────────────────────────

    /** Emette il token (90 gg) e prepara il payload di accesso */
    private function issueToken(User $user, array $row, string $device, string $ip, string $ua): array
    {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_DAYS * 86400);

        // Societa' del gruppo: il client mobile non tiene la sessione PHP,
        // quindi la scelta vive SUL TOKEN (bb_api_tokens.group_company_id).
        //  - una sola societa'  → univoca, la persisto subito;
        //  - nessuna assegnazione → fallback Consorzio storico, ri-derivato a ogni richiesta;
        //  - piu' di una         → NULL: l'app sceglie via /api/v1/switch-company.
        $currentCompany = $this->currentCompany();
        $storedCompany  = null;
        $needsSelection = false;
        if (!in_array($user->type, ['worker', 'client'], true)
            && !isset($_SESSION[CurrentCompany::SESSION_KEY])) {
            $lista = $currentCompany->availableFor((int)$user->id);
            $auto  = $currentCompany->autoSelectOnLogin((int)$user->id);
            $needsSelection = !$auto;
            if ($auto && count($lista) === 1) {
                $storedCompany = (int)$lista[0]['id'];
            }
        }

        $this->conn->prepare("
            INSERT INTO bb_api_tokens
                (user_id, group_company_id, token_hash, device_name, device_info, ip_address, expires_at, created_at)
            VALUES
                (:uid, :gc, :hash, :name, :info, :ip, :exp, NOW())
        ")->execute([
            ':uid'   => (int)$user->id,
            ':gc'    => $storedCompany,
            ':hash'  => hash('sha256', $token),
            ':name'  => $device !== '' ? $device : null,
            ':info'  => substr($ua, 0, 255),
            ':ip'    => $ip,
            ':exp'   => $expires,
        ]);

        AuditLogger::log($this->conn, $user, 'api_login_success', 'user', (int)$user->id, (string)$user->username, [
            'device' => $device,
            'ip'     => $ip,
        ]);

        return [
            'success'                 => true,
            'token'                   => $token,
            'token_expires_at'        => $expires,
            'user'                    => $this->userPayload($row),
            'modules'                 => array_keys(array_filter($user->getPermissions())),
            'can_see_prices'          => $user->canSeePrices(),
            'companies'               => $currentCompany->availableFor((int)$user->id),
            'active_company_id'       => $needsSelection ? null : $currentCompany->id(),
            'needs_company_selection' => $needsSelection,
        ];
    }

    /** @return array<string,mixed>|null Riga completa di bb_users */
    private function userRow(int $userId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Profilo dell'utente per l'app (foto inline in base64, max 1 MB) */
    private function userPayload(array $row): array
    {
        $payload = [
            'id'           => (int)$row['id'],
            'username'     => (string)$row['username'],
            'first_name'   => (string)($row['first_name'] ?? ''),
            'last_name'    => (string)($row['last_name'] ?? ''),
            'email'        => (string)($row['email'] ?? ''),
            'type'         => (string)($row['type'] ?? 'staff'),
            'role'         => (string)($row['role'] ?? ''),
            'company'      => (string)($row['company'] ?? ''),
            'must_change_password' => !empty($row['must_change_password']),
            'photo_data_uri' => $this->photoDataUri($row),
        ];
        return $payload;
    }

    /** Foto dell'utente come data URI (stessa risoluzione del sito) */
    private function photoDataUri(array $row): ?string
    {
        $photo = (string)($row['photo'] ?? '');
        if ($photo === '' || preg_match('#^https?://#i', $photo)) {
            return null; // foto esterna: l'app la carica direttamente
        }

        $cloudRoot = (string)($_ENV['CLOUD_ROOT'] ?? (getenv('CLOUD_ROOT') ?: ''));
        if ($cloudRoot === '') {
            $cloudRoot = (string)(realpath(dirname(APP_ROOT) . '/cloud') ?: dirname(APP_ROOT) . '/cloud');
        }
        $cloudRoot = rtrim($cloudRoot, '/\\');

        $filePath = realpath($cloudRoot . '/' . $photo);
        if (!$filePath || !is_file($filePath)) {
            $legacy = realpath(APP_ROOT . '/' . $photo);
            $filePath = ($legacy && is_file($legacy)) ? $legacy : null;
        }
        if ($filePath === null || filesize($filePath) > 1048576) {
            return null;
        }

        $mime  = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($filePath);
        if ($mime === '' || strpos($mime, '/') === false) {
            $mime = 'image/jpeg';
        }

        $b64 = base64_encode((string)file_get_contents($filePath));
        return $b64 !== false ? 'data:' . $mime . ';base64,' . $b64 : null;
    }

    /**
     * Filtro societa' del gruppo per le notifiche (stesso filtroSocieta
     * del NotificationsController web).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function companyFilter(): array
    {
        $sql  = '';
        $args = [];

        $col = $this->conn->query("SHOW COLUMNS FROM bb_notifications LIKE 'group_company_id'")->fetch(\PDO::FETCH_ASSOC);
        if ($col) {
            $cid = (int)$this->currentCompany()->id();
            $sql = ($cid > 0)
                ? " AND n.group_company_id = :gc"
                : " AND (n.group_company_id IS NULL OR n.group_company_id = 1)";
            if ($cid > 0) {
                $args[':gc'] = $cid;
            }
        }

        return [$sql, $args];
    }

    private function currentCompany(): CurrentCompany
    {
        return $GLOBALS['currentCompany'] ?? new CurrentCompany($this->conn);
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw  = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $body */
    private function deviceName(array $body): string
    {
        return substr(trim((string)($body['device_name'] ?? '')), 0, 120);
    }

    private function deviceUa(string $device): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return 'BOB-App/' . ($device !== '' ? $device : 'android') . ' ' . $ua;
    }

    private function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['HTTP_X_AUTHORIZATION']
            ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', trim((string)$header), $m)) {
            return $m[1];
        }
        return '';
    }

    // ── RATE LIMIT LOGIN (regole identiche al login web) ─────────────────────

    private function loginRateLimitOk(string $ip, string $username): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - 900);

        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM bb_login_attempts
            WHERE ip_address = :ip AND attempted_at > :cutoff
        ");
        $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return false;
        }

        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM bb_login_attempts
            WHERE username = :u AND attempted_at > :cutoff
        ");
        $stmt->execute([':u' => $username, ':cutoff' => $cutoff]);
        if ((int)$stmt->fetchColumn() >= 10) {
            return false;
        }

        return true;
    }

    private function recordLoginAttempt(string $ip, string $username): void
    {
        $this->conn->prepare("
            INSERT INTO bb_login_attempts (ip_address, username, attempted_at)
            VALUES (:ip, :u, NOW())
        ")->execute([':ip' => $ip, ':u' => $username]);
    }

    // ── EMAIL VERIFICA (stesso template del login web) ────────────────────────

    /** @param array<string,mixed> $row */
    private function sendVerificationEmail(User $user, array $row, string $code, string $ip, string $ua): void
    {
        $name   = (string)($row['first_name'] ?? ($row['username'] ?? ''));
        $email  = (string)($row['email'] ?? '');
        $site   = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $ipHtml = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        $uaHtml = htmlspecialchars($ua, ENT_QUOTES, 'UTF-8');

        $html = "
            <div style=\"font-family:Arial,Helvetica,sans-serif; max-width:560px; margin:0 auto; color:#1e293b\">
                <div style=\"display:flex; align-items:center; gap:12px; padding:24px 0; border-bottom:1px solid #e2e8f0\">
                    <img src=\"" . $site . "/images/logo.png\" alt=\"BOB\" width=\"72\" height=\"72\"
                         onerror=\"this.style.display='none'\">
                    <div>
                        <div style=\"font-size:22px; font-weight:800; letter-spacing:1px; color:#0f766e\">BOB</div>
                    </div>
                </div>
                <h2 style=\"font-size:18px; margin:28px 0 8px\">Codice di accesso da nuovo dispositivo</h2>
                <p style=\"font-size:14px; line-height:1.6; margin:0 0 20px\">Ciao <strong>{$name}</strong>,<br>
                qualcuno ha provato ad accedere al tuo account BOB da un indirizzo IP non riconosciuto.
                Usa questo codice per completare l'accesso dall'app:</p>
                <div style=\"background:#f0fdfa; border:2px dashed #0d9488; border-radius:12px; padding:20px; text-align:center; margin-bottom:24px\">
                    <div style=\"font-size:34px; font-weight:800; letter-spacing:10px; color:#0f766e; font-family:monospace\">{$code}</div>
                </div>
                <p style=\"font-size:13px; line-height:1.7; color:#475569; margin:0 0 8px\">
                    <strong>Dati del tentativo</strong><br>
                    IP: {$ipHtml}<br>
                    Dispositivo: {$uaHtml}<br>
                    Ora: " . date('d/m/Y H:i') . "</p>
                <p style=\"font-size:12px; color:#94a3b8; margin:24px 0 0\">
                    Il codice scade tra 15 minuti. Se non hai richiesto tu questo accesso,
                    ignora questa email o cambia subito la password.
                </p>
            </div>";

        try {
            $mailer = new Mailer();
            $mailer->setSender('security');
            $mail = $mailer->getMailer();
            $mail->addAddress($email);
            $mail->Subject = 'BOB: codice di accesso da nuovo dispositivo';
            $mail->Body = $html;
            $mail->send();
        } catch (\Throwable $e) {
            \App\Infrastructure\LoggerFactory::app()->error("Impossibile inviare email verifica API: " . $e->getMessage());
        }
    }

    private function maskedEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email !== '' ? $email[0] . '***' : '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $localMask  = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . (strlen($local) > 1 ? substr($local, -1) : '');
        $domainPart = substr($domain, 0, strpos($domain, '.') ?: strlen($domain));
        return $localMask . '@' . $domainPart . '***';
    }
}
