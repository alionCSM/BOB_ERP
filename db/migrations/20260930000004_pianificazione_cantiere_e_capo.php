<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pianificazione: cantiere collegato all'anagrafica e capo squadra.
 *
 * CANTIERE. Finora era testo battuto a mano — "Via Roma Lecco", "cantiere
 * Bergamo" — e scritto cosi' non si incrocia con niente: ne' con le presenze,
 * che hanno un worksite_id, ne' con i costi, ne' con BOB Zone. Voleva dire
 * che la pianificazione sapeva chi andava dove, ma quel "dove" non era la
 * stessa cosa che BOB chiama cantiere.
 *
 * Si aggiunge worksite_id accanto al testo invece di sostituirlo: le righe
 * gia' pianificate hanno solo il testo, e buttarlo vorrebbe dire perdere la
 * programmazione passata. Il testo resta come etichetta e come ripiego per
 * quello che si scrive prima che il cantiere sia a sistema.
 *
 * CAPO SQUADRA. Sulla riga dell'operaio, non su quella del cantiere: il capo
 * e' uno di quelli che ci vanno, e tenerlo come campo separato in testata
 * vorrebbe dire poterci scrivere un nome che nella squadra non c'e'.
 *
 * Niente foreign key: sulle tabelle nuove in produzione l'utente del
 * database non ha il permesso REFERENCES.
 */
final class PianificazioneCantiereECapo extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->table('bb_pianificazione');

        if (!$p->hasColumn('worksite_id')) {
            $p->addColumn('worksite_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'bb_worksites.id. Vuoto sulle righe pianificate prima, dove c\'e\' solo il testo',
                'after'   => 'cantiere',
            ]);
        }
        if (!$p->hasIndex(['worksite_id', 'data'])) {
            // "chi c'e' domani su questo cantiere" e "dove sono domani":
            // sono le due letture dell'app, una per ogni operaio ogni mattina
            $p->addIndex(['worksite_id', 'data'], ['name' => 'idx_cantiere_giorno']);
        }
        $p->update();

        $n = $this->table('bb_pianificazione_nostri');

        if (!$n->hasColumn('capo_squadra')) {
            $n->addColumn('capo_squadra', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Chi guida la squadra quel giorno: vede piu' . "'" . ' informazioni nell\'app',
            ]);
        }
        if (!$n->hasIndex(['worker_id'])) {
            // l'operaio apre l'app e chiede dove deve andare: si parte da lui
            $n->addIndex(['worker_id'], ['name' => 'idx_operaio']);
        }
        $n->update();
    }

    public function down(): void
    {
        $this->table('bb_pianificazione')
            ->removeIndexByName('idx_cantiere_giorno')
            ->removeColumn('worksite_id')
            ->update();

        $this->table('bb_pianificazione_nostri')
            ->removeIndexByName('idx_operaio')
            ->removeColumn('capo_squadra')
            ->update();
    }
}
