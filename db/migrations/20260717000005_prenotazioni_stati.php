<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prenotazioni autocarrate: via lo stato 'opzione'.
 *
 * Restano 'confermata' e 'annullata'. Le righe gia' salvate come opzione
 * diventano confermate: bloccavano gia' il mezzo, quindi e' il significato
 * piu' vicino a quello che avevano.
 */
final class PrenotazioniStati extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pn_prenotazioni')) {
            $this->execute("
                UPDATE pn_prenotazioni SET stato = 'confermata' WHERE stato = 'opzione'
            ");
        }
    }

    public function down(): void
    {
        // Non si torna indietro: quali fossero opzioni non e' piu' distinguibile.
    }
}
