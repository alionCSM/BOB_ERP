<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Chi vede una cosa, scritto coi nomi.
 *
 * Prima ogni contenuto aveva un'etichetta (squadra, capi, cliente...) e
 * chi la vedeva si ricavava dal ruolo. L'ufficio pero' non pensa per
 * categorie: pensa "questo lo vede Rossi, e il geometra del committente".
 * Qui le righe sono persone, una per oggetto.
 *
 * Due regole restano fuori dalla tabella, e sono quelle che tengono in
 * piedi la Zone:
 *
 * 1. **L'ufficio vede tutto.** Non sta in tabella: scrivercelo vorrebbe
 *    dire una riga per impiegato per file, e dimenticarne una significa
 *    nascondere un documento a chi lo cerca.
 * 2. **Chi crea una cosa la vede sempre.** Se no un operaio che carica una
 *    foto la perde di vista appena l'ha caricata.
 *
 * E una terza che riguarda solo i file: **un file senza righe proprie
 * segue la cartella**. L'ufficio decide una volta per "Contratti", non
 * file per file. Questa la applica il controller, che e' l'unico a sapere
 * in che cartella sta un file.
 */
final class Condivisione
{
    /**
     * Gli oggetti che si condividono.
     *
     * Non ci sono i messaggi di un'attivita': quelli hanno due interruttori
     * loro ("nota interna", "per il cliente") che si decidono mentre si
     * scrive, e trasformarli in un elenco di nomi vorrebbe dire fermarsi a
     * compilare una lista per rispondere a una domanda.
     */
    public const TIPI = ['attivita', 'cartella', 'file', 'disegno', 'modulo', 'risposta'];

    /**
     * Le parti con cui si condivide in blocco. L'ufficio non c'e': vede
     * gia' tutto.
     */
    public const PARTI = [Accesso::CANTIERE, Accesso::CLIENTE];

    /**
     * Con chi nasce condivisa una cosa nuova: con tutte e due le parti.
     *
     * La Zone e' lo spazio che dividiamo con il cliente e la squadra: quello
     * che uno mette lo vedono gli altri due, e l'ufficio stringe quando
     * serve. Prima nasceva "solo ufficio", e la Zone del cliente restava
     * vuota finche' qualcuno non condivideva le cose una per una.
     */
    public const PARTI_PREDEFINITE = self::PARTI;

    /** @var array<int, array<string, array<int, true>>> cache per richiesta */
    private array $cache = [];

    /** @var array<string, array<string, array<int, true>>> cache: cantiere|parte => tipo => id */
    private array $cacheParti = [];

    public function __construct(private PDO $conn, private Accesso $accesso) {}

    // ── Leggere ─────────────────────────────────────────────────────────────

