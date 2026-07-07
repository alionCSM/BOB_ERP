<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Noleggio mezzi — calendario a giorni liberi + giorni extra come date.
 *
 * - calendario: non piu' preset (lun_ven, ...) ma un set libero di giorni
 *   della settimana in CSV ISO (1=lun ... 7=dom), es. "1,2,3,4,5".
 *   I preset esistenti vengono convertiti.
 *
 * - giorni_extra (contatore) sostituito da bb_lifting_extra_days: una riga
 *   per DATA specifica conteggiata fuori calendario (es. sabato 04/07),
 *   cosi' si sa esattamente quali giorni sono stati aggiunti e perche'.
 */
final class LiftingWeekdayCalendar extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('bb_worksite_lifting');

        $table->changeColumn('calendario', 'string', [
            'limit'   => 30,
            'null'    => false,
            'default' => '1,2,3,4,5',
            'comment' => 'Giorni della settimana conteggiati, CSV ISO (1=lun...7=dom)',
        ])->update();

        // converti i preset della prima versione
        $this->execute("UPDATE bb_worksite_lifting SET calendario = '1,2,3,4,5'     WHERE calendario = 'lun_ven'");
        $this->execute("UPDATE bb_worksite_lifting SET calendario = '1,2,3,4,5,6'   WHERE calendario = 'lun_sab'");
        $this->execute("UPDATE bb_worksite_lifting SET calendario = '1,2,3,4,5,6,7' WHERE calendario = 'lun_dom'");
        $this->execute("UPDATE bb_worksite_lifting SET calendario = '6,7'           WHERE calendario = 'sab_dom'");

        if ($table->hasColumn('giorni_extra')) {
            $table->removeColumn('giorni_extra')->update();
        }

        if (!$this->hasTable('bb_lifting_extra_days')) {
            $this->table('bb_lifting_extra_days', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('rental_id',  'integer',  ['null' => false, 'signed' => false])
                ->addColumn('data',       'date',     ['null' => false, 'comment' => 'Giorno fuori calendario conteggiato comunque'])
                ->addColumn('created_by', 'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['rental_id', 'data'], ['unique' => true, 'name' => 'uq_rental_extra_data'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_lifting_extra_days')) {
            $this->table('bb_lifting_extra_days')->drop()->update();
        }
        $table = $this->table('bb_worksite_lifting');
        if (!$table->hasColumn('giorni_extra')) {
            $table->addColumn('giorni_extra', 'integer', ['null' => false, 'default' => 0])->update();
        }
    }
}
