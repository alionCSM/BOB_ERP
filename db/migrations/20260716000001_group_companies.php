<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Societa' del gruppo (multi-azienda).
 *
 * ATTENZIONE — da non confondere con bb_companies: quella tabella contiene le
 * AZIENDE CONSORZIATE (fornitori del Consorzio) e le anagrafiche collegate ai
 * lavoratori. Qui invece stanno le SOCIETA' DEL GRUPPO, che sono aziende
 * distinte e indipendenti fra loro (Consorzio, Poti Noleggi, ...), ognuna con
 * i propri dati e i propri moduli.
 *
 * Tenerle separate e' voluto: mescolarle renderebbe impossibile garantire che
 * i dati di una societa' non siano visibili alle altre.
 *
 * I dati gia' presenti in BOB appartengono tutti al Consorzio (id 1), che
 * continua a funzionare esattamente come prima.
 */
final class GroupCompanies extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_group_companies')) {
            $this->table('bb_group_companies', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('nome',        'string',  ['limit' => 120, 'null' => false])
                ->addColumn('codice',      'string',  ['limit' => 20,  'null' => false,
                                                       'comment' => 'Sigla breve mostrata nel selettore'])
                ->addColumn('colore',      'string',  ['limit' => 9, 'null' => false, 'default' => '#1e3a5f',
                                                       'comment' => 'Colore identificativo in UI'])
                ->addColumn('moduli',      'text',    ['null' => true,
                                                       'comment' => 'CSV dei moduli abilitati; NULL = tutti'])
                ->addColumn('attiva',      'boolean', ['null' => false, 'default' => true])
                ->addColumn('ordinamento', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at',  'datetime',['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['codice'], ['unique' => true, 'name' => 'uq_codice'])
                ->create();

            // Consorzio = id 1: e' la societa' a cui appartiene tutto lo
            // storico, e resta il default per chi non ha altre assegnazioni.
            $this->execute("
                INSERT INTO bb_group_companies (id, nome, codice, colore, moduli, attiva, ordinamento)
                VALUES (1, 'Consorzio Soluzione Montaggi', 'CSM', '#1e3a5f', NULL, 1, 0)
            ");
        }

        if (!$this->hasTable('bb_user_companies')) {
            $this->table('bb_user_companies', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('user_id',           'integer',  ['null' => false, 'signed' => false])
                ->addColumn('group_company_id',  'integer',  ['null' => false, 'signed' => false])
                ->addColumn('is_default',        'boolean',  ['null' => false, 'default' => false,
                                                              'comment' => 'Societa' . "'" . ' proposta per prima al login'])
                ->addColumn('created_at',        'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_id', 'group_company_id'], ['unique' => true, 'name' => 'uq_user_company'])
                ->addIndex(['group_company_id'], ['name' => 'idx_company'])
                ->create();

            // Tutti gli utenti interni esistenti entrano nel Consorzio: senza
            // questo si troverebbero senza societa' e non potrebbero accedere.
            $this->execute("
                INSERT INTO bb_user_companies (user_id, group_company_id, is_default)
                SELECT id, 1, 1 FROM bb_users
            ");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_user_companies'))  { $this->table('bb_user_companies')->drop()->update(); }
        if ($this->hasTable('bb_group_companies')) { $this->table('bb_group_companies')->drop()->update(); }
    }
}
