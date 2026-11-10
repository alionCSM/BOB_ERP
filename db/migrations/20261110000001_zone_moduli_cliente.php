<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * I moduli del committente.
 *
 * Il committente crea i suoi moduli, per un cantiere o per tutti i suoi
 * cantieri. "Tutti i cantieri" di CSM (worksite_id NULL) li vedrebbe ogni
 * committente: qui il modello porta il cliente a cui appartiene, e un
 * modello senza cantiere ma con il cliente compare solo nei cantieri di
 * quel cliente.
 */
final class ZoneModuliCliente extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_zone_form_templates');
        if (!$t->hasColumn('client_id')) {
            $t->addColumn('client_id', 'integer', ['null' => true, 'after' => 'worksite_id'])
              ->addIndex(['client_id'])
              ->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('bb_zone_form_templates');
        if ($t->hasColumn('client_id')) {
            $t->removeIndex(['client_id'])->removeColumn('client_id')->update();
        }
    }
}
