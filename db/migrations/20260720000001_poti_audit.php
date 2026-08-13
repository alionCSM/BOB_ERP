<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — registro delle modifiche e cancellazione logica.
 *
 * Il registro salva lo stato PRIMA e DOPO ogni operazione, non solo la
 * descrizione di cosa e' successo: con i due stati si vede esattamente quale
 * campo e' cambiato, e da un'eliminazione si puo' rimettere indietro la riga.
 * Con il solo testo "ha modificato la prenotazione 12" non si ricostruisce
 * niente.
 *
 * eliminato_at sostituisce la cancellazione vera: le righe restano in
 * tabella e spariscono dalle letture. Serve perche' una prenotazione
 * cancellata per sbaglio, senza questo, non tornerebbe piu' indietro.
 *
 * Il nome di chi ha operato e' copiato nel registro: se l'utente viene
 * cancellato o rinominato, lo storico deve restare leggibile.
 */
final class PotiAudit extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pn_audit')) {
            $this->table('pn_audit', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('entita',     'string',  ['limit' => 30, 'null' => false,
                                                      'comment' => 'autocarrata | prenotazione | macchina | noleggio'])
                ->addColumn('entita_id',  'integer', ['null' => false, 'signed' => false])
                ->addColumn('azione',     'string',  ['limit' => 20, 'null' => false,
                                                      'comment' => 'creato | modificato | eliminato | ripristinato'])
                ->addColumn('etichetta',  'string',  ['limit' => 200, 'null' => true,
                                                      'comment' => 'Come si chiamava la riga: targa, cliente, ...'])
                ->addColumn('user_id',    'integer', ['null' => true, 'signed' => false])
                ->addColumn('user_nome',  'string',  ['limit' => 120, 'null' => true])
                ->addColumn('dati_prima', 'text',    ['null' => true, 'comment' => 'JSON dello stato precedente'])
                ->addColumn('dati_dopo',  'text',    ['null' => true, 'comment' => 'JSON dello stato successivo'])
                ->addColumn('created_at', 'datetime',['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['group_company_id', 'created_at'], ['name' => 'idx_societa_data'])
                ->addIndex(['entita', 'entita_id'], ['name' => 'idx_entita'])
                ->create();
        }

        foreach (['pn_prenotazioni', 'pn_noleggi'] as $tabella) {
            if (!$this->hasTable($tabella)) {
                continue;
            }
            $t = $this->table($tabella);
            if (!$t->hasColumn('eliminato_at')) {
                $t->addColumn('eliminato_at', 'datetime', ['null' => true,
                      'comment' => 'Valorizzata = eliminata, ma recuperabile'])
                  ->addColumn('eliminato_da', 'integer', ['null' => true, 'signed' => false])
                  ->addIndex(['group_company_id', 'eliminato_at'], ['name' => 'idx_eliminato'])
                  ->update();
            }
        }
    }

    public function down(): void
    {
        foreach (['pn_prenotazioni', 'pn_noleggi'] as $tabella) {
            if (!$this->hasTable($tabella)) {
                continue;
            }
            $t = $this->table($tabella);
            foreach (['eliminato_at', 'eliminato_da'] as $c) {
                if ($t->hasColumn($c)) { $t->removeColumn($c); }
            }
            $t->update();
        }
        if ($this->hasTable('pn_audit')) {
            $this->table('pn_audit')->drop()->update();
        }
    }
}
