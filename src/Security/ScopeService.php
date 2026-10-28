<?php

declare(strict_types=1);

namespace App\Security;
use PDO;
use App\Domain\User;

class ScopeService
{
    /** @return array<int>|string */
    public function resolveAllowedWorksites(PDO $connection, User $user)
    {
        if ($user->type === 'user') {
            if ((int)$user->company_id === 1) {
                return 'ALL';
            }

            $stmt = $connection->prepare('SELECT id FROM bb_worksites WHERE company_id = :cid');
            $stmt->execute([':cid' => $user->company_id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($user->type === 'worker') {
            // Due porte per lo stesso cortile: la vecchia assegnazione per
            // lavoratore, e quella nuova per utente che si fa dalla scheda
            // del cantiere e porta con se' i livelli di accesso.
            //
            // UNION e non una sola: chi e' passato dalla prima resta dov'e',
            // e togliere di mezzo la vecchia tabella senza sapere cosa ci
            // sia dentro vorrebbe dire chiudere fuori qualcuno di punto in
            // bianco.
            $stmt = $connection->prepare('
                SELECT worksite_id FROM bb_worksite_assignments WHERE worker_id = :wid
                UNION
                SELECT worksite_id FROM bb_zone_accessi          WHERE user_id   = :uid
            ');
            $stmt->execute([':wid' => $user->worker_id, ':uid' => $user->id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($user->type === 'client') {
            // Non tutti i cantieri del committente: solo quelli che
            // l'ufficio gli ha condiviso, uno per uno.
            //
            // Prima era "SELECT id FROM bb_worksites WHERE client_id = :cid":
            // bastava essere l'account di quel committente per avere in mano
            // tutti i suoi cantieri, compresi quelli che in ufficio nessuno
            // aveva deciso di mostrargli. Un cantiere si condivide, non si
            // eredita.
            //
            // Tre condizioni, e servono tutte e tre:
            //   - assegnato a lui dalla scheda del cantiere;
            //   - il cantiere e' del suo committente (un'assegnazione
            //     sbagliata non apre i lavori di un altro cliente);
            //   - sul cantiere e' acceso "Condividi col cliente", che e'
            //     l'interruttore con cui l'ufficio decide quando e' pronto.
            $stmt = $connection->prepare('
                SELECT wu.worksite_id
                FROM   bb_worksite_users wu
                JOIN   bb_worksites w ON w.id = wu.worksite_id
                WHERE  wu.user_id     = :uid
                  AND  w.client_id    = :cid
                  AND  w.zone_cliente = 1
            ');
            $stmt->execute([':uid' => $user->id, ':cid' => $user->client_id]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return [];
    }

    /** @param array<int>|string $allowedWorksites */
    public function canAccessWorksite($allowedWorksites, int $worksiteId): bool
    {
        if ($allowedWorksites === 'ALL') {
            return true;
        }

        return in_array($worksiteId, $allowedWorksites, true);
    }
}
