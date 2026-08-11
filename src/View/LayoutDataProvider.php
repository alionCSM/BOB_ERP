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

            // ── Top-bar: societa' del gruppo ─────────────────────────────
            ...$this->groupCompanies($userId),

            // ── CSRF / CSP ───────────────────────────────────────────────
            'csrfToken'          => csrf_token(),
            'cspNonce'           => csp_nonce(),

            // ── Flash ────────────────────────────────────────────────────
            'flash'              => $this->flash(),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Societa' del gruppo dell'utente e quella attiva.
     *
     * Il selettore in top bar compare solo a chi ne ha piu' di una: per tutti
     * gli altri la barra resta identica a prima.
     *
     * @return array{groupCompanies: array, currentGroupCompany: ?array}
     */
    private function groupCompanies(int $userId): array
    {
        $service = $GLOBALS['currentCompany'] ?? new \App\Service\CurrentCompany($this->conn);
        $lista   = $service->availableFor($userId);

        $attiva = null;
        foreach ($lista as $c) {
            if ($c['id'] === $service->id()) {
                $attiva = $c;
                break;
            }
        }

        // null = tutti i moduli abilitati (caso del Consorzio); altrimenti
        // il menu mostra solo le voci della societa' in cui si sta lavorando
        $moduli = null;
        $dati   = $service->current();
        if ($dati && !empty($dati['moduli'])) {
            $moduli = array_filter(array_map('trim', explode(',', (string)$dati['moduli'])));
        }

        return [
            'groupCompanies'      => $lista,
            'currentGroupCompany' => $attiva,
            'moduliSocieta'       => $moduli,
        ];
    }

    /**
     * Filtro sulla societa' attiva da aggiungere alle query sulle notifiche.
     *
     * Restituisce stringa vuota finche' la migration non e' stata applicata,
     * cosi' le notifiche continuano a comparire invece di sparire tutte.
     */
    private function filtroSocietaNotifiche(): string
    {
        static $colonna = null;

        if ($colonna === null) {
            try {
                $stmt    = $this->conn->query("SHOW COLUMNS FROM bb_notifications LIKE 'group_company_id'");
                $colonna = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
            } catch (\Throwable $e) {
                $colonna = false;
            }
        }

        return $colonna ? ' AND n.group_company_id = :cid' : '';
    }

    /** Parametri da unire alla query, coerenti con filtroSocietaNotifiche(). */
    private function parametriSocieta(): array
    {
        if ($this->filtroSocietaNotifiche() === '') {
            return [];
        }
        $service = $GLOBALS['currentCompany'] ?? new \App\Service\CurrentCompany($this->conn);
        return [':cid' => $service->id()];
    }

    private function unreadCount(int $userId): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM bb_notifications n WHERE n.user_id = :uid AND n.is_read = 0'
            . $this->filtroSocietaNotifiche()
        );
        $stmt->execute([':uid' => $userId] + $this->parametriSocieta());
        return (int) $stmt->fetchColumn();
    }

    private function hasHighPriority(int $userId): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM bb_notifications n
             WHERE n.user_id = :uid AND n.is_read = 0 AND n.priority = 'high'"
            . $this->filtroSocietaNotifiche()
        );
        $stmt->execute([':uid' => $userId] + $this->parametriSocieta());
        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function recentNotifications(int $userId): array
    {
        // Si vedono solo le notifiche della societa' in cui si sta lavorando:
        // e' la stessa separazione che vale per menu e dashboard.
        if ($this->filtroSocietaNotifiche() !== '') {
            $stmt = $this->conn->prepare('
                SELECT n.*, u.first_name, u.last_name, w.photo,
                       g.codice AS societa_codice, g.colore AS societa_colore
                FROM   bb_notifications n
                LEFT JOIN bb_users           u ON n.created_by = u.id
                LEFT JOIN bb_workers         w ON u.worker_id  = w.id
                LEFT JOIN bb_group_companies g ON g.id = n.group_company_id
                WHERE  n.user_id  = :uid
                  AND  n.is_read  = 0'
                . $this->filtroSocietaNotifiche() . '
                ORDER  BY n.created_at DESC
                LIMIT  10
            ');
            $stmt->execute([':uid' => $userId] + $this->parametriSocieta());
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

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
