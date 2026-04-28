<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

/**
 * Handles all /clients/* routes.
 *
 * Routes (registered in public/index.php):
 *   GET  /clients              → index    list all clients
 *   GET  /clients/create       → create   show create form
 *   POST /clients              → store    save new client
 *   GET  /clients/{id}         → show     client details
 *   GET  /clients/{id}/edit    → edit     show edit form
 *   POST /clients/{id}/update  → update   save changes
 *   POST /clients/{id}/delete  → destroy  delete client
 *   GET  /clients/search       → search   AJAX autocomplete
 */
final class ClientsController
{
    private \PDO             $conn;
    private ClientRepository $repo;
    private ClientService    $service;

    public function __construct(\PDO $conn)
    {
        $this->conn    = $conn;
        $validator     = new ClientValidator();
        $this->repo    = new ClientRepository($conn);
        $this->service = new ClientService($this->repo, $validator);
    }

    // ── GET /clients ──────────────────────────────────────────────────────────

    public function index(Request $request): void
    {
        $this->assertAdmin($request);
        $clients      = $this->repo->getAllWithStats();
        $totalClients = count($clients);
        $totalWorksites = array_sum(array_column($clients, 'total_worksites'));
        $totalOffers    = array_sum(array_column($clients, 'total_offers'));
        $pageTitle = 'Clienti';
        $sessionSuccess = $_SESSION['success'] ?? null;
        $sessionError = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']); // Clear after reading
        Response::view('clients/index.html.twig', $request, compact('clients', 'totalClients', 'totalWorksites', 'totalOffers', 'pageTitle', 'sessionSuccess', 'sessionError'));
    }

    // ── GET /clients/create ───────────────────────────────────────────────────

    public function create(Request $request): void
    {
        $this->assertAdmin($request);
        $errors    = $_SESSION['errors'] ?? [];
        $old       = $_SESSION['old']    ?? [];
        unset($_SESSION['errors'], $_SESSION['old']);
        $pageTitle = 'Inserisci Nuovo Cliente';
        Response::view('clients/create.html.twig', $request, compact('errors', 'old', 'pageTitle'));
    }

    // ── POST /clients ─────────────────────────────────────────────────────────

    public function store(Request $request): void
    {
        $this->assertAdmin($request);
        $data = $this->extractFormData($request);

        try {
            $this->service->create($request->user(), $data);
            $_SESSION['success'] = 'Nuovo cliente inserito.';
            Response::redirect('/clients');
        } catch (\InvalidArgumentException $e) {
            $_SESSION['errors'] = json_decode($e->getMessage(), true);
            $_SESSION['old']    = $data;
            Response::redirect('/clients/create');
        } catch (\RuntimeException) {
            Response::redirect('/dashboard');
        }
    }

    // ── GET /clients/search ───────────────────────────────────────────────────

    public function search(Request $request): void
    {
        $this->assertAdmin($request);
        $query = (string) $request->get('query', '');
        $rows  = $this->repo->searchByName($query);
        $out   = array_map(static fn(array $c): array => [
            'id'     => $c['id'],
            'name'   => $c['name'] . ' - ' . $c['filiale'],
            'filiale'=> $c['filiale'],
        ], $rows);
        Response::json($out);
    }

    // ── GET /clients/{id} ─────────────────────────────────────────────────────

    public function show(Request $request): void
    {
        $this->assertAdmin($request);
        $id      = $request->intParam('id');
        $page    = max(1, (int) $request->get('page', 1));
        $details = $this->service->getDetails($request->user(), $id, $page);

        $client        = $details['client'];
        $lastOffer     = $details['lastOffer'];
        $totalOffers   = $details['totalOffers'];
        $totalCantieri = $details['totalCantieri'];
        $cantieri      = $details['cantieri'];
        $cantieriPage  = $details['cantieriPage'];
        $cantieriTotal = $details['cantieriTotal'];
        $pageTitle     = 'Dettagli Cliente – ' . $client['name'];

        Response::view('clients/show.html.twig', $request,
            compact('client', 'lastOffer', 'totalOffers', 'totalCantieri', 'cantieri', 'cantieriPage', 'cantieriTotal', 'pageTitle'));
    }

    // ── GET /clients/{id}/edit ────────────────────────────────────────────────

    public function edit(Request $request): void
    {
        $this->assertAdmin($request);
        $id     = $request->intParam('id');
        $client = $this->repo->getById($id);
        if (!$client) { Response::redirect('/clients'); }

        $errors    = $_SESSION['errors'] ?? [];
        $old       = $_SESSION['old']    ?? [];
        unset($_SESSION['errors'], $_SESSION['old']);
        $pageTitle = 'Modifica Cliente';
        Response::view('clients/edit.html.twig', $request, compact('client', 'errors', 'old', 'pageTitle'));
    }

