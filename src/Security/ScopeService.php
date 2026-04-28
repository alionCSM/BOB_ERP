<?php

declare(strict_types=1);

namespace App\Security;
use PDO;
use App\Domain\User;

class ScopeService
{
    /** @return array<int>|string */
    public function resolveAllowedWorksites(PDO $connection, User $user)
    {
        if ($user->type === 'user') {
            if ((int)$user->company_id === 1) {
                return 'ALL';
            }

            $stmt = $connection->prepare('SELECT id FROM bb_worksites WHERE company_id = :cid');
            $stmt->execute([':cid' => $user->company_id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($user->type === 'worker') {
            $stmt = $connection->prepare('SELECT worksite_id FROM bb_worksite_assignments WHERE worker_id = :wid');
            $stmt->execute([':wid' => $user->worker_id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($user->type === 'client') {
            $stmt = $connection->prepare('SELECT id FROM bb_worksites WHERE client_id = :cid');
            $stmt->execute([':cid' => $user->client_id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return [];
    }

    /** @param array<int>|string $allowedWorksites */
    public function canAccessWorksite($allowedWorksites, int $worksiteId): bool
    {
        if ($allowedWorksites === 'ALL') {
            return true;
        }

        return in_array($worksiteId, $allowedWorksites, true);
    }
}
