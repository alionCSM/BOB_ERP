<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le presenze del cantiere: si mostrano al committente o no. Lo decide CSM
 * cantiere per cantiere; di base si', come prima.
 */
final class ZonePresenzeCliente extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_worksites');
        if (!$t->hasColumn('zone_presenze_cliente')) {
            $t->addColumn('zone_presenze_cliente', 'boolean', ['default' => true, 'null' => false])->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('bb_worksites');
        if ($t->hasColumn('zone_presenze_cliente')) {
            $t->removeColumn('zone_presenze_cliente')->update();
        }
    }
}
