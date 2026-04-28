<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public function __construct(private \PDO $conn) {}

    // ── GET|POST /login ───────────────────────────────────────────────────────

    public function login(Request $request): never
    {
        $cookieDomain = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?: '';
        $error        = null;
        $autoRedirect = false;

        // 1) Already logged in
        if (!empty($_COOKIE['authentication_token'])) {
            $tmpUser = new User($this->conn);
            if ($tmpUser->validateToken($_COOKIE['authentication_token'])) {
                $autoRedirect = true;
            }
        }

        // 2) Auto-login via remember-me (GET only)
        if (
            !$autoRedirect
            && $_SERVER['REQUEST_METHOD'] === 'GET'
            && empty($_COOKIE['authentication_token'])
            && !empty($_COOKIE['remember_me'])
            && str_contains($_COOKIE['remember_me'], ':')
        ) {
            [$selector, $token] = explode(':', $_COOKIE['remember_me'], 2);

            $stmt = $this->conn->prepare("
                SELECT user_id, token_hash
                FROM bb_user_remember_tokens
                WHERE selector = :selector
                  AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([':selector' => $selector]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && hash_equals($row['token_hash'], hash('sha256', $token))) {
                $user = new User($this->conn, (int)$row['user_id']);
                $ip   = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
                $ua   = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                $user->trustLoginIp($ip, $ua);

                $sessionToken = $user->generateToken();
                $user->token  = $sessionToken;
                $expires_at   = date('Y-m-d H:i:s', strtotime('+8 hours'));
                $user->storeSession($ip, $ua, $expires_at);

                setcookie('authentication_token', $sessionToken, time() + 28800, '/', $cookieDomain, true, true);

                $user->revokeRememberToken($_COOKIE['remember_me']);
                $user->createRememberToken($ua, $cookieDomain);

                $autoRedirect = true;
            }
        }

        // 3) POST handler
        if (!$autoRedirect && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Rate limiting: max 5 failed attempts per IP per 15 minutes
            $countStmt = $this->conn->prepare("
                SELECT COUNT(*) AS cnt, MAX(attempted_at) AS last_at
                FROM bb_login_attempts
                WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $countStmt->execute([$ip]);
            $rateRow   = $countStmt->fetch(\PDO::FETCH_ASSOC);
            $failCount = (int)$rateRow['cnt'];
            $lastAt    = $rateRow['last_at'];

            if ($failCount >= 5) {
                http_response_code(429);
                $error = 'Troppi tentativi di accesso. Riprova tra 15 minuti.';
            } elseif ($failCount > 0 && $lastAt !== null) {
                $waitSeconds      = min(60, 2 ** $failCount);
                $secondsSinceLast = time() - strtotime($lastAt);
                if ($secondsSinceLast < $waitSeconds) {
                    $remaining = $waitSeconds - $secondsSinceLast;
                    http_response_code(429);
                    $error = "Attendi {$remaining} secondi prima di riprovare.";
                }
            }

            if (empty($error)) {
                $user           = new User($this->conn);
                $user->username = $_POST['username'];
                $user->password = $_POST['password'];
                $rememberMe     = !empty($_POST['remember_me']);

                if ($user->login()) {
                    $user->loadById($user->id);

                    $ip_address  = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
                    $device_info = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                    if (!$user->isKnownLogin($ip_address)) {
                        $verifyCode  = (string) random_int(100000, 999999);
                        $verifyToken = $user->createLoginVerification($ip_address, $verifyCode);
                        $verifyLink  = rtrim($_ENV['APP_URL'] ?? '', '/') . '/verify-login?token=' . $verifyToken;

                        try {
                            $mailer = new Mailer();
                            $mailer->setSender('security');
                            $mail = $mailer->getMailer();

                            if (empty($user->email)) {
                                throw new \Exception('Email utente mancante');
                            }

                            $mail->addAddress($user->email);
                            $mail->Subject = 'Nuovo accesso rilevato - BOB';
                            $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');

                            $mail->Body = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:30px 10px;">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="max-width:520px;background:#ffffff;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.08);">
        <tr>
            <td style="padding:20px;text-align:center;border-bottom:1px solid #e5e7eb;">
                <img src="' . $appUrl . '/includes/template/dist/images/logo.png" alt="BOB" style="height:40px;">
            </td>
        </tr>
        <tr>
            <td style="padding:30px;color:#1f2937;font-size:14px;line-height:1.6;">
                <p>Abbiamo rilevato un nuovo tentativo di accesso al tuo account.</p>
                <p>
                    <strong>Indirizzo IP:</strong> ' . htmlspecialchars($ip_address) . '<br>
                    <strong>Dispositivo:</strong> ' . htmlspecialchars($device_info) . '
                </p>
                <p>Inserisci questo codice di verifica:</p>
                <div style="margin:20px 0;padding:15px;background:#f8fafc;border:1px dashed #2563eb;
                            text-align:center;font-size:28px;font-weight:bold;letter-spacing:6px;
                            color:#2563eb;border-radius:8px;">
                    ' . $verifyCode . '
                </div>
                <p style="font-size:13px;color:#6b7280;">Codice valido per 10 minuti.</p>
            </td>
        </tr>
        <tr>
            <td style="padding:15px;text-align:center;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb;">
                BOB
            </td>
        </tr>
    </table>
</td></tr>
</table>
</body>
</html>';

                            $mail->send();
                        } catch (\Exception $e) {
                            \App\Infrastructure\LoggerFactory::mail()->error('Verify login email error: ' . $e->getMessage(), ['ip_address' => $ip_address]);
                        }

                        if ($rememberMe) {
                            $_SESSION['pending_remember_me'] = true;
                        }

                        Response::redirect('/verify-login?token=' . $verifyToken);
                    }

                    // Known IP → direct login
                    $token      = $user->generateToken();
                    $user->token = $token;
                    $expires_at  = date('Y-m-d H:i:s', strtotime('+8 hours'));

                    if ($user->storeSession($ip_address, $device_info, $expires_at)) {
                        setcookie('authentication_token', $token, time() + 28800, '/', $cookieDomain, true, true);

                        if ($rememberMe) {
                            $user->createRememberToken($device_info, $cookieDomain);
                        }

                        Response::redirect('/');
                    }

                    echo 'Errore creazione sessione.';
                    exit;

                } else {
                    $this->conn->prepare(
                        "INSERT INTO bb_login_attempts (ip_address, attempted_at) VALUES (?, NOW())"
                    )->execute([$ip]);
                    $error = 'Nome utente o password non corretti.';
                }
            }
        }

        $appUrl       = rtrim($_ENV['APP_URL'] ?? '', '/');
        $postUsername = $_POST['username'] ?? '';
        Response::view('auth/login.html.twig', $request, compact('autoRedirect', 'error', 'appUrl', 'postUsername'));
    }

    // ── GET /logout ───────────────────────────────────────────────────────────

    public function logout(Request $request): never
    {
        $cookieDomain = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?: '';
        $token        = $_COOKIE['authentication_token'] ?? '';
        $logoutUser   = null;

        if ($token) {
            $tempUser = new User($this->conn);
            $authRow  = $tempUser->getUserByToken($token);
            if (!empty($authRow['user_id'])) {
                $logoutUser = new User($this->conn, (int)$authRow['user_id']);
            }
        }

        $user = new User($this->conn);

        if ($token) {
            $user->invalidateToken($token);
            setcookie('authentication_token', '', time() - 3600, '/', $cookieDomain, true, true);
        }

        if (!empty($_COOKIE['remember_me'])) {
            $user->revokeRememberToken($_COOKIE['remember_me']);
            setcookie('remember_me', '', time() - 3600, '/', $cookieDomain, true, true);
        }

        if ($logoutUser) {
            AuditLogger::log($this->conn, $logoutUser, 'logout', 'session');
        }

        Response::redirect('/login');
    }

    // ── GET|POST /verify-login ────────────────────────────────────────────────

    public function verifyLogin(Request $request): never
    {
        $cookieDomain = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?: '';
        $appUrl       = rtrim($_ENV['APP_URL'] ?? '', '/');
        $token        = $_GET['token'] ?? '';
        $pageError    = '';
        $error        = '';

        if (!$token) {
            $pageError = 'Token mancante o non valido.';
        }

        $verification = null;
        if (!$pageError) {
            $stmt = $this->conn->prepare("
                SELECT user_id, ip_address
                FROM bb_login_verifications
                WHERE token = :token
                  AND used = 'N'
                  AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([':token' => $token]);
            $verification = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$verification) {
                $pageError = 'Il codice di verifica è scaduto o non valido.';
            }
        }

        $user        = null;
        $maskedEmail = '';

        if (!$pageError) {
            $user = new User($this->conn, $verification['user_id']);

            if (!empty($user->email)) {
                [$name, $domain] = explode('@', $user->email);
                $maskedEmail = substr($name, 0, 1) . '***@' . substr($domain, 0, 1) . '***';
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($pageError)) {
            $code = trim($_POST['code'] ?? '');

            if ($user->verifyLoginCode($token, $code)) {
                $ip_address  = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
                $device_info = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                $user->trustLoginIp($ip_address, $device_info);

                $sessionToken = $user->generateToken();
                $user->token  = $sessionToken;
                $expires_at   = date('Y-m-d H:i:s', strtotime('+8 hours'));
                $user->storeSession($ip_address, $device_info, $expires_at);

                setcookie('authentication_token', $sessionToken, time() + 28800, '/', $cookieDomain, true, true);

                if (!empty($_SESSION['pending_remember_me'])) {
                    $user->createRememberToken($device_info, $cookieDomain);
                    unset($_SESSION['pending_remember_me']);
                }

                Response::redirect('/');
            }

            $error = 'Codice non valido o scaduto.';
        }

        Response::view('auth/verify_login.html.twig', $request, compact('pageError', 'error', 'maskedEmail', 'appUrl'));
    }

    // ── GET|POST /change-password ─────────────────────────────────────────────

    public function changePassword(Request $request): never
    {
        $user = $request->user();

        if (empty($user->must_change_password)) {
            Response::redirect('/');
        }

        header('Referrer-Policy: no-referrer');

        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password']         ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';

            if (strlen($password) < 8) {
                $error = 'La password deve avere almeno 8 caratteri';
            } elseif ($password !== $confirm) {
                $error = 'Le password non coincidono';
            } elseif (function_exists('isPasswordPwned') && isPasswordPwned($password)) {
                $error = "Questa password e' presente in database di violazioni note. Scegline un'altra.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->conn->prepare('UPDATE bb_users SET password = :pwd, must_change_password = 0 WHERE id = :id');
                $stmt->execute([':pwd' => $hash, ':id' => $user->id]);
                Response::redirect('/login?pwd_changed=1');
            }
        }

        Response::view('auth/change_password.html.twig', $request, compact('error'));
    }

    // ── GET /confirm-email ────────────────────────────────────────────────────

    public function confirmEmail(Request $request): never
    {
        $pageTitle = 'Email Confirmation';
        $email     = $_GET['email'] ?? '';
        $success   = null;
        $error     = null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            $user = new User($this->conn);
            if ($user->confirmEmail($email)) {
                $success = 'Your email has been confirmed successfully! You can now log in.';
            } else {
                $error = 'There was an issue confirming your email. Please try again later.';
            }
        }

        Response::view('auth/confirm_email.html.twig', $request, compact('pageTitle', 'success', 'error'));
    }
}
