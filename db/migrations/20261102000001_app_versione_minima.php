<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La versione minima dell'app, per Android e per iPhone.
 *
 * Adesso l'app arriva dal Play Store e dall'App Store, non piu' solo
 * dall'APK caricato in BOB: gli store la aggiornano da soli, ma quando
 * vogliono loro, e chi ha gli aggiornamenti automatici spenti resta indietro
 * per mesi. Quando una versione vecchia non va piu' bene — il server e'
 * cambiato, un errore che sporca i dati — si alza la minima qui, e l'app
 * sotto quel numero si ferma su "Aggiorna BOB" finche' non la si aggiorna.
 *
 * Un numero per piattaforma: il build di Android e quello di iOS non vanno
 * per forza insieme.
 */
final class AppVersioneMinima extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_app_minima')) {
            return;
        }
        $this->table('bb_app_minima', ['id' => false, 'primary_key' => 'piattaforma'])
            ->addColumn('piattaforma', 'string', ['limit' => 10, 'null' => false,
                        'comment' => 'android | ios'])
            ->addColumn('version_code', 'integer', ['null' => false, 'signed' => false, 'default' => 0,
                        'comment' => 'sotto questo numero di build l\'app si ferma'])
            ->addColumn('messaggio', 'text', ['null' => true,
                        'comment' => 'perche\' bisogna aggiornare, lo legge la persona'])
            ->addColumn('store_url', 'string', ['limit' => 300, 'null' => true,
                        'comment' => 'dove aggiornare; vuoto = la pagina dello store di BOB'])
            ->addColumn('aggiornato_da', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP',
                        'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_app_minima')) {
            $this->table('bb_app_minima')->drop()->save();
        }
    }
}
