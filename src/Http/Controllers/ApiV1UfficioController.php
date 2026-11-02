<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Attendance\LeaveRepository;
use App\Repository\Attendance\RichiestaPresenzaRepository;

/**
 * L'app per l'ufficio: le cose che oggi si fanno solo dal computer.
 *
 * Gli stessi repository e le stesse regole delle pagine web: un'approvazione
 * fatta dal telefono e una fatta dal sito devono essere la stessa cosa,
 * con la stessa notifica all'operaio. Nessuna seconda versione delle regole.
 *
 * I permessi sono quelli della societa' in cui l'utente sta lavorando (la
 * sceglie l'app con /api/v1/switch-company): chi approva presenze in una
 * societa' e non nell'altra, dall'app fa lo stesso.
 */
final class ApiV1UfficioController
{
    public function __construct(private \PDO $conn) {}

    // ── Da approvare ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/ufficio/approvare
     *
     * Le presenze dichiarate e le richieste di ferie/permessi in attesa,
     * con gli stessi avvisi che vede la pagina web (cena fuori trasferta,
     * cantiere diverso dal pianificato, scritto a mano...).
     */
    public function approvare(Request $request): never
    {
        $this->pretende($request, 'attendance');

        Response::json([
            'success'  => true,
            'presenze' => (new RichiestaPresenzaRepository($this->conn))->daApprovare(['stato' => 'in_attesa']),
            'assenze'  => (new LeaveRepository($this->conn))->daApprovare(),
        ]);
    }

