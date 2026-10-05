<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Presenze dichiarate dagli operai e ferie richieste dall'app.
 *
 * PRESENZE. Quello che manda l'operaio non e' una presenza: e' una
 * dichiarazione che l'ufficio guarda, corregge e approva. Per questo sta in
 * una tabella sua e non in bb_presenze, che resta com'e'. Da bb_presenze
 * escono i costi e quello che va in busta paga: una riga in attesa di
 * conferma li' dentro falserebbe i conti di ogni cantiere finche' qualcuno
 * non se ne accorge.
 *
 * All'approvazione la dichiarazione genera la riga vera in bb_presenze, e ne
 * conserva l'id: cosi' si sa sempre da quale dichiarazione e' nata una
 * presenza, e si vede se l'ufficio l'ha corretta prima di approvarla.
 *
 * FERIE. Qui invece la tabella esiste gia' ed e' la stessa cosa, solo
 * inserita dall'ufficio: si aggiunge lo stato invece di farne una seconda.
 * Le righe che ci sono nascono "approvata", perche' le ha messe l'ufficio e
 * ritrovarsele tutte da approvare il giorno del rilascio sarebbe un disastro.
 *
 * Niente foreign key: sulle tabelle nuove in produzione l'utente del
 * database non ha il permesso REFERENCES.
 */
final class PresenzeDichiarate extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_presenze_richieste')) {
            $this->table('bb_presenze_richieste', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worker_id',   'integer', ['null' => false, 'signed' => false])
                ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('data',        'date',    ['null' => false])
                ->addColumn('turno',       'string',  ['limit' => 10, 'null' => false, 'default' => 'Intero',
                                                       'comment' => 'Intero | Mezzo, come in bb_presenze'])
                ->addColumn('note',        'string',  ['limit' => 255, 'null' => true])
                ->addColumn('stato',       'string',  ['limit' => 12, 'null' => false, 'default' => 'in_attesa',
                                                       'comment' => 'in_attesa | approvata | rifiutata'])
                // la presenza nata da questa dichiarazione: serve a risalire
                // da una riga di bb_presenze a chi l'aveva dichiarata
                ->addColumn('presenza_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('motivo',      'string',  ['limit' => 255, 'null' => true,
                                                       'comment' => 'Perche\' e\' stata rifiutata'])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('decisa_at',   'datetime', ['null' => true])
                ->addColumn('decisa_da',   'integer',  ['null' => true, 'signed' => false])
                // l'operaio apre l'app e chiede le sue: e' la lettura piu'
                // frequente di tutte, una per ogni avvio
                ->addIndex(['worker_id', 'data'], ['name' => 'idx_operaio_giorno'])
                // l'ufficio apre l'elenco di quelle da approvare
                ->addIndex(['stato', 'data'], ['name' => 'idx_stato_giorno'])
                ->addIndex(['worksite_id', 'data'], ['name' => 'idx_cantiere_giorno'])
                ->create();
        }

        $ferie = $this->table('bb_ferie_permessi');

        if (!$ferie->hasColumn('stato')) {
            $ferie->addColumn('stato', 'string', [
                'limit'   => 12,
                'null'    => false,
                'default' => 'approvata',
                'comment' => 'in_attesa | approvata | rifiutata. Dall\'ufficio nascono approvate',
            ]);
        }
        if (!$ferie->hasColumn('richiesta_da_operaio')) {
            $ferie->addColumn('richiesta_da_operaio', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Arrivata dall\'app invece che dall\'ufficio',
            ]);
        }
        if (!$ferie->hasColumn('motivo')) {
            $ferie->addColumn('motivo', 'string', [
                'limit'   => 255,
                'null'    => true,
                'comment' => 'Perche\' e\' stata rifiutata',
            ]);
        }
        $ferie->update();

        // Le righe gia' presenti le ha messe l'ufficio: sono approvate per
        // definizione. Il default della colonna lo fa gia' per le nuove, ma
        // scriverlo esplicitamente toglie ogni dubbio su quelle vecchie.
        $this->execute("UPDATE bb_ferie_permessi SET stato = 'approvata' WHERE stato IS NULL OR stato = ''");
    }

    public function down(): void
    {
        if ($this->hasTable('bb_presenze_richieste')) {
            $this->table('bb_presenze_richieste')->drop()->update();
        }

        $this->table('bb_ferie_permessi')
            ->removeColumn('stato')
            ->removeColumn('richiesta_da_operaio')
            ->removeColumn('motivo')
            ->update();
    }
}
