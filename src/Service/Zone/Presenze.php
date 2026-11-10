<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Il registro presenze del cantiere, per la Zone.
 *
 * Per ora CSM le scrive anche a mano (bb_zone_presenze_manuali): si leggono
 * insieme a quelle del registro, che resta l'unico per i conti.
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
        $giornateConsorziate = 0.0;
        foreach ($cons->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['persone'];
            $consorziate[] = ['azienda' => (string)$r['azienda'], 'persone' => $n];
            $daConsorziate += $n;
            $giornateConsorziate += $n;
        }

        // Le presenze scritte a mano da CSM: una persona con nome va fra le
        // persone, un'azienda con il numero fra le aziende. Portano il loro
        // id, per poterle togliere.
        foreach ($this->manuali($worksiteId, $data) as $m) {
            $mezza = (bool)$m['mezza'];
            if (trim((string)$m['nome']) !== '') {
                $righe[] = [
                    'id'      => (int)$m['id'],
                    'manuale' => true,
                    'nome'    => (string)$m['nome'],
                    'azienda' => (string)($m['azienda'] ?? ''),
                    'turno'   => $mezza ? 'Mezza giornata' : 'Giornata intera',
                    'mezza'   => $mezza,
                ];
                $giornate += $mezza ? 0.5 : 1.0;
            } else {
                $n = max(1, (int)$m['persone']);
                $consorziate[] = [
                    'id'      => (int)$m['id'],
                    'manuale' => true,
                    'azienda' => (string)($m['azienda'] ?? '—'),
                    'persone' => $n,
                    'mezza'   => $mezza,
                ];
                $daConsorziate       += $n;
                $giornateConsorziate += $mezza ? $n * 0.5 : $n;
            }
        }

        return [
            'data'        => $data,
            'nostri'      => $righe,
            'consorziate' => $consorziate,
            'persone'     => count($righe) + $daConsorziate,
            'giornate'    => $giornate + $giornateConsorziate,
        ];
    }

    /** Le righe scritte a mano in un giorno; vuoto se la tabella non c'e' ancora. */
    private function manuali(int $worksiteId, string $data): array
    {
        try {
            $st = $this->conn->prepare('SELECT * FROM bb_zone_presenze_manuali WHERE worksite_id = :w AND data = :d ORDER BY azienda, nome, id');
            $st->execute([':w' => $worksiteId, ':d' => $data]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Le giornate uomo di tutto il cantiere, dall'inizio.
     *
     * Il conto e' quello della scheda cantiere, non un altro: giornata
     * intera 1, mezza 0,5, e per le consorziate le persone che hanno
     * mandato. Due numeri diversi per la stessa cosa, in due pagine di
     * BOB, sarebbero una domanda a cui nessuno saprebbe rispondere.
     *
     * @return array{nostri: float, consorziate: float, giornate: float, dal: ?string, al: ?string}
     */
    public function totali(int $worksiteId): array
    {
        $nostri = $this->conn->prepare("
            SELECT COALESCE(SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END), 0) AS giornate,
                   MIN(data) AS dal, MAX(data) AS al
            FROM   bb_presenze
            WHERE  worksite_id = :ws
        ");
        $nostri->execute([':ws' => $worksiteId]);
        $a = $nostri->fetch(PDO::FETCH_ASSOC) ?: [];

        $cons = $this->conn->prepare("
            SELECT COALESCE(SUM(quantita), 0) AS giornate,
                   MIN(data_presenza) AS dal, MAX(data_presenza) AS al
            FROM   bb_presenze_consorziate
            WHERE  worksite_id = :ws
        ");
        $cons->execute([':ws' => $worksiteId]);
        $b = $cons->fetch(PDO::FETCH_ASSOC) ?: [];

        // il primo e l'ultimo giorno guardando tutti e due gli elenchi
        $date = array_filter([$a['dal'] ?? null, $b['dal'] ?? null]);
        $dal  = $date ? min($date) : null;
        $date = array_filter([$a['al'] ?? null, $b['al'] ?? null]);
        $al   = $date ? max($date) : null;

        $nostre = (float)($a['giornate'] ?? 0);
        $loro   = (float)($b['giornate'] ?? 0);

        // e quelle scritte a mano: con il nome fra le persone, senza fra le aziende
        try {
            $m = $this->conn->prepare("
                SELECT COALESCE(SUM(CASE WHEN TRIM(COALESCE(nome,'')) <> '' THEN (CASE WHEN mezza THEN 0.5 ELSE 1 END) ELSE 0 END), 0) AS persone,
                       COALESCE(SUM(CASE WHEN TRIM(COALESCE(nome,'')) = '' THEN GREATEST(persone, 1) * (CASE WHEN mezza THEN 0.5 ELSE 1 END) ELSE 0 END), 0) AS aziende,
                       MIN(data) AS dal, MAX(data) AS al
                FROM bb_zone_presenze_manuali WHERE worksite_id = :ws
            ");
            $m->execute([':ws' => $worksiteId]);
            $c = $m->fetch(PDO::FETCH_ASSOC) ?: [];
            $nostre += (float)($c['persone'] ?? 0);
            $loro   += (float)($c['aziende'] ?? 0);
            $x = array_filter([$dal, $c['dal'] ?? null]);
            $dal = $x ? min($x) : null;
            $x = array_filter([$al, $c['al'] ?? null]);
            $al = $x ? max($x) : null;
        } catch (\PDOException $e) {
            // tabella non ancora creata: solo il registro
        }

        return [
            'nostri'      => $nostre,
            'consorziate' => $loro,
            'giornate'    => $nostre + $loro,
            'dal'         => $dal ? (string)$dal : null,
            'al'          => $al ? (string)$al : null,
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
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // i giorni con presenze scritte a mano, sommati agli altri
        try {
            $m = $this->conn->prepare("
                SELECT data, SUM(CASE WHEN TRIM(COALESCE(nome,'')) <> '' THEN 1 ELSE GREATEST(persone, 1) END) AS persone
                FROM bb_zone_presenze_manuali WHERE worksite_id = :ws GROUP BY data
            ");
            $m->execute([':ws' => $worksiteId]);
            $per = [];
            foreach ($righe as $r) { $per[(string)$r['data']] = (int)$r['persone']; }
            foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $per[(string)$r['data']] = ($per[(string)$r['data']] ?? 0) + (int)$r['persone'];
            }
            krsort($per);
            $righe = [];
            foreach (array_slice($per, 0, $quanti, true) as $d => $n) {
                $righe[] = ['data' => (string)$d, 'persone' => $n];
            }
        } catch (\PDOException $e) {
            // tabella non ancora creata: solo il registro
        }

        return array_map(
            static fn(array $r): array => [
                'data'    => (string)$r['data'],
                'persone' => (int)$r['persone'],
            ],
            $righe
        );
    }

    /**
     * Registra a mano una presenza: una persona (nome) o un'azienda con
     * quante persone ha mandato.
     *
     * @param array{data?: string, nome?: string, azienda?: string, persone?: int, mezza?: bool} $d
     */
    public function aggiungi(int $worksiteId, array $d, int $daChi): int
    {
        [$data, $nome, $azienda, $persone, $mezza] = $this->valori($d);
        $this->conn->prepare('
            INSERT INTO bb_zone_presenze_manuali (worksite_id, data, nome, azienda, persone, mezza, created_by)
            VALUES (:w, :d, :n, :a, :p, :m, :by)
        ')->execute([
            ':w' => $worksiteId, ':d' => $data, ':n' => $nome, ':a' => $azienda,
            ':p' => $persone, ':m' => $mezza, ':by' => $daChi ?: null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    /** Corregge una presenza scritta a mano, di questo cantiere. */
    public function modifica(int $worksiteId, int $id, array $d): bool
    {
        [$data, $nome, $azienda, $persone, $mezza] = $this->valori($d);
        $c = $this->conn->prepare('SELECT 1 FROM bb_zone_presenze_manuali WHERE id = :id AND worksite_id = :w');
        $c->execute([':id' => $id, ':w' => $worksiteId]);
        if (!$c->fetchColumn()) {
            return false;
        }
        $this->conn->prepare('
            UPDATE bb_zone_presenze_manuali SET data = :d, nome = :n, azienda = :a, persone = :p, mezza = :m
            WHERE id = :id AND worksite_id = :w
        ')->execute([
            ':d' => $data, ':n' => $nome, ':a' => $azienda, ':p' => $persone, ':m' => $mezza,
            ':id' => $id, ':w' => $worksiteId,
        ]);
        return true;
    }

    /**
     * I valori di una presenza, controllati.
     *
     * @return array{0: string, 1: ?string, 2: ?string, 3: int, 4: int}
     */
    private function valori(array $d): array
    {
        $data = (string)($d['data'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            throw new \RuntimeException('Data non valida');
        }
        if ($data > date('Y-m-d')) {
            throw new \RuntimeException('Non si possono registrare presenze future');
        }
        $nome    = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
        $azienda = mb_substr(trim((string)($d['azienda'] ?? '')), 0, 120);
        if ($nome === '' && $azienda === '') {
            throw new \RuntimeException('Inserisci il nome della persona o dell\'azienda');
        }
        $persone = $nome !== '' ? 1 : max(1, min(500, (int)($d['persone'] ?? 1)));
        return [$data, $nome !== '' ? $nome : null, $azienda !== '' ? $azienda : null, $persone, !empty($d['mezza']) ? 1 : 0];
    }

    /** Toglie una presenza scritta a mano, di questo cantiere. */
    public function elimina(int $worksiteId, int $id): bool
    {
        $st = $this->conn->prepare('DELETE FROM bb_zone_presenze_manuali WHERE id = :id AND worksite_id = :w');
        $st->execute([':id' => $id, ':w' => $worksiteId]);
        return $st->rowCount() > 0;
    }
}
