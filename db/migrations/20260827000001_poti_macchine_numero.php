<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — numero identificativo del mezzo.
 *
 * Su ogni macchina c'e' attaccato un adesivo con un numero. E' quello che
 * la gente legge in cantiere e si scrive sul foglio: la matricola sta sulla
 * targhetta del costruttore, spesso in un punto scomodo, ed e' lunga.
 *
 * Testo e non numero intero: gli adesivi cambiano da lotto a lotto e capita
 * di trovarci uno zero davanti o una lettera ("A-12"). Salvato come intero,
 * "007" tornerebbe indietro come "7" e non corrisponderebbe piu' a quello
 * che si legge sulla macchina.
 *
 * Puo' restare vuoto: i mezzi gia' registrati l'adesivo non ce l'hanno
 * ancora, e obbligarlo bloccherebbe ogni modifica finche' qualcuno non gira
 * il piazzale con le etichette in mano.
 *
 * Niente foreign key: in produzione l'utente del database non ha il
 * permesso REFERENCES.
 */
final class PotiMacchineNumero extends AbstractMigration
{
    public function up(): void
    {
        $macchine = $this->table('pn_macchine');

        if (!$macchine->hasColumn('numero')) {
            $macchine->addColumn('numero', 'string', [
                'limit'   => 20,
                'null'    => true,
                'comment' => "Numero dell'adesivo attaccato sulla macchina",
                'after'   => 'group_company_id',
            ]);
        }

        // Indice e non vincolo di unicita': con il numero vuoto sui mezzi
        // gia' registrati un UNIQUE reggerebbe comunque (MySQL ammette piu'
        // NULL), ma il doppione va intercettato prima, con un messaggio che
        // dice quale macchina ha gia' quel numero. Qui serve solo a far
        // correre la ricerca per numero, che e' il modo in cui questa
        // tabella verra' interrogata piu' spesso.
        if (!$macchine->hasIndex(['group_company_id', 'numero'])) {
            $macchine->addIndex(['group_company_id', 'numero'], ['name' => 'idx_societa_numero']);
        }

        $macchine->update();
    }

    public function down(): void
    {
        $this->table('pn_macchine')
            ->removeIndexByName('idx_societa_numero')
            ->removeColumn('numero')
            ->update();
    }
}
