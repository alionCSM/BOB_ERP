<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Fleet\FleetRepository;
use App\Repository\Fleet\FleetTelemetryRepository;
use App\Service\Fleet\GpsRiepilogoTratteImporter;
use App\Service\Fleet\Q8FuelInvoiceImporter;
use App\Service\Fleet\FleetReconciliationService;

/**
 * Modulo Flotta — step 1.
 *
 * /fleet                       pagina principale (3 tab via ?tab=)
 * POST /fleet/vehicle/save     create/update veicolo
 * POST /fleet/vehicle/delete   elimina
 * POST /fleet/vehicle/reassign cambia assegnatario (operaio)
 * GET  /fleet/vehicle/{id}/history   JSON storico
 * (idem per card e telepass)
 *
 * Permessi:
 *   - fleet_view   leggere la pagina + storico
 *   - fleet_manage CRUD + riassegnazioni
 *
 * Assegnatari = operai (bb_workers).
 */
final class FleetController
{
    public function __construct(
        private \PDO $conn,
        private FleetRepository $fleet,
        private FleetTelemetryRepository $telemetry
    ) {}

    // ─── Pagina principale ────────────────────────────────────────────────────

    public function index(Request $request): void
    {
        $this->requireView($request);

        $allowedTabs = ['vehicles', 'cards', 'telepass', 'trips', 'fuel', 'anomalies', 'imports'];
        $tab = $_GET['tab'] ?? 'vehicles';
        if (!in_array($tab, $allowedTabs, true)) $tab = 'vehicles';

        // dati base sempre presenti per i tab principali
        $payload = [
            'tab'         => $tab,
            'canManage'   => $this->canManage($request),
            'vehicles'    => $this->fleet->listVehicles(),
            'cards'       => $this->fleet->listFuelCards(),
            'telepasses'  => $this->fleet->listTelepass(),
            'workers'     => $this->fleet->activeWorkers(),
            'vehiclesSel' => $this->fleet->activeVehicles(),
        ];

        // dati extra per tab di telemetria
        if ($tab === 'trips') {
            $filter = $this->tripsFilterFromQuery();
            $payload['trips']       = $this->telemetry->listTrips($filter);
            $payload['tripsFilter'] = $filter;
            $payload['tripsStats']  = $this->telemetry->tripStats();
        } elseif ($tab === 'fuel') {
            $filter = [
                'from' => $_GET['from'] ?? null,
                'to'   => $_GET['to']   ?? null,
            ];
            $payload['fuelTx']     = $this->telemetry->listFuelTx($filter);
            $payload['fuelStats']  = $this->telemetry->fuelTxStats();
            $payload['fuelFilter'] = $filter;
        } elseif ($tab === 'anomalies') {
            $payload['anomalies'] = $this->telemetry->listAnomalies([
                'severity' => $_GET['severity'] ?? null,
                'status'   => $_GET['status']   ?? 'open',
            ]);
        } elseif ($tab === 'imports') {
            $payload['imports'] = $this->telemetry->listImports();
        }

        Response::view('fleet/index.html.twig', $request, $payload);
    }

    private function tripsFilterFromQuery(): array
    {
        return [
            'vehicle_id' => !empty($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : null,
            'targa'      => !empty($_GET['targa']) ? trim($_GET['targa']) : null,
            'from'       => $_GET['from'] ?? null,
            'to'         => $_GET['to']   ?? null,
            'limit'      => 300,
        ];
    }

    // ─── Import upload ────────────────────────────────────────────────────────

    public function importForm(Request $request): void
    {
        $this->requireManage($request);
        Response::view('fleet/import.html.twig', $request, [
            'canManage'   => true,
            'vehiclesSel' => $this->fleet->activeVehicles(),
        ]);
    }

    public function importUpload(Request $request): void
    {
        $this->requireManage($request);

        $source = $_POST['source'] ?? '';
        if (!in_array($source, ['gps', 'q8'], true)) {
            $_SESSION['error'] = 'Tipo file non valido.';
            Response::redirect('/fleet/import');
        }
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Nessun file caricato (o errore upload).';
            Response::redirect('/fleet/import');
        }

        $tmpPath  = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];

