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

    /**
     * Utenti selezionabili come commerciale.
     *
     * Sono quelli assegnati alla societa': mettere in elenco tutti gli utenti
     * di BOB significherebbe proporre persone che con Poti non c'entrano.
     * Se nessuno risulta assegnato si ripiega sugli utenti interni, cosi' il
     * campo resta utilizzabile prima di aver sistemato le assegnazioni.
     */
    public function commerciali(int $companyId): array
    {
        $sql = "
            SELECT u.id,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                            u.username) AS nome
            FROM   bb_users u
            JOIN   bb_user_companies uc ON uc.user_id = u.id AND uc.group_company_id = :cid
            WHERE  u.type NOT IN ('worker', 'client')
            ORDER BY nome ASC
        ";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':cid' => $companyId]);
            $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($righe) {
                return $righe;
            }
        } catch (\Throwable $e) {
            error_log('[AutocarrataRepository] commerciali: ' . $e->getMessage());
        }

        $stmt = $this->conn->query("
            SELECT u.id,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                            u.username) AS nome
            FROM   bb_users u
            WHERE  u.type NOT IN ('worker', 'client')
            ORDER BY nome ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Prenotazioni ─────────────────────────────────────────────────────────

    /**
     * Prenotazioni che toccano il periodo indicato.
     *
     * Due periodi si sovrappongono quando ognuno inizia prima che l'altro
     * finisca: e' il confronto incrociato qui sotto, e vale sia per la
     * timeline sia per il controllo dei doppioni.
     */
    public function prenotazioni(int $companyId, string $dal, string $al, ?int $mezzoId = null): array
    {
        $sql = "
            SELECT p.*, a.targa, a.modello,
                   DATEDIFF(p.data_fine, p.data_inizio) + 1 AS giorni,
                   -- il commerciale si salva per id e si mostra per nome:
                   -- se cambia nome la prenotazione resta legata alla persona
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''),
                            c.username) AS commerciale_nome
            FROM   pn_prenotazioni p
            JOIN   pn_autocarrate  a ON a.id = p.autocarrata_id
            LEFT JOIN bb_users     c ON c.id = p.commerciale_user_id
            WHERE  p.group_company_id = :cid
              AND  p.stato <> 'annullata'
              AND  p.data_inizio <= :al
              AND  p.data_fine   >= :dal
        ";
        $args = [':cid' => $companyId, ':dal' => $dal, ':al' => $al];

        if ($mezzoId) {
            $sql .= ' AND p.autocarrata_id = :mid';
            $args[':mid'] = $mezzoId;
        }
        $sql .= ' ORDER BY a.targa ASC, p.data_inizio ASC';

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
            ':importo'  => $d['importo'] !== '' ? $d['importo'] : null,
            ':comm'     => $d['commerciale_user_id'] ?: null,
            ':cid'      => $companyId,
        ];

        if ($id) {
            $stmt = $this->conn->prepare("
                UPDATE pn_prenotazioni
                SET autocarrata_id = :mid, cliente = :cliente, telefono = :telefono,
                    luogo = :luogo, data_inizio = :dal, data_fine = :al, stato = :stato,
                    tariffa_giorno = :tariffa, totale = :totale, note = :note,
                    contratto = :contratto, importo = :importo, commerciale_user_id = :comm
                WHERE id = :id AND group_company_id = :cid
            ");
            $stmt->execute($p + [':id' => $id]);
            return $id;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO pn_prenotazioni
                (group_company_id, autocarrata_id, cliente, telefono, luogo,
                 data_inizio, data_fine, stato, tariffa_giorno, totale, note,
                 contratto, importo, commerciale_user_id, created_by)
            VALUES (:cid, :mid, :cliente, :telefono, :luogo,
                    :dal, :al, :stato, :tariffa, :totale, :note,
                    :contratto, :importo, :comm, :uid)
        ");
        $stmt->execute($p + [':uid' => $userId]);
        return (int)$this->conn->lastInsertId();
    }

    public function eliminaPrenotazione(int $companyId, int $id): void
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM pn_prenotazioni WHERE id = :id AND group_company_id = :cid'
        );
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