    /**
     * Tutto quello che una persona vede, in un colpo solo.
     *
     * Una query per richiesta e non una per oggetto: la Zone di un cantiere
     * con duecento file farebbe duecento interrogazioni per disegnare un
     * elenco, e il filtro si fa in memoria.
     *
     * @return array<string, array<int, true>> tipo => [id dell'oggetto => true]
     */
    public function vistiDa(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $stmt = $this->conn->prepare(
            'SELECT tipo, oggetto_id FROM bb_zone_condivisioni WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);

        $fuori = array_fill_keys(self::TIPI, []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[(string)$r['tipo']][(int)$r['oggetto_id']] = true;
        }

        return $this->cache[$userId] = $fuori;
    }

    /**
     * Questa persona vede questo oggetto?
     *
     * @param int $creatoDa chi l'ha caricato: lo vede sempre (0 = non si sa)
     */
    /**
     * Tutto quello che una parte vede in un cantiere, in un colpo solo.
     *
     * @return array<string, array<int, true>> tipo => [id => true]
     */
    public function vistiDaParte(int $worksiteId, ?string $parte): array
    {
        if (!in_array($parte, self::PARTI, true)) {
            return [];
        }
        $chiave = $worksiteId . '|' . $parte;
        if (isset($this->cacheParti[$chiave])) {
            return $this->cacheParti[$chiave];
        }
        $fuori = array_fill_keys(self::TIPI, []);
        try {
            $st = $this->conn->prepare(
                'SELECT tipo, oggetto_id FROM bb_zone_condivisioni_ruoli WHERE worksite_id = :w AND ruolo = :r'
            );
            $st->execute([':w' => $worksiteId, ':r' => $parte]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $fuori[(string)$r['tipo']][(int)$r['oggetto_id']] = true;
            }
        } catch (\PDOException $e) {
            // tabella non ancora creata (migrazione da lanciare): si
            // continua con le sole condivisioni per nome
        }
        return $this->cacheParti[$chiave] = $fuori;
    }

    /**
     * Con quali parti sono condivisi questi oggetti.
     *
     * @param  int[] $oggettoIds
     * @return array<int, string[]> id => parti
     */
    public function partiDi(string $tipo, array $oggettoIds): array
    {
        $oggettoIds = array_values(array_unique(array_map('intval', $oggettoIds)));
        $fuori = array_fill_keys($oggettoIds, []);
        if (!$oggettoIds || !in_array($tipo, self::TIPI, true)) {
            return $fuori;
        }
        $segni = implode(', ', array_map(static fn($i) => ':o' . $i, array_keys($oggettoIds)));
        $par   = [':t' => $tipo];
        foreach ($oggettoIds as $i => $id) {
            $par[':o' . $i] = $id;
        }
        try {
            $st = $this->conn->prepare("SELECT oggetto_id, ruolo FROM bb_zone_condivisioni_ruoli WHERE tipo = :t AND oggetto_id IN ($segni)");
            $st->execute($par);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $fuori[(int)$r['oggetto_id']][] = (string)$r['ruolo'];
            }
        } catch (\PDOException $e) {
            // tabella non ancora creata
        }
        return $fuori;
    }

    /** Mette esattamente queste parti su un oggetto. */
    public function salvaParti(int $worksiteId, string $tipo, int $oggettoId, array $parti): array
    {
        if (!in_array($tipo, self::TIPI, true)) {
            throw new \RuntimeException('Tipo non valido');
        }
        $parti = array_values(array_intersect(self::PARTI, $parti));
        $this->conn->prepare('DELETE FROM bb_zone_condivisioni_ruoli WHERE tipo = :t AND oggetto_id = :o')
            ->execute([':t' => $tipo, ':o' => $oggettoId]);
        $ins = $this->conn->prepare(
            'INSERT INTO bb_zone_condivisioni_ruoli (worksite_id, tipo, oggetto_id, ruolo) VALUES (:w, :t, :o, :r)'
        );
        foreach ($parti as $r) {
            $ins->execute([':w' => $worksiteId, ':t' => $tipo, ':o' => $oggettoId, ':r' => $r]);
        }
        $this->cacheParti = [];
        return $parti;
    }

    /** Aggiunge una parte senza togliere le altre. */
    public function aggiungiParte(int $worksiteId, string $tipo, int $oggettoId, string $parte): void
    {
        if (!in_array($parte, self::PARTI, true)) {
            return;
        }
        $this->conn->prepare(
            'INSERT IGNORE INTO bb_zone_condivisioni_ruoli (worksite_id, tipo, oggetto_id, ruolo) VALUES (:w, :t, :o, :r)'
        )->execute([':w' => $worksiteId, ':t' => $tipo, ':o' => $oggettoId, ':r' => $parte]);
        $this->cacheParti = [];
    }

