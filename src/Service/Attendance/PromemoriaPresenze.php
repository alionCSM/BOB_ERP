<?php

declare(strict_types=1);

namespace App\Service\Attendance;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Il promemoria della sera: "non hai segnato la presenza".
 *
 * Il problema vero delle presenze dichiarate non e' compilarle, e' ricordarsi
 * di farlo. Uno stacca, sale in macchina, e se ne ricorda tre giorni dopo
 * quando non sa piu' dove e' stato.
 *
 * Due passaggi: alle venti il primo, alle ventuno il secondo solo a chi
 * ancora non l'ha fatta. Il secondo si spedisce da solo a chi serve, perche'
 * ricontrolla: chi ha compilato alle venti e cinque non riceve niente.
 *
 * Si avvisa SOLO chi era pianificato quel giorno. Mandarlo a tutti e
 * centoquaranta vorrebbe dire svegliare ogni sera anche chi era in ferie, in
 * malattia o semplicemente non lavorava — e una notifica che arriva quando
 * non serve insegna a ignorare anche quelle che servono.
 */
final class PromemoriaPresenze
{
    public function __construct(
        private PDO $conn,
        private NotificationService $notifiche,
    ) {}

    /**
     * Manda il promemoria per un giorno.
     *
     * @param string $quando 'primo' | 'secondo' — cambia solo il testo
     * @return array{avvisati:int, saltati:int}
     */
    public function invia(string $giorno, string $quando = 'primo'): array
    {
        $chiave = $quando === 'secondo' ? 'promemoria_secondo' : 'promemoria_primo';

        $avvisati = 0;
        $saltati  = 0;

        foreach ($this->daAvvisare($giorno) as $u) {
            // Chi non ha un telefono registrato riceve comunque la notifica
            // dentro BOB: la trovera' al prossimo accesso. Meglio di niente,
            // e non costa nulla.
            $this->notifiche->create(
                (int)$u['user_id'],
                Lingua::testo('promemoria_titolo', $u['lingua']),
                Lingua::testo($chiave, $u['lingua']),
                '/',                 // l'app apre la sua schermata, il web la home
                'attendance',
                $quando === 'secondo' ? 'high' : 'normal',
            );
            $avvisati++;
        }

        return ['avvisati' => $avvisati, 'saltati' => $saltati];
    }

    /**
     * Chi era pianificato oggi e non ha ancora dichiarato niente.
     *
     * La dichiarazione conta in qualunque stato: se l'ha mandata e gliel'hanno
     * rifiutata, un promemoria che dice "non hai segnato" sarebbe falso —
     * l'ha segnata, gliel'hanno respinta, ed e' un'altra conversazione.
     *
     * @return array<int, array<string, mixed>>
     */
    private function daAvvisare(string $giorno): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id AS user_id, u.lingua,
                   CONCAT(w.last_name, ' ', w.first_name) AS operaio
            FROM   bb_pianificazione p
            JOIN   bb_pianificazione_nostri pn ON pn.pianificazione_id = p.id
            JOIN   bb_workers w  ON w.id = pn.worker_id
            JOIN   bb_users   u  ON u.worker_id = w.id
            WHERE  p.data = :giorno
              AND  u.active = 'Y'
              AND  u.removed = 'N'
              AND  NOT EXISTS (
                    SELECT 1 FROM bb_presenze_richieste r
                    WHERE  r.worker_id = pn.worker_id
                      AND  r.data = :giorno2
                   )
              -- se l'ufficio l'ha gia' messa lui in presenze vere, non c'e'
              -- niente da ricordare: capita per chi non usa l'app
              AND  NOT EXISTS (
                    SELECT 1 FROM bb_presenze pr
                    WHERE  pr.worker_id = pn.worker_id
                      AND  pr.data = :giorno3
                   )
        ");
        $stmt->execute([
            ':giorno'  => $giorno,
            ':giorno2' => $giorno,
            ':giorno3' => $giorno,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
