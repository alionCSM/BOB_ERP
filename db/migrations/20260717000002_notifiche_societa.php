<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Societa' del gruppo sulle notifiche.
 *
 * Il valore predefinito e' 1 (Consorzio) di proposito: le notifiche
 * esistenti e i punti che le creano oggi appartengono tutti al Consorzio,
 * quindi restano corretti senza dover modificare i cinque punti sparsi che
 * fanno l'INSERT. I moduli nuovi passano l'id esplicitamente.
 */
final class NotificheSocieta extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_notifications');

        if (!$t->hasColumn('group_company_id')) {
            $t->addColumn('group_company_id', 'integer', [
                    'null'    => false,
                    'default' => 1,
                    'signed'  => false,
                    'comment' => 'Societa' . "'" . ' del gruppo a cui si riferisce la notifica',
                ])
              ->addIndex(['group_company_id'], ['name' => 'idx_group_company'])
              ->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('bb_notifications');
        if ($t->hasColumn('group_company_id')) {
            $t->removeColumn('group_company_id')->update();
        }
    }
}
