<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Fleet\FleetRepository;

/**
 * Modulo Flotta — step 1.
 *
 * Le tre azioni "index" (vehicles / fuelCards / telepass) caricano una
 * pagina con tab. Le mutazioni passano da endpoint POST dedicati che poi
 * redirigono sulla tab giusta.
 *
 * Permessi:
 *   - fleet_view   leggere la pagina
 *   - fleet_manage creare/modificare/riassegnare
 */
final class FleetController
{
    public function __construct(
        private \PDO $conn,
        private FleetRepository $fleet
    ) {}

    // ─── Pagina principale (tab unica) ────────────────────────────────────────

    public function index(Request $request): void
    {
        $this->requireView($request);

        $tab = $_GET['tab'] ?? 'vehicles';
        if (!in_array($tab, ['vehicles', 'cards', 'telepass'], true)) {
            $tab = 'vehicles';
        }

        $canManage = $this->canManage($request);

        Response::view('fleet/index.html.twig', $request, [
            'tab'             => $tab,
            'canManage'       => $canManage,
            'vehicles'        => $this->fleet->listVehicles(),
            'cards'           => $this->fleet->listFuelCards(),
            'telepasses'      => $this->fleet->listTelepass(),
            'usersForSelect'  => $this->fleet->activeUsers(),
            'worksitesForSel' => $this->fleet->activeWorksites(),
            'vehiclesForSel'  => $this->fleet->activeVehicles(),
        ]);
    }

    // ─── Veicoli ──────────────────────────────────────────────────────────────

    public function saveVehicle(Request $request): void
    {
        $this->requireManage($request);

        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'targa'               => strtoupper(trim($_POST['targa'] ?? '')),
            'modello'             => trim($_POST['modello'] ?? '') ?: null,
            'tipo'                => $_POST['tipo'] ?? 'furgone',
            'gps_device_id'       => trim($_POST['gps_device_id'] ?? '') ?: null,
            'current_worksite_id' => !empty($_POST['current_worksite_id']) ? (int)$_POST['current_worksite_id'] : null,
            'notes'               => trim($_POST['notes'] ?? '') ?: null,
            'active'              => !empty($_POST['active']),
        ];

        if ($data['targa'] === '') {
            $_SESSION['error'] = 'Targa obbligatoria.';
            Response::redirect('/fleet?tab=vehicles');
        }

