<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — autocarrate e prenotazioni.
 *
 * Primo modulo nato gia' multi-societa': ogni riga porta group_company_id,
 * cosi' i dati di Poti non si mescolano con quelli del Consorzio. I moduli
 * storici verranno adeguati dopo, uno per volta.
 *
 * Niente foreign key: l'utente del database in produzione non ha il
 * permesso REFERENCES, quindi si usano gli indici.
 */
final class PotiAutocarrate extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pn_autocarrate')) {
            $this->table('pn_autocarrate', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('targa',            'string',  ['limit' => 20, 'null' => false])
                ->addColumn('modello',          'string',  ['limit' => 120, 'null' => true])
                // altezza e portata sono numeri e non testo libero: servono
                // per rispondere a "ne hai una che arriva a 30 metri?"
                ->addColumn('altezza_max_m',    'decimal', ['precision' => 6, 'scale' => 2, 'null' => true,
                                                            'comment' => 'Altezza massima di lavoro in metri'])
                ->addColumn('portata_kg',       'integer', ['null' => true, 'signed' => false])
                ->addColumn('note',             'text',    ['null' => true,
                                                            'comment' => 'Altre caratteristiche, in forma libera'])
                ->addColumn('stato',            'string',  ['limit' => 20, 'null' => false, 'default' => 'attiva',
                                                            'comment' => 'attiva | manutenzione | dismessa'])
                ->addColumn('created_at',       'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['group_company_id'], ['name' => 'idx_societa'])
                ->addIndex(['group_company_id', 'targa'], ['unique' => true, 'name' => 'uq_societa_targa'])
                ->create();
        }

        if (!$this->hasTable('pn_prenotazioni')) {
            $this->table('pn_prenotazioni', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('autocarrata_id',   'integer', ['null' => false, 'signed' => false])
                // il cliente e' testo libero: Poti non ha ancora un'anagrafica
                // e quella del Consorzio non c'entra
                ->addColumn('cliente',          'string',  ['limit' => 160, 'null' => false])
                ->addColumn('telefono',         'string',  ['limit' => 40, 'null' => true])
                ->addColumn('luogo',            'string',  ['limit' => 200, 'null' => true])
                ->addColumn('data_inizio',      'date',    ['null' => false])
                ->addColumn('data_fine',        'date',    ['null' => false])
                ->addColumn('stato',            'string',  ['limit' => 20, 'null' => false, 'default' => 'confermata',
                                                            'comment' => 'opzione | confermata | annullata'])
                ->addColumn('tariffa_giorno',   'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
                ->addColumn('totale',           'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
                ->addColumn('note',             'text',     ['null' => true])
                ->addColumn('created_by',       'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',       'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                // l'indice per mezzo e data e' quello che regge sia la
                // timeline sia il controllo delle sovrapposizioni
                ->addIndex(['autocarrata_id', 'data_inizio', 'data_fine'], ['name' => 'idx_mezzo_periodo'])
                ->addIndex(['group_company_id'], ['name' => 'idx_societa'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pn_prenotazioni')) { $this->table('pn_prenotazioni')->drop()->update(); }
        if ($this->hasTable('pn_autocarrate'))  { $this->table('pn_autocarrate')->drop()->update(); }
    }
}
