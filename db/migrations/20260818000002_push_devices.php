<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dispositivi mobili registrati per le notifiche push (FCM).
 *
 * Un utente puo' avere piu' dispositivi (es. telefono di lavoro + telefono
 * personale). L'FCM token e' univoco: quando un dispositivo lo ritira, FCM
 * lo segnala come non valido e qui viene disattivato (is_active = 0) invece
 * di cancellarlo, per non perdere lo storico dell'ultimo utilizzo.
 */
final class PushDevices extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_push_devices')) {
            return;
        }

        $this->table('bb_push_devices', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('fcm_token', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('platform', 'string', ['limit' => 20, 'null' => false, 'default' => 'android'])
            ->addColumn('device_name', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('app_version', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('last_seen_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['user_id'], ['name' => 'idx_push_devices_user'])
            ->addIndex(['fcm_token'], ['name' => 'uq_push_devices_fcm', 'unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_push_devices')) {
            $this->table('bb_push_devices')->drop()->update();
        }
    }
}
