<?php

declare(strict_types=1);

namespace App\Repository\Poti;

use PDO;

/**
 * Autocarrate e prenotazioni di Poti Noleggi.
 *
 * Ogni metodo filtra per societa': e' un modulo nato multi-societa', quindi
 * l'id non e' un parametro facoltativo da ricordarsi di passare.
 */
final class AutocarrataRepository
{
    public function __construct(private PDO $conn) {}

    // ── Mezzi ────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function mezzi(int $companyId, bool $soloAttive = false): array
    {
        $sql = 'SELECT * FROM pn_autocarrate WHERE group_company_id = :cid';
        if ($soloAttive) {
            $sql .= " AND stato = 'attiva'";
        }
        $sql .= ' ORDER BY targa ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mezzo(int $companyId, int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM pn_autocarrate WHERE id = :id AND group_company_id = :cid'
        );
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function salvaMezzo(int $companyId, ?int $id, array $d): int
    {
        $p = [
            ':targa'    => $d['targa'],
            ':modello'  => $d['modello'] !== '' ? $d['modello'] : null,
            ':altezza'  => $d['altezza_max_m'] !== '' ? $d['altezza_max_m'] : null,
            ':portata'  => $d['portata_kg'] !== '' ? $d['portata_kg'] : null,
            ':note'     => $d['note'] !== '' ? $d['note'] : null,
            ':stato'    => $d['stato'],
            ':cid'      => $companyId,
        ];

        if ($id) {
            $stmt = $this->conn->prepare("
                UPDATE pn_autocarrate
                SET targa = :targa, modello = :modello, altezza_max_m = :altezza,
                    portata_kg = :portata, note = :note, stato = :stato
                WHERE id = :id AND group_company_id = :cid
            ");
            $stmt->execute($p + [':id' => $id]);
            return $id;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO pn_autocarrate
                (group_company_id, targa, modello, altezza_max_m, portata_kg, note, stato)
            VALUES (:cid, :targa, :modello, :altezza, :portata, :note, :stato)
        ");
        $stmt->execute($p);
        return (int)$this->conn->lastInsertId();
    }

    public function targaInUso(int $companyId, string $targa, ?int $escludiId = null): bool
    {
        $sql  = 'SELECT COUNT(*) FROM pn_autocarrate WHERE group_company_id = :cid AND targa = :t';
        $args = [':cid' => $companyId, ':t' => $targa];
        if ($escludiId) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $escludiId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ── Prenotazioni ─────────────────────────────────────────────────────────

    /**
     * Prenotazioni che toccano il periodo indicato.
     *
     * Due periodi si sovrappongono quando ognuno inizia prima che l'altro
     * finisca: e' il confronto incrociato qui sotto, e vale sia per la
     * timeline sia per il controllo dei doppioni.
     */
    public function prenotazioni(int $companyId, string $dal, string $al, ?int $mezzoId = null, string $cerca = ''): array
    {
        $sql = "
            SELECT p.*, a.targa, a.modello,
                   DATEDIFF(p.data_fine, p.data_inizio) + 1 AS giorni,
                   -- il commerciale si salva per id e si mostra per nome: se
                   -- cambia nome la prenotazione resta legata alla persona.
                   -- Sulle righe importate dal vecchio sistema non c'e' un
                   -- utente, solo un nome scritto: si ripiega su quello.
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''),
                            c.username,
                            p.commerciale_testo) AS commerciale_nome
            FROM   pn_prenotazioni p
            JOIN   pn_autocarrate  a ON a.id = p.autocarrata_id
            LEFT JOIN bb_users     c ON c.id = p.commerciale_user_id
            WHERE  p.group_company_id = :cid
              AND  p.eliminato_at IS NULL
              AND  p.stato <> 'annullata'
              AND  p.data_inizio <= :al
              AND  p.data_fine   >= :dal
        ";
        $args = [':cid' => $companyId, ':dal' => $dal, ':al' => $al];

        if ($mezzoId) {
            $sql .= ' AND p.autocarrata_id = :mid';
            $args[':mid'] = $mezzoId;
        }

        // La ricerca guarda tutto quello che si ha in mano quando si cerca una
        // prenotazione: chi, dove, quale mezzo, che contratto, le note.
        // Ogni campo ha il suo segnaposto: con le query preparate native di
        // MySQL lo stesso nome non si puo' ripetere, e riusare :q ovunque
        // faceva fallire la ricerca con "Invalid parameter number".
        if ($cerca !== '') {
            $campi = [
                'p.cliente', 'p.luogo', 'p.contratto', 'p.note',
                'p.telefono', 'a.targa', 'p.commerciale_testo',
            ];
            $pezzi = [];
            foreach ($campi as $i => $campo) {
                $pezzi[] = $campo . ' LIKE :q' . $i;
                $args[':q' . $i] = '%' . $cerca . '%';
            }
            $sql .= ' AND (' . implode(' OR ', $pezzi) . ')';
        }

        // le piu' recenti in cima: e' quello che si guarda per prima cosa
        $sql .= ' ORDER BY p.data_inizio DESC, a.targa ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function prenotazione(int $companyId, int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM pn_prenotazioni WHERE id = :id AND group_company_id = :cid'
        );
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Prenotazioni gia' presenti che si accavallano con il periodo dato.
     * Serve ad avvisare prima di salvare due lavori sullo stesso mezzo.
     */
    public function sovrapposizioni(int $companyId, int $mezzoId, string $dal, string $al, ?int $escludiId = null): array
    {
        $sql = "
            SELECT id, cliente, data_inizio, data_fine
            FROM   pn_prenotazioni
            WHERE  group_company_id = :cid
              AND  autocarrata_id = :mid
              AND  eliminato_at IS NULL
              AND  stato <> 'annullata'
              AND  data_inizio <= :al
              AND  data_fine   >= :dal
        ";
        $args = [':cid' => $companyId, ':mid' => $mezzoId, ':dal' => $dal, ':al' => $al];

        if ($escludiId) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $escludiId;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mezzi gia' impegnati in un periodo, con il motivo.
     *
     * Serve a togliere dall'elenco le autocarrate che non si possono
     * prenotare, invece di lasciarle scegliere e rifiutarle al salvataggio.
     *
     * @return array<int, array{cliente:string, fino:string}>
     */
    public function occupatiTra(int $companyId, string $dal, string $al, ?int $escludiId = null): array
    {
        $sql = "
            SELECT autocarrata_id, cliente, data_fine
            FROM   pn_prenotazioni
            WHERE  group_company_id = :cid
              AND  eliminato_at IS NULL
              AND  stato <> 'annullata'
              AND  data_inizio <= :al
              AND  data_fine   >= :dal
        ";
        $args = [':cid' => $companyId, ':dal' => $dal, ':al' => $al];

        if ($escludiId) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $escludiId;
        }
        $sql .= ' ORDER BY data_fine DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mezzo = (int)$r['autocarrata_id'];
            // ordinate per data_fine decrescente: la prima che arriva e'
            // quella che libera il mezzo piu' tardi, ed e' quella da dire
            if (!isset($out[$mezzo])) {
                $out[$mezzo] = [
                    'cliente' => (string)$r['cliente'],
                    'fino'    => (string)$r['data_fine'],
                ];
            }
        }
        return $out;
    }

