<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Noleggio mezzi sollevamento: il costo non deriva piu' dalle presenze ma
 * dalla periodicita' del noleggio.
 *
 * - tipo_noleggio: 'Una Tantum' | 'Giornaliero' | 'Settimanale' | 'Mensile'
 * - calendario (solo Giornaliero): quali giorni si contano
 *     lun_ven | lun_sab | lun_dom | sab_dom
 * - festivi_inclusi (solo Giornaliero): se i festivi nazionali contano
 *   come giorni di noleggio.
 *
 * costo_giornaliero resta il nome della colonna ma il significato e'
 * "costo per periodo" (giorno/settimana/mese/una tantum).
 */
final class LiftingRentalPeriods extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('bb_worksite_lifting');

        // Assicura che tipo_noleggio accetti i nuovi valori anche se in
        // origine era un ENUM ristretto.
        $table->changeColumn('tipo_noleggio', 'string', [
            'limit'   => 20,
            'null'    => false,
            'default' => 'Giornaliero',
        ]);

        if (!$table->hasColumn('calendario')) {
            $table->addColumn('calendario', 'string', [
                'limit'   => 10,
                'null'    => false,
                'default' => 'lun_ven',
                'comment' => 'Conteggio giorni (solo Giornaliero): lun_ven|lun_sab|lun_dom|sab_dom',
                'after'   => 'tipo_noleggio',
            ]);
        }

        if (!$table->hasColumn('festivi_inclusi')) {
            $table->addColumn('festivi_inclusi', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Se i festivi nazionali contano come giorni di noleggio',
                'after'   => 'calendario',
            ]);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('bb_worksite_lifting');
        if ($table->hasColumn('festivi_inclusi')) $table->removeColumn('festivi_inclusi');
        if ($table->hasColumn('calendario'))      $table->removeColumn('calendario');
        $table->update();
    }
}
