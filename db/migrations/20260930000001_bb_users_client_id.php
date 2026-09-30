<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * bb_users: il collegamento al cliente.
 *
 * bb_users e' una tabella nata prima delle migration, quindi nessuna di
 * queste l'ha mai creata e il suo elenco di colonne non sta scritto da
 * nessuna parte nel repository. Di worker_id e company_id si ha la prova che
 * esistono — c'e' una INSERT che li scrive, in User::createFromWorker, e
 * senza di loro quella funzione non avrebbe mai funzionato. Di client_id no:
 * compariva solo in letture che tollerano l'assenza (`$row['client_id'] ??
 * null`) e nel controllo del middleware, che con un valore vuoto risponde
 * 403 "Client user not linked".
 *
 * Il che vuol dire che se la colonna non c'e' mai stata, ogni utente di tipo
 * cliente era bloccato fuori senza che nessuno potesse capire perche': il
 * controllo falliva sempre, e sembrava un problema di permessi.
 *
 * La creazione utente adesso scrive quel collegamento, quindi la colonna
 * serve davvero: senza, l'INSERT fallirebbe e non si creerebbe piu' nessun
 * utente, di nessun tipo.
 *
 * hasColumn prima di aggiungere: se la colonna c'e' gia' questa migration
 * non fa niente, e passa lo stesso su un database dove era gia' presente.
 *
 * Niente foreign key: in produzione l'utente del database non ha il
 * permesso REFERENCES.
 */
final class BbUsersClientId extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_users');

        if (!$t->hasColumn('client_id')) {
            $t->addColumn('client_id', 'integer', [
                'null'     => true,
                'signed'   => false,
                'comment'  => 'bb_clients.id per gli utenti di tipo cliente',
                'after'    => 'company_id',
            ]);
        }

        // Le altre due dovrebbero esserci gia'. Si controllano lo stesso:
        // costa un'interrogazione e toglie il dubbio su installazioni vecchie
        // dove qualcuno potrebbe averle perse per strada.
        if (!$t->hasColumn('worker_id')) {
            $t->addColumn('worker_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'bb_workers.id per gli utenti di tipo operaio',
            ]);
        }
        if (!$t->hasColumn('company_id')) {
            $t->addColumn('company_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'bb_companies.id: azienda consorziata',
            ]);
        }

        $t->update();
    }

    public function down(): void
    {
        // Solo client_id: worker_id e company_id c'erano prima di questa
        // migration e portarseli via tornando indietro romperebbe
        // l'accesso degli operai, che non e' quello che si e' toccato qui.
        $t = $this->table('bb_users');
        if ($t->hasColumn('client_id')) {
            $t->removeColumn('client_id')->update();
        }
    }
}