    public function vede(?object $utente, int $worksiteId, string $tipo, int $oggettoId, int $creatoDa = 0): bool
    {
        if (!$utente) {
            return false;
        }
        // L'ufficio di QUESTO cantiere, non chiunque abbia il modulo: il
        // modulo dice chi puo' dare gli accessi, non chi li ha.
        if ($this->accesso->daUfficio($utente, $worksiteId)) {
            return true;
        }
        $io = (int)$utente->id;
        if ($creatoDa > 0 && $creatoDa === $io) {
            return true;
        }
        // Entrare nel cantiere e' il primo cancello: senza, non conta
        // nemmeno avere una riga di condivisione.
        $ruolo = $this->accesso->ruolo($utente, $worksiteId);
        if ($ruolo === null) {
            return false;
        }

        return isset($this->vistiDa($io)[$tipo][$oggettoId])
            || isset($this->vistiDaParte($worksiteId, $ruolo)[$tipo][$oggettoId]);
    }

    /**
     * Con chi sono condivisi questi oggetti. Serve all'ufficio per
     * disegnare il pannello "Chi la vede" senza una query per riga.
     *
     * @param  int[] $oggettoIds
     * @return array<int, int[]> id dell'oggetto => elenco di user_id
     */
    public function conChi(string $tipo, array $oggettoIds): array
    {
        $oggettoIds = array_values(array_unique(array_map('intval', $oggettoIds)));
        if (!$oggettoIds || !in_array($tipo, self::TIPI, true)) {
            return [];
        }

        // Segnaposto numerati: le prepared statement non sono emulate, e un
        // nome ripetuto fa fallire la query.
        $segnaposto = implode(', ', array_map(static fn($i) => ':o' . $i, array_keys($oggettoIds)));
        $stmt = $this->conn->prepare(
            "SELECT oggetto_id, user_id FROM bb_zone_condivisioni
             WHERE tipo = :t AND oggetto_id IN ($segnaposto)"
        );
        $parametri = [':t' => $tipo];
        foreach ($oggettoIds as $i => $id) {
            $parametri[':o' . $i] = $id;
        }
        $stmt->execute($parametri);

        $fuori = array_fill_keys($oggettoIds, []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[(int)$r['oggetto_id']][] = (int)$r['user_id'];
        }
        return $fuori;
    }

    /**
     * Le persone fra cui l'ufficio sceglie, raggruppate per ruolo.
     *
     * Raggruppate e non in fila: in un cantiere con quindici persone, un
     * elenco piatto di nomi non dice chi e' il capo e chi il cliente, ed e'
     * esattamente quello che serve sapere per decidere.
     *
     * @return array<int, array{ruolo: string, titolo: string, persone: array<int, array{id: int, nome: string, azienda: string}>}>
     */
    public function persone(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.user_id, a.ruolo,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS nome,
                   u.username, u.company
            FROM   bb_zone_accessi a
            JOIN   bb_users u ON u.id = a.user_id
            WHERE  a.worksite_id = :w
              AND  u.removed = 'N'
              AND  u.active  = 'Y'
            ORDER BY nome, u.username
        ");
        $stmt->execute([':w' => $worksiteId]);

        // Niente gruppo "Ufficio": chi e' nell'elenco come ufficio vede
        // gia' tutto il cantiere, e spuntarlo non aggiungerebbe niente —
        // darebbe solo l'impressione che togliendolo smetta di vedere.
        $titoli = [
            Accesso::CANTIERE => 'In cantiere',
            Accesso::CLIENTE => 'Cliente',
        ];
        $gruppi = array_fill_keys(array_keys($titoli), []);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ruolo = (string)$r['ruolo'];
            if (!isset($gruppi[$ruolo])) {
                continue;
            }
            $nome = trim((string)$r['nome']);
            $gruppi[$ruolo][] = [
                'id'      => (int)$r['user_id'],
                'nome'    => $nome !== '' ? $nome : (string)$r['username'],
                'azienda' => (string)($r['company'] ?? ''),
            ];
        }

