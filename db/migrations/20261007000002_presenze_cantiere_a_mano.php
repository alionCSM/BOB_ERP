<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Una giornata dichiarata su un cantiere scritto a mano.
 *
 * L'operaio non cerca fra i cantieri: l'app gli propone quello della
 * pianificazione, e se e' sbagliato scrive lui dov'era. Capita soprattutto
 * con le mezze giornate: pianificato a BUDRIO, una telefonata lo manda il
 * pomeriggio a IMOLA, e la seconda mezza giornata non ha un cantiere della
 * pianificazione a cui attaccarsi.
 *
 * - `worksite_id` puo' restare vuoto finche' l'ufficio non sceglie il
 *   cantiere giusto, che si fa sulla pagina delle dichiarazioni prima di
 *   approvare;
 * - `cantiere_testo` tiene le parole dell'operaio, anche dopo: si vede sempre
 *   cosa aveva scritto e dove e' stata registrata.
 */
final class PresenzeCantiereAMano extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_presenze_richieste');
        $t->changeColumn('worksite_id', 'integer', ['null' => true, 'signed' => false]);
        if (!$t->hasColumn('cantiere_testo')) {
            $t->addColumn('cantiere_testo', 'string', ['limit' => 150, 'null' => true, 'after' => 'worksite_id']);
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('bb_presenze_richieste');
        if ($t->hasColumn('cantiere_testo')) {
            $t->removeColumn('cantiere_testo');
        }
        $t->update();
        // worksite_id resta nullable: rimetterlo NOT NULL fallirebbe sulle
        // righe scritte a mano gia' approvate o rifiutate
    }
}