    /**
     * POST /api/v1/ufficio/presenze/{id}/decidi
     * {azione: approva|rifiuta, motivo, correzioni: {worksite_id, turno, pranzo, ...}}
     */
    public function decidiPresenza(Request $request): never
    {
        $this->pretende($request, 'attendance');
        $id     = (int)$request->param('id');
        $body   = $this->corpo();
        $azione = (string)($body['azione'] ?? '');
        $userId = (int)($request->user()->id ?? 0);
        $repo   = new RichiestaPresenzaRepository($this->conn);

        try {
            if ($azione === 'approva') {
                $c = is_array($body['correzioni'] ?? null) ? $body['correzioni'] : [];
                $repo->approva($id, [
                    'worksite_id'   => (int)($c['worksite_id'] ?? 0),
                    'turno'         => (string)($c['turno'] ?? ''),
                    'pranzo'        => (string)($c['pranzo'] ?? '-'),
                    'cena'          => (string)($c['cena'] ?? '-'),
                    'pranzo_prezzo' => (string)($c['pranzo_prezzo'] ?? ''),
                    'cena_prezzo'   => (string)($c['cena_prezzo'] ?? ''),
                    'hotel'         => trim((string)($c['hotel'] ?? '')),
                    'targa_auto'    => trim((string)($c['targa_auto'] ?? '')),
                    'trasferta'     => !empty($c['trasferta']),
                    'azienda'       => trim((string)($c['azienda'] ?? '')),
                    'note'          => trim((string)($c['note'] ?? '')),
                ], $userId);
                $this->avvisa(fn($a) => $a->presenza($id, true));
            } elseif ($azione === 'rifiuta') {
                $motivo = trim((string)($body['motivo'] ?? ''));
                if ($motivo === '') {
                    Response::json(['success' => false, 'message' => "Per rifiutare serve un motivo: lo legge l'operaio."], 422);
                }
                if (!$repo->rifiuta($id, $motivo, $userId)) {
                    Response::json(['success' => false, 'message' => "Non trovata, o gia' decisa da qualcun altro."], 409);
                }
                $this->avvisa(fn($a) => $a->presenza($id, false, $motivo));
            } else {
                Response::json(['success' => false, 'message' => 'Azione non valida'], 422);
            }
        } catch (\RuntimeException $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        Response::json(['success' => true]);
    }

    /** POST /api/v1/ufficio/assenze/{id}/decidi {azione, motivo} */
    public function decidiAssenza(Request $request): never
    {
        $this->pretende($request, 'attendance');
        $id      = (int)$request->param('id');
        $body    = $this->corpo();
        $azione  = (string)($body['azione'] ?? '');
        $motivo  = trim((string)($body['motivo'] ?? ''));

        if (!in_array($azione, ['approva', 'rifiuta'], true)) {
            Response::json(['success' => false, 'message' => 'Azione non valida'], 422);
        }
        if ($azione === 'rifiuta' && $motivo === '') {
            Response::json(['success' => false, 'message' => "Per rifiutare serve un motivo: lo legge l'operaio."], 422);
        }
        $approva = $azione === 'approva';
        $ok = (new LeaveRepository($this->conn))->decidi($id, $approva, $motivo, (int)($request->user()->id ?? 0));
        if (!$ok) {
            Response::json(['success' => false, 'message' => "Non trovata, o gia' decisa da qualcun altro."], 409);
        }
        $this->avvisa(fn($a) => $a->ferie($id, $approva, $motivo));
        Response::json(['success' => true]);
    }

    /**
     * GET /api/v1/ufficio/cantieri?q=
     * Per scegliere il cantiere giusto prima di approvare: la stessa ricerca
     * del sito, solo i campi che servono.
     */
    public function cercaCantieri(Request $request): never
    {
        $this->pretende($request, 'attendance');
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['success' => true, 'cantieri' => []]);
        }
        $st = $this->conn->prepare("
            SELECT id, worksite_code, name, location
            FROM   bb_worksites
            WHERE  is_draft = 0 AND (name LIKE :q OR worksite_code LIKE :q2 OR location LIKE :q3)
            ORDER BY worksite_code DESC
            LIMIT 20
        ");
        $like = '%' . $q . '%';
        $st->execute([':q' => $like, ':q2' => $like, ':q3' => $like]);
        Response::json(['success' => true, 'cantieri' => $st->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    // ── Presenze (registro dell'ufficio) ─────────────────────────────────────

    /**
     * GET /api/v1/ufficio/presenze?dal=&al=&cantiere_id=&worker_id=
     * La stessa ricerca della pagina Presenze, le stesse 200 righe al massimo.
     */
    public function presenze(Request $request): never
    {
        $this->pretende($request, 'attendance');
        $repo = new \App\Repository\AttendanceRepository($this->conn);
        $dal  = $this->data($_GET['dal'] ?? '') ?? date('Y-m-01');
        $al   = $this->data($_GET['al'] ?? '') ?? date('Y-m-d');
        $cant = (int)($_GET['cantiere_id'] ?? 0) ?: null;
        $op   = (int)($_GET['worker_id'] ?? 0) ?: null;
        Response::json([
            'success'  => true,
            'dal'      => $dal,
            'al'       => $al,
            'presenze' => $repo->getFiltered($dal, $al, $cant, $op, 200),
        ]);
    }

    /**
     * POST /api/v1/ufficio/presenze
     * {cantiere, start_date, end_date, overwrite, righe: [{worker_id, turno,
     *  pranzo, pranzo_prezzo, cena, cena_prezzo, hotel, auto, note}]}
     *
     * Lo stesso salvataggio "a blocchi" della pagina Inserisci presenze
     * (AttendanceService::saveBulk), con gli stessi controlli dei conflitti:
     * se un operaio ha gia' una presenza quel giorno, torna 422 con l'elenco
     * e l'app chiede se sovrascrivere.
     */
    public function salvaPresenze(Request $request): never
    {
        $this->pretende($request, 'attendance');
        $b = $this->corpo();
        $dati = [
            'cantiere'   => (int)($b['cantiere'] ?? 0),
            'start_date' => $this->data($b['start_date'] ?? ''),
            'end_date'   => $this->data($b['end_date'] ?? '') ?? $this->data($b['start_date'] ?? ''),
        ];
        foreach (['worker_id', 'turno', 'pranzo', 'pranzo_prezzo', 'cena', 'cena_prezzo', 'hotel', 'auto', 'note'] as $k) {
            $dati[$k] = [];
        }
        foreach ((array)($b['righe'] ?? []) as $r) {
            if ((int)($r['worker_id'] ?? 0) <= 0) continue;
            $dati['worker_id'][]     = (int)$r['worker_id'];
            $dati['turno'][]         = ($r['turno'] ?? '') === 'Mezzo' ? 'Mezzo' : 'Intero';
            $dati['pranzo'][]        = in_array($r['pranzo'] ?? '', ['Loro', 'Noi'], true) ? $r['pranzo'] : '-';
            $dati['pranzo_prezzo'][] = (string)($r['pranzo_prezzo'] ?? '');
            $dati['cena'][]          = in_array($r['cena'] ?? '', ['Loro', 'Noi'], true) ? $r['cena'] : '-';
            $dati['cena_prezzo'][]   = (string)($r['cena_prezzo'] ?? '');
            $dati['hotel'][]         = trim((string)($r['hotel'] ?? ''));
            $dati['auto'][]          = trim((string)($r['auto'] ?? ''));
            $dati['note'][]          = trim((string)($r['note'] ?? ''));
        }
        if (!$dati['worker_id']) {
            Response::json(['success' => false, 'message' => 'Aggiungi almeno un operaio'], 422);
        }

        try {
            $repo = new \App\Repository\AttendanceRepository($this->conn);
            (new \App\Service\AttendanceService($repo, new \App\Validator\AttendanceValidator($repo)))
                ->saveBulk($dati, (int)($request->user()->id ?? 0), !empty($b['overwrite']));
        } catch (\Exception $e) {
            $conflitti = $e->getCode() === 422 ? json_decode($e->getMessage(), true) : null;
            Response::json([
                'success'   => false,
                'code'      => $conflitti ? 'conflitti' : null,
                'message'   => $conflitti ? 'Alcuni operai hanno gia\' una presenza in quei giorni.' : $e->getMessage(),
                'conflitti' => $conflitti,
            ], 422);
        }
        Response::json(['success' => true]);
    }

    /** GET /api/v1/ufficio/operai?q= — i nostri operai attivi, per aggiungerli */
    public function operai(Request $request): never
    {
        $this->pretende($request, 'attendance');
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['success' => true, 'operai' => []]);
        }
        $st = $this->conn->prepare("
            SELECT w.id, w.first_name, w.last_name, w.company
            FROM   bb_workers w
            WHERE  w.active = 'Y'
              AND  (CONCAT(w.last_name, ' ', w.first_name) LIKE :q OR CONCAT(w.first_name, ' ', w.last_name) LIKE :q2)
              AND  (w.company IS NULL OR w.company = ''
                    OR w.company NOT IN (SELECT name FROM bb_companies WHERE consorziata = 1))
            ORDER BY w.last_name, w.first_name
            LIMIT 20
        ");
        $like = '%' . $q . '%';
        $st->execute([':q' => $like, ':q2' => $like]);
        Response::json(['success' => true, 'operai' => $st->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    // ── Documenti in scadenza ────────────────────────────────────────────────

    /**
     * GET /api/v1/ufficio/documenti?stato=scadenza|scaduti
     * Gli stessi elenchi delle pagine "In scadenza" (30 giorni) e "Scaduti",
     * con lo stesso filtro per azienda di chi guarda.
     */
    public function documenti(Request $request): never
    {
        $this->pretende($request, 'documents');
        $c = new \App\Service\Documents\WorkerDocumentController($this->conn);
        $scaduti = ($_GET['stato'] ?? '') === 'scaduti';
        $d = $scaduti
            ? $c->getExpiredDocuments($request->user())
            : $c->getExpiringDocuments($request->user(), 30);

        $riga = fn(array $x, string $di) => [
            'id'        => (int)$x['id'],
            'di'        => $di,
            'tipo'      => (string)($x['tipo_documento'] ?? ''),
            'scadenza'  => (string)($x['scadenza_norm'] ?? $x['scadenza'] ?? ''),
            'nome'      => (string)($di === 'operaio' ? ($x['worker_name'] ?? '') : ($x['company_name'] ?? '')),
            'azienda'   => (string)($x['company_name'] ?? ''),
        ];
        $tutti = array_merge(
            array_map(fn($x) => $riga($x, 'operaio'), $d['workerDocs'] ?? []),
            array_map(fn($x) => $riga($x, 'azienda'), $d['companyDocs'] ?? []),
        );
        // la scadenza piu' vicina (o la piu' vecchia, per gli scaduti) in cima
        usort($tutti, fn($a, $b) => $scaduti ? strcmp($b['scadenza'], $a['scadenza']) : strcmp($a['scadenza'], $b['scadenza']));
        Response::json(['success' => true, 'documenti' => $tutti]);
    }

    // ── Flotta ───────────────────────────────────────────────────────────────

    /** GET /api/v1/ufficio/flotta — mezzi, carte carburante e telepass, a chi sono */
    public function flotta(Request $request): never
    {
        $u = $request->user();
        if (!($u && ((int)$u->id === 1 || $u->canAccess('fleet_view') || $u->canAccess('fleet_manage')))) {
            Response::json(['success' => false, 'message' => 'Non hai questo permesso in questa societa\''], 403);
        }
        $f = new \App\Repository\Fleet\FleetRepository($this->conn);
        Response::json([
            'success'  => true,
            'mezzi'    => $f->listVehicles(),
            'carte'    => $f->listFuelCards(),
            'telepass' => $f->listTelepass(),
        ]);
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    private function data(mixed $v): ?string
    {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /** Il modulo, nella societa' attiva; il superadmin passa sempre. */
    private function pretende(Request $request, string $modulo): void
    {
        $u = $request->user();
        if ($u && ((int)$u->id === 1 || $u->canAccess($modulo))) {
            return;
        }
        Response::json(['success' => false, 'message' => 'Non hai questo permesso in questa societa\''], 403);
    }

    /** L'esito all'operaio; se il push si rompe, la decisione resta. */
    private function avvisa(callable $cosa): void
    {
        try {
            $cosa(new \App\Service\Attendance\AvvisoDecisione(
                $this->conn,
                new \App\Service\Notifications\NotificationService($this->conn, new \App\Infrastructure\Config())
            ));
        } catch (\Throwable $e) {
            error_log('avviso decisione non mandato: ' . $e->getMessage());
        }
    }

    private function corpo(): array
    {
        $d = json_decode((string)file_get_contents('php://input'), true);
        return is_array($d) ? $d : [];
    }
}
