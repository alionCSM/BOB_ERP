<?php

declare(strict_types=1);

namespace App\Service\Operaio;

use PDO;

/**
 * Quello che un operaio vede di se stesso.
 *
 * Sta qui e non dentro un controller perche' gli operai arrivano da due
 * porte — l'app che chiama le API e il browser del telefono che apre le
 * pagine — e sono gli stessi dati. Due copie della stessa query divergono:
 * una si corregge, l'altra no, e per mesi la stessa giornata si legge in
 * due modi a seconda di dove la guardi.
 *
 * La traduzione dei pasti, in particolare, non puo' stare in nessun
 * controller: "ho pagato io" diventa "Loro" — dal punto di vista di chi
 * tiene i conti, loro sono gli operai — ed e' un'inversione che sbagliata
 * sposta i costi da una parte all'altra senza che nessuno se ne accorga.
 */
final class Dati
{
    public function __construct(private PDO $conn) {}

    /**
     * Dove va, con chi, e chi comanda.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pianificazione(int $workerId, string $dal, string $al): array
    {
        $stmt = $this->conn->prepare("
            SELECT p.id, p.data, p.cantiere, p.worksite_id,
                   pn.capo_squadra AS sei_capo,
                   pn.trasferta,
                   pn.auto_targa,
                   w.worksite_code, w.name AS cantiere_nome, w.location
            FROM   bb_pianificazione_nostri pn
            JOIN   bb_pianificazione p ON p.id = pn.pianificazione_id
            LEFT JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE  pn.worker_id = :wid
              AND  p.data BETWEEN :dal AND :al
            ORDER BY p.data ASC
        ");
        $stmt->execute([':wid' => $workerId, ':dal' => $dal, ':al' => $al]);
        $giorni = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Una query per giornata e non un join: le giornate sono due o tre, e
        // un join con GROUP_CONCAT per risparmiare una chiamata renderebbe
        // illeggibile la query principale.
        $squadre = [];
        foreach ($giorni as &$g) {
            $g['sei_capo']  = (bool)$g['sei_capo'];
            // l'app la usa per sapere se proporre cena e albergo; l'ufficio
            // la usa in approvazione per accorgersi di chi li dichiara senza
            $g['trasferta'] = (bool)$g['trasferta'];

            $pid = (int)$g['id'];
            $squadre[$pid] ??= $this->squadra($pid, $workerId);
            $g['squadra'] = $squadre[$pid];
        }
        unset($g);

        return $giorni;
    }

    /**
     * Chi c'e' in squadra quel giorno su quel cantiere.
     *
     * @return array<int, array<string, mixed>>
     */
    public function squadra(int $pianificazioneId, int $ioSono = 0): array
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(CONCAT(w.last_name, ' ', w.first_name), pn.worker_name) AS nome,
                   pn.worker_id,
                   pn.auto_targa,
                   pn.capo_squadra
            FROM   bb_pianificazione_nostri pn
            LEFT JOIN bb_workers w ON w.id = pn.worker_id
            WHERE  pn.pianificazione_id = :pid
            ORDER BY pn.capo_squadra DESC, nome ASC
        ");
        $stmt->execute([':pid' => $pianificazioneId]);

        // Chi guarda si deve riconoscere nell'elenco senza cercarsi: in una
        // squadra di sei, due righe uguali e nessuna che dice "questo sei
        // tu" si leggono due volte.
        return array_map(static function (array $r) use ($ioSono): array {
            $r['sei_tu'] = $ioSono > 0 && (int)$r['worker_id'] === $ioSono;
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dalle parole dell'operaio a quelle dell'ufficio.
     *
     * "Ho pagato io" diventa "Loro": chi tiene i conti guarda dall'altra
     * parte del tavolo. E' un'inversione facilissima da sbagliare, e
     * sbagliata sposta i costi dei pasti senza lasciare traccia — per questo
     * la fa il server, una volta sola, qualunque sia il client.
     *
     * Qualsiasi altra cosa diventa '-': meglio una riga che dice "non
     * pervenuto" di una che afferma quello che nessuno ha detto.
     */
    public function chiHaPagato(mixed $valore): string
    {
        return match (strtolower(trim((string)$valore))) {
            'io', 'operaio', 'loro' => 'Loro',
            'azienda', 'noi'        => 'Noi',
            default                 => '-',
        };
    }

    /**
     * Il cantiere e' aperto?
     *
     * E' l'unico limite su quale cantiere un operaio puo' dichiarare: non
     * quelli a cui e' assegnato, perche' lo mandano dove serve e la mattina
     * dopo e' da un'altra parte. Su un cantiere chiuso invece non ci va
     * nessuno, e una presenza li' sarebbe un errore di battitura o peggio.
     */
    public function cantiereAperto(int $worksiteId): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM bb_worksites
             WHERE id = :ws AND status IN ('In corso', 'A rischio')"
        );
        $stmt->execute([':ws' => $worksiteId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * I cantieri fra cui puo' scegliere quando dichiara una giornata.
     *
     * `recenti` sono quelli dove ha gia' lavorato negli ultimi sei mesi: nove
     * volte su dieci la risposta e' li', ed e' molto piu' veloce che cercare.
     * `aperti` sono tutti quelli in corso, per quando lo mandano altrove.
     *
     * @return array{recenti: array<int, array<string, mixed>>, aperti: array<int, array<string, mixed>>}
     */
    public function cantieri(int $workerId, string $cerca = ''): array
    {
        // Sei mesi: piu' indietro sono cantieri chiusi che non servono a
        // nessuno, e allungano una tendina che si guarda col pollice.
        $stmt = $this->conn->prepare("
            SELECT w.id, w.worksite_code, w.name, w.location,
                   MAX(p.data) AS ultima_presenza
            FROM   bb_presenze p
            JOIN   bb_worksites w ON w.id = p.worksite_id
            WHERE  p.worker_id = :wid
              AND  p.data >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY w.id, w.worksite_code, w.name, w.location
            ORDER BY ultima_presenza DESC
            LIMIT 15
        ");
        $stmt->execute([':wid' => $workerId]);
        $recenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sql  = "SELECT id, worksite_code, name, location
                 FROM   bb_worksites
                 WHERE  status IN ('In corso', 'A rischio')";
        $args = [];

        // Tre segnaposto per lo stesso valore e non uno ripetuto: le
        // prepared statement non sono emulate, e un nome usato due volte
        // fa fallire la query con "Invalid parameter number".
        if ($cerca !== '') {
            $sql .= " AND (name LIKE :q1 OR worksite_code LIKE :q2 OR location LIKE :q3)";
            foreach (['q1', 'q2', 'q3'] as $seg) {
                $args[':' . $seg] = '%' . $cerca . '%';
            }
        }
        $sql .= ' ORDER BY name ASC LIMIT 200';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);

        return [
            'recenti' => $recenti,
            'aperti'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
