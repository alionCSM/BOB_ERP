<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — macchine (piattaforme, carrelli, telescopici, ...) e noleggi.
 *
 * Differenza sostanziale rispetto alle autocarrate: qui un noleggio puo'
 * contenere piu' macchine, quindi serve una testata con delle righe. Ogni
 * riga ha il suo periodo, perche' capita che una macchina arrivi dopo o
 * rientri prima delle altre.
 *
 * Sulla testata data_inizio e data_fine sono il minimo e il massimo delle
 * righe: sono ripetute di proposito, perche' senza di esse filtrare per
 * periodo o disegnare il calendario vorrebbe dire leggere tutte le righe di
 * tutti i noleggi ogni volta. Vengono riscritte a ogni salvataggio.
 *
 * Niente foreign key: in produzione l'utente del database non ha il
 * permesso REFERENCES, quindi si usano gli indici.
 */
final class PotiMacchine extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pn_macchine')) {
            $this->table('pn_macchine', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('tipo',             'string',  ['limit' => 60, 'null' => false,
                                                            'comment' => 'Piattaforma, carrello elevatore, telescopico, ...'])
                ->addColumn('matricola',        'string',  ['limit' => 40, 'null' => false])
                ->addColumn('modello',          'string',  ['limit' => 120, 'null' => true])
                ->addColumn('altezza_max_m',    'decimal', ['precision' => 6, 'scale' => 2, 'null' => true])
                ->addColumn('portata_kg',       'integer', ['null' => true, 'signed' => false])
                ->addColumn('note',             'text',    ['null' => true])
                ->addColumn('stato',            'string',  ['limit' => 20, 'null' => false, 'default' => 'attiva',
                                                            'comment' => 'attiva | manutenzione | dismessa'])
                ->addColumn('origine_id',       'integer', ['null' => true, 'signed' => false,
                                                            'comment' => 'id nel vecchio database noleggi'])
                ->addColumn('created_at',       'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['group_company_id', 'matricola'], ['unique' => true, 'name' => 'uq_societa_matricola'])
                ->addIndex(['group_company_id', 'tipo'], ['name' => 'idx_tipo'])
                ->addIndex(['group_company_id', 'origine_id'], ['name' => 'idx_origine'])
                ->create();
        }

        if (!$this->hasTable('pn_noleggi')) {
            $this->table('pn_noleggi', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('cliente',          'string',  ['limit' => 160, 'null' => false])
                ->addColumn('telefono',         'string',  ['limit' => 40, 'null' => true])
                ->addColumn('luogo',            'string',  ['limit' => 200, 'null' => true])
                ->addColumn('contratto',        'string',  ['limit' => 100, 'null' => true])
                // minimo e massimo delle righe, riscritti a ogni salvataggio
                ->addColumn('data_inizio',      'date',    ['null' => true])
                ->addColumn('data_fine',        'date',    ['null' => true])
                ->addColumn('stato',            'string',  ['limit' => 20, 'null' => false, 'default' => 'confermato',
                                                            'comment' => 'confermato | annullato'])
                ->addColumn('trasporto',        'decimal', ['precision' => 10, 'scale' => 2, 'null' => true,
                                                            'comment' => 'Importo unico del trasporto'])
                ->addColumn('totale',           'decimal', ['precision' => 10, 'scale' => 2, 'null' => true,
                                                            'comment' => 'Somma delle righe piu\' trasporto, correggibile'])
                ->addColumn('pagamento',        'string',  ['limit' => 20, 'null' => false, 'default' => 'da_pagare'])
                ->addColumn('note',             'text',    ['null' => true])
                ->addColumn('commerciale_user_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('commerciale_testo', 'string', ['limit' => 120, 'null' => true,
                                                            'comment' => 'Nome nei dati importati, dove non e\' un utente'])
                ->addColumn('created_by',       'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',       'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('origine_id',       'integer',  ['null' => true, 'signed' => false])
                ->addIndex(['group_company_id', 'data_inizio', 'data_fine'], ['name' => 'idx_societa_periodo'])
                ->addIndex(['group_company_id', 'origine_id'], ['name' => 'idx_origine'])
                ->create();
        }

        if (!$this->hasTable('pn_noleggi_righe')) {
            $this->table('pn_noleggi_righe', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('noleggio_id',   'integer', ['null' => false, 'signed' => false])
                ->addColumn('macchina_id',   'integer', ['null' => false, 'signed' => false])
                ->addColumn('data_inizio',   'date',    ['null' => false])
                ->addColumn('data_fine',     'date',    ['null' => false])
                ->addColumn('tariffa_giorno','decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
                ->addColumn('totale',        'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
                ->addColumn('note',          'string',  ['limit' => 200, 'null' => true])
                // regge sia il controllo delle sovrapposizioni sia la timeline
                ->addIndex(['macchina_id', 'data_inizio', 'data_fine'], ['name' => 'idx_macchina_periodo'])
                ->addIndex(['noleggio_id'], ['name' => 'idx_noleggio'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['pn_noleggi_righe', 'pn_noleggi', 'pn_macchine'] as $t) {
            if ($this->hasTable($t)) { $this->table($t)->drop()->update(); }
        }
    }
}
