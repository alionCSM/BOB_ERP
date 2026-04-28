<?php

declare(strict_types=1);

namespace App\View;

use PDO;
use App\Infrastructure\Config;

/**
 * Gathers all data required by the shared layout templates
 * (base, menu, topbar) in a single, testable place.
 *
 * Replaces the ad-hoc SQL scattered across:
 *   includes/template/top_bar.php
 *   includes/template/menu.php
 */
final class LayoutDataProvider
{
    public function __construct(
        private readonly PDO    $conn,
        private readonly array  $authenticatedUser,
        private readonly \User  $user,
        private readonly Config $config,
    ) {}

    /** @return array<string, mixed> Ready-to-use Twig globals for every layout template. */
    public function getData(): array
    {
        $userId = (int) ($this->authenticatedUser['user_id'] ?? 0);

        return [
            // ── User identity ─────────────────────────────────────────────
            'user'               => $this->user,
            'isCompanyScopedUser'=> $GLOBALS['isCompanyScopedUser'] ?? false,

            // ── Navigation ────────────────────────────────────────────────
            'currentPath'        => strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/',
            'appUrl'             => $this->config->appUrl(),
            'bobVersion'         => $this->bobVersion(),

            // ── Top-bar: current user display ────────────────────────────
            'currentUserId'      => $userId,
            ...$this->currentUserDisplay($userId),

            // ── Top-bar: notifications ────────────────────────────────────
            'unreadCount'        => $this->unreadCount($userId),
            'hasHighPriority'    => $this->hasHighPriority($userId),
            'notifications'      => $this->recentNotifications($userId),
            'vapidPublicKey'     => $this->config->vapidPublicKey(),

            // ── CSRF / CSP ───────────────────────────────────────────────
            'csrfToken'          => csrf_token(),
            'cspNonce'           => csp_nonce(),

            // ── Flash ────────────────────────────────────────────────────
            'flash'              => $this->flash(),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function unreadCount(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM bb_notifications WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function hasHighPriority(int $userId): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM bb_notifications WHERE user_id = :uid AND is_read = 0 AND priority = 'high'"
        );
        $stmt->execute([':uid' => $userId]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function recentNotifications(int $userId): array
    {
        $stmt = $this->conn->prepare('
            SELECT n.*, u.first_name, u.last_name, w.photo
            FROM   bb_notifications n
            LEFT JOIN bb_users   u ON n.created_by = u.id
            LEFT JOIN bb_workers w ON u.worker_id  = w.id
            WHERE  n.user_id  = :uid
              AND  n.is_read  = 0
            ORDER  BY n.created_at DESC
            LIMIT  10
        ');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{currentUserName: string, currentUserPhoto: string, currentCompanyName: string} */
    private function currentUserDisplay(int $userId): array
    {
        $stmt = $this->conn->prepare('
            SELECT u.first_name, u.last_name, u.username, u.photo,
                   COALESCE(c.name, u.company, \'N/D\') AS company_name
            FROM   bb_users    u
            LEFT JOIN bb_companies c ON c.id = u.company_id
            WHERE  u.id = :uid
            LIMIT  1
        ');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($row['username'] ?? 'User');
        }

        $photo = (string) ($row['photo'] ?? '');
        if ($photo === '') {
            $photo = '/uploads/avatar.jpg';
        } elseif (str_starts_with($photo, 'Users/')) {
            $photo = '/users/' . $userId . '/user-photo';
        } elseif (!preg_match('#^https?://#i', $photo) && ($photo[0] ?? '') !== '/') {
            $photo = '/' . ltrim($photo, '/');
        }

        return [
            'currentUserName'    => $name,
            'currentUserPhoto'   => $photo,
            'currentCompanyName' => (string) ($row['company_name'] ?? 'N/D'),
        ];
    }

    private function bobVersion(): array
    {
        return getBobVersion();
    }

    /** @return array{type: string, message: string}|null */
    private function flash(): ?array
    {
        // Session-based flash (set by controllers via $_SESSION['success'|'error'|'info'])
        foreach (['success', 'error', 'info'] as $type) {
            if (!empty($_SESSION[$type])) {
                $message = (string) $_SESSION[$type];
                unset($_SESSION[$type]);
                return ['type' => $type, 'message' => $message];
            }
        }
        // Legacy: query-string flash (?success=... / ?error=...)
        foreach (['success', 'error', 'info'] as $type) {
            if (!empty($_GET[$type])) {
                return ['type' => $type, 'message' => (string) $_GET[$type]];
            }
        }
        return null;
    }
}
