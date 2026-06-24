<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Aggiunge assignee_user_id a bb_zone_tasks per poter inviare notifiche BOB
 * all'operaio/utente assegnato. assignee_name resta per display e sync
 * Fieldwire (che usa nomi liberi).
 */
final class ZoneTaskAssigneeUser extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('bb_zone_tasks');
        if (!$t->hasColumn('assignee_user_id')) {
            $t->addColumn('assignee_user_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'assignee_name'])
              ->addIndex(['assignee_user_id'], ['name' => 'idx_assignee_user'])
              ->update();
        }
    }
}
