<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dichiarazione dell'operaio: pasti, albergo, auto e trasferta.
 *
 * Stesso vocabolario che l'ufficio usa gia' in bb_presenze — "Loro" ha
 * pagato l'operaio, "Noi" ha pagato l'azienda — cosi' in approvazione si
 * ritrovano le cose come le scrive tutti i giorni, senza tradurre niente.
 *
 * L'importo NON si chiede all'operaio: lui sa di aver mangiato, non sa
 * quanto l'azienda ha pagato quel pasto. Chiederglielo vorrebbe dire
 * raccogliere numeri inventati che poi qualcuno deve correggere uno per uno.
 * Il prezzo lo mette l'ufficio in approvazione, dove ci sono le fatture.
 *
 * La trasferta la dichiara anche l'operaio, e serve al confronto: se mette
 * cena o albergo su un giorno che in pianificazione non era in trasferta,
 * l'ufficio se lo vede segnalato invece di accorgersene a fine mese.
 */
final class RichiestePasti extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_presenze_richieste');

        if (!$t->hasColumn('pranzo')) {
            $t->addColumn('pranzo', 'string', [
                'limit'   => 10,
                'null'    => false,
                'default' => '-',
                'comment' => '- | Loro (paga l\'operaio) | Noi (paga l\'azienda)',
                'after'   => 'turno',
            ]);
        }
        if (!$t->hasColumn('cena')) {
            $t->addColumn('cena', 'string', [
                'limit'   => 10,
                'null'    => false,
                'default' => '-',
                'comment' => '- | Loro | Noi',
                'after'   => 'pranzo',
            ]);
        }
        if (!$t->hasColumn('hotel')) {
            $t->addColumn('hotel', 'string', [
                'limit'   => 160,
                'null'    => true,
                'comment' => 'Nome struttura, testo libero come in bb_presenze',
                'after'   => 'cena',
            ]);
        }
        if (!$t->hasColumn('targa_auto')) {
            $t->addColumn('targa_auto', 'string', [
                'limit'   => 20,
                'null'    => true,
                'after'   => 'hotel',
            ]);
        }
        if (!$t->hasColumn('trasferta')) {
            $t->addColumn('trasferta', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Dichiarata dall\'operaio, confrontata con quella della pianificazione',
                'after'   => 'targa_auto',
            ]);
        }

        $t->update();
    }

    public function down(): void
    {
        $this->table('bb_presenze_richieste')
            ->removeColumn('pranzo')
            ->removeColumn('cena')
            ->removeColumn('hotel')
            ->removeColumn('targa_auto')
            ->removeColumn('trasferta')
            ->update();
    }
}