        // Un gruppo vuoto non si manda: una voce "Cliente" senza nessuno
        // sotto fa pensare che manchi qualcosa da caricare.
        $fuori = [];
        foreach ($titoli as $ruolo => $titolo) {
            if ($gruppi[$ruolo]) {
                $fuori[] = ['ruolo' => $ruolo, 'titolo' => $titolo, 'persone' => $gruppi[$ruolo]];
            }
        }
        return $fuori;
    }

    // ── Scrivere ────────────────────────────────────────────────────────────

    /**
     * Mette la condivisione esattamente su queste persone: chi c'era e non
     * c'e' piu' esce.
     *
     * Si accettano solo persone che hanno accesso a quel cantiere. Un id
     * arrivato da fuori — una richiesta scritta a mano — non apre un
     * documento a chi nel cantiere non e' mai entrato.
     *
     * @param  int[] $userIds
     * @return int[] le persone che ci sono davvero finite
     */
    public function salva(int $worksiteId, string $tipo, int $oggettoId, array $userIds, int $daChi): array
    {
        if (!in_array($tipo, self::TIPI, true)) {
            throw new \RuntimeException('Tipo non valido');
        }

        $ammessi = $this->idAmmessi($worksiteId);
        $voluti  = array_values(array_intersect(
            array_unique(array_map('intval', $userIds)),
            $ammessi
        ));

        $this->conn->beginTransaction();
        try {
            $this->conn->prepare(
                'DELETE FROM bb_zone_condivisioni WHERE tipo = :t AND oggetto_id = :o'
            )->execute([':t' => $tipo, ':o' => $oggettoId]);

            if ($voluti) {
                $ins = $this->conn->prepare(
                    'INSERT INTO bb_zone_condivisioni (tipo, oggetto_id, user_id, created_by)
                     VALUES (:t, :o, :u, :da)'
                );
                foreach ($voluti as $uid) {
                    $ins->execute([':t' => $tipo, ':o' => $oggettoId, ':u' => $uid, ':da' => $daChi ?: null]);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        $this->cache = [];
        return $voluti;
    }

    /**
     * A chi tocca un'attivita', la vede.
     *
     * Sembra ovvio e non lo era: assegnare e condividere erano due cose
     * separate. Un'attivita' dell'ufficio "per la squadra", o una nata da
     * una segnalazione, non era condivisa con nessuno — la squadra non la
     * vedeva, e l'avviso, che parte solo verso chi la vede, non partiva.
     * Quella aperta da un cliente per la squadra lo stesso.
     *
     * Qui si aggiungono le persone a cui tocca, senza togliere nessuno.
     */
    public function aggiungiAssegnatari(int $worksiteId, int $taskId): void
    {
        $st = $this->conn->prepare('SELECT assegnata_a, assignee_user_id FROM bb_zone_tasks WHERE id = :t AND worksite_id = :w');
        $st->execute([':t' => $taskId, ':w' => $worksiteId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }

        // a un gruppo: lo vede tutta quella parte, anche chi arriva dopo
        $parte = match ((string)$t['assegnata_a']) {
            'squadra', 'capi', 'cantiere' => Accesso::CANTIERE,
            'cliente'                     => Accesso::CLIENTE,
            default                       => null,
        };
        if ($parte !== null) {
            $this->aggiungiParte($worksiteId, 'attivita', $taskId, $parte);
        }
        // a una persona: lei, se e' nel cantiere (all'ufficio non serve)
        if (!empty($t['assignee_user_id'])) {
            $q = $this->conn->prepare("
                SELECT a.user_id FROM bb_zone_accessi a JOIN bb_worksites w ON w.id = a.worksite_id
                WHERE a.worksite_id = :w AND a.user_id = :p AND a.ruolo <> 'ufficio'
                  AND " . Accesso::SQL_CLIENTE_ENTRA
            );
            $q->execute([':w' => $worksiteId, ':p' => (int)$t['assignee_user_id']]);
            if ($uid = (int)$q->fetchColumn()) {
                $this->conn->prepare(
                    'INSERT IGNORE INTO bb_zone_condivisioni (tipo, oggetto_id, user_id) VALUES (\'attivita\', :o, :u)'
                )->execute([':o' => $taskId, ':u' => $uid]);
                $this->cache = [];
            }
        }
    }

    /**
     * Chi arriva dopo vede quello che vedono gli altri col suo ruolo.
     *
     * Le condivisioni sono per nome: il secondo geometra del committente,
     * aggiunto un mese dopo il primo, trovava la Zone vuota, e l'ufficio
     * doveva ricondividergli a mano ogni attivita', file e disegno. Adesso
     * eredita quello che e' gia' condiviso con gli altri del suo ruolo su
     * questo cantiere. Solo di questo cantiere: le righe non hanno il
     * cantiere, e copiarle senza guardare l'oggetto aprirebbe le cose degli
     * altri cantieri dove quelle persone stanno.
     *
     * @return int le righe aggiunte
     */
    public function comeGliAltri(int $worksiteId, int $userId, string $ruolo): int
    {
        $tabelle = [
            'attivita' => 'bb_zone_tasks',
            'cartella' => 'bb_zone_folders',
            'file'     => 'bb_zone_files',
            'disegno'  => 'bb_worksite_documents',
            'modulo'   => 'bb_zone_form_assegnazioni',
            'risposta' => 'bb_zone_form_submissions',
        ];
        $aggiunte = 0;
        foreach ($tabelle as $tipo => $tabella) {
            // la tabella viene da questo elenco, non dalla richiesta
            $st = $this->conn->prepare("
                INSERT IGNORE INTO bb_zone_condivisioni (tipo, oggetto_id, user_id, created_by)
                SELECT DISTINCT c.tipo, c.oggetto_id, :io, NULL
                FROM   bb_zone_condivisioni c
                JOIN   `$tabella` o ON o.id = c.oggetto_id AND o.worksite_id = :w
                JOIN   bb_zone_accessi a ON a.user_id = c.user_id AND a.worksite_id = :w2
                WHERE  c.tipo = :t AND a.ruolo = :r AND c.user_id <> :io2
            ");
            $st->execute([
                ':io' => $userId, ':w' => $worksiteId, ':w2' => $worksiteId,
                ':t' => $tipo, ':r' => $ruolo, ':io2' => $userId,
            ]);
            $aggiunte += $st->rowCount();
        }
        $this->cache = [];
        return $aggiunte;
    }

    /**
     * Con chi nasce condivisa una cosa appena creata.
     *
     * Chi la crea non deve fermarsi a spuntare nomi per caricare una foto:
     * si parte da quello che era il comportamento delle etichette, e
     * l'ufficio stringe o allarga dopo.
     *
     * - l'ufficio crea per l'ufficio: niente righe, decide lui a chi darla;
     * - un capo o un operaio creano per la squadra (capi e operai), mai per
     *   il cliente: cosa va al committente lo decide l'ufficio;
     * - un cliente crea per i clienti.
     *
     * @return int[]
     */
    public function predefinite(int $worksiteId, ?string $ruolo): array
    {
        return match ($ruolo) {
            Accesso::CANTIERE => $this->idAmmessi($worksiteId, [Accesso::CANTIERE]),
            Accesso::CLIENTE                => $this->idAmmessi($worksiteId, [Accesso::CLIENTE]),
            default                         => [],
        };
    }

    /**
     * Gli utenti che hanno accesso a quel cantiere, eventualmente di certi
     * ruoli soltanto.
     *
     * @param  string[] $ruoli elenco vuoto = tutti
     * @return int[]
     */
    private function idAmmessi(int $worksiteId, array $ruoli = []): array
    {
        // Fuori l'ufficio: vede tutto per conto suo, e una riga di
        // condivisione in piu' falserebbe il conteggio che l'ufficio legge
        // sul bottone ("3 persone" deve voler dire tre oltre a noi).
        $sql = "SELECT user_id FROM bb_zone_accessi WHERE worksite_id = :w AND ruolo <> 'ufficio'";
        $parametri = [':w' => $worksiteId];

        if ($ruoli) {
            $segnaposto = [];
            foreach (array_values($ruoli) as $i => $r) {
                $segnaposto[] = ':r' . $i;
                $parametri[':r' . $i] = $r;
            }
            $sql .= ' AND ruolo IN (' . implode(', ', $segnaposto) . ')';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($parametri);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
