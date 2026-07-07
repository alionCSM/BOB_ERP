<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Alert calendario noleggi:
 *
 * - bb_worksite_lifting.giorni_extra: giorni aggiunti MANUALMENTE dal
 *   gestore mezzi quando il mezzo e' stato usato fuori dal calendario di
 *   conteggio (es. presenze di sabato con noleggio Lun-Ven). Vengono
 *   sommati ai giorni calcolati (solo tipo Giornaliero).
 *
 * - bb_lifting_calendar_alerts: registro delle segnalazioni gia' inviate
 *   via email (una riga per noleggio+data), per non rinotificare la stessa
 *   situazione ogni giorno.
 */
final class LiftingCalendarAlerts extends AbstractMigration
{
    public function change(): void
    {
        $lifting = $this->table('bb_worksite_lifting');
        if (!$lifting->hasColumn('giorni_extra')) {
            $lifting->addColumn('giorni_extra', 'integer', [
                'null'    => false,
                'default' => 0,
                'comment' => 'Giorni aggiunti manualmente (uso fuori calendario)',
                'after'   => 'festivi_inclusi',
            ])->update();
        }

        if (!$this->hasTable('bb_lifting_calendar_alerts')) {
            $this->table('bb_lifting_calendar_alerts', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('rental_id',    'integer', ['null' => false, 'signed' => false])
                ->addColumn('worksite_id',  'integer', ['null' => false, 'signed' => false])
                ->addColumn('data',         'date',    ['null' => false, 'comment' => 'Giorno fuori calendario con presenze'])
                ->addColumn('presenze_qta', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('notified_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['rental_id', 'data'], ['unique' => true, 'name' => 'uq_rental_data'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->create();
        }
    }
}
