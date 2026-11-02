<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * I moduli assegnati "ai capi" o "agli operai" passano a "Cantiere".
 *
 * 20261030000004 ha fatto di capo e operaio un ruolo solo nella Zone, ma
 * le assegnazioni dei moduli sono rimaste coi nomi vecchi: un modulo dato
 * "agli operai" cercava persone col ruolo operaio, che non c'e' piu', e non
 * arrivava a nessuno — niente "Da compilare", niente notifica, niente
 * promemoria. Qui si allineano.
 */
final class ZoneModuliRuoloCantiere extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_form_assegnazioni')) {
            return;
        }

        $this->table('bb_zone_form_assegnazioni')
            ->changeColumn('a_ruolo', 'enum', [
                'values' => ['capo', 'operaio', 'cliente', 'ufficio', 'cantiere'],
                'null'   => true,
            ])
            ->update();

        $this->execute("UPDATE bb_zone_form_assegnazioni SET a_ruolo = 'cantiere' WHERE a_ruolo IN ('capo', 'operaio')");

        $this->table('bb_zone_form_assegnazioni')
            ->changeColumn('a_ruolo', 'enum', [
                'values' => ['cantiere', 'cliente', 'ufficio'],
                'null'   => true,
            ])
            ->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('bb_zone_form_assegnazioni')) {
            return;
        }

        $this->table('bb_zone_form_assegnazioni')
            ->changeColumn('a_ruolo', 'enum', [
                'values' => ['capo', 'operaio', 'cliente', 'ufficio', 'cantiere'],
                'null'   => true,
            ])
            ->update();

        // come per gli accessi: tornando indietro diventano dei capi
        $this->execute("UPDATE bb_zone_form_assegnazioni SET a_ruolo = 'capo' WHERE a_ruolo = 'cantiere'");

        $this->table('bb_zone_form_assegnazioni')
            ->changeColumn('a_ruolo', 'enum', [
                'values' => ['capo', 'operaio', 'cliente', 'ufficio'],
                'null'   => true,
            ])
            ->update();
    }
}
