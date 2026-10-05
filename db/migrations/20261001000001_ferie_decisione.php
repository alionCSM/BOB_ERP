<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Chi ha deciso una richiesta di ferie, e quando.
 *
 * Lo stato c'era gia' dalla migration delle presenze dichiarate, ma non chi
 * l'aveva messo: per le presenze si era aggiunto (`bb_presenze_richieste` ha
 * decisa_at e decisa_da), per le ferie no.
 *
 * Serve piu' qui che altrove. Due settimane di ferie negate a inizio agosto
 * diventano una discussione, e "chi me le ha rifiutate?" deve avere una
 * risposta che non dipende da chi si ricorda cosa.
 *
 * Niente foreign key su decisa_da: l'utente di produzione non ha REFERENCES
 * sulle tabelle nuove, e una colonna che punta a un utente cancellato e'
 * comunque meglio di una migration che non parte.
 */
final class FerieDecisione extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_ferie_permessi');

        if (!$t->hasColumn('decisa_at')) {
            $t->addColumn('decisa_at', 'datetime', [
                'null'    => true,
                'comment' => 'Quando l\'ufficio ha approvato o rifiutato',
            ]);
        }

        if (!$t->hasColumn('decisa_da')) {
            $t->addColumn('decisa_da', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'bb_users.id di chi ha deciso',
            ]);
        }

        $t->update();
    }

    public function down(): void
    {
        $this->table('bb_ferie_permessi')
            ->removeColumn('decisa_at')
            ->removeColumn('decisa_da')
            ->update();
    }
}
