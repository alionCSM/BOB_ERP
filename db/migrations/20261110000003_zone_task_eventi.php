<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La cronologia di un'attivita': chi l'ha portata avanti, chiusa,
 * verificata o riaperta, e quando. Chi l'ha creata sta gia' nell'attivita'
 * (created_by, created_at).
 */
final class ZoneTaskEventi extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_task_eventi')) {
            return;
        }
        $this->table('bb_zone_task_eventi')
            ->addColumn('task_id', 'integer')
            ->addColumn('worksite_id', 'integer')
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('stato_da', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('stato_a', 'string', ['limit' => 20])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['worksite_id'])
            ->addIndex(['task_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_task_eventi')) {
            $this->table('bb_zone_task_eventi')->drop()->save();
        }
    }
}
