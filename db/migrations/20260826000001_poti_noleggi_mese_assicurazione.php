<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — noleggio a mese e assicurazione.
 *
 * Due aggiunte che si toccano solo alla fine, sul totale:
 *
 * 1) Fino a qui una riga aveva una sola tariffa, al giorno. Adesso puo'
 *    essere a mese: `unita` dice quale delle due si applica. Resta anche la
 *    tariffa giornaliera sulle righe a mese, perche' i giorni oltre l'ultimo
 *    mese intero si contano a giorni (dal 10 gennaio al 15 febbraio = 1 mese
 *    piu' 5 giorni).
 *
 * 2) L'assicurazione e' una percentuale sul noleggio dei mezzi — il
 *    trasporto resta fuori. La percentuale si conserva sul noleggio invece
 *    di stare solo nel codice: quando cambiera' non deve riscrivere i
 *    totali dei noleggi gia' fatti.
 *
 * L'importo calcolato si salva anche se e' ricavabile dagli altri due
 * campi. Sembra ridondante, ma il totale di un noleggio e' un dato
 * contabile: deve restare quello che si e' visto e concordato, non quello
 * che ricalcolerebbe oggi il codice.
 *
 * Niente foreign key: in produzione l'utente del database non ha il
 * permesso REFERENCES.
 */
final class PotiNoleggiMeseAssicurazione extends AbstractMigration
{
    public function up(): void
    {
        $righe = $this->table('pn_noleggi_righe');

        if (!$righe->hasColumn('unita')) {
            $righe->addColumn('unita', 'string', [
                'limit'   => 10,
                'null'    => false,
                'default' => 'giorno',
                'comment' => 'giorno | mese — quale tariffa si applica',
                'after'   => 'data_fine',
            ]);
        }
        if (!$righe->hasColumn('tariffa_mese')) {
            $righe->addColumn('tariffa_mese', 'decimal', [
                'precision' => 10,
                'scale'     => 2,
                'null'      => true,
                'comment'   => 'Usata solo quando unita = mese',
                'after'     => 'tariffa_giorno',
            ]);
        }
        $righe->update();

        $noleggi = $this->table('pn_noleggi');

        if (!$noleggi->hasColumn('assicurazione')) {
            $noleggi->addColumn('assicurazione', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Spunta: assicurazione inclusa nel noleggio',
                'after'   => 'trasporto',
            ]);
        }
        if (!$noleggi->hasColumn('assicurazione_perc')) {
            $noleggi->addColumn('assicurazione_perc', 'decimal', [
                'precision' => 5,
                'scale'     => 2,
                'null'      => true,
                'comment'   => 'Percentuale applicata, 12.00 di norma',
                'after'     => 'assicurazione',
            ]);
        }
        if (!$noleggi->hasColumn('assicurazione_importo')) {
            $noleggi->addColumn('assicurazione_importo', 'decimal', [
                'precision' => 10,
                'scale'     => 2,
                'null'      => true,
                'comment'   => 'Importo concordato, non ricalcolato alla lettura',
                'after'     => 'assicurazione_perc',
            ]);
        }
        $noleggi->update();

        // Indice per l'elenco: si legge sempre una societa' alla volta,
        // ordinata per data di inizio. Senza, con qualche migliaio di
        // noleggi ogni pagina passerebbe da una scansione completa.
        if (!$this->table('pn_noleggi')->hasIndex(['group_company_id', 'data_inizio'])) {
            $this->table('pn_noleggi')
                ->addIndex(['group_company_id', 'data_inizio'], ['name' => 'idx_societa_inizio'])
                ->update();
        }
    }

    public function down(): void
    {
        $this->table('pn_noleggi_righe')
            ->removeColumn('unita')
            ->removeColumn('tariffa_mese')
            ->update();

        $this->table('pn_noleggi')
            ->removeColumn('assicurazione')
            ->removeColumn('assicurazione_perc')
            ->removeColumn('assicurazione_importo')
            ->removeIndexByName('idx_societa_inizio')
            ->update();
    }
}
