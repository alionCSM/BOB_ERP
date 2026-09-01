<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — mezzi presi da altri noleggiatori (sotto-noleggio).
 *
 * Quando il parco non basta, il mezzo si prende da un altro noleggiatore e
 * si gira al cliente. Di quella macchina non sappiamo la matricola e non e'
 * nostra: non sta in pn_macchine e non deve entrare ne' nella
 * disponibilita' ne' nel controllo delle sovrapposizioni, perche' non
 * possiamo impegnare una macchina che non abbiamo.
 *
 * Girano quindi due contratti e due cifre sulla stessa riga: il contratto e
 * il prezzo del noleggiatore (quello che paghiamo noi) e il nostro
 * contratto e il nostro prezzo (quello che paga il cliente). Il nostro
 * contratto sta gia' sulla testata del noleggio e il nostro prezzo nella
 * riga: qui si aggiunge il lato fornitore.
 *
 * macchina_id diventa facoltativa: su una riga di sotto-noleggio non c'e'
 * una macchina nostra da collegare. Chi legge le righe deve usare LEFT JOIN
 * su pn_macchine, altrimenti queste righe spariscono senza dire niente.
 *
 * Niente foreign key: in produzione l'utente del database non ha il
 * permesso REFERENCES.
 */
final class PotiNoleggiSottoNoleggio extends AbstractMigration
{
    public function up(): void
    {
        $righe = $this->table('pn_noleggi_righe');

        // da NOT NULL a facoltativa: le righe esistenti hanno tutte la loro
        // macchina, quindi il passaggio non tocca niente di quello che c'e'
        $righe->changeColumn('macchina_id', 'integer', [
            'null'    => true,
            'signed'  => false,
            'comment' => 'Vuota sulle righe di sotto-noleggio: la macchina non e\' nostra',
        ]);

        if (!$righe->hasColumn('fornitore')) {
            $righe->addColumn('fornitore', 'string', [
                'limit'   => 160,
                'null'    => true,
                'comment' => 'Noleggiatore da cui abbiamo preso il mezzo',
                'after'   => 'macchina_id',
            ]);
        }
        if (!$righe->hasColumn('mezzo_esterno')) {
            $righe->addColumn('mezzo_esterno', 'string', [
                'limit'   => 160,
                'null'    => true,
                'comment' => "Com'e' fatto il mezzo preso a nolo: della matricola non disponiamo",
                'after'   => 'fornitore',
            ]);
        }
        if (!$righe->hasColumn('contratto_fornitore')) {
            $righe->addColumn('contratto_fornitore', 'string', [
                'limit'   => 100,
                'null'    => true,
                'comment' => 'Numero di contratto del noleggiatore, non il nostro',
                'after'   => 'mezzo_esterno',
            ]);
        }
        if (!$righe->hasColumn('costo')) {
            $righe->addColumn('costo', 'decimal', [
                'precision' => 10,
                'scale'     => 2,
                'null'      => true,
                'comment'   => 'Quanto paghiamo al noleggiatore. Il totale della riga resta quello che paga il cliente',
                'after'     => 'contratto_fornitore',
            ]);
        }

        $righe->update();
    }

    public function down(): void
    {
        $righe = $this->table('pn_noleggi_righe');

        // le righe di sotto-noleggio non hanno una macchina da rimettere:
        // vanno tolte, altrimenti macchina_id non puo' tornare obbligatoria
        $this->getAdapter()->getConnection()
             ->exec('DELETE FROM pn_noleggi_righe WHERE macchina_id IS NULL');

        $righe->removeColumn('fornitore')
              ->removeColumn('mezzo_esterno')
              ->removeColumn('contratto_fornitore')
              ->removeColumn('costo')
              ->changeColumn('macchina_id', 'integer', ['null' => false, 'signed' => false])
              ->update();
    }
}
