<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Il registro presenze del cantiere, per la Zone.
 *
 * Non e' una copia: legge le presenze vere, quelle che l'ufficio approva e
 * su cui si fanno i conti. Un secondo registro compilato a mano divergerebbe
 * dal primo nel giro di una settimana, e poi nessuno saprebbe quale guardare.
 *
 * **Qui dentro passa solo chi c'era.** Pasti, cena, albergo, auto, costi e
 * note restano fuori: sono numeri che riguardano noi e il nostro fornitore,
 * e questo elenco lo legge anche il committente. Un registro presenze dice
 * chi ha lavorato e quanto, punto.
 *
 * Due elenchi, perche' sono due cose diverse:
 *
 * - **i nostri**: uno per riga, con nome e azienda, e se ha fatto giornata
 *   intera o mezza;
 * - **le consorziate**: non nominative — in BOB non ci sono i nomi dei loro
 *   operai, c'e' quanti ne hanno mandati — quindi azienda e numero di
 *   persone.
 */
final class Presenze
{
    public function __construct(private PDO $conn) {}

    /**
     * Chi c'era, in un giorno.
     *
     * @return array{
     *     data: string,
     *     nostri: array<int, array{nome: string, azienda: string, turno: string, mezza: bool}>,
     *     consorziate: array<int, array{azienda: string, persone: int}>,
     *     persone: int,
     *     giornate: float
     * }
     */
    public function giorno(int $worksiteId, string $data): array
    {
        $nostri = $this->conn->prepare("
            SELECT TRIM(CONCAT(COALESCE(w.last_name, ''), ' ', COALESCE(w.first_name, ''))) AS nome,
                   COALESCE(w.company, '') AS azienda,
                   p.turno
            FROM   bb_presenze p
            JOIN   bb_workers w ON w.id = p.worker_id
            WHERE  p.worksite_id = :ws AND p.data = :d
            ORDER BY azienda, nome
        ");
        $nostri->execute([':ws' => $worksiteId, ':d' => $data]);

        $righe    = [];
        $giornate = 0.0;
        foreach ($nostri->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mezza = ($r['turno'] ?? '') === 'Mezzo';
            $righe[] = [
                'nome'    => $r['nome'] !== '' ? $r['nome'] : '—',
                'azienda' => (string)$r['azienda'],
                // scritto per esteso: "Mezzo" da solo, in una tabella, non
                // dice mezzo cosa
                'turno'   => $mezza ? 'Mezza giornata' : 'Giornata intera',
                'mezza'   => $mezza,
            ];
            $giornate += $mezza ? 0.5 : 1.0;
        }

        // Le consorziate si raggruppano per azienda: in una giornata la
        // stessa azienda puo' avere piu' righe (aggiunte in momenti
        // diversi), e in cantiere quello che conta e' quanti ne ha mandati
        // in tutto.
        $cons = $this->conn->prepare("
            SELECT COALESCE(co.name, '—') AS azienda,
                   SUM(c.quantita) AS persone
            FROM   bb_presenze_consorziate c
            LEFT   JOIN bb_companies co ON co.id = c.azienda_id
            WHERE  c.worksite_id = :ws AND c.data_presenza = :d
            GROUP BY co.name
            ORDER BY azienda
        ");
        $cons->execute([':ws' => $worksiteId, ':d' => $data]);

        $consorziate = [];
        $daConsorziate = 0;
        foreach ($cons->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['persone'];
            $consorziate[] = ['azienda' => (string)$r['azienda'], 'persone' => $n];
            $daConsorziate += $n;
        }

        return [
            'data'        => $data,
            'nostri'      => $righe,
            'consorziate' => $consorziate,
            'persone'     => count($righe) + $daConsorziate,
            'giornate'    => $giornate + $daConsorziate,
        ];
    }

    /**
     * I giorni in cui c'e' stato qualcuno, dal piu' recente.
     *
     * Nostri e consorziate insieme: un giorno in cui ha lavorato solo una
     * consorziata e' un giorno di cantiere come gli altri, e se mancasse
     * dall'elenco sembrerebbe che non ci fosse andato nessuno.
     *
     * @return array<int, array{data: string, persone: int}>
     */
    public function giorni(int $worksiteId, int $quanti = 60): array
    {
        $quanti = max(1, min(366, $quanti));

        // Due segnaposto per lo stesso cantiere: le prepared statement non
        // sono emulate, e un nome ripetuto fa fallire la query.
        $stmt = $this->conn->prepare("
            SELECT data, SUM(persone) AS persone FROM (
                SELECT p.data AS data, COUNT(*) AS persone
                FROM   bb_presenze p
                WHERE  p.worksite_id = :ws1
                GROUP BY p.data
                UNION ALL
                SELECT c.data_presenza AS data, SUM(c.quantita) AS persone
                FROM   bb_presenze_consorziate c
                WHERE  c.worksite_id = :ws2
                GROUP BY c.data_presenza
            ) AS insieme
            GROUP BY data
            ORDER BY data DESC
            LIMIT $quanti
        ");
        $stmt->execute([':ws1' => $worksiteId, ':ws2' => $worksiteId]);

        return array_map(
            static fn(array $r): array => [
                'data'    => (string)$r['data'],
                'persone' => (int)$r['persone'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
