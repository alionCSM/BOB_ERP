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
     * I cantieri su cui l'operaio puo' dichiarare una presenza.
     *
     * Sono quelli a cui e' assegnato, non tutti: un elenco completo su
     * centoquaranta operai vorrebbe dire che chiunque puo' dichiarare ore su
     * un cantiere dove non ha mai messo piede, e l'ufficio se ne accorge solo
     * leggendo riga per riga.
     */
    public function cantieri(Request $request): never
    {
        $operaio = $this->operaio($request);

        $stmt = $this->conn->prepare("
            SELECT w.id, w.worksite_code, w.name, w.location
            FROM   bb_worksite_assignments a
            JOIN   bb_worksites w ON w.id = a.worksite_id
            WHERE  a.worker_id = :wid
            ORDER BY w.name ASC
        ");
        $stmt->execute([':wid' => $operaio]);

        Response::json([
            'success'  => true,
            'cantieri' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
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

        if (!$this->suoCantiere($operaio, $worksiteId)) {
            Response::json([
                'success' => false,
                'message' => 'Non risulti assegnato a questo cantiere',
            ], 403);
        }

        $repo = new RichiestaPresenzaRepository($this->conn);

        if ($repo->giaInAttesa($operaio, $data, $worksiteId)) {
            Response::json([
                'success' => false,
                'message' => 'Hai gia\' dichiarato questo giorno su questo cantiere',
            ], 409);
        }

        $id = $repo->crea($operaio, [
            'worksite_id' => $worksiteId,
            'data'        => $data,
            'turno'       => $turno,
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

    /** L'operaio e' assegnato a questo cantiere? */
    private function suoCantiere(int $workerId, int $worksiteId): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM bb_worksite_assignments
             WHERE worker_id = :wid AND worksite_id = :ws'
        );
        $stmt->execute([':wid' => $workerId, ':ws' => $worksiteId]);
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
