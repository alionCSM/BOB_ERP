<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La lingua dell'utente.
 *
 * Con centoquaranta operai le lingue in cantiere sono parecchie, e una
 * notifica che nessuno capisce e' peggio di nessuna notifica: la si ignora,
 * e con lei si ignorano anche quelle che contano.
 *
 * Sull'utente e non sull'operaio: e' chi riceve la notifica, ed e' lui che
 * sceglie in che lingua vuole BOB. Un operaio senza account non riceve
 * niente e non ha bisogno di una lingua.
 *
 * Italiano come ripiego: chi non ha scelto niente e' quasi sempre un utente
 * dell'ufficio, e comunque e' la lingua in cui BOB e' scritto.
 */
final class LinguaUtente extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_users');

        if (!$t->hasColumn('lingua')) {
            $t->addColumn('lingua', 'string', [
                'limit'   => 5,
                'null'    => false,
                'default' => 'it',
                'comment' => 'it | en | sq (albanese) | ro (rumeno) | mo (moldavo)',
            ]);
            $t->update();
        }
    }

    public function down(): void
    {
        $this->table('bb_users')->removeColumn('lingua')->update();
    }
}
