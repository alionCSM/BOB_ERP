<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Attendance\LeaveRepository;
use App\Repository\Attendance\RichiestaPresenzaRepository;

/**
 * API dell'app per l'operaio: le sue cose e basta.
 *
 * Sta separato da ApiV1Controller perche' la regola di accesso e' diversa da
 * tutto il resto di BOB. Altrove si controlla un permesso di modulo; qui non
 * c'e' nessun modulo da controllare — un operaio non ne ha — e quello che
 * conta e' una cosa sola: **sono i tuoi dati?**
 *
 * Per questo ogni metodo parte da operaio(), che ricava il worker_id
 * dall'utente collegato e non lo prende mai dalla richiesta. Un id di
 * operaio passato dall'app sarebbe modificabile, e basterebbe cambiare un
 * numero per leggere le presenze e i documenti di un collega.
 *
 * Tutte le risposte hanno la forma { success, ... } come il resto di
 * /api/v1, cosi' l'app non deve distinguere due tipi di risposta.
 */
final class ApiV1OperaioController
{
    public function __construct(private \PDO $conn) {}

    // ── GET /api/v1/me/cantieri ──────────────────────────────────────────────

    /**
     * I cantieri fra cui l'operaio sceglie quando dichiara una giornata.
     *
     * Il problema vero non e' il permesso, e' che uno deve ritrovare il
     * cantiere dove e' stato. Un operaio non ragiona per codici: sa "quello
     * di Lecco", o "lo stesso di ieri". Quindi l'elenco parte da dove ha gia'
     * lavorato — sono quasi sempre quelli, e stanno in cima gia' pronti — e
     * lascia cercare fra i cantieri aperti per il caso in cui lo mandino da
     * un'altra parte.
     *
     * "Dove ha gia' lavorato" si legge da bb_presenze, che e' l'unico posto
     * dove quel dato esiste davvero: l'ufficio lo scrive ogni giorno. Prima
     * qui leggevo bb_worksite_assignments, che in BOB **nessuno scrive mai** —
     * viene solo letta — quindi l'elenco sarebbe uscito vuoto per tutti e
     * nessuno avrebbe potuto dichiarare niente.
     *
     * Parametro `q`: cerca per nome, codice o paese fra i cantieri aperti.
     */
    public function cantieri(Request $request): never
    {
        $operaio = $this->operaio($request);
        $cerca   = trim((string)($_GET['q'] ?? ''));

        // ── Dove e' stato di recente ────────────────────────────────────
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
        $stmt->execute([':wid' => $operaio]);
        $recenti = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // ── Tutti gli aperti, per quando lo mandano altrove ─────────────
        $sql  = "SELECT id, worksite_code, name, location
                 FROM   bb_worksites
                 WHERE  status IN ('In corso', 'A rischio')";
        $args = [];

        if ($cerca !== '') {
            $sql .= " AND (name LIKE :q1 OR worksite_code LIKE :q2 OR location LIKE :q3)";
            foreach (['q1', 'q2', 'q3'] as $seg) {
                $args[':' . $seg] = '%' . $cerca . '%';
            }
        }
        $sql .= ' ORDER BY name ASC LIMIT 200';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        $aperti = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'success' => true,
            // quelli suoi, gia' ordinati dall'ultimo giorno lavorato
            'recenti' => $recenti,
            // tutti gli aperti, per cercare quando lo mandano da un'altra parte
            'aperti'  => $aperti,
        ]);
    }

    // ── GET /api/v1/me/pianificazione ────────────────────────────────────────

    /**
     * Dove l'operaio e' pianificato.
     *
     * E' la risposta alla domanda che l'operaio si fa davvero: non "quale
     * cantiere cerco", ma "domani dove vado". Cercare un cantiere in un
     * elenco e' una cosa che gli si chiede solo quando la pianificazione non
     * dice niente.
     *
     * Senza parametri risponde per oggi e domani: la sera si guarda domani,
     * la mattina si guarda oggi, e mandarle insieme evita la seconda
     * chiamata proprio nell'ora in cui la linea in cantiere e' peggiore.
     *
     * Chi e' capo squadra lo sa da qui: sulla sua riga `sei_capo` e' vero, e
     * l'app gli mostra la squadra al completo.
     */
    public function pianificazione(Request $request): never
    {
        $operaio = $this->operaio($request);

        $dal = $this->data($_GET['dal'] ?? '', date('Y-m-d'));
        $al  = $this->data($_GET['al']  ?? '', date('Y-m-d', strtotime('+1 day')));

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
        $stmt->execute([':wid' => $operaio, ':dal' => $dal, ':al' => $al]);
        $giorni = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // La squadra si manda solo al capo: agli altri non serve sapere chi
        // altro c'e', e mandare l'elenco dei colleghi a tutti vuol dire
        // spargere dati di centoquaranta persone su centoquaranta telefoni.
        foreach ($giorni as &$g) {
            $g['sei_capo']  = (bool)$g['sei_capo'];
            // l'app la usa per sapere se proporre cena e albergo; l'ufficio
            // la usa in approvazione per accorgersi di chi li dichiara senza
            $g['trasferta'] = (bool)$g['trasferta'];
            $g['squadra']  = $g['sei_capo'] ? $this->squadra((int)$g['id']) : [];
        }
        unset($g);

        Response::json([
            'success'       => true,
            'dal'           => $dal,
            'al'            => $al,
            'pianificazione'=> $giorni,
        ]);
    }

    // ── GET /api/v1/me/documenti ─────────────────────────────────────────────

    /**
     * I documenti dell'operaio, con quanto manca alla scadenza.
     *
     * I giorni li conta il server: farlo nell'app vorrebbe dire dipendere
     * dall'orologio del telefono, che in cantiere e' spesso sbagliato, e un
     * documento scaduto che risulta valido e' esattamente il tipo di errore
     * che non ci si puo' permettere su un cantiere.
     */
    public function documenti(Request $request): never
    {
        $operaio = $this->operaio($request);

        $stmt = $this->conn->prepare("
            SELECT id, tipo_documento, data_emissione, scadenza,
                   CASE
                       WHEN scadenza IS NULL THEN NULL
                       ELSE DATEDIFF(scadenza, CURDATE())
                   END AS giorni_alla_scadenza
            FROM   bb_worker_documents
            WHERE  worker_id = :wid
            ORDER BY scadenza IS NULL, scadenza ASC
        ");
        $stmt->execute([':wid' => $operaio]);

        Response::json([
            'success'   => true,
            'documenti' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        ]);
    }

    // ── GET /api/v1/me/presenze ──────────────────────────────────────────────

    public function presenze(Request $request): never
    {
        $operaio = $this->operaio($request);

        // un mese indietro e uno avanti se non si chiede altro: e' la
        // finestra in cui si guarda quando si apre l'app
        $dal = $this->data($_GET['dal'] ?? '', date('Y-m-d', strtotime('-1 month')));
        $al  = $this->data($_GET['al']  ?? '', date('Y-m-d', strtotime('+1 month')));

        $repo = new RichiestaPresenzaRepository($this->conn);

        Response::json([
            'success'  => true,
            'dal'      => $dal,
            'al'       => $al,
            'presenze' => $repo->perOperaio($operaio, $dal, $al),
        ]);
    }

    // ── POST /api/v1/me/presenze ─────────────────────────────────────────────

    /**
     * L'operaio dichiara di aver lavorato.
     *
     * Non nasce una presenza: nasce una dichiarazione che l'ufficio guarda,
     * eventualmente corregge e approva. Da bb_presenze escono i costi e le
     * buste paga, e una riga non verificata li' dentro falserebbe i conti di
     * un cantiere finche' qualcuno non se ne accorge.
     */
    public function creaPresenza(Request $request): never
    {
        $operaio = $this->operaio($request);
        $body    = $this->corpo();

        $worksiteId = (int)($body['worksite_id'] ?? 0);
        $data       = $this->data($body['data'] ?? '', '');
        $turno      = (string)($body['turno'] ?? 'Intero');

        if (!$worksiteId || $data === '') {
            Response::json([
                'success' => false,
                'message' => 'Servono il cantiere e il giorno',
            ], 422);
        }

        // Il futuro no: si dichiara quello che si e' fatto, non quello che si
        // fara'. Senza questo controllo basta sbagliare mese sul calendario
        // del telefono per riempire l'elenco dell'ufficio di giornate che non
        // esistono ancora.
        if ($data > date('Y-m-d')) {
            Response::json([
                'success' => false,
                'message' => 'Non si puo\' dichiarare una giornata futura',
            ], 422);
        }

        // Basta che il cantiere sia aperto. Il controllo vero e'
        // l'approvazione dell'ufficio: e' li' che qualcuno guarda se quella
        // giornata ha senso. Restringere qui ai cantieri "assegnati"
        // sembrava piu' sicuro, ma quell'assegnazione in BOB non la scrive
        // nessuno — avrebbe rifiutato tutto — e comunque un operaio mandato
        // per un giorno da un'altra parte deve poterlo dichiarare.
        if (!$this->cantiereAperto($worksiteId)) {
            Response::json([
                'success' => false,
                'message' => 'Cantiere non trovato o non aperto',
            ], 422);
        }

        $repo = new RichiestaPresenzaRepository($this->conn);

        if ($repo->giaInAttesa($operaio, $data, $worksiteId)) {
            Response::json([
                'success' => false,
                'message' => 'Hai gia\' dichiarato questo giorno su questo cantiere',
            ], 409);
        }

        // Pasti col vocabolario dell'ufficio: "Loro" ha pagato l'operaio,
        // "Noi" ha pagato l'azienda. L'importo non si chiede — lui sa di aver
        // mangiato, non quanto e' costato — e lo mette l'ufficio in
        // approvazione, dove ci sono le fatture.
        $id = $repo->crea($operaio, [
            'worksite_id' => $worksiteId,
            'data'        => $data,
            'turno'       => $turno,
            'pranzo'      => (string)($body['pranzo'] ?? '-'),
            'cena'        => (string)($body['cena'] ?? '-'),
            'hotel'       => trim((string)($body['hotel'] ?? '')),
            'targa_auto'  => strtoupper(trim((string)($body['targa_auto'] ?? ''))),
            'trasferta'   => !empty($body['trasferta']),
            'note'        => trim((string)($body['note'] ?? '')),
        ]);

        Response::json(['success' => true, 'id' => $id, 'stato' => 'in_attesa']);
    }

    // ── POST /api/v1/me/presenze/{id}/ritira ─────────────────────────────────

    /** Finche' l'ufficio non ha deciso, l'operaio puo' ritirare quello che ha mandato. */
    public function ritiraPresenza(Request $request): never
    {
        $operaio = $this->operaio($request);
        $id      = (int)$request->param('id');

        $tolta = (new RichiestaPresenzaRepository($this->conn))->ritira($operaio, $id);

        if (!$tolta) {
            Response::json([
                'success' => false,
                'message' => 'Non trovata, o l\'ufficio l\'ha gia\' decisa',
            ], 409);
        }

        Response::json(['success' => true]);
    }

    // ── GET /api/v1/me/ferie ─────────────────────────────────────────────────

    public function ferie(Request $request): never
    {
        $operaio = $this->operaio($request);

        Response::json([
            'success' => true,
            'ferie'   => (new LeaveRepository($this->conn))->getByWorker($operaio),
        ]);
    }

    // ── POST /api/v1/me/ferie ────────────────────────────────────────────────

    /**
     * Richiesta di ferie o permesso.
     *
     * Nasce in attesa, al contrario di quelle che inserisce l'ufficio: quelle
     * le mette chi decide, questa la manda chi chiede.
     */
    public function creaFerie(Request $request): never
    {
        $operaio = $this->operaio($request);
        $body    = $this->corpo();

        $tipo = in_array($body['tipo'] ?? '', ['ferie', 'permesso'], true)
            ? (string)$body['tipo'] : '';
        $dal  = $this->data($body['dal'] ?? '', '');
        $al   = $this->data($body['al']  ?? '', '');

        if ($tipo === '' || $dal === '') {
            Response::json([
                'success' => false,
                'message' => 'Servono il tipo e il giorno di inizio',
            ], 422);
        }

        // un giorno solo: chi chiede un permesso di mezza giornata compila
        // solo l'inizio, e chiedergli di ripetere la stessa data e' un campo
        // in piu' che non aggiunge niente
        if ($al === '') {
            $al = $dal;
        }
        if ($al < $dal) {
            [$dal, $al] = [$al, $dal];
        }

        $ore = ($body['ore'] ?? '') !== '' ? (float)$body['ore'] : null;

        $stmt = $this->conn->prepare("
            INSERT INTO bb_ferie_permessi
                (worker_id, tipo, data_inizio, data_fine, ore, note,
                 stato, richiesta_da_operaio, created_by)
            VALUES (:wid, :tipo, :dal, :al, :ore, :note,
                    'in_attesa', 1, NULL)
        ");
        $stmt->execute([
            ':wid'  => $operaio,
            ':tipo' => $tipo,
            ':dal'  => $dal,
            ':al'   => $al,
            ':ore'  => $ore,
            ':note' => trim((string)($body['note'] ?? '')) ?: null,
        ]);

        Response::json([
            'success' => true,
            'id'      => (int)$this->conn->lastInsertId(),
            'stato'   => 'in_attesa',
        ]);
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * L'operaio collegato all'utente.
     *
     * Dall'utente e mai dalla richiesta: un id passato dall'app sarebbe
     * modificabile, e basterebbe cambiare un numero per leggere le presenze e
     * i documenti di un collega.
     */
    private function operaio(Request $request): int
    {
        $user     = $request->user();
        $workerId = (int)($user->worker_id ?? 0);

        if (!$workerId) {
            Response::json([
                'success' => false,
                'message' => 'Questo utente non e\' collegato a nessun operaio',
            ], 403);
        }
        return $workerId;
    }

    /**
     * Chi c'e' in squadra quel giorno su quel cantiere.
     *
     * @return array<int, array<string, mixed>>
     */
    private function squadra(int $pianificazioneId): array
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(CONCAT(w.last_name, ' ', w.first_name), pn.worker_name) AS nome,
                   pn.auto_targa,
                   pn.capo_squadra
            FROM   bb_pianificazione_nostri pn
            LEFT JOIN bb_workers w ON w.id = pn.worker_id
            WHERE  pn.pianificazione_id = :pid
            ORDER BY pn.capo_squadra DESC, nome ASC
        ");
        $stmt->execute([':pid' => $pianificazioneId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Il cantiere esiste ed e' aperto? */
    private function cantiereAperto(int $worksiteId): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM bb_worksites
             WHERE id = :ws AND status IN ('In corso', 'A rischio')"
        );
        $stmt->execute([':ws' => $worksiteId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Data in formato aaaa-mm-gg, o il ripiego se non lo e'. */
    private function data(mixed $valore, string $ripiego): string
    {
        $v = trim((string)$valore);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $ripiego;
        }
        return (new \DateTimeImmutable($v))->format('Y-m-d') === $v ? $v : $ripiego;
    }

    /** @return array<string, mixed> */
    private function corpo(): array
    {
        $grezzo = file_get_contents('php://input') ?: '';
        $dati   = json_decode($grezzo, true);
        return is_array($dati) ? $dati : [];
    }
}
