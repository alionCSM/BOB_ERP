<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Chi entra nella Zone di un cantiere, e fin dove.
 *
 * Fino a ora l'accesso a BOB Zone era una cosa sola: o hai il modulo `zone`
 * e vedi tutto di tutti i cantieri, o non vedi niente. Con centoquaranta
 * operai non regge — a un capo squadra si vuole dare il suo cantiere, non
 * l'archivio dell'azienda.
 *
 * Una riga per persona per cantiere, e per ogni famiglia di contenuti un
 * livello: 0 non vede, 1 vede, 2 vede e modifica.
 *
 * Le famiglie sono sei perche' sei sono le sezioni della Zone. Una colonna
 * per famiglia e non righe separate: sono poche e non cambiano, e una
 * tabella chiave-valore avrebbe voluto dire sei righe da leggere e scrivere
 * ogni volta per sapere una cosa sola.
 *
 * La chiave e' l'utente e non il lavoratore: quello che si sta dando e'
 * l'accesso di un account, e un account puo' anche non essere un operaio.
 *
 * Niente foreign key: l'utente di produzione non ha REFERENCES sulle tabelle
 * nuove. L'unico su worksite_id + user_id basta a non assegnare due volte la
 * stessa persona allo stesso cantiere.
 */
final class ZoneAccessi extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_accessi')) {
            return;
        }

        $livello = [
            'limit'   => 1,
            'null'    => false,
            'default' => 1,
            'signed'  => false,
            'comment' => '0 non vede, 1 vede, 2 vede e modifica',
        ];

        $this->table('bb_zone_accessi', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id',     'integer', ['null' => false, 'signed' => false])
            // Un assegnato nuovo vede tutto e non tocca niente: e' il caso
            // piu' comune e il piu' innocuo da sbagliare. Alzare un livello
            // e' una scelta, abbassarlo dopo un danno e' tardi.
            ->addColumn('attivita', 'integer', $livello)
            ->addColumn('file',     'integer', $livello)
            ->addColumn('moduli',   'integer', $livello)
            ->addColumn('disegni',  'integer', $livello)
            ->addColumn('foto',     'integer', $livello)
            ->addColumn('report',   'integer', $livello)
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('created_by', 'integer',  ['null' => true, 'signed' => false])
            ->addIndex(['worksite_id', 'user_id'], ['unique' => true, 'name' => 'uq_cantiere_utente'])
            ->addIndex(['user_id'], ['name' => 'idx_za_utente'])
            ->create();
    }

    public function down(): void
    {
        $this->table('bb_zone_accessi')->drop()->save();
    }
}
