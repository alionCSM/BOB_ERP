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
                        <div style=\"font-size:14px; font-weight:700; letter-spacing:1px; color:#0f766e\">C S MONTAGGI S.R.L.</div>
                        <div style=\"font-size:11px; color:#64748b\">Sistema di Gestione Interna</div>
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