        // salva il file in storage/fleet_imports/YYYY-MM/...
        $storedPath = $this->moveUploadedFile($tmpPath, $origName, $source);

        $importId = $this->telemetry->createImport($source, $origName, $storedPath, $this->currentUserId($request));

        try {
            $result = $source === 'gps'
                ? (new GpsRiepilogoTratteImporter($this->conn))->import($storedPath, $importId)
                : (new Q8FuelInvoiceImporter($this->conn))->import($storedPath, $importId);

            $this->telemetry->finalizeImport($importId, $result);

            $_SESSION['success'] = sprintf(
                'Import %s completato: %d righe importate, %d saltate (su %d totali).',
                strtoupper($source),
                $result['rows_imported'], $result['rows_skipped'], $result['rows_total']
            );
        } catch (\Throwable $e) {
            $this->telemetry->finalizeImport($importId, [
                'rows_total' => 0, 'rows_imported' => 0, 'rows_skipped' => 0,
                'errors' => ['Eccezione: ' . $e->getMessage()],
                'period_from' => null, 'period_to' => null,
            ]);
            $_SESSION['error'] = 'Import fallito: ' . $e->getMessage();
            Response::redirect('/fleet/import');
        }

        Response::redirect('/fleet?tab=' . ($source === 'gps' ? 'trips' : 'fuel'));
    }

    /** Salva il file caricato sotto APP_ROOT/storage/fleet_imports/YYYY-MM/<hash>_<filename>. */
    private function moveUploadedFile(string $tmpPath, string $origName, string $source): string
    {
        $base = APP_ROOT . '/storage/fleet_imports/' . date('Y-m');
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('Impossibile creare la directory di storage.');
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
        $dest = $base . '/' . $source . '_' . date('Ymd_His') . '_' . substr(sha1_file($tmpPath), 0, 8) . '_' . $safe;
        if (!move_uploaded_file($tmpPath, $dest)) {
            // fallback per CLI/test
            if (!@rename($tmpPath, $dest)) {
                throw new \RuntimeException('Spostamento file fallito.');
            }
        }
        return $dest;
    }

    public function analyze(Request $request): void
    {
        $this->requireManage($request);

        $from = $_POST['from'] ?? null;
        $to   = $_POST['to']   ?? null;
        $vehicleId = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;

        $svc = new FleetReconciliationService($this->conn);
        try {
            $r = $svc->run($from, $to, $vehicleId, $this->currentUserId($request));
            $_SESSION['success'] = sprintf(
                'Analisi completata in %dms: %d anomalie su %d transazioni Q8 e %d tratte GPS.',
                $r['duration_ms'], $r['anomalies'], $r['tx'], $r['trips']
            );
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Analisi fallita: ' . $e->getMessage();
            Response::redirect('/fleet/import');
        }
        Response::redirect('/fleet?tab=anomalies');
    }

    public function dismissAnomaly(Request $request): void
    {
        $this->requireManage($request);
        $id = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '') ?: null;
        if ($id > 0) {
            $this->telemetry->dismissAnomaly($id, $this->currentUserId($request), $note);
            $_SESSION['success'] = 'Anomalia archiviata.';
        }
        Response::redirect('/fleet?tab=anomalies');
    }

    // ─── Veicoli ──────────────────────────────────────────────────────────────

    public function saveVehicle(Request $request): void
    {
        $this->requireManage($request);

        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'targa'         => strtoupper(trim($_POST['targa'] ?? '')),
            'modello'       => trim($_POST['modello'] ?? '') ?: null,
            'tipo'          => $_POST['tipo'] ?? 'furgone',
            'gps_device_id' => trim($_POST['gps_device_id'] ?? '') ?: null,
            'notes'         => trim($_POST['notes'] ?? '') ?: null,
            'active'        => !empty($_POST['active']),
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
        $workerId  = !empty($_POST['worker_id']) ? (int)$_POST['worker_id'] : null;
        $fromDate  = $_POST['from_date'] ?? date('Y-m-d');
        $notes     = trim($_POST['notes'] ?? '') ?: null;

        if ($vehicleId <= 0) Response::error('Veicolo non valido', 400);

        try {
            $this->fleet->reassignVehicle($vehicleId, $workerId, $fromDate, $this->currentUserId($request), $notes);
            $_SESSION['success'] = $workerId ? 'Assegnazione aggiornata.' : 'Veicolo liberato.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=vehicles');
    }

    public function vehicleHistory(Request $request): void
    {
        $this->requireView($request);
        $id = $request->intParam('id');
        if ($id <= 0) Response::error('Veicolo non valido', 400);

        $vehicle = $this->fleet->findVehicle($id);
        if (!$vehicle) Response::error('Non trovato', 404);

        Response::json([
            'vehicle' => $vehicle,
            'history' => $this->fleet->vehicleHistory($id),
        ]);
    }

    // ─── Carte ────────────────────────────────────────────────────────────────

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
        $holder   = $_POST['holder_type'] ?? '';   // 'vehicle' | 'worker' | 'none'
        $fromDate = $_POST['from_date'] ?? date('Y-m-d');
        $notes    = trim($_POST['notes'] ?? '') ?: null;
        if ($cardId <= 0) Response::error('Carta non valida', 400);

        [$vehicleId, $workerId] = $this->resolveHolder($holder, '/fleet?tab=cards');

        try {
            $this->fleet->reassignFuelCard($cardId, $vehicleId, $workerId, $fromDate, $this->currentUserId($request), $notes);
            $_SESSION['success'] = 'Assegnazione carta aggiornata.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=cards');
    }

    public function cardHistory(Request $request): void
    {
        $this->requireView($request);
        $id = $request->intParam('id');
        if ($id <= 0) Response::error('Carta non valida', 400);
        $card = $this->fleet->findFuelCard($id);
        if (!$card) Response::error('Non trovata', 404);

        Response::json([
            'card'    => $card,
            'history' => $this->fleet->fuelCardHistory($id),
        ]);
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

        [$vehicleId, $workerId] = $this->resolveHolder($holder, '/fleet?tab=telepass');

        try {
            $this->fleet->reassignTelepass($tpId, $vehicleId, $workerId, $fromDate, $this->currentUserId($request), $notes);
            $_SESSION['success'] = 'Assegnazione telepass aggiornata.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Errore: ' . $e->getMessage();
        }
        Response::redirect('/fleet?tab=telepass');
    }

    public function telepassHistory(Request $request): void
    {
        $this->requireView($request);
        $id = $request->intParam('id');
        if ($id <= 0) Response::error('Telepass non valido', 400);
        $tp = $this->fleet->findTelepass($id);
        if (!$tp) Response::error('Non trovato', 404);

        Response::json([
            'telepass' => $tp,
            'history'  => $this->fleet->telepassHistory($id),
        ]);
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /** Estrae (vehicleId, workerId) dalla scelta del radio "holder_type" e
     *  fa redirect-con-errore se mancante. Centralizza la validazione XOR. */
    private function resolveHolder(string $holder, string $redirectOnError): array
    {
        if ($holder === 'vehicle') {
            $vehicleId = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
            if (!$vehicleId) {
                $_SESSION['error'] = 'Selezionare un veicolo.';
                Response::redirect($redirectOnError);
            }
            return [$vehicleId, null];
        }
        if ($holder === 'worker') {
            $workerId = !empty($_POST['worker_id']) ? (int)$_POST['worker_id'] : null;
            if (!$workerId) {
                $_SESSION['error'] = 'Selezionare un operaio.';
                Response::redirect($redirectOnError);
            }
            return [null, $workerId];
        }
        // 'none' o vuoto => libera
        return [null, null];
    }

    private function currentUserId(Request $request): ?int
    {
        $u = $request->user();
        return ($u && isset($u->id)) ? (int)$u->id : null;
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
        if (!$this->canView($request)) Response::error('Accesso negato al modulo Flotta.', 403);
    }

    private function requireManage(Request $request): void
    {
        if (!$this->canManage($request)) Response::error('Permesso fleet_manage richiesto.', 403);
    }
}
