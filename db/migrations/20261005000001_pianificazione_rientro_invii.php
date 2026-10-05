<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pianificazione: il rientro previsto della trasferta, e cosa e' gia' stato
 * mandato alle squadre.
 *
 * Il rientro sta sulla riga del cantiere e non su quella dell'operaio: una
 * trasferta finisce quando finisce il lavoro li', e la squadra torna insieme.
 * Chi la sera rientra a casa ha la trasferta spenta e il rientro non lo
 * riguarda. Serve a chi parte: sapere fino a quando sta fuori e' quello che
 * gli fa preparare la borsa giusta e avvisare a casa.
 *
 * Gli invii servono al pulsante "Invia alle squadre". L'ufficio prepara il
 * piano con calma, lo salva quante volte vuole, e lo manda quando e'
 * definitivo. Se dopo l'invio cambia qualcosa e lo rimanda, l'avviso parte
 * solo a chi ha la giornata cambiata: per gli altri sarebbe lo stesso
 * messaggio due volte, e al terzo uguale si smette di leggerli.
 *
 * `firma` e' l'impronta di quello che l'operaio ha ricevuto (cantiere,
 * squadra, capo, auto, trasferta, rientro): se non cambia, non si rimanda.
 */
final class PianificazioneRientroInvii extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->table('bb_pianificazione');
        if (!$p->hasColumn('rientro_previsto')) {
            $p->addColumn('rientro_previsto', 'date', [
                'null'    => true,
                'default' => null,
                'comment' => 'Fine prevista della trasferta su questo cantiere',
                'after'   => 'worksite_id',
            ]);
            $p->update();
        }

        if (!$this->hasTable('bb_pianificazione_invii')) {
            $this->table('bb_pianificazione_invii')
                ->addColumn('data',       'date',     ['null' => false])
                ->addColumn('worker_id',  'integer',  ['null' => false, 'signed' => false])
                ->addColumn('firma',      'string',   ['limit' => 40, 'null' => false])
                ->addColumn('inviata_da', 'integer',  ['null' => true, 'signed' => false])
                ->addColumn('inviata_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['data', 'worker_id'], ['unique' => true, 'name' => 'uq_data_operaio'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_pianificazione_invii')) {
            $this->table('bb_pianificazione_invii')->drop()->save();
        }
        $this->table('bb_pianificazione')->removeColumn('rientro_previsto')->update();
    }
}