    // ── POST /clients/{id}/update ─────────────────────────────────────────────

    public function update(Request $request): void
    {
        $this->assertAdmin($request);
        $id   = $request->intParam('id');
        $data = $this->extractFormData($request);

        try {
            $this->service->update($request->user(), $id, $data);
            $_SESSION['success'] = 'Cliente aggiornato con successo.';
            Response::redirect('/clients');
        } catch (\InvalidArgumentException $e) {
            $_SESSION['errors'] = json_decode($e->getMessage(), true);
            $_SESSION['old']    = $data;
            Response::redirect("/clients/{$id}/edit");
        } catch (\RuntimeException) {
            Response::redirect('/dashboard');
        }
    }

    // ── GET /clients/{id}/check-delete ───────────────────────────────────────

    public function checkDelete(Request $request): void
    {
        $this->assertAdmin($request);
        $id = $request->intParam('id');

        if (!$id) {
            Response::json(['error' => 'ID non valido'], 400);
            return;
        }

        $stmt = $this->conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM bb_offers    WHERE client_id = :cid1) as offers,
                (SELECT COUNT(*) FROM bb_worksites WHERE client_id = :cid2) as worksites
        ");
        $stmt->execute([':cid1' => $id, ':cid2' => $id]);
        $related = $stmt->fetch(\PDO::FETCH_ASSOC);

        Response::json([
            'can_delete' => ($related['offers'] == 0 && $related['worksites'] == 0),
            'offers'     => (int) $related['offers'],
            'worksites'  => (int) $related['worksites'],
        ]);
    }

    // ── POST /clients/{id}/delete ─────────────────────────────────────────────

    public function destroy(Request $request): void
    {
        $this->assertAdmin($request);
        $id = $request->intParam('id');

        if (!$id) {
            $_SESSION['error'] = 'ID cliente non valido.';
            Response::redirect('/clients');
            return;
        }

        try {
            // Get client name before deletion
            $nameStmt = $this->conn->prepare('SELECT name FROM bb_clients WHERE id = :id LIMIT 1');
            $nameStmt->execute([':id' => $id]);
            $nameRow = $nameStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$nameRow) {
                $_SESSION['error'] = 'Cliente non trovato.';
                Response::redirect('/clients');
                return;
            }

            $clientLabel = $nameRow['name'];

            // Check for related records
            $checkStmt = $this->conn->prepare("
                SELECT
                    (SELECT COUNT(*) FROM bb_offers WHERE client_id = :cid1) as offers,
                    (SELECT COUNT(*) FROM bb_worksites WHERE client_id = :cid2) as worksites
            ");
            $checkStmt->execute([':cid1' => $id, ':cid2' => $id]);
            $related = $checkStmt->fetch(\PDO::FETCH_ASSOC);

            if ($related['offers'] > 0 || $related['worksites'] > 0) {
                $msg = 'Impossibile eliminare: il cliente ha ';
                $msg .= $related['offers'] > 0 ? $related['offers'] . ' offerta/e e ' : '';
                $msg .= $related['worksites'] > 0 ? $related['worksites'] . ' cantiere/i' : '';
                $msg .= ' associati.';
                throw new \RuntimeException($msg);
            }

            // Direct delete
            $deleteStmt = $this->conn->prepare('DELETE FROM bb_clients WHERE id = :id LIMIT 1');
            $result = $deleteStmt->execute([':id' => $id]);

            if (!$result) {
                $errorInfo = $deleteStmt->errorInfo();
                throw new \RuntimeException('Errore durante l\'eliminazione: ' . $errorInfo[2]);
            }

            if ($deleteStmt->rowCount() === 0) {
                throw new \RuntimeException('Cliente non trovato o impossibile da eliminare.');
            }

            $_SESSION['success'] = 'Cliente eliminato con successo.';
            AuditLogger::log($this->conn, $request->user(), 'client_delete', 'client', $id, $clientLabel);
        } catch (\RuntimeException $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        Response::redirect('/clients');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assertAdmin(Request $request): void
    {
        if ($request->user()->getCompanyId() !== 1) {
            Response::redirect('/dashboard');
        }
    }

    private function extractFormData(Request $request): array
    {
        return [
            'name'     => trim((string) $request->post('name',     '')),
            'via'      => trim((string) $request->post('via',      '')),
            'cap'      => trim((string) $request->post('cap',      '')),
            'localita' => trim((string) $request->post('localita', '')),
            'filiale'  => trim((string) $request->post('filiale',  '')),
            'vat'      => trim((string) $request->post('vat',      '')),
            'email'    => trim((string) $request->post('email',    '')),
        ];
    }
}