    public function salvaPrenotazione(int $companyId, ?int $id, array $d, ?int $userId): int
    {
        $p = [
            ':mid'      => $d['autocarrata_id'],
            ':cliente'  => $d['cliente'],
            ':telefono' => $d['telefono'] !== '' ? $d['telefono'] : null,
            ':luogo'    => $d['luogo'] !== '' ? $d['luogo'] : null,
            ':dal'      => $d['data_inizio'],
            ':al'       => $d['data_fine'],
            ':stato'    => $d['stato'],
            ':tariffa'  => $d['tariffa_giorno'] !== '' ? $d['tariffa_giorno'] : null,
            ':totale'   => $d['totale'] !== '' ? $d['totale'] : null,
            ':note'     => $d['note'] !== '' ? $d['note'] : null,
            ':contratto'=> $d['contratto'] !== '' ? $d['contratto'] : null,
            ':pag'      => $d['pagamento'],
            ':cid'      => $companyId,
        ];

        // Il commerciale si scrive solo alla creazione: e' chi ha preso la
        // prenotazione, non chi la corregge dopo, quindi la UPDATE non lo
        // tocca nemmeno e il parametro non compare fra i suoi.
        if ($id) {
            $stmt = $this->conn->prepare("
                UPDATE pn_prenotazioni
                SET autocarrata_id = :mid, cliente = :cliente, telefono = :telefono,
                    luogo = :luogo, data_inizio = :dal, data_fine = :al, stato = :stato,
                    tariffa_giorno = :tariffa, totale = :totale, note = :note,
                    contratto = :contratto, pagamento = :pag
                WHERE id = :id AND group_company_id = :cid
            ");
            $stmt->execute($p + [':id' => $id]);
            return $id;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO pn_prenotazioni
                (group_company_id, autocarrata_id, cliente, telefono, luogo,
                 data_inizio, data_fine, stato, tariffa_giorno, totale, note,
                 contratto, commerciale_user_id, pagamento, created_by)
            VALUES (:cid, :mid, :cliente, :telefono, :luogo,
                    :dal, :al, :stato, :tariffa, :totale, :note,
                    :contratto, :comm, :pag, :uid)
        ");
        $stmt->execute($p + [':uid' => $userId, ':comm' => $userId]);
        return (int)$this->conn->lastInsertId();
    }

    /**
     * Eliminazione logica: la riga resta in tabella e sparisce dalle
     * letture. Una prenotazione cancellata per sbaglio, altrimenti, non
     * tornerebbe piu' indietro.
     */
    public function eliminaPrenotazione(int $companyId, int $id, ?int $userId): void
    {
        $stmt = $this->conn->prepare("
            UPDATE pn_prenotazioni
            SET eliminato_at = NOW(), eliminato_da = :uid
            WHERE id = :id AND group_company_id = :cid
        ");
        $stmt->execute([':id' => $id, ':cid' => $companyId, ':uid' => $userId]);
    }

    public function ripristinaPrenotazione(int $companyId, int $id): void
    {
        $stmt = $this->conn->prepare("
            UPDATE pn_prenotazioni
            SET eliminato_at = NULL, eliminato_da = NULL
            WHERE id = :id AND group_company_id = :cid
        ");
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
    }

    /**
     * Per ogni mezzo, la prima data in cui torna libero.
     *
     * E' la risposta alla domanda che arriva al telefono: se il mezzo e'
     * occupato oggi si guarda la catena di prenotazioni attaccate una
     * all'altra, e si restituisce il giorno dopo l'ultima della catena.
     *
     * @return array<int, string|null> id mezzo => data (null se gia' libero)
     */
    public function primoGiornoLibero(int $companyId, string $daData): array
    {
        $stmt = $this->conn->prepare("
            SELECT autocarrata_id, data_inizio, data_fine
            FROM   pn_prenotazioni
            WHERE  group_company_id = :cid
              AND  eliminato_at IS NULL
              AND  stato <> 'annullata'
              AND  data_fine >= :da
            ORDER BY autocarrata_id ASC, data_inizio ASC
        ");
        $stmt->execute([':cid' => $companyId, ':da' => $daData]);

        $perMezzo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $perMezzo[(int)$r['autocarrata_id']][] = $r;
        }

        $out = [];
        foreach ($perMezzo as $mezzoId => $righe) {
            $libero = $daData;
            foreach ($righe as $r) {
                // se la prenotazione inizia dopo il giorno candidato c'e' un
                // buco: il mezzo e' libero da li'
                if ($r['data_inizio'] > $libero) {
                    break;
                }
                $dopo = date('Y-m-d', strtotime($r['data_fine'] . ' +1 day'));
                if ($dopo > $libero) {
                    $libero = $dopo;
                }
            }
            $out[$mezzoId] = $libero === $daData ? null : $libero;
        }

        return $out;
    }
}
