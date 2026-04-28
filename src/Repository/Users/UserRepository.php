<?php

declare(strict_types=1);

namespace App\Repository\Users;

use PDO;

/**
 * Application-user (bb_users) queries.
 *
 * Note: the full User domain class (App\Domain\User) still owns the complex
 * session / auth / registration flow. This repository covers the read-only
 * queries needed by other bounded contexts (e.g. WorksitesController).
 */
final class UserRepository
{
    public function __construct(private PDO $conn) {}

    /**
     * All worker- and client-type users that can be assigned to a worksite.
     */
    public function getAssignableUsers(): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, first_name, last_name, username, type
            FROM bb_users
            WHERE type IN ('worker', 'client')
              AND active  = 'Y'
              AND removed = 'N'
            ORDER BY type, first_name, last_name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Users currently assigned to a worksite (via bb_worksite_users).
     */
    public function getUsersByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.username, u.type
            FROM bb_worksite_users wu
            JOIN bb_users u ON u.id = wu.user_id
            WHERE wu.worksite_id = :wid
              AND u.active  = 'Y'
              AND u.removed = 'N'
            ORDER BY u.type, u.first_name, u.last_name
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
