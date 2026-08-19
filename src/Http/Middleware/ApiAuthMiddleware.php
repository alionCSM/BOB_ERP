<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use PDO;
use User;

/**
 * Autenticazione delle rotte /api/v1/* (app Android).
 *
 * A differenza dell'AuthMiddleware web (cookie di sessione + remember-me),
 * l'API mobile usa ESCLUSIVAMENTE il Bearer token di bb_api_tokens:
 *  - nessun cookie => nessun CSRF sulle rotte API;
 *  - il token e' revocabile (revoked_at) e ha scadenza propria;
 *  - i permessi restano quelli dell'utente di bb_users, come nel web.
 *
 * Su fallimento risponde 401 JSON (il client mostra la schermata di login)
 * e termina la richiesta, senza redirect HTML.
 */
final class ApiAuthMiddleware
{
    public function __construct(private PDO $conn) {}

    public function handle(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']   // rewrite nginx
            ?? $_SERVER['HTTP_X_AUTHORIZATION']          // fallback proxy
            ?? '';

        if (!preg_match('/^Bearer\s+(\S+)$/i', trim((string)$header), $m)) {
            $this->unauthorized('Token di accesso mancante');
        }
        $token = $m[1];

        $stmt = $this->conn->prepare("
            SELECT t.user_id, t.expires_at, t.revoked_at, t.group_company_id,
                   u.username, u.active, u.removed
            FROM   bb_api_tokens t
            JOIN   bb_users u ON u.id = t.user_id
            WHERE  t.token_hash = :hash
            LIMIT  1
        ");
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->unauthorized('Token non valido');
        }
        if (!empty($row['revoked_at'])) {
            $this->unauthorized('Token revocato. Accedi di nuovo.');
        }
        if (strtotime((string)$row['expires_at']) <= time()) {
            $this->unauthorized('Token scaduto. Accedi di nuovo.');
        }
        if ((string)($row['active'] ?? 'Y') !== 'Y'
            || (string)($row['removed'] ?? 'N') === 'Y') {
            $this->unauthorized('Account disattivato');
        }

        // Aggiorna last_used_at al massimo una volta ogni ora (evita write a ogni richiesta)
        $upd = $this->conn->prepare("
            UPDATE bb_api_tokens
            SET    last_used_at = NOW()
            WHERE  user_id = :uid
              AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
        ");
        $upd->execute([':uid' => (int)$row['user_id']]);

        // Idrata lo stesso User usato dal web: permessi e societa' identici
        $user = new User($this->conn, (int)$row['user_id']);
        $user->loadPermissions();
        $user->loadCompany();

        $GLOBALS['user']               = $user;
        $GLOBALS['authenticated_user'] = [
            'user_id'  => (int)$row['user_id'],
            'username' => (string)$row['username'],
        ];
        // Societa' persistita sul token (stateless): il client mobile non
        // conserva la sessione PHP, quindi e' il middleware a riapplicarla
        // a ogni richiesta (api_v1_middleware.php).
        $GLOBALS['api_token_company'] = $row['group_company_id'] !== null
            ? (int)$row['group_company_id']
            : null;
    }

    private function unauthorized(string $message): never
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'code'    => 'unauthorized',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
