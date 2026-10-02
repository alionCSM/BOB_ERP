<?php

declare(strict_types=1);

namespace App\Service\Attendance;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Dire all'operaio com'e' andata.
 *
 * Senza questo, uno manda la presenza o chiede le ferie e poi deve riaprire
 * l'app a caso per scoprire se gli hanno risposto. Nella pratica non la
 * riapre: passa in ufficio a chiedere, che e' esattamente la domanda che
 * l'app doveva togliere di mezzo.
 *
 * Il rifiuto porta con se' il motivo scritto dall'ufficio. Quello resta
 * nella lingua in cui l'hanno scritto — e' testo libero, nessuno lo puo'
 * tradurre — ma il resto della frase arriva nella lingua dell'operaio.
 *
 * Si manda DOPO che la decisione e' sul database e la transazione e'
 * chiusa. Una notifica spedita dentro la transazione resterebbe spedita
 * anche se il salvataggio si ribalta: il push non si richiama indietro, e
 * l'operaio si ritroverebbe le ferie approvate su un telefono e in attesa
 * dentro BOB.
 *
 * Se qualcosa si rompe qui non deve rompere la decisione, che e' gia'
 * salvata: chi chiama se ne accorge dal valore di ritorno, ma la pagina
 * dell'ufficio continua a dire che l'ha approvata, perche' l'ha approvata.
 */
final class AvvisoDecisione
{
    public function __construct(
        private PDO $conn,
        private NotificationService $notifiche,
    ) {}

    /**
     * Esito di una presenza dichiarata.
     *
     * @param string $motivo vale solo sui rifiuti
     * @return int a quanti utenti e' arrivata
     */
    public function presenza(int $richiestaId, bool $approvata, string $motivo = ''): int
    {
        $stmt = $this->conn->prepare("
            SELECT u.id AS user_id, u.lingua, r.data
            FROM   bb_presenze_richieste r
            JOIN   bb_users u ON u.worker_id = r.worker_id
            WHERE  r.id = :id
              AND  u.active = 'Y'
              AND  u.removed = 'N'
        ");
        $stmt->execute([':id' => $richiestaId]);

        $mandate = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $this->manda(
                (int)$d['user_id'],
                $d['lingua'],
                $approvata ? 'esito_presenza_ok' : 'esito_presenza_ko',
                $approvata ? 'presenza_approvata' : 'presenza_rifiutata',
                [
                    'data'   => $this->giorno((string)$d['data']),
                    'motivo' => $motivo,
                ],
                $approvata,
            );
            $mandate++;
        }

        return $mandate;
    }

    /**
     * Esito di una richiesta di ferie, permesso o malattia.
     *
     * @return int a quanti utenti e' arrivata
     */
    public function ferie(int $ferieId, bool $approvata, string $motivo = ''): int
    {
        $stmt = $this->conn->prepare("
            SELECT u.id AS user_id, u.lingua,
                   f.tipo, f.data_inizio, f.data_fine
            FROM   bb_ferie_permessi f
            JOIN   bb_users u ON u.worker_id = f.worker_id
            WHERE  f.id = :id
              AND  u.active = 'Y'
              AND  u.removed = 'N'
        ");
        $stmt->execute([':id' => $ferieId]);

        $mandate = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $lingua = $d['lingua'];
            $dal    = (string)$d['data_inizio'];
            $al     = (string)$d['data_fine'];

            // "dal 23/12 al 23/12" si legge male e sembra un errore: un
            // giorno solo si dice e basta
            $periodo = $dal === $al
                ? Lingua::testo('periodo_giorno', $lingua, ['dal' => $this->giorno($dal)])
                : Lingua::testo('periodo_dal_al', $lingua, [
                    'dal' => $this->giorno($dal),
                    'al'  => $this->giorno($al),
                  ]);

            // La malattia ha parole sue. Nessuno "approva" un'influenza:
            // l'ufficio la registra, e sentirsi dire "richiesta approvata"
            // da malati suona come se ci fosse stato un dubbio.
            if ((string)$d['tipo'] === 'malattia') {
                $this->manda(
                    (int)$d['user_id'],
                    $lingua,
                    $approvata ? 'esito_malattia_ok' : 'esito_malattia_ko',
                    $approvata ? 'malattia_registrata' : 'malattia_rifiutata',
                    ['periodo' => $periodo, 'motivo' => $motivo],
                    $approvata,
                );
                $mandate++;
                continue;
            }

            $this->manda(
                (int)$d['user_id'],
                $lingua,
                $approvata ? 'esito_richiesta_ok' : 'esito_richiesta_ko',
                $approvata ? 'richiesta_approvata' : 'richiesta_rifiutata',
                [
                    'cosa' => Lingua::testo(
                        $d['tipo'] === 'permesso' ? 'cosa_permesso' : 'cosa_ferie',
                        $lingua,
                    ),
                    'periodo' => $periodo,
                    'motivo'  => $motivo,
                ],
                $approvata,
            );
            $mandate++;
        }

        return $mandate;
    }

    /**
     * @param array<string, string> $valori
     */
    private function manda(
        int $userId,
        ?string $lingua,
        string $titolo,
        string $corpo,
        array $valori,
        bool $approvata,
    ): void {
        $this->notifiche->create(
            $userId,
            Lingua::testo($titolo, $lingua),
            Lingua::testo($corpo, $lingua, $valori),
            '/',
            'attendance',
            // Un rifiuto chiede di fare qualcosa — ridichiarare il giorno,
            // riproporre altre date — e se arriva silenzioso l'operaio lo
            // scopre quando ormai non serve piu'.
            $approvata ? 'normal' : 'high',
        );
    }

    /** aaaa-mm-gg come lo legge una persona. */
    private function giorno(string $data): string
    {
        $t = strtotime($data);
        return $t ? date('d/m/Y', $t) : $data;
    }
}
