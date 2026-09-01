<?php

declare(strict_types=1);

namespace App\Repository\Poti;

use PDO;

/**
 * Macchine a noleggio di Poti (piattaforme, carrelli, telescopici, ...)
 * e i relativi noleggi, che possono contenere piu' macchine.
 *
 * Come per le autocarrate, ogni metodo filtra per societa': l'id non e' un
 * parametro facoltativo da ricordarsi di passare.
 */
final class MacchinaRepository
{
    public function __construct(private PDO $conn) {}

    // ── Macchine ─────────────────────────────────────────────────────────────

    public function macchine(int $companyId, bool $soloAttive = false, string $tipo = ''): array
    {
        $sql  = 'SELECT * FROM pn_macchine WHERE group_company_id = :cid';
        $args = [':cid' => $companyId];

        if ($soloAttive) {
            $sql .= " AND stato = 'attiva'";
        }
        if ($tipo !== '') {
            $sql .= ' AND tipo = :tipo';
            $args[':tipo'] = $tipo;
        }
        $sql .= ' ORDER BY tipo ASC, matricola ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Una pagina di macchine, con i filtri della pagina di gestione.
     *
     * Esiste separata da macchine() perche' li' serve l'elenco intero: e'
     * quello che riempie la tendina del form e la griglia degli impegni.
     * Qui invece si guarda un parco di centinaia di mezzi, e mandarli tutti
     * al browser vuol dire una pagina che non si riesce a leggere prima
     * ancora che a caricare.
     *
     * @param array{q?:string, tipo?:string, stato?:string} $filtri
     * @return array{righe: array<int, array<string,mixed>>, totale: int}
     */
    public function elencoMacchine(int $companyId, array $filtri, int $pagina, int $perPagina): array
    {
        [$where, $args] = $this->filtriMacchine($companyId, $filtri);

        $conta = $this->conn->prepare("SELECT COUNT(*) FROM pn_macchine WHERE {$where}");
        $conta->execute($args);
        $totale = (int)$conta->fetchColumn();

        $offset = max(0, ($pagina - 1) * $perPagina);

        // LIMIT e OFFSET interpolati e non legati: PDO in modalita' emulata
        // li manderebbe fra virgolette e MySQL rifiuterebbe la query. Sono
        // interi ricavati da (int), non arrivano mai grezzi dall'indirizzo.
        $stmt = $this->conn->prepare("
            SELECT * FROM pn_macchine
            WHERE  {$where}
            ORDER BY tipo ASC,
                     -- i mezzi senza adesivo in fondo al loro tipo: sono i
                     -- pochi da etichettare, e in cima darebbero l'idea che
                     -- manchi il numero a tutti
                     numero IS NULL, LENGTH(numero), numero, matricola
            LIMIT  {$perPagina} OFFSET {$offset}
        ");
        $stmt->execute($args);

        return ['righe' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'totale' => $totale];
    }

    /**
     * Condizioni comuni a conteggio ed elenco: scritte una volta sola,
     * altrimenti il numero di pagine finisce per non corrispondere a
     * quello che si vede.
     *
     * @param array{q?:string, tipo?:string, stato?:string,
     *               liberiDal?:string, liberiAl?:string} $filtri
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function filtriMacchine(int $companyId, array $filtri): array
    {
        $where = 'group_company_id = :cid';
        $args  = [':cid' => $companyId];

        // "mi serve dal ... al ...": restano solo i mezzi liberi in quel
        // periodo. Il taglio lo fa il database e non PHP dopo aver letto:
        // scartando le righe a valle, una pagina da cinquanta ne mostrerebbe
        // magari sei, e il conteggio direbbe un numero che non si vede.
        if (!empty($filtri['liberiDal']) && !empty($filtri['liberiAl'])) {
            $where .= " AND id NOT IN (
                SELECT r.macchina_id
                FROM   pn_noleggi_righe r
                JOIN   pn_noleggi n ON n.id = r.noleggio_id
                WHERE  n.group_company_id = :cid2
                  AND  n.eliminato_at IS NULL
                  AND  n.stato <> 'annullato'
                  AND  r.data_inizio <= :lal
                  AND  r.data_fine   >= :ldal
            )";
            $args[':cid2'] = $companyId;
            $args[':ldal'] = $filtri['liberiDal'];
            $args[':lal']  = $filtri['liberiAl'];
        }

        if (!empty($filtri['tipo'])) {
            $where .= ' AND tipo = :tipo';
            $args[':tipo'] = $filtri['tipo'];
        }
        if (!empty($filtri['stato'])) {
            $where .= ' AND stato = :stato';
            $args[':stato'] = $filtri['stato'];
        }
        if (!empty($filtri['q'])) {
            // il numero sta per primo perche' e' quello che si legge sulla
            // macchina: chi cerca parte da li', non dalla matricola
            $where .= ' AND (numero LIKE :q0 OR matricola LIKE :q1 OR modello LIKE :q2
                             OR tipo LIKE :q3 OR note LIKE :q4)';
            foreach (['q0', 'q1', 'q2', 'q3', 'q4'] as $seg) {
                $args[':' . $seg] = '%' . $filtri['q'] . '%';
            }
        }
        return [$where, $args];
    }

    public function macchina(int $companyId, int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM pn_macchine WHERE id = :id AND group_company_id = :cid'
        );
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Tipi gia' usati, per proporli senza doverli configurare altrove:
     * l'elenco cresce da solo man mano che si registrano le macchine.
     *
     * @return string[]
     */
    public function tipi(int $companyId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT DISTINCT tipo FROM pn_macchine WHERE group_company_id = :cid ORDER BY tipo'
        );
        $stmt->execute([':cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function salvaMacchina(int $companyId, ?int $id, array $d): int
    {
        $p = [
            ':tipo'      => $d['tipo'],
            ':numero'    => ($d['numero'] ?? '') !== '' ? $d['numero'] : null,
            ':matricola' => $d['matricola'],
            ':modello'   => $d['modello'] !== '' ? $d['modello'] : null,
            ':altezza'   => $d['altezza_max_m'] !== '' ? $d['altezza_max_m'] : null,
            ':portata'   => $d['portata_kg'] !== '' ? $d['portata_kg'] : null,
            ':note'      => $d['note'] !== '' ? $d['note'] : null,
            ':stato'     => $d['stato'],
            ':cid'       => $companyId,
        ];

        if ($id) {
            $stmt = $this->conn->prepare("
                UPDATE pn_macchine
                SET tipo = :tipo, numero = :numero, matricola = :matricola, modello = :modello,
                    altezza_max_m = :altezza, portata_kg = :portata,
                    note = :note, stato = :stato
                WHERE id = :id AND group_company_id = :cid
            ");
            $stmt->execute($p + [':id' => $id]);
            return $id;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO pn_macchine
                (group_company_id, tipo, numero, matricola, modello, altezza_max_m, portata_kg, note, stato)
            VALUES (:cid, :tipo, :numero, :matricola, :modello, :altezza, :portata, :note, :stato)
        ");
        $stmt->execute($p);
        return (int)$this->conn->lastInsertId();
    }

    /**
     * Il numero dell'adesivo e' gia' su un'altra macchina?
     *
     * Ritorna la matricola di quella macchina invece di un si'/no: davanti a
     * "il numero 42 esiste gia'" la prima domanda e' sempre "su quale?", e
     * senza la risposta tocca cercarla a mano.
     */
    public function numeroInUso(int $companyId, string $numero, ?int $escludiId = null): ?string
    {
        if ($numero === '') {
            return null;   // vuoto: non e' un doppione, e' un adesivo non ancora messo
        }

        $sql  = 'SELECT matricola FROM pn_macchine WHERE group_company_id = :cid AND numero = :n';
        $args = [':cid' => $companyId, ':n' => $numero];
        if ($escludiId) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $escludiId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn() ?: null;
    }

    public function matricolaInUso(int $companyId, string $matricola, ?int $escludiId = null): bool
    {
        $sql  = 'SELECT COUNT(*) FROM pn_macchine WHERE group_company_id = :cid AND matricola = :m';
        $args = [':cid' => $companyId, ':m' => $matricola];
        if ($escludiId) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $escludiId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ── Noleggi ──────────────────────────────────────────────────────────────

    /**
     * Noleggi che toccano il periodo, con le macchine di ciascuno.
     *
     * Le righe si leggono con una seconda query e si attaccano in PHP: farlo
     * con una JOIN moltiplicherebbe le testate per il numero di righe e
     * i totali andrebbero poi ripuliti a mano.
     */
    /**
     * Una pagina di noleggi.
     *
     * La ricerca la fa il database e non il browser: prima le schede
     * arrivavano tutte e un pezzo di JavaScript ne nascondeva alcune, il che
     * funziona finche' i noleggi sono poche decine. Con qualche migliaio
     * significa spedire tutto l'archivio a ogni apertura per guardarne
     * quindici.
     *
     * @param array{dal:string, al:string, q?:string, stato?:string, pagamento?:string} $filtri
     * @return array{righe: array<int, array<string,mixed>>, totale: int}
     */
    public function noleggi(int $companyId, array $filtri, int $pagina = 1, int $perPagina = 25): array
    {
        [$where, $args] = $this->filtriNoleggi($companyId, $filtri);

        $conta = $this->conn->prepare("SELECT COUNT(*) FROM pn_noleggi n WHERE {$where}");
        $conta->execute($args);
        $totale = (int)$conta->fetchColumn();

        $offset = max(0, ($pagina - 1) * $perPagina);

        // Il nome del commerciale si aggancia solo alla pagina che si
        // mostra: fare la join prima di tagliare vorrebbe dire risolverlo
        // per tutto l'archivio e buttarne via il 99%.
        $stmt = $this->conn->prepare("
            SELECT n.*,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                            u.username,
                            n.commerciale_testo) AS commerciale_nome
            FROM   pn_noleggi n
            LEFT JOIN bb_users u ON u.id = n.commerciale_user_id
            WHERE  {$where}
            ORDER BY n.data_inizio DESC, n.id DESC
            LIMIT  {$perPagina} OFFSET {$offset}
        ");
        $stmt->execute($args);
        $testate = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$testate) {
            return ['righe' => [], 'totale' => $totale];
        }

        $righe = $this->righeDi(array_column($testate, 'id'));
        foreach ($testate as &$t) {
            $t['righe'] = $righe[(int)$t['id']] ?? [];
        }
        unset($t);

        return ['righe' => $testate, 'totale' => $totale];
    }

    /**
     * Condizioni comuni a conteggio ed elenco.
     *
     * @param array{dal:string, al:string, q?:string, stato?:string, pagamento?:string} $filtri
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function filtriNoleggi(int $companyId, array $filtri): array
    {
        $where = "n.group_company_id = :cid
                  AND n.eliminato_at IS NULL
                  AND n.data_inizio <= :al
                  AND n.data_fine   >= :dal";
        $args = [
            ':cid' => $companyId,
            ':dal' => $filtri['dal'],
            ':al'  => $filtri['al'],
        ];

        // Gli annullati restano nascosti se non si chiedono: sono la
        // minoranza e in mezzo agli altri confondono. Chiedendoli
        // esplicitamente pero' si devono vedere, altrimenti non c'e' modo
        // di ritrovarli.
        if (!empty($filtri['stato'])) {
            $where .= ' AND n.stato = :stato';
            $args[':stato'] = $filtri['stato'];
        } else {
            $where .= " AND n.stato <> 'annullato'";
        }

        if (!empty($filtri['pagamento'])) {
            $where .= ' AND n.pagamento = :pag';
            $args[':pag'] = $filtri['pagamento'];
        }

        if (!empty($filtri['q'])) {
            // un segnaposto per campo: con le query preparate native lo
            // stesso nome non si puo' ripetere
            $campi = ['n.cliente', 'n.luogo', 'n.contratto', 'n.note',
                      'n.telefono', 'n.commerciale_testo'];
            $pezzi = [];
            foreach ($campi as $i => $campo) {
                $pezzi[] = $campo . ' LIKE :q' . $i;
                $args[':q' . $i] = '%' . $filtri['q'] . '%';
            }
            $where .= ' AND (' . implode(' OR ', $pezzi) . ')';
        }

        return [$where, $args];
    }

    public function noleggio(int $companyId, int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM pn_noleggi WHERE id = :id AND group_company_id = :cid'
        );
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
        $n = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$n) {
            return null;
        }
        $n['righe'] = $this->righeDi([$n['id']])[(int)$n['id']] ?? [];
        return $n;
    }

    /**
     * Righe raggruppate per noleggio.
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function righeDi(array $noleggioIds): array
    {
        if (!$noleggioIds) {
            return [];
        }
        $segnaposti = implode(',', array_fill(0, count($noleggioIds), '?'));

        $stmt = $this->conn->prepare("
            SELECT r.*, m.numero, m.matricola, m.tipo, m.modello,
                   DATEDIFF(r.data_fine, r.data_inizio) + 1 AS giorni
            FROM   pn_noleggi_righe r
            JOIN   pn_macchine m ON m.id = r.macchina_id
            WHERE  r.noleggio_id IN ({$segnaposti})
            ORDER BY r.data_inizio ASC, m.matricola ASC
        ");
        $stmt->execute(array_map('intval', $noleggioIds));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // la durata si scompone qui e non nel template: una riga a mese
            // si legge "1 mese + 5 gg", e Twig non e' il posto dove fare i
            // conti sui calendari
            // a corpo la durata resta il periodo: si paga a forfait, ma
            // sapere quanti giorni il mezzo e' stato fuori serve lo stesso
            if (($r['unita'] ?? 'giorno') === 'mese') {
                $q = \App\Service\Poti\Tariffa::mesiEGiorni(
                    (string)$r['data_inizio'], (string)$r['data_fine']
                );
                $r['mesi']           = $q['mesi'];
                $r['giorni_residui'] = $q['giorni'];
            } else {
                $r['mesi']           = 0;
                $r['giorni_residui'] = (int)$r['giorni'];
            }
            $out[(int)$r['noleggio_id']][] = $r;
        }
        return $out;
    }

    /**
     * Salva testata e righe insieme.
     *
     * Le righe si riscrivono da zero dentro una transazione: e' l'unico modo
     * per gestire in un colpo solo quelle aggiunte, modificate e tolte senza
     * lasciare la testata scollegata dalle sue righe se qualcosa fallisce.
     *
     * @param array<int, array<string, mixed>> $righe
     */
    public function salvaNoleggio(int $companyId, ?int $id, array $d, array $righe, ?int $userId): int
    {
        // le date della testata vengono dalle righe: sono il periodo in cui
        // il noleggio e' aperto, dalla prima consegna all'ultimo rientro
        $inizi = array_column($righe, 'data_inizio');
        $fini  = array_column($righe, 'data_fine');

        $p = [
            ':cliente'   => $d['cliente'],
            ':telefono'  => $d['telefono'] !== '' ? $d['telefono'] : null,
            ':luogo'     => $d['luogo'] !== '' ? $d['luogo'] : null,
            ':contratto' => $d['contratto'] !== '' ? $d['contratto'] : null,
            ':dal'       => $inizi ? min($inizi) : null,
            ':al'        => $fini  ? max($fini)  : null,
            ':stato'     => $d['stato'],
            ':trasporto' => $d['trasporto'] !== '' ? $d['trasporto'] : null,
            ':totale'    => $d['totale'] !== '' ? $d['totale'] : null,
            ':assic'     => !empty($d['assicurazione']) ? 1 : 0,
            ':assicperc' => $d['assicurazione_perc'] !== '' ? $d['assicurazione_perc'] : null,
            ':assicimp'  => $d['assicurazione_importo'] !== '' ? $d['assicurazione_importo'] : null,
            ':pag'       => $d['pagamento'],
            ':firmato'   => !empty($d['contratto_firmato']) ? 1 : 0,
            ':note'      => $d['note'] !== '' ? $d['note'] : null,
            ':cid'       => $companyId,
        ];

        $this->conn->beginTransaction();
        try {
            if ($id) {
                $stmt = $this->conn->prepare("
                    UPDATE pn_noleggi
                    SET cliente = :cliente, telefono = :telefono, luogo = :luogo,
                        contratto = :contratto, data_inizio = :dal, data_fine = :al,
                        stato = :stato, trasporto = :trasporto, totale = :totale,
                        pagamento = :pag, note = :note, contratto_firmato = :firmato,
                        assicurazione = :assic, assicurazione_perc = :assicperc,
                        assicurazione_importo = :assicimp
                    WHERE id = :id AND group_company_id = :cid
                ");
                $stmt->execute($p + [':id' => $id]);
                $noleggioId = $id;

                $del = $this->conn->prepare('DELETE FROM pn_noleggi_righe WHERE noleggio_id = :nid');
                $del->execute([':nid' => $noleggioId]);
            } else {
                // il commerciale si scrive solo alla creazione: e' chi ha
                // preso il noleggio, non chi lo corregge dopo
                $stmt = $this->conn->prepare("
                    INSERT INTO pn_noleggi
                        (group_company_id, cliente, telefono, luogo, contratto,
                         data_inizio, data_fine, stato, trasporto, totale,
                         pagamento, note, contratto_firmato,
                         assicurazione, assicurazione_perc, assicurazione_importo,
                         commerciale_user_id, created_by)
                    VALUES (:cid, :cliente, :telefono, :luogo, :contratto,
                            :dal, :al, :stato, :trasporto, :totale,
                            :pag, :note, :firmato,
                            :assic, :assicperc, :assicimp,
                            :comm, :uid)
                ");
                $stmt->execute($p + [':comm' => $userId, ':uid' => $userId]);
                $noleggioId = (int)$this->conn->lastInsertId();
            }

            $ins = $this->conn->prepare("
                INSERT INTO pn_noleggi_righe
                    (noleggio_id, macchina_id, data_inizio, data_fine, unita,
                     tariffa_giorno, tariffa_mese, totale, note)
                VALUES (:nid, :mid, :dal, :al, :unita,
                        :tariffa, :tariffamese, :totale, :note)
            ");
            foreach ($righe as $r) {
                $ins->execute([
                    ':nid'         => $noleggioId,
                    ':mid'         => (int)$r['macchina_id'],
                    ':dal'         => $r['data_inizio'],
                    ':al'          => $r['data_fine'],
                    ':unita'       => $r['unita'] ?? 'giorno',
                    ':tariffa'     => $r['tariffa_giorno'] !== '' ? $r['tariffa_giorno'] : null,
                    ':tariffamese' => ($r['tariffa_mese'] ?? '') !== '' ? $r['tariffa_mese'] : null,
                    ':totale'      => $r['totale'] !== '' ? $r['totale'] : null,
                    ':note'        => $r['note'] !== '' ? $r['note'] : null,
                ]);
            }

            $this->conn->commit();
            return $noleggioId;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Eliminazione logica: testata e righe restano dove sono e spariscono
     * dalle letture. Le righe non si toccano di proposito: servono intatte
     * se il noleggio va ripristinato.
     */
    public function eliminaNoleggio(int $companyId, int $id, ?int $userId): void
    {
        $stmt = $this->conn->prepare("
            UPDATE pn_noleggi
            SET eliminato_at = NOW(), eliminato_da = :uid
            WHERE id = :id AND group_company_id = :cid
        ");
        $stmt->execute([':id' => $id, ':cid' => $companyId, ':uid' => $userId]);
    }

    public function ripristinaNoleggio(int $companyId, int $id): void
    {
        $stmt = $this->conn->prepare("
            UPDATE pn_noleggi
            SET eliminato_at = NULL, eliminato_da = NULL
            WHERE id = :id AND group_company_id = :cid
        ");
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
    }

    // ── Disponibilita' ───────────────────────────────────────────────────────

    /**
     * Macchine impegnate nel periodo, con cliente e data di rientro.
     *
     * @return array<int, array{cliente:string, fino:string}>
     */
    public function occupateTra(int $companyId, string $dal, string $al, ?int $escludiNoleggio = null): array
    {
        $sql = "
            SELECT r.macchina_id, n.cliente, r.data_fine
            FROM   pn_noleggi_righe r
            JOIN   pn_noleggi n ON n.id = r.noleggio_id
            WHERE  n.group_company_id = :cid
              AND  n.eliminato_at IS NULL
              AND  n.stato <> 'annullato'
              AND  r.data_inizio <= :al
              AND  r.data_fine   >= :dal
        ";
        $args = [':cid' => $companyId, ':dal' => $dal, ':al' => $al];

        if ($escludiNoleggio) {
            $sql .= ' AND n.id <> :nid';
            $args[':nid'] = $escludiNoleggio;
        }
        $sql .= ' ORDER BY r.data_fine DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid = (int)$r['macchina_id'];
            // ordinate per data_fine decrescente: la prima e' quella che
            // libera la macchina piu' tardi, ed e' quella da dire
            if (!isset($out[$mid])) {
                $out[$mid] = ['cliente' => (string)$r['cliente'], 'fino' => (string)$r['data_fine']];
            }
        }
        return $out;
    }

    /**
     * Righe che si accavallano con un periodo su una data macchina.
     * Serve ad avvisare prima di impegnare due volte la stessa macchina.
     */
    public function sovrapposizioni(int $companyId, int $macchinaId, string $dal, string $al, ?int $escludiNoleggio = null): array
    {
        $sql = "
            SELECT n.id, n.cliente, r.data_inizio, r.data_fine
            FROM   pn_noleggi_righe r
            JOIN   pn_noleggi n ON n.id = r.noleggio_id
            WHERE  n.group_company_id = :cid
              AND  n.eliminato_at IS NULL
              AND  n.stato <> 'annullato'
              AND  r.macchina_id = :mid
              AND  r.data_inizio <= :al
              AND  r.data_fine   >= :dal
        ";
        $args = [':cid' => $companyId, ':mid' => $macchinaId, ':dal' => $dal, ':al' => $al];

        if ($escludiNoleggio) {
            $sql .= ' AND n.id <> :nid';
            $args[':nid'] = $escludiNoleggio;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Per ogni macchina, il primo giorno in cui torna libera.
     * Segue la catena di impegni attaccati uno all'altro, come per le
     * autocarrate: se un noleggio finisce venerdi' e un altro parte sabato,
     * il primo buco vero e' dopo il secondo.
     *
     * @return array<int, string|null> id macchina => data (null se gia' libera)
     */
    public function primoGiornoLibero(int $companyId, string $daData): array
    {
        $stmt = $this->conn->prepare("
            SELECT r.macchina_id, r.data_inizio, r.data_fine
            FROM   pn_noleggi_righe r
            JOIN   pn_noleggi n ON n.id = r.noleggio_id
            WHERE  n.group_company_id = :cid
              AND  n.eliminato_at IS NULL
              AND  n.stato <> 'annullato'
              AND  r.data_fine >= :da
            ORDER BY r.macchina_id ASC, r.data_inizio ASC
        ");
        $stmt->execute([':cid' => $companyId, ':da' => $daData]);

        $perMacchina = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $perMacchina[(int)$r['macchina_id']][] = $r;
        }

        $out = [];
        foreach ($perMacchina as $macchinaId => $righe) {
            $libero = $daData;
            foreach ($righe as $r) {
                if ($r['data_inizio'] > $libero) {
                    break;   // c'e' un buco: la macchina e' libera da li'
                }
                $dopo = date('Y-m-d', strtotime($r['data_fine'] . ' +1 day'));
                if ($dopo > $libero) {
                    $libero = $dopo;
                }
            }
            $out[$macchinaId] = $libero === $daData ? null : $libero;
        }
        return $out;
    }

    /** Righe dei noleggi che toccano il periodo, per timeline e calendario. */
    /**
     * @param int[] $soloMacchine Vuoto = tutte. La griglia degli impegni ne
     *                            disegna una pagina per volta: chiedere gli
     *                            impegni dell'intero parco per mostrarne
     *                            cinquanta e' lavoro che si butta via.
     */
    public function righeNelPeriodo(int $companyId, string $dal, string $al, array $soloMacchine = []): array
    {
        $filtro = '';
        $args   = [':cid' => $companyId, ':dal' => $dal, ':al' => $al];

        if ($soloMacchine) {
            $segnaposti = [];
            foreach (array_values($soloMacchine) as $i => $mid) {
                $segnaposti[]      = ':m' . $i;
                $args[':m' . $i]   = (int)$mid;
            }
            $filtro = ' AND r.macchina_id IN (' . implode(',', $segnaposti) . ')';
        }

        $stmt = $this->conn->prepare("
            SELECT r.*, n.cliente, n.luogo, n.stato, m.numero, m.matricola, m.tipo
            FROM   pn_noleggi_righe r
            JOIN   pn_noleggi n ON n.id = r.noleggio_id
            JOIN   pn_macchine m ON m.id = r.macchina_id
            WHERE  n.group_company_id = :cid
              AND  n.eliminato_at IS NULL
              AND  n.stato <> 'annullato'
              AND  r.data_inizio <= :al
              AND  r.data_fine   >= :dal
              {$filtro}
            ORDER BY m.tipo ASC, m.matricola ASC, r.data_inizio ASC
        ");
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Giornata (vista tecnici) ─────────────────────────────────────────────

    /**
     * Cosa succede in un giorno, riga per riga.
     *
     * Qui l'unita' e' la RIGA e non il noleggio: in un noleggio con tre
     * macchine puo' uscirne una oggi e le altre domani, e al tecnico serve
     * sapere quale.
     *
     * @return array{escono:array, rientrano:array, fuori:array, ritardo:array}
     */
    public function giornata(int $companyId, string $data): array
    {
        $stmt = $this->conn->prepare("
            SELECT r.*, m.numero, m.matricola, m.tipo, m.modello,
                   DATEDIFF(r.data_fine, r.data_inizio) + 1 AS giorni,
                   n.id AS noleggio_id, n.cliente, n.telefono, n.luogo,
                   n.contratto, n.contratto_firmato, n.pagamento, n.note AS note_noleggio
            FROM   pn_noleggi_righe r
            JOIN   pn_noleggi   n ON n.id = r.noleggio_id
            JOIN   pn_macchine  m ON m.id = r.macchina_id
            WHERE  n.group_company_id = :cid
              AND  n.eliminato_at IS NULL
              AND  n.stato <> 'annullato'
              AND  (
                    (r.data_inizio <= :d1 AND r.data_fine >= :d2)
                    OR (r.data_fine < :d3 AND r.rientrato_at IS NULL
                        AND r.data_fine >= DATE_SUB(:d4, INTERVAL 60 DAY))
                   )
            ORDER BY m.tipo ASC, m.matricola ASC
        ");
        $stmt->execute([
            ':cid' => $companyId,
            ':d1' => $data, ':d2' => $data, ':d3' => $data, ':d4' => $data,
        ]);

        $out = ['escono' => [], 'rientrano' => [], 'fuori' => [], 'ritardo' => []];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $inizio = (string)$r['data_inizio'];
            $fine   = (string)$r['data_fine'];

            if ($fine < $data && empty($r['rientrato_at'])) {
                $out['ritardo'][] = $r;
                continue;
            }
            if ($inizio === $data) {
                $out['escono'][] = $r;
            }
            if ($fine === $data) {
                $out['rientrano'][] = $r;
            }
            if ($inizio < $data && $fine > $data) {
                $out['fuori'][] = $r;
            }
        }
        return $out;
    }

    /**
     * Consegne previste nei giorni successivi, per preparare le macchine.
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function prossimeConsegne(int $companyId, string $dal, int $giorni = 14): array
    {
        $al = date('Y-m-d', strtotime($dal . ' +' . $giorni . ' days'));

        $stmt = $this->conn->prepare("
            SELECT r.*, m.numero, m.matricola, m.tipo, m.modello,
                   DATEDIFF(r.data_fine, r.data_inizio) + 1 AS giorni,
                   n.id AS noleggio_id, n.cliente, n.telefono, n.luogo,
                   n.contratto, n.contratto_firmato, n.pagamento
            FROM   pn_noleggi_righe r
            JOIN   pn_noleggi   n ON n.id = r.noleggio_id
            JOIN   pn_macchine  m ON m.id = r.macchina_id
            WHERE  n.group_company_id = :cid
              AND  n.eliminato_at IS NULL
              AND  n.stato <> 'annullato'
              AND  r.data_inizio > :dal
              AND  r.data_inizio <= :al
            ORDER BY r.data_inizio ASC, m.matricola ASC
        ");
        $stmt->execute([':cid' => $companyId, ':dal' => $dal, ':al' => $al]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['data_inizio']][] = $r;
        }
        return $out;
    }

    /**
     * Segna consegna o rientro di una riga.
     *
     * Il controllo sulla societa' passa dalla testata: la riga da sola non
     * sa a quale societa' appartiene, e senza la JOIN si potrebbe toccare
     * la riga di un'altra azienda conoscendone l'id.
     */
    public function segnaMomento(int $companyId, int $rigaId, string $campo, ?int $userId): void
    {
        if (!in_array($campo, ['consegnato', 'rientrato'], true)) {
            return;
        }
        $quando = $campo . '_at';
        $chi    = $campo . '_da';

        $stmt = $this->conn->prepare("
            UPDATE pn_noleggi_righe r
            JOIN   pn_noleggi n ON n.id = r.noleggio_id
            SET    r.{$quando} = CASE WHEN r.{$quando} IS NULL THEN NOW() ELSE NULL END,
                   r.{$chi}    = CASE WHEN r.{$quando} IS NULL THEN :uid ELSE NULL END
            WHERE  r.id = :id AND n.group_company_id = :cid AND n.eliminato_at IS NULL
        ");
        $stmt->execute([':id' => $rigaId, ':cid' => $companyId, ':uid' => $userId]);
    }

    /** Spunta o toglie la firma del contratto (e' del noleggio, non della riga). */
    public function segnaContrattoFirmato(int $companyId, int $noleggioId, bool $firmato): void
    {
        $stmt = $this->conn->prepare("
            UPDATE pn_noleggi
            SET    contratto_firmato = :f
            WHERE  id = :id AND group_company_id = :cid AND eliminato_at IS NULL
        ");
        $stmt->execute([':id' => $noleggioId, ':cid' => $companyId, ':f' => $firmato ? 1 : 0]);
    }
}
