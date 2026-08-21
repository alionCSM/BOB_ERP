<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\GroupCompanyRepository;
use App\Service\CurrentCompany;

/**
 * Scelta e cambio della societa' del gruppo attiva.
 *
 * La pagina di scelta viene mostrata solo a chi ha accesso a piu' di una
 * societa': con una sola la selezione avviene da sola nel middleware, cosi'
 * per la maggior parte degli utenti non cambia nulla rispetto a prima.
 */
final class GroupCompanyController
{
    public function __construct(private \PDO $conn) {}

    /** Utente della richiesta; oltre il middleware non puo' mancare. */
    private function utente(Request $request): \User
    {
        $user = $request->user();
        if (!$user) {
            Response::error('Accesso negato', 403);
        }
        return $user;
    }

    // ── GET /select-company ──────────────────────────────────────────────────

    public function selectForm(Request $request): void
    {
        $user    = $this->utente($request);
        $service = new CurrentCompany($this->conn);
        $lista   = $service->availableFor((int)$user->id);

        // Chi ci arriva senza averne bisogno (una sola societa', o nessuna
        // assegnazione) non deve restare bloccato su una pagina inutile.
        if (count($lista) <= 1) {
            $service->autoSelectOnLogin((int)$user->id);
            Response::redirect('/');
        }

        Response::view('auth/select_company.html.twig', $request, [
            'societa'   => $lista,
            'attivaId'  => $_SESSION[CurrentCompany::SESSION_KEY] ?? null,
            'nomeUtente'=> $user->username ?? '',
            'errore'    => isset($_GET['errore']),
        ]);
    }

    // ── POST /select-company ─────────────────────────────────────────────────

    public function select(Request $request): void
    {
        $user      = $this->utente($request);
        $companyId = (int)($_POST['group_company_id'] ?? 0);
        $service   = new CurrentCompany($this->conn);

        if (!$service->select((int)$user->id, $companyId)) {
            // Id non valido o non assegnato all'utente: si torna alla scelta
            // senza dire quali societa' esistono.
            Response::redirect('/select-company?errore=1');
        }

        Response::redirect('/');
    }

    // ── POST /switch-company ─────────────────────────────────────────────────
    // Cambio societa' dalla top bar, a sessione gia' avviata.

    public function switch(Request $request): void
    {
        $user      = $this->utente($request);
        $companyId = (int)($_POST['group_company_id'] ?? 0);
        $service   = new CurrentCompany($this->conn);

        $ok = $service->select((int)$user->id, $companyId);

        if ($request->isAjax()) {
            Response::json(['ok' => $ok]);
        }

        Response::redirect($ok ? '/' : '/?societa_errore=1');
    }

    // ── Gestione (riservata) ─────────────────────────────────────────────────

    /** GET /societa */
    public function manage(Request $request): void
    {
        $this->assertGestione($request);

        $repo     = new GroupCompanyRepository($this->conn);
        $societa  = $repo->all();

        // societa' aperta nel pannello di destra: la prima se non specificato
        $sel = (int)($_GET['id'] ?? 0);
        if (!$sel && $societa) {
            $sel = (int)$societa[0]['id'];
        }

        $scelta = $sel ? $repo->find($sel) : null;

        // moduli della societa' aperta: null = tutti, anche quelli futuri
        $moduliScelta = $scelta
            ? (new \App\Security\AccessControl($this->conn))->moduliSocieta((int)$scelta['id'])
            : null;

        Response::view('users/societa.html.twig', $request, [
            'societa'     => $societa,
            'selezionata' => $scelta,
            'utenti'      => $sel ? $repo->usersForCompany($sel) : [],
            'gruppiModuli'=> \UsersController::buildPermissionGroups(),
            // null = la societa' ha il flag "tutti i moduli"
            'moduliAttivi'=> $moduliScelta ?? [],
            'tuttiModuli' => $moduliScelta === null,
            'salvato'     => isset($_GET['salvato']),
            'errore'      => $_GET['errore'] ?? null,
        ]);
    }

    /** POST /societa/salva */
    public function save(Request $request): void
    {
        $this->assertGestione($request);

        $repo   = new GroupCompanyRepository($this->conn);
        $id     = (int)($_POST['id'] ?? 0) ?: null;
        $nome   = trim((string)($_POST['nome'] ?? ''));
        $codice = strtoupper(trim((string)($_POST['codice'] ?? '')));

        if ($nome === '' || $codice === '') {
            Response::redirect('/societa?errore=' . urlencode('Nome e codice sono obbligatori'));
        }
        if ($repo->codiceInUso($codice, $id)) {
            Response::redirect('/societa?errore=' . urlencode("Il codice {$codice} e' gia' usato"));
        }

        $nuovoId = $repo->save($id, [
            'nome'        => $nome,
            'codice'      => $codice,
            'colore'      => trim((string)($_POST['colore'] ?? '#1e3a5f')),
            'attiva'      => !empty($_POST['attiva']),
            'ordinamento' => (int)($_POST['ordinamento'] ?? 0),
        ]);

        // I moduli si salvano a parte: sono righe, non una colonna. La scelta
        // "tutti" e' esplicita e comprende anche i moduli aggiunti in futuro.
        (new \App\Security\AccessControl($this->conn))->salvaModuliSocieta(
            $nuovoId,
            !empty($_POST['tutti_moduli']),
            (array)($_POST['moduli'] ?? [])
        );

        Response::redirect('/societa?id=' . $nuovoId . '&salvato=1');
    }

    /** POST /societa/utenti */
    public function saveUsers(Request $request): void
    {
        $this->assertGestione($request);

        $companyId = (int)($_POST['group_company_id'] ?? 0);
        if (!$companyId) {
            Response::redirect('/societa?errore=' . urlencode('Societa\' non valida'));
        }

        $userIds = array_map('intval', (array)($_POST['user_ids'] ?? []));

        (new GroupCompanyRepository($this->conn))->setUsers($companyId, $userIds);

        Response::redirect('/societa?id=' . $companyId . '&salvato=1');
    }

    /**
     * Chi puo' gestire le societa'.
     *
     * Tenuto stretto di proposito: da qui si decide chi vede i dati di quale
     * societa', quindi non e' una pagina da permesso di modulo qualunque.
     */
    private function assertGestione(Request $request): void
    {
        $user = $this->utente($request);
        if ((int)$user->id === 1) {
            return;
        }
        if ($user->canAccess('societa')) {
            return;
        }
        Response::error("Permesso 'societa' richiesto per gestire le societa' del gruppo", 403);
    }
}
