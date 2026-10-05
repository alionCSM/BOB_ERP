<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * pn_foto -> bb_poti_foto.
 *
 * Le tabelle di BOB cominciano per bb_. Le pn_ del modulo Poti sono
 * precedenti a questa regola e restano dove sono — rinominarne sei e tutto
 * il codice che le nomina sarebbe un rischio senza guadagno — ma pn_foto e'
 * nata dopo e non ha motivo di fare eccezione.
 *
 * RENAME TABLE si porta dietro i dati e gli indici: le foto gia' caricate
 * non si toccano, cambia solo il nome. Il percorso dei file non e' a
 * database dentro il nome della tabella, quindi non c'e' niente da spostare
 * su disco.
 *
 * Il controllo su hasTable regge tutti e due i casi: chi ha gia' applicato
 * la migration precedente ha pn_foto e viene rinominata, chi parte da zero
 * la crea col vecchio nome un attimo prima e se la ritrova rinominata qui.
 */
final class FotoPrefissoBb extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pn_foto') && !$this->hasTable('bb_poti_foto')) {
            $this->table('pn_foto')->rename('bb_poti_foto')->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_poti_foto') && !$this->hasTable('pn_foto')) {
            $this->table('bb_poti_foto')->rename('pn_foto')->update();
        }
    }
}
