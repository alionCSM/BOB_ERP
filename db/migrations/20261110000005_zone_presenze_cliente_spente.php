<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le presenze al committente: di base NO, si accendono cantiere per
 * cantiere. La migrazione di prima le aveva di base accese: se e' gia'
 * passata, qui si cambia il valore di base e si spengono su tutti i
 * cantieri (nessuno le aveva ancora scelte).
 */
final class ZonePresenzeClienteSpente extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_worksites');
        if (!$t->hasColumn('zone_presenze_cliente')) {
            return;
        }
        $t->changeColumn('zone_presenze_cliente', 'boolean', ['default' => false, 'null' => false])->update();
        $this->execute('UPDATE bb_worksites SET zone_presenze_cliente = 0');
    }

    public function down(): void
    {
        $t = $this->table('bb_worksites');
        if ($t->hasColumn('zone_presenze_cliente')) {
            $t->changeColumn('zone_presenze_cliente', 'boolean', ['default' => true, 'null' => false])->update();
        }
    }
}
