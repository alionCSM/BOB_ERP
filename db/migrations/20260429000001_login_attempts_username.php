<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-username lockout for login rate-limiter (AuthController).
 *
 * Adds a nullable username column and a composite index used by the
 * "10 fails per 15 minutes" check that defends against distributed
 * brute-force where the per-IP limit is trivially bypassed.
 */
final class LoginAttemptsUsername extends AbstractMigration
{
    public function change(): void
    {
        $this->table('bb_login_attempts')
            ->addColumn('username', 'string', [
                'limit'   => 150,
                'null'    => true,
                'default' => null,
                'after'   => 'ip_address',
            ])
            ->addIndex(['username', 'attempted_at'], ['name' => 'idx_username_time'])
            ->update();
    }
}
