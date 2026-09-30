<?php

declare(strict_types=1);

namespace App\Service\Attendance;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Il promemoria della sera: "ti manca la presenza di lunedi' e di oggi".
 *
 * Il problema vero delle presenze dichiarate non e' compilarle, e' ricordarsi
 * di farlo. Uno stacca, sale in macchina, e se ne ricorda tre giorni dopo
 * quando non sa piu' dove e' stato.
 *
 * Per questo il promemoria non guarda solo oggi ma gli ultimi giorni, e
 * nomina quelli che mancano. "Non hai segnato la presenza di oggi" a uno che
 * ne ha indietro tre lo lascia col problema piu' grosso invisibile: compila
 * oggi, si mette a posto la coscienza, e lunedi' resta li'.
 *
 * I giorni si chiamano col loro nome — "lunedi'", "martedi'" — e non con la
 * data: la notifica si legge di sfuggita sulla schermata di blocco, e
 * "2026-09-28" non dice niente a nessuno. Il giorno corrente si chiama
 * "oggi", che e' come uno ce l'ha in testa la sera.
 *
 * Due passaggi: alle venti il primo, alle ventuno il secondo solo a chi
 * ancora non l'ha fatta. Il secondo si ricalcola da zero, quindi chi ha
 * compilato alle venti e cinque non riceve niente, e chi ne aveva tre e ne ha
 * sistemato uno si sente nominare solo i due che restano.
 *
 * Si avvisa SOLO chi era pianificato, e fra quelli si tolgono chi ha gia'
 * dichiarato, chi ha gia' una presenza messa dall'ufficio, e chi era in ferie
 * o in permesso di giornata intera.
 *
 * Mandarlo a tutti e centoquaranta vorrebbe dire svegliare ogni sera anche
 * chi non lavorava — e una notifica che arriva quando non serve insegna a
 * ignorare anche quelle che servono.
 *
 * Il sabato e la domenica si reggono da soli: se nessuno era pianificato,
 * non si avvisa nessuno, e se invece qualcuno lavorava quel sabato riceve il
 * promemoria come ogni altro giorno. Per questo il lavoro va messo nel
 * crontab tutti i giorni e non da lunedi' a venerdi': con 1-5 un sabato
 * pianificato resterebbe scoperto.
 */
final class PromemoriaPresenze
{
    /**
     * Quanti giorni indietro guardare, oltre a quello corrente.
     *
     * Sei piu' oggi fa una settimana: abbastanza da recuperare la settimana
     * in corso, non tanto da rinfacciare a uno un giorno di tre mesi fa che
     * ormai e' un problema dell'ufficio e non suo.
     */
    private const GIORNI_INDIETRO = 6;

    public function __construct(
        private PDO $conn,
        private NotificationService $notifiche,
    ) {}

    /**
     * Manda il promemoria.
     *
     * @param string $giorno l'ultimo giorno da controllare, di solito oggi
     * @param string $quando 'primo' | 'secondo' — cambia solo il testo
     * @return array{avvisati:int, giorni:int}
     */
    public function invia(string $giorno, string $quando = 'primo'): array
    {
        $coda = $quando === 'secondo' ? '_2' : '';
        $dal  = date('Y-m-d', strtotime($giorno . ' -' . self::GIORNI_INDIETRO . ' days'));

        $avvisati = 0;
        $giorni   = 0;

        foreach ($this->daAvvisare($dal, $giorno) as $u) {
            $elenco = Lingua::elencoGiorni($u['giorni'], $u['lingua'], $giorno);
            $chiave = count($u['giorni']) > 1
                ? 'promemoria_piu' . $coda
                : 'promemoria_uno' . $coda;

            // Chi non ha un telefono registrato riceve comunque la notifica
            // dentro BOB: la trovera' al prossimo accesso. Meglio di niente,
            // e non costa nulla.
            $this->notifiche->create(
                (int)$u['user_id'],
                Lingua::testo('promemoria_titolo', $u['lingua']),
                Lingua::testo($chiave, $u['lingua'], ['giorni' => $elenco]),
                '/',                 // l'app apre la sua schermata, il web la home
                'attendance',
                $quando === 'secondo' ? 'high' : 'normal',
            );
            $avvisati++;
            $giorni += count($u['giorni']);
        }

        return ['avvisati' => $avvisati, 'giorni' => $giorni];
    }

    /**
     * Chi era pianificato nella finestra e non ha dichiarato niente, con
     * l'elenco dei giorni che gli mancano.
     *
     * La dichiarazione conta in qualunque stato: se l'ha mandata e gliel'hanno
     * rifiutata, un promemoria che dice "non hai segnato" sarebbe falso —
     * l'ha segnata, gliel'hanno respinta, ed e' un'altra conversazione.
     *
     * Una riga per giorno mancante, raggruppata per utente in PHP invece che
     * con un GROUP_CONCAT: i giorni vanno poi tradotti uno per uno, e farli
     * tornare dal database gia' incollati vorrebbe solo dire risepararli.
     *
     * @return array<int, array{user_id:int, lingua:?string, giorni:string[]}>
     */
    private function daAvvisare(string $dal, string $al): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id AS user_id, u.lingua, p.data
            FROM   bb_pianificazione p
            JOIN   bb_pianificazione_nostri pn ON pn.pianificazione_id = p.id
            JOIN   bb_workers w  ON w.id = pn.worker_id
            JOIN   bb_users   u  ON u.worker_id = w.id
            WHERE  p.data BETWEEN :dal AND :al
              AND  u.active = 'Y'
              AND  u.removed = 'N'
              AND  NOT EXISTS (
                    SELECT 1 FROM bb_presenze_richieste r
                    WHERE  r.worker_id = pn.worker_id
                      AND  r.data = p.data
                   )
              -- se l'ufficio l'ha gia' messa lui in presenze vere, non c'e'
              -- niente da ricordare: capita per chi non usa l'app
              AND  NOT EXISTS (
                    SELECT 1 FROM bb_presenze pr
                    WHERE  pr.worker_id = pn.worker_id
                      AND  pr.data = p.data
                   )
              -- Chi era in ferie o aveva un permesso di giornata intera non
              -- deve segnare niente, e ricordarglielo la sera del suo giorno
              -- libero e' il modo piu' veloce per far disinstallare l'app.
              --
              -- Il permesso di poche ore non esclude: ha lavorato mezza
              -- giornata e la presenza va segnata lo stesso. Le ore piene si
              -- riconoscono da `ore` vuoto, che e' come le scrive l'ufficio.
              --
              -- Solo le approvate: una richiesta ancora in attesa non e' un
              -- giorno libero, e fino a risposta quella giornata va segnata.
              AND  NOT EXISTS (
                    SELECT 1 FROM bb_ferie_permessi f
                    WHERE  f.worker_id = pn.worker_id
                      AND  f.data_inizio <= p.data
                      AND  f.data_fine   >= p.data
                      AND  f.stato = 'approvata'
                      AND  (f.tipo = 'ferie' OR f.ore IS NULL)
                   )
            ORDER BY u.id, p.data
        ");
        $stmt->execute([':dal' => $dal, ':al' => $al]);

        $per = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['user_id'];
            if (!isset($per[$id])) {
                $per[$id] = ['user_id' => $id, 'lingua' => $r['lingua'], 'giorni' => []];
            }
            $per[$id]['giorni'][] = (string)$r['data'];
        }

        return array_values($per);
    }
}
