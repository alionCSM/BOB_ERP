<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Backfill the new `view_prices` permission for users who were hardcoded
 * in AiSqlController, ApiController, WorksitesController etc. Behaviour
 * is preserved on day one; from this point forward, granting/revoking
 * price visibility happens through the normal permissions UI.
 */
final class ViewPricesPermissionBackfill extends AbstractMigration
{
    public function up(): void
    {
        $hardcoded = ['alion', 'laura', 'osman', 'elena', 'ermal'];
        $placeholders = implode(',', array_fill(0, count($hardcoded), '?'));

        $stmt = $this->getAdapter()->getConnection()->prepare(
            "SELECT id, username FROM bb_users WHERE username IN ({$placeholders})"
        );
        $stmt->execute($hardcoded);
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // No UNIQUE constraint on (user_id, module) is assumed — do an
        // existence check then insert/update to stay portable.
        $check = $this->getAdapter()->getConnection()->prepare(
            "SELECT id FROM bb_user_permissions WHERE user_id = :uid AND module = 'view_prices' LIMIT 1"
        );
        $insert = $this->getAdapter()->getConnection()->prepare(
            "INSERT INTO bb_user_permissions (user_id, module, allowed) VALUES (:uid, 'view_prices', 1)"
        );
        $update = $this->getAdapter()->getConnection()->prepare(
            "UPDATE bb_user_permissions SET allowed = 1 WHERE user_id = :uid AND module = 'view_prices'"
        );

        $userIds = array_map(fn($u) => (int)$u['id'], $users);
        if (!in_array(1, $userIds, true)) {
            $userIds[] = 1; // superadmin defensively
        }

        foreach ($userIds as $uid) {
            $check->execute([':uid' => $uid]);
            if ($check->fetchColumn()) {
                $update->execute([':uid' => $uid]);
            } else {
                $insert->execute([':uid' => $uid]);
            }
        }
    }

    public function down(): void
    {
        $this->getAdapter()->getConnection()
            ->prepare("DELETE FROM bb_user_permissions WHERE module = 'view_prices'")
            ->execute();
    }
}
