<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prenotazioni autocarrate: resta il solo totale.
 *
 * L'importo di contratto era un terzo numero accanto a tariffa e totale e
 * non serviva: si tiene il totale, proposto dal calcolo e correggibile a mano.
 *
 * Attenzione al dato gia' presente: l'import dal vecchio database scriveva
 * l'importo del noleggio proprio in quella colonna. Prima di eliminarla il
 * valore viene spostato nel totale, e dove il totale c'era gia' ed era
 * diverso l'importo finisce nelle note: buttarlo via in silenzio sarebbe
 * il modo peggiore di semplificare.
 */
final class PrenotazioniSoloTotale extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pn_prenotazioni')) {
            return;
        }

        $t = $this->table('pn_prenotazioni');
        if (!$t->hasColumn('importo')) {
            return;
        }

        // 1. dove il totale manca, l'importo diventa il totale
        $this->execute("
            UPDATE pn_prenotazioni
            SET totale = importo
            WHERE importo IS NOT NULL AND totale IS NULL
        ");

        // 2. dove c'erano entrambi e non coincidevano, si annota il valore
        //    che altrimenti andrebbe perso
        $this->execute("
            UPDATE pn_prenotazioni
            SET note = CONCAT(
                    COALESCE(CONCAT(note, '\n'), ''),
                    'Importo di contratto registrato in precedenza: ',
                    FORMAT(importo, 2, 'de_DE')
                )
            WHERE importo IS NOT NULL AND totale IS NOT NULL AND importo <> totale
        ");

        $t->removeColumn('importo')->update();
    }

    public function down(): void
    {
        $t = $this->table('pn_prenotazioni');
        if (!$t->hasColumn('importo')) {
            $t->addColumn('importo', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
              ->update();
        }
    }
}
