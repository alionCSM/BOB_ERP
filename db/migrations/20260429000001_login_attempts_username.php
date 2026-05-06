<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-username lockout for login rate-limiter (AuthController).
 *
 * Adds a nullable username column and a composite index used by the
 * "10 fails per 15 minutes" check that defends against distributed
 * brute-force where the per-IP limit is trivially bypassed.
 *
 * Idempotent — safe to re-run if a previous (lazy ALTER) attempt already
 * applied the column outside Phinx.
 */
final class LoginAttemptsUsername extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_login_attempts');

        if (!$table->hasColumn('username')) {
            $table->addColumn('username', 'string', [
                'limit'   => 150,
                'null'    => true,
                'default' => null,
                'after'   => 'ip_address',
            ]);
        }

        if (!$table->hasIndexByName('idx_username_time')) {
            $table->addIndex(['username', 'attempted_at'], ['name' => 'idx_username_time']);
        }

        $table->update();
    }
}
