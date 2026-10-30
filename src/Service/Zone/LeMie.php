<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Quello che tocca a me, in tutti i miei cantieri: le attivita' assegnate e
 * i moduli da compilare.
 *
 * Nell'app e' la prima cosa che uno vede della Zone. Chi lavora non cerca
 * cantiere per cantiere cosa c'e' da fare: vuole l'elenco suo, gia' fatto.
 *
 * Valgono le stesse regole della Zone (Accesso): una cosa che non si vede non
 * compare nemmeno qui, anche se e' assegnata. E il cliente conta solo dove
 * l'ufficio ha acceso "Condividi col cliente".
 */
final class LeMie
{
    public function __construct(private PDO $conn) {}

    /**
     * Le attivita' assegnate a me: a me per nome, alla squadra (se ci sono
     * dentro), ai capi (se sono capo), al cliente (se sono il cliente).
     *
     * @param bool $anchePronte anche quelle completate, non ancora verificate
     * @return array<int, array<string, mixed>>
     */
    public function attivita(int $userId, bool $anchePronte = false): array
    {
        $stati = $anchePronte ? "'open','in_progress','complete'" : "'open','in_progress'";
        $stmt = $this->conn->prepare("
            SELECT t.id, t.worksite_id, t.name, t.description, t.status, t.category,
                   t.assignee_name, t.assignee_user_id, t.assegnata_a,
                   t.start_date, t.due_date, t.priority, t.updated_at,
                   w.name AS cantiere_nome, w.worksite_code, a.ruolo
            FROM   bb_zone_tasks t
            JOIN   bb_zone_accessi a ON a.worksite_id = t.worksite_id AND a.user_id = :u
            JOIN   bb_worksites w   ON w.id = t.worksite_id
            WHERE  t.status IN ($stati)
              AND  (a.ruolo <> 'cliente' OR w.zone_cliente = 1)
              -- condivisa con me, o l'ho creata io: un'attivita' che
              -- nessuno mi ha condiviso non e' mia, nemmeno se tocca al
              -- mio ruolo
              AND  (EXISTS (
                        SELECT 1 FROM bb_zone_condivisioni c
                        WHERE c.tipo = 'attivita' AND c.oggetto_id = t.id
                          AND c.user_id = :u2
                   ) OR t.created_by = :u3)
        ");
        // tre segnaposto per lo stesso valore: le prepared statement non
        // sono emulate, e un nome ripetuto fa fallire la query
        $stmt->execute([':u' => $userId, ':u2' => $userId, ':u3' => $userId]);

        $mie = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function (array $t) use ($userId) {
            $ruolo = (string)$t['ruolo'];
            // chi la vede l'ha deciso la query, con le condivisioni: qui
            // resta solo a chi tocca
            return match ((string)($t['assegnata_a'] ?? 'squadra')) {
                'persona' => (int)$t['assignee_user_id'] === $userId,
                'squadra', 'capi' => $ruolo === Accesso::CANTIERE,
                'cliente' => $ruolo === Accesso::CLIENTE,
                default   => false,
            };
        }));

        // prima le urgenti, poi chi scade prima; senza scadenza in fondo
        usort($mie, function (array $a, array $b) {
            return [(int)$b['priority'], $a['due_date'] ?? '9999-12-31', -(int)$a['id']]
               <=> [(int)$a['priority'], $b['due_date'] ?? '9999-12-31', -(int)$b['id']];
        });

        foreach ($mie as &$t) {
            $t['id'] = (int)$t['id'];
            $t['worksite_id'] = (int)$t['worksite_id'];
            $t['priority'] = (int)$t['priority'];
            $t['in_ritardo'] = !empty($t['due_date']) && $t['due_date'] < date('Y-m-d');
        }
        return $mie;
    }

    /**
     * I moduli assegnati a me, con lo stato del periodo: uno "ogni giorno"
     * e' fatto se l'ho compilato oggi, uno "ogni settimana" se l'ho fatto
     * da lunedi', uno "una volta" se l'ho fatto e basta.
     *
     * Ognuno compila il suo: assegnato "agli operai", vuol dire uno per
     * operaio (il DPI lo fa ciascuno), non uno per tutti.
     *
     * @return array<int, array<string, mixed>>
     */
    public function daCompilare(int $userId): array
    {
        $stmt = $this->conn->prepare("
            SELECT fa.id, fa.worksite_id, fa.template_id, fa.frequenza, fa.scadenza, fa.visibilita,
                   t.name AS modulo, w.name AS cantiere_nome, w.worksite_code, a.ruolo,
                   (SELECT MAX(s.created_at) FROM bb_zone_form_submissions s
                     WHERE s.assegnazione_id = fa.id AND s.submitted_by = :u2) AS mia_ultima
            FROM   bb_zone_form_assegnazioni fa
            JOIN   bb_zone_accessi a ON a.worksite_id = fa.worksite_id AND a.user_id = :u
            JOIN   bb_zone_form_templates t ON t.id = fa.template_id
            JOIN   bb_worksites w ON w.id = fa.worksite_id
            WHERE  fa.attiva = 1
              AND  (fa.a_user_id = :u3 OR (fa.a_user_id IS NULL AND fa.a_ruolo = a.ruolo))
              AND  (a.ruolo <> 'cliente' OR w.zone_cliente = 1)
            ORDER BY w.name, t.name
        ");
        $stmt->execute([':u' => $userId, ':u2' => $userId, ':u3' => $userId]);

        $oggi   = date('Y-m-d');
        $lunedi = date('Y-m-d', strtotime('monday this week'));

        $fuori = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $ultima = $m['mia_ultima'] ? substr((string)$m['mia_ultima'], 0, 10) : null;
            $fatto = match ((string)$m['frequenza']) {
                'giornaliera' => $ultima !== null && $ultima >= $oggi,
                'settimanale' => $ultima !== null && $ultima >= $lunedi,
                default       => $ultima !== null,
            };
            $m['id']          = (int)$m['id'];
            $m['worksite_id'] = (int)$m['worksite_id'];
            $m['template_id'] = (int)$m['template_id'];
            $m['fatto']       = $fatto;
            $m['in_ritardo']  = !$fatto && !empty($m['scadenza']) && $m['scadenza'] < $oggi;
            $fuori[] = $m;
        }

        // prima quelli da fare (in ritardo in cima), poi i fatti
        usort($fuori, fn(array $a, array $b) => [$a['fatto'], !$a['in_ritardo']] <=> [$b['fatto'], !$b['in_ritardo']]);
        return $fuori;
    }

    /** @return array{attivita:int, da_compilare:int} */
    public function conti(int $userId): array
    {
        return [
            'attivita'     => count($this->attivita($userId)),
            'da_compilare' => count(array_filter($this->daCompilare($userId), fn($m) => !$m['fatto'])),
        ];
    }
}
