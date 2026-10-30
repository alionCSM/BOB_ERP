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

    // ── Supporto ─────────────────────────────────────────────────────────────

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
