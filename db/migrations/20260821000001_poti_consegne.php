<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti — consegna, rientro e contratto firmato.
 *
 * Serve alla vista dei tecnici: la mattina devono sapere cosa esce e cosa
 * rientra, e poter segnare che l'hanno fatto. Finora le prenotazioni
 * dicevano solo cosa era PREVISTO, non cosa e' successo davvero.
 *
 * Le date effettive stanno accanto a quelle previste invece di sostituirle:
 * il confronto fra le due e' proprio l'informazione utile (un mezzo che
 * doveva rientrare ieri e non e' rientrato).
 *
 * Sui noleggi a piu' mezzi consegna e rientro stanno sulla RIGA e non sulla
 * testata, perche' ogni macchina parte e torna per conto suo; il contratto
 * invece e' uno solo per noleggio e sta in testata.
 */
final class PotiConsegne extends AbstractMigration
{
    public function up(): void
    {
        // ── Autocarrate: una prenotazione = un mezzo ──────────────────────
        if ($this->hasTable('pn_prenotazioni')) {
            $t = $this->table('pn_prenotazioni');
            if (!$t->hasColumn('contratto_firmato')) {
                $t->addColumn('contratto_firmato', 'boolean', ['null' => false, 'default' => false,
                      'comment' => 'Il cliente ha firmato il contratto']);
            }
            if (!$t->hasColumn('consegnato_at')) {
                $t->addColumn('consegnato_at', 'datetime', ['null' => true,
                      'comment' => 'Consegna effettiva, segnata dal tecnico'])
                  ->addColumn('consegnato_da', 'integer', ['null' => true, 'signed' => false])
                  ->addColumn('rientrato_at', 'datetime', ['null' => true,
                      'comment' => 'Rientro effettivo, segnato dal tecnico'])
                  ->addColumn('rientrato_da', 'integer', ['null' => true, 'signed' => false]);
            }
            $t->update();
        }

        // ── Noleggi mezzi: il contratto e' della testata ──────────────────
        if ($this->hasTable('pn_noleggi')) {
            $t = $this->table('pn_noleggi');
            if (!$t->hasColumn('contratto_firmato')) {
                $t->addColumn('contratto_firmato', 'boolean', ['null' => false, 'default' => false,
                      'comment' => 'Il cliente ha firmato il contratto'])
                  ->update();
            }
        }

        // ── Righe: ogni macchina ha la sua consegna e il suo rientro ──────
        if ($this->hasTable('pn_noleggi_righe')) {
            $t = $this->table('pn_noleggi_righe');
            if (!$t->hasColumn('consegnato_at')) {
                $t->addColumn('consegnato_at', 'datetime', ['null' => true])
                  ->addColumn('consegnato_da', 'integer', ['null' => true, 'signed' => false])
                  ->addColumn('rientrato_at', 'datetime', ['null' => true])
                  ->addColumn('rientrato_da', 'integer', ['null' => true, 'signed' => false])
                  ->update();
            }
        }
    }

    public function down(): void
    {
        $colonne = [
            'pn_prenotazioni'   => ['contratto_firmato', 'consegnato_at', 'consegnato_da', 'rientrato_at', 'rientrato_da'],
            'pn_noleggi'        => ['contratto_firmato'],
            'pn_noleggi_righe'  => ['consegnato_at', 'consegnato_da', 'rientrato_at', 'rientrato_da'],
        ];
        foreach ($colonne as $tabella => $lista) {
            if (!$this->hasTable($tabella)) {
                continue;
            }
            $t = $this->table($tabella);
            foreach ($lista as $c) {
                if ($t->hasColumn($c)) { $t->removeColumn($c); }
            }
            $t->update();
        }
    }
}
