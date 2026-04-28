<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use PDO;
use User;

/**
 * Handles authentication for every protected request.
 *
 * Resolution order:
 *   1. authentication_token cookie  → validate session
 *   2. remember_me cookie           → rotate token, create new session
 *   3. Neither valid               → redirect to /login
 *
 * On success sets:
 *   $GLOBALS['user']               – hydrated User object
 *   $GLOBALS['authenticated_user'] – raw DB row from getUserByToken()
 */
final class AuthMiddleware
{
    public function __construct(
        private readonly PDO    $conn,
        private readonly string $cookieDomain,
    ) {}

    /**
     * Run authentication. Redirects to /login and exits if unauthenticated.
     */
    public function handle(): void
    {
        $token = $_COOKIE['authentication_token'] ?? '';

        // 1. Validate existing token
        if ($token) {
            $tempUser = new User($this->conn);
            if (!$tempUser->validateToken($token)) {
                $this->clearCookie('authentication_token');
                $token = '';
            }
        }

        // 2. Fallback: remember_me
        if (!$token) {
            $refreshed = $this->tryRememberLogin();
            $token     = $_COOKIE['authentication_token'] ?? '';

            if (!$refreshed || !$token) {
                header('Location: /login');
                exit;
            }
        }

        // 3. Resolve authenticated user from token
        $tempUser           = new User($this->conn);
        $authenticated_user = $tempUser->getUserByToken($token);

        if (!$authenticated_user || empty($authenticated_user['user_id'])) {
            $this->clearCookie('authentication_token');
            header('Location: /login');
            exit;
        }

        // 4. Hydrate user object
        $user = new User($this->conn, (int) $authenticated_user['user_id']);
        $user->loadPermissions();
        $user->loadCompany();

        $GLOBALS['user']               = $user;
        $GLOBALS['authenticated_user'] = $authenticated_user;
    }

    // ── Remember-me ──────────────────────────────────────────────────────────

    private function tryRememberLogin(): bool
    {
        if (empty($_COOKIE['remember_me'])) {
            return false;
        }

        $remember = (string) $_COOKIE['remember_me'];

        if (!str_contains($remember, ':')) {
            $this->clearCookie('remember_me');
            return false;
        }

        [$selector, $token] = explode(':', $remember, 2);

        if ($selector === '' || $token === '') {
            $this->clearCookie('remember_me');
            return false;
        }

        $stmt = $this->conn->prepare('
            SELECT user_id, token_hash
            FROM   bb_user_remember_tokens
            WHERE  selector   = :selector
              AND  expires_at > NOW()
            LIMIT  1
        ');
        $stmt->execute([':selector' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->clearCookie('remember_me');
            return false;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            $this->clearCookie('remember_me');
            return false;
        }

        // Valid — create a fresh session token
        $user     = new User($this->conn, (int) $row['user_id']);
        $newToken = $user->generateToken();
        $user->token = $newToken;

        $expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));
        $ok = $user->storeSession(
            $_SERVER['REMOTE_ADDR']    ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $expiresAt
        );

        if (!$ok) {
            $this->clearCookie('remember_me');
            return false;
        }

        $user->trustLoginIp(
            $_SERVER['REMOTE_ADDR']    ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        );

        setcookie('authentication_token', $newToken, time() + 28800, '/', $this->cookieDomain, true, true);
        $_COOKIE['authentication_token'] = $newToken;

        // Rotate the remember token
        $user->revokeRememberToken($remember);
        $user->createRememberToken($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', $this->cookieDomain);

        return true;
    }

    private function clearCookie(string $name): void
    {
        setcookie($name, '', time() - 3600, '/', $this->cookieDomain, true, true);
        unset($_COOKIE[$name]);
    }
}
