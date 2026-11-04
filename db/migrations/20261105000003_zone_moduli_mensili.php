<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moduli da compilare ogni mese: un controllo mensile dei mezzi, un
 * verbale di fine mese. C'erano una volta, ogni giorno, ogni settimana.
 */
final class ZoneModuliMensili extends AbstractMigration
{
    public function up(): void
    {
        $this->table('bb_zone_form_assegnazioni')
            ->changeColumn('frequenza', 'enum', [
                'values'  => ['una_volta', 'giornaliera', 'settimanale', 'mensile'],
                'null'    => false,
                'default' => 'una_volta',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->execute("UPDATE bb_zone_form_assegnazioni SET frequenza = 'settimanale' WHERE frequenza = 'mensile'");
        $this->table('bb_zone_form_assegnazioni')
            ->changeColumn('frequenza', 'enum', [
                'values'  => ['una_volta', 'giornaliera', 'settimanale'],
                'null'    => false,
                'default' => 'una_volta',
            ])
            ->update();
    }
}
