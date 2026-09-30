<?php

declare(strict_types=1);

namespace App\Repository\Attendance;

use PDO;
use RuntimeException;

/**
 * Presenze dichiarate dagli operai dall'app.
 *
 * Non sono presenze: sono dichiarazioni in attesa che l'ufficio guardi,
 * corregga e approvi. Finche' restano qui non entrano in nessun conto —
 * bb_presenze, da cui escono costi e buste paga, la scrive solo
 * l'approvazione.
 *
 * Ogni lettura parte dall'operaio e non dall'id della riga: un operaio deve
 * poter vedere e ritirare solo le proprie. Passare il worker_id a ogni
 * metodo invece di controllarlo nel controller vuol dire che non ci si puo'
 * dimenticare di farlo.
 */
final class RichiestaPresenzaRepository
{
    public const STATI  = ['in_attesa', 'approvata', 'rifiutata'];
    public const TURNI  = ['Intero', 'Mezzo'];

    /** Chi ha pagato il pasto: lo stesso vocabolario che usa gia' l'ufficio. */
    public const PASTI  = ['-', 'Loro', 'Noi'];

    public function __construct(private PDO $conn) {}

    /**
     * Le dichiarazioni di un operaio in un periodo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perOperaio(int $workerId, string $dal, string $al): array
    {
        $stmt = $this->conn->prepare("
            SELECT r.*,
                   w.name          AS cantiere_nome,
                   w.worksite_code AS cantiere_codice
            FROM   bb_presenze_richieste r
            LEFT JOIN bb_worksites w ON w.id = r.worksite_id
            WHERE  r.worker_id = :wid
              AND  r.data BETWEEN :dal AND :al
            ORDER BY r.data DESC, r.id DESC
        ");
        $stmt->execute([':wid' => $workerId, ':dal' => $dal, ':al' => $al]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Una dichiarazione, solo se e' di questo operaio. */
    public function trova(int $workerId, int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM bb_presenze_richieste WHERE id = :id AND worker_id = :wid'
        );
        $stmt->execute([':id' => $id, ':wid' => $workerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * C'e' gia' una dichiarazione in attesa per lo stesso giorno e cantiere?
     *
     * Si controlla solo sulle "in attesa": una rifiutata si deve poter
     * rimandare corretta, e dopo un'approvazione l'operaio che ridichiara lo
     * stesso giorno sta segnalando qualcosa che l'ufficio deve vedere.
     */
    public function giaInAttesa(int $workerId, string $data, int $worksiteId): bool
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM   bb_presenze_richieste
            WHERE  worker_id = :wid AND data = :data AND worksite_id = :ws
              AND  stato = 'in_attesa'
        ");
        $stmt->execute([':wid' => $workerId, ':data' => $data, ':ws' => $worksiteId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $d */
    public function crea(int $workerId, array $d): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_presenze_richieste
                (worker_id, worksite_id, data, turno,
                 pranzo, cena, hotel, targa_auto, trasferta, note)
            VALUES (:wid, :ws, :data, :turno,
                    :pranzo, :cena, :hotel, :targa, :tras, :note)
        ");
        $stmt->execute([
            ':wid'    => $workerId,
            ':ws'     => (int)$d['worksite_id'],
            ':data'   => (string)$d['data'],
            ':turno'  => in_array($d['turno'] ?? '', self::TURNI, true) ? $d['turno'] : 'Intero',
            ':pranzo' => in_array($d['pranzo'] ?? '', self::PASTI, true) ? $d['pranzo'] : '-',
            ':cena'   => in_array($d['cena'] ?? '', self::PASTI, true) ? $d['cena'] : '-',
            ':hotel'  => ($d['hotel'] ?? '') !== '' ? $d['hotel'] : null,
            ':targa'  => ($d['targa_auto'] ?? '') !== '' ? $d['targa_auto'] : null,
            ':tras'   => !empty($d['trasferta']) ? 1 : 0,
            ':note'   => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    /**
     * L'operaio ritira una dichiarazione.
     *
     * Solo finche' e' in attesa: una volta che l'ufficio ha deciso, toglierla
     * vorrebbe dire cancellare una decisione altrui — e se e' stata
     * approvata, la presenza vera e' gia' nata e resterebbe orfana.
     */
    public function ritira(int $workerId, int $id): bool
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_presenze_richieste
            WHERE id = :id AND worker_id = :wid AND stato = 'in_attesa'
        ");
        $stmt->execute([':id' => $id, ':wid' => $workerId]);
        return $stmt->rowCount() > 0;
    }

    // ── Lato ufficio ─────────────────────────────────────────────────────────

    /**
     * Le dichiarazioni da guardare.
     *
     * @param array{stato?:string, dal?:string, al?:string, worksite_id?:int} $filtri
     * @return array<int, array<string, mixed>>
     */
    public function daApprovare(array $filtri = []): array
    {
        $where = ['1 = 1'];
        $args  = [];

        $stato = $filtri['stato'] ?? 'in_attesa';
        if ($stato !== '') {
            $where[] = 'r.stato = :stato';
            $args[':stato'] = $stato;
        }
        if (!empty($filtri['dal'])) {
            $where[] = 'r.data >= :dal';
            $args[':dal'] = $filtri['dal'];
        }
        if (!empty($filtri['al'])) {
            $where[] = 'r.data <= :al';
            $args[':al'] = $filtri['al'];
        }
        if (!empty($filtri['worksite_id'])) {
            $where[] = 'r.worksite_id = :ws';
            $args[':ws'] = (int)$filtri['worksite_id'];
        }

        $sql = '
            SELECT r.*,
                   CONCAT(wk.last_name, " ", wk.first_name) AS operaio_nome,
                   wk.company       AS operaio_azienda,
                   ws.name          AS cantiere_nome,
                   ws.worksite_code AS cantiere_codice
            FROM   bb_presenze_richieste r
            JOIN   bb_workers   wk ON wk.id = r.worker_id
            LEFT JOIN bb_worksites ws ON ws.id = r.worksite_id
            WHERE  ' . implode(' AND ', $where) . '
            ORDER BY r.data ASC, operaio_nome ASC
        ';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$righe) {
            return [];
        }

        // La pianificazione si chiede a parte e si accosta qui.
        //
        // Con una join sola servirebbero un NOT EXISTS e un GROUP BY per non
        // duplicare le righe di chi quel giorno era pianificato su due
        // cantieri: una query che nessuno riesce piu' a leggere fra sei mesi,
        // per risparmiare una lettura su un elenco che ne ha qualche decina.
        $pianificazione = $this->pianificazionePer($righe);

        foreach ($righe as &$r) {
            $chiave = $r['worker_id'] . '|' . $r['data'];
            $r['pianificazione'] = $pianificazione[$chiave] ?? null;
            $r['avvisi']         = $this->avvisi($r);
        }
        unset($r);

        return $righe;
    }

    /**
     * Dove e come era pianificato ogni operaio, per le righe date.
     *
     * Chiave "workerId|data". Se quel giorno era pianificato su due cantieri
     * si tiene il primo: l'avviso serve a far cadere l'occhio, non a
     * ricostruire la giornata.
     *
     * @param array<int, array<string, mixed>> $righe
     * @return array<string, array<string, mixed>>
     */
    private function pianificazionePer(array $righe): array
    {
        $operai = array_values(array_unique(array_map(
            static fn($r) => (int)$r['worker_id'], $righe
        )));
        $date = array_values(array_unique(array_map(
            static fn($r) => (string)$r['data'], $righe
        )));

        if (!$operai || !$date) {
            return [];
        }

        $segOperai = implode(',', array_fill(0, count($operai), '?'));
        $segDate   = implode(',', array_fill(0, count($date), '?'));

        $stmt = $this->conn->prepare("
            SELECT pn.worker_id, p.data, pn.trasferta, p.worksite_id,
                   w.worksite_code, w.name AS cantiere_nome
            FROM   bb_pianificazione_nostri pn
            JOIN   bb_pianificazione p ON p.id = pn.pianificazione_id
            LEFT JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE  pn.worker_id IN ({$segOperai})
              AND  p.data IN ({$segDate})
        ");
        $stmt->execute(array_merge($operai, $date));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $chiave = $p['worker_id'] . '|' . $p['data'];
            $out[$chiave] ??= $p;
        }
        return $out;
    }

    /**
     * Cosa non torna fra quello che ha dichiarato e quello che era previsto.
     *
     * Non blocca niente: e' l'ufficio che decide. Serve a far cadere l'occhio
     * sulle righe che meritano un secondo sguardo, invece di leggerle tutte
     * con la stessa attenzione — che con centoquaranta operai vuol dire
     * leggerle tutte male.
     *
     * @param array<string, mixed> $r
     * @return string[]
     */
    private function avvisi(array $r): array
    {
        $out  = [];
        $pian = $r['pianificazione'] ?? null;

        $haCena  = ($r['cena'] ?? '-') !== '-';
        $haHotel = trim((string)($r['hotel'] ?? '')) !== '';

        // Il caso che interessa: cena o albergo su un giorno che in
        // pianificazione non era in trasferta. Sono le voci che costano.
        if ($pian && empty($pian['trasferta']) && ($haCena || $haHotel)) {
            $cosa = $haCena && $haHotel ? 'cena e albergo' : ($haCena ? 'la cena' : "l'albergo");
            $out[] = 'Ha dichiarato ' . $cosa . ' ma non era in trasferta';
        }

        // Cantiere diverso da quello pianificato: puo' essere giusto — capita
        // di spostare qualcuno all'ultimo — ma va guardato.
        if ($pian && !empty($pian['worksite_id'])
            && (int)$pian['worksite_id'] !== (int)$r['worksite_id']) {
            $out[] = 'Era pianificato su ' . trim(
                ($pian['worksite_code'] ?? '') . ' ' . ($pian['cantiere_nome'] ?? '')
            );
        }

        // Non pianificato affatto: non e' un errore, ma nessuno l'aspettava
        // li' quel giorno.
        if (!$pian) {
            $out[] = 'Non era in pianificazione quel giorno';
        }

        return $out;
    }

    /**
     * Approva una dichiarazione e genera la presenza vera.
     *
     * L'ufficio puo' correggere prima di approvare: quello che finisce in
     * bb_presenze e' $correzioni, non per forza quello che aveva scritto
     * l'operaio. La dichiarazione resta com'era, cosi' si vede la differenza
     * fra quello che e' stato detto e quello che e' stato registrato.
     *
     * Tutto dentro una transazione: una presenza creata senza che la
     * dichiarazione risulti approvata verrebbe approvata una seconda volta,
     * e il cantiere si troverebbe il doppio delle giornate.
     *
     * @param array<string, mixed> $correzioni turno, note, azienda
     */
    public function approva(int $id, array $correzioni, int $userId): int
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM bb_presenze_richieste WHERE id = :id AND stato = 'in_attesa' FOR UPDATE"
            );
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$r) {
                throw new RuntimeException('Dichiarazione non trovata o gia\' decisa');
            }

            // Quello che finisce in bb_presenze e' la correzione, se c'e',
            // altrimenti quello che aveva scritto l'operaio. La dichiarazione
            // resta com'era: cosi' si vede sempre la differenza fra quello
            // che e' stato detto e quello che e' stato registrato.
            $scegli = static fn(string $campo, $ripiego) =>
                array_key_exists($campo, $correzioni) ? $correzioni[$campo] : $ripiego;

            $ins = $this->conn->prepare("
                INSERT INTO bb_presenze
                    (worker_id, worksite_id, azienda, data, turno,
                     pranzo, pranzo_prezzo, cena, cena_prezzo,
                     hotel, targa_auto, trasferta, note, created_by)
                VALUES (:wid, :ws, :azienda, :data, :turno,
                        :pranzo, :pprezzo, :cena, :cprezzo,
                        :hotel, :targa, :tras, :note, :uid)
            ");
            $ins->execute([
                ':wid'     => (int)$r['worker_id'],
                ':ws'      => (int)$r['worksite_id'],
                ':azienda' => (string)($correzioni['azienda'] ?? ''),
                ':data'    => (string)$r['data'],
                ':turno'   => in_array($correzioni['turno'] ?? '', self::TURNI, true)
                              ? $correzioni['turno'] : (string)$r['turno'],
                ':pranzo'  => (string)$scegli('pranzo', $r['pranzo'] ?? '-'),
                ':cena'    => (string)$scegli('cena',   $r['cena']   ?? '-'),
                // I prezzi li mette l'ufficio: l'operaio sa di aver mangiato,
                // non quanto e' costato. Vuoti restano NULL.
                ':pprezzo' => ($correzioni['pranzo_prezzo'] ?? '') !== ''
                              ? (float)$correzioni['pranzo_prezzo'] : null,
                ':cprezzo' => ($correzioni['cena_prezzo'] ?? '') !== ''
                              ? (float)$correzioni['cena_prezzo'] : null,
                ':hotel'   => (string)($scegli('hotel', $r['hotel'] ?? '') ?? ''),
                ':targa'   => (string)($scegli('targa_auto', $r['targa_auto'] ?? '') ?? ''),
                ':tras'    => !empty($scegli('trasferta', $r['trasferta'] ?? 0)) ? 1 : 0,
                ':note'    => ($correzioni['note'] ?? $r['note']) ?: null,
                ':uid'     => $userId,
            ]);
            $presenzaId = (int)$this->conn->lastInsertId();

            $upd = $this->conn->prepare("
                UPDATE bb_presenze_richieste
                SET stato = 'approvata', presenza_id = :pid,
                    decisa_at = NOW(), decisa_da = :uid
                WHERE id = :id
            ");
            $upd->execute([':pid' => $presenzaId, ':uid' => $userId, ':id' => $id]);

            $this->conn->commit();
            return $presenzaId;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /** Rifiuta, col motivo: senza, l'operaio non sa cosa correggere. */
    public function rifiuta(int $id, string $motivo, int $userId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_presenze_richieste
            SET stato = 'rifiutata', motivo = :motivo,
                decisa_at = NOW(), decisa_da = :uid
            WHERE id = :id AND stato = 'in_attesa'
        ");
        $stmt->execute([
            ':motivo' => $motivo !== '' ? $motivo : null,
            ':uid'    => $userId,
            ':id'     => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Quante ne restano da guardare: per il contatore in dashboard. */
    public function quanteInAttesa(): int
    {
        return (int)$this->conn
            ->query("SELECT COUNT(*) FROM bb_presenze_richieste WHERE stato = 'in_attesa'")
            ->fetchColumn();
    }
}
