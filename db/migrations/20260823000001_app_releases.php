<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Distribuzione dell'app Android: i pacchetti caricati da BOB.
 *
 * L'app non sta sul Play Store, quindi finora ogni aggiornamento voleva dire
 * mandare l'APK a mano su WhatsApp e sperare che tutti lo installassero. Da
 * qui invece si carica una volta sola: chi ha il telefono lo scarica dallo
 * stesso indirizzo, e l'app stessa controlla se ne esiste uno piu' nuovo.
 *
 * version_code e' il numero che decide "e' piu' nuovo?": e' quello di
 * Android (un intero che cresce), non version_name, perche' confrontare
 * "1.10.0" con "1.9.0" come testo darebbe la risposta sbagliata.
 *
 * Il token serve per l'indirizzo di scaricamento. Il telefono scarica fuori
 * dal browser, senza i cookie della sessione, quindi l'indirizzo non puo'
 * chiedere il login: al suo posto e' lungo e non indovinabile, e si puo'
 * spegnere disattivando la versione.
 */
final class AppReleases extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_app_releases')) {
            return;
        }

        // niente chiavi esterne: l'utente MySQL di produzione non ha il
        // privilegio REFERENCES, come per tutte le altre tabelle di BOB
        $this->table('bb_app_releases', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('version_code', 'integer', ['null' => false, 'signed' => false,
                        'comment' => 'versionCode di Android: decide chi e\' piu\' nuovo'])
            ->addColumn('version_name', 'string', ['limit' => 40, 'null' => false,
                        'comment' => 'versionName mostrato alle persone, es. 1.4.2'])
            ->addColumn('file_nome',    'string', ['limit' => 200, 'null' => false,
                        'comment' => 'come si chiamava il file caricato'])
            ->addColumn('file_salvato', 'string', ['limit' => 200, 'null' => false,
                        'comment' => 'nome su disco in storage/app_releases'])
            ->addColumn('dimensione',   'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('sha256',       'string', ['limit' => 64, 'null' => true,
                        'comment' => 'per verificare che il download sia integro'])
            ->addColumn('note',         'text',   ['null' => true,
                        'comment' => 'cosa cambia in questa versione'])
            ->addColumn('obbligatorio', 'boolean', ['default' => false, 'null' => false,
                        'comment' => 'l\'app blocca l\'uso finche\' non si aggiorna'])
            ->addColumn('attiva',       'boolean', ['default' => true, 'null' => false,
                        'comment' => 'disattivata = non si scarica e non si propone'])
            ->addColumn('token',        'string', ['limit' => 32, 'null' => false,
                        'comment' => 'indirizzo di scaricamento, non indovinabile'])
            ->addColumn('caricato_da',   'integer', ['null' => true, 'signed' => false])
            ->addColumn('caricato_nome', 'string', ['limit' => 120, 'null' => true,
                        'comment' => 'copiato qui: se l\'utente sparisce lo storico resta leggibile'])
            ->addColumn('created_at',   'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token'], ['unique' => true, 'name' => 'uq_token'])
            ->addIndex(['version_code'], ['name' => 'idx_version_code'])
            ->addIndex(['attiva', 'version_code'], ['name' => 'idx_attiva_versione'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_app_releases')) {
            $this->table('bb_app_releases')->drop()->save();
        }
    }
}
