<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * BOB Zone diventa un permesso a se'.
 *
 * Fino a ora Zone non aveva nessun controllo suo: le rotte stanno sotto
 * /worksites, e il middleware del sito controllava il permesso 'worksites'.
 * Chi vedeva i cantieri entrava quindi anche in Zone. Da adesso i due sono
 * separati, perche' i cantieri li guardano in molti — capo, ufficio,
 * direzione — mentre Zone e' un'altra cosa.
 *
 * Il codice pero' e' gia' in produzione e la gente ci lavora: introdurre il
 * controllo e basta significherebbe che il giorno del rilascio Zone si
 * spegne per tutti finche' qualcuno non passa a dare i permessi a mano.
 * Quindi qui si concede 'zone' a chi ha 'worksites', su ogni societa' dove
 * ce l'ha. Il giorno dopo il rilascio nessuno si accorge di niente; da li'
 * in poi si toglie a chi non serve, che e' molto piu' comodo del contrario.
 */
final class PermessoZone extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        // 1. Le societa' che hanno i cantieri hanno anche Zone: senza questo
        //    il permesso del singolo utente non basterebbe, perche' il
        //    controllo guarda prima i moduli della societa'.
        $pdo->exec("
            INSERT IGNORE INTO bb_company_modules (group_company_id, modulo)
            SELECT group_company_id, 'zone'
            FROM   bb_company_modules
            WHERE  modulo = 'worksites'
        ");

        // 2. Gli utenti: stesso permesso, stessa societa'.
        $pdo->exec("
            INSERT IGNORE INTO bb_user_company_permissions
                   (user_id, group_company_id, module, allowed)
            SELECT user_id, group_company_id, 'zone', 1
            FROM   bb_user_company_permissions
            WHERE  module = 'worksites' AND allowed = 1
        ");

        // 3. Il riassunto usato dai lavori notturni e dalle mail di avviso.
        //    Niente INSERT IGNORE: quella tabella non ha un indice unico,
        //    quindi si finirebbe con righe doppie invece che con una sola.
        $pdo->exec("
            INSERT INTO bb_user_permissions (user_id, module, allowed)
            SELECT DISTINCT p.user_id, 'zone', 1
            FROM   bb_user_company_permissions p
            WHERE  p.module = 'zone' AND p.allowed = 1
              AND  NOT EXISTS (
                       SELECT 1 FROM bb_user_permissions x
                       WHERE  x.user_id = p.user_id AND x.module = 'zone'
                   )
        ");
    }

    public function down(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $pdo->exec("DELETE FROM bb_user_company_permissions WHERE module = 'zone'");
        $pdo->exec("DELETE FROM bb_user_permissions WHERE module = 'zone'");
        $pdo->exec("DELETE FROM bb_company_modules WHERE modulo = 'zone'");
    }
}