        try {
            if ($id > 0) {
                $this->fleet->updateVehicle($id, $data);
                $_SESSION['success'] = 'Veicolo aggiornato.';
            } else {
                $this->fleet->createVehicle($data);
                $_SESSION['success'] = 'Veicolo creato.';
            }
        } catch (\PDOException $e) {
            $_SESSION['error'] = str_contains($e->getMessage(), 'Duplicate')
                ? 'Targa gia esistente.'
                : 'Errore salvataggio: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=vehicles');
    }

    public function deleteVehicle(Request $request): void
    {
        $this->requireManage($request);
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->fleet->deleteVehicle($id);
            $_SESSION['success'] = 'Veicolo eliminato.';
        }
        Response::redirect('/fleet?tab=vehicles');
    }

    public function reassignVehicle(Request $request): void
    {
        $this->requireManage($request);
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $userId    = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $fromDate  = $_POST['from_date'] ?? date('Y-m-d');
        $notes     = trim($_POST['notes'] ?? '') ?: null;

        if ($vehicleId <= 0) {
            Response::error('Veicolo non valido', 400);
        }

        try {
            $this->fleet->reassignVehicle(
                $vehicleId,
                $userId,
                $fromDate,
                $request->user()?->id ? (int)$request->user()->id : null,
                $notes
            );
            $_SESSION['success'] = $userId
                ? 'Assegnazione aggiornata.'
                : 'Veicolo liberato.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=vehicles');
    }

    // ─── Carte carburante ─────────────────────────────────────────────────────

    public function saveCard(Request $request): void
    {
        $this->requireManage($request);
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'numero'    => trim($_POST['numero'] ?? ''),
            'fornitore' => trim($_POST['fornitore'] ?? 'Q8') ?: 'Q8',
            'notes'     => trim($_POST['notes'] ?? '') ?: null,
            'active'    => !empty($_POST['active']),
        ];
        if ($data['numero'] === '') {
            $_SESSION['error'] = 'Numero carta obbligatorio.';
            Response::redirect('/fleet?tab=cards');
        }
        try {
            if ($id > 0) {
                $this->fleet->updateFuelCard($id, $data);
                $_SESSION['success'] = 'Carta aggiornata.';
            } else {
                $this->fleet->createFuelCard($data);
                $_SESSION['success'] = 'Carta creata.';
            }
        } catch (\PDOException $e) {
            $_SESSION['error'] = str_contains($e->getMessage(), 'Duplicate')
                ? 'Carta (numero + fornitore) gia esistente.'
                : 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=cards');
    }

    public function deleteCard(Request $request): void
    {
        $this->requireManage($request);
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->fleet->deleteFuelCard($id);
            $_SESSION['success'] = 'Carta eliminata.';
        }
        Response::redirect('/fleet?tab=cards');
    }

    public function reassignCard(Request $request): void
    {
        $this->requireManage($request);
        $cardId   = (int)($_POST['card_id'] ?? 0);
        $holder   = $_POST['holder_type'] ?? '';   // 'vehicle' | 'user' | 'none'
        $fromDate = $_POST['from_date'] ?? date('Y-m-d');
        $notes    = trim($_POST['notes'] ?? '') ?: null;

        if ($cardId <= 0) Response::error('Carta non valida', 400);

        $vehicleId = null;
        $userId    = null;
        if ($holder === 'vehicle') {
            $vehicleId = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
            if (!$vehicleId) { $_SESSION['error'] = 'Selezionare un veicolo.'; Response::redirect('/fleet?tab=cards'); }
        } elseif ($holder === 'user') {
            $userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            if (!$userId) { $_SESSION['error'] = 'Selezionare un utente.'; Response::redirect('/fleet?tab=cards'); }
        }
        // holder === 'none' => entrambi null => libera la carta

        try {
            $this->fleet->reassignFuelCard(
                $cardId, $vehicleId, $userId, $fromDate,
                $request->user()?->id ? (int)$request->user()->id : null,
                $notes
            );
            $_SESSION['success'] = 'Assegnazione carta aggiornata.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=cards');
    }

    // ─── Telepass ─────────────────────────────────────────────────────────────

    public function saveTelepass(Request $request): void
    {
        $this->requireManage($request);
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'numero' => trim($_POST['numero'] ?? ''),
            'tipo'   => trim($_POST['tipo'] ?? '') ?: null,
            'notes'  => trim($_POST['notes'] ?? '') ?: null,
            'active' => !empty($_POST['active']),
        ];
        if ($data['numero'] === '') {
            $_SESSION['error'] = 'Numero telepass obbligatorio.';
            Response::redirect('/fleet?tab=telepass');
        }
        try {
            if ($id > 0) {
                $this->fleet->updateTelepass($id, $data);
                $_SESSION['success'] = 'Telepass aggiornato.';
            } else {
                $this->fleet->createTelepass($data);
                $_SESSION['success'] = 'Telepass creato.';
            }
        } catch (\PDOException $e) {
            $_SESSION['error'] = str_contains($e->getMessage(), 'Duplicate')
                ? 'Numero telepass gia esistente.'
                : 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=telepass');
    }

    public function deleteTelepass(Request $request): void
    {
        $this->requireManage($request);
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->fleet->deleteTelepass($id);
            $_SESSION['success'] = 'Telepass eliminato.';
        }
        Response::redirect('/fleet?tab=telepass');
    }

    public function reassignTelepass(Request $request): void
    {
        $this->requireManage($request);
        $tpId     = (int)($_POST['telepass_id'] ?? 0);
        $holder   = $_POST['holder_type'] ?? '';
        $fromDate = $_POST['from_date'] ?? date('Y-m-d');
        $notes    = trim($_POST['notes'] ?? '') ?: null;
        if ($tpId <= 0) Response::error('Telepass non valido', 400);

        $vehicleId = null;
        $userId    = null;
        if ($holder === 'vehicle') {
            $vehicleId = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
            if (!$vehicleId) { $_SESSION['error'] = 'Selezionare un veicolo.'; Response::redirect('/fleet?tab=telepass'); }
        } elseif ($holder === 'user') {
            $userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            if (!$userId) { $_SESSION['error'] = 'Selezionare un utente.'; Response::redirect('/fleet?tab=telepass'); }
        }

        try {
            $this->fleet->reassignTelepass(
                $tpId, $vehicleId, $userId, $fromDate,
                $request->user()?->id ? (int)$request->user()->id : null,
                $notes
            );
            $_SESSION['success'] = 'Assegnazione telepass aggiornata.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=telepass');
    }

    // ─── Permission guards ────────────────────────────────────────────────────

    private function canManage(Request $request): bool
    {
        $u = $request->user();
        if (!$u) return false;
        if ((int)$u->id === 1) return true;
        return $u->canAccess('fleet_manage');
    }

    private function canView(Request $request): bool
    {
        $u = $request->user();
        if (!$u) return false;
        if ((int)$u->id === 1) return true;
        return $u->canAccess('fleet_view') || $u->canAccess('fleet_manage');
    }

    private function requireView(Request $request): void
    {
        if (!$this->canView($request)) {
            Response::error('Accesso negato al modulo Flotta.', 403);
        }
    }

    private function requireManage(Request $request): void
    {
        if (!$this->canManage($request)) {
            Response::error('Permesso fleet_manage richiesto.', 403);
        }
    }
}
