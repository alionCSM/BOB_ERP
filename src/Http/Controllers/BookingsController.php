<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Worksites\WorksiteRepository;
use App\Repository\Workers\WorkerRepository;

final class BookingsController
{
    private \PDO $conn;
    private WorksiteRepository $worksiteRepo;
    private WorkerRepository $workerRepo;

    public function __construct(\PDO $conn, WorksiteRepository $worksiteRepo, WorkerRepository $workerRepo)
    {
        $this->conn         = $conn;
        $this->worksiteRepo = $worksiteRepo;
        $this->workerRepo   = $workerRepo;
    }

    // ── Index ──────────────────────────────────────────────────────────────────

    public function index(Request $request): void
    {
        $bookingService = new BookingService($this->conn);

        $activeTab = $_GET['tab'] ?? 'all';
        $filters = [];
        if ($activeTab === 'restaurant') {
            $filters['type'] = 'restaurant';
        } elseif ($activeTab === 'hotel') {
            $filters['type'] = 'hotel';
        }

        if (isset($_GET['active']) && $_GET['active'] !== '') {
            $filters['active'] = $_GET['active'];
        }
        if (isset($_GET['pagato']) && $_GET['pagato'] !== '') {
            $filters['pagato'] = $_GET['pagato'];
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }

        $bookings = $bookingService->getAllBookings($filters);

        $searchQuery      = $_GET['search'] ?? '';
        $activeFilterRaw  = $_GET['active'] ?? '';
        $pagatoFilterRaw  = $_GET['pagato'] ?? '';
        $isActiveFilter   = $activeFilterRaw === '1';
        $isCompletedFilter = $activeFilterRaw === '0';
        $isUnpaidFilter   = $pagatoFilterRaw === '0';
        $flashSuccess     = $_SESSION['success'] ?? null;
        unset($_SESSION['success']);
        Response::view('bookings/index.html.twig', $request, compact(
            'bookings', 'activeTab', 'searchQuery', 'activeFilterRaw', 'pagatoFilterRaw',
            'isActiveFilter', 'isCompletedFilter', 'isUnpaidFilter', 'flashSuccess'
        ));
    }

    // ── Create ─────────────────────────────────────────────────────────────────

    public function create(Request $request): void
    {
        $bookingService = new BookingService($this->conn);

        $workers      = $this->workerRepo->getAllActive();
        $worksites    = $this->worksiteRepo->getAllMinimal();
        $consorziate  = $this->conn->query("SELECT id, name FROM bb_companies WHERE consorziata = 1 ORDER BY name ASC")->fetchAll(\PDO::FETCH_ASSOC);

        $errors      = [];
        $old         = [];
        $defaultType = $_GET['type'] ?? 'restaurant';

        if ($request->isPost()) {
            $old  = $_POST;
            $data = [
                'type'                      => $_POST['type'] ?? 'restaurant',
                'struttura_id'              => $_POST['struttura_id'] ?? '',
                'struttura_nome'            => trim($_POST['struttura_nome'] ?? ''),
                'struttura_telefono'        => trim($_POST['struttura_telefono'] ?? ''),
                'struttura_indirizzo'       => trim($_POST['struttura_indirizzo'] ?? ''),
                'struttura_citta'           => trim($_POST['struttura_citta'] ?? ''),
                'struttura_provincia'       => trim($_POST['struttura_provincia'] ?? ''),
                'struttura_country'         => trim($_POST['struttura_country'] ?? 'Italia'),
                'struttura_ragione_sociale' => trim($_POST['struttura_ragione_sociale'] ?? ''),
                'worksite_id'               => $_POST['worksite_id'] ?: null,
                'capo_squadra_id'           => $_POST['capo_squadra_id'] ?: null,
                'pranzo'                    => isset($_POST['pranzo']) ? 1 : 0,
                'cena'                      => isset($_POST['cena']) ? 1 : 0,
                'regime'                    => $_POST['regime'] ?? null,
                'note'                      => trim($_POST['note'] ?? ''),
                'a_carico_consorziata'      => isset($_POST['a_carico_consorziata']) ? 1 : 0,
                'consorziata_id'            => $_POST['consorziata_id'] ?: null,
                'periods'                   => $_POST['periods'] ?? [],
            ];

            if ($data['struttura_nome'] === '') {
                $errors[] = 'Il nome della struttura è obbligatorio.';
            }

            $hasValidPeriod = false;
            foreach ($data['periods'] as $p) {
                if (trim((string)($p['prezzo_persona'] ?? '')) !== '') {
                    $hasValidPeriod = true;
                    break;
                }
            }
            if (!$hasValidPeriod) {
                $errors[] = 'Aggiungi almeno un periodo con un prezzo.';
            }

            if (empty($errors)) {
                try {
                    $userId = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);
                    $bookingService->createFromPayload($data, $userId);
                    $_SESSION['success'] = 'Prenotazione creata con successo!';
                    Response::redirect('/bookings');
                } catch (Exception $e) {
                    $errors[] = 'Errore: ' . $e->getMessage();
                }
            }

            $defaultType      = $data['type'];
            $existingPeriods  = $old['periods'] ?? [];
            if (empty($existingPeriods)) {
                $existingPeriods = [['data_dal' => '', 'data_al' => '', 'n_persone' => '', 'prezzo_persona' => '', 'note' => '']];
            }
        } else {
            $existingPeriods = [['data_dal' => '', 'data_al' => '', 'n_persone' => '', 'prezzo_persona' => '', 'note' => '']];
        }

        Response::view('bookings/create.html.twig', $request, compact(
            'defaultType', 'errors', 'old', 'existingPeriods', 'workers', 'worksites', 'consorziate'
        ));
    }

    // ── Edit ───────────────────────────────────────────────────────────────────

    public function edit(Request $request): void
    {
        $bookingService = new BookingService($this->conn);

        $bookingId = $request->intParam('id');

        if ($bookingId <= 0) {
            Response::redirect('/bookings');
        }

        $booking = $bookingService->getById($bookingId);
        if (!$booking) {
            Response::redirect('/bookings');
        }

        $periods     = $bookingService->getPeriods($bookingId);
        $fatture     = $bookingService->getFatture($bookingId);
        $overrides   = $bookingService->getOverrides($bookingId);
        $workers     = $this->workerRepo->getAllActive();
        $worksites   = $this->worksiteRepo->getAllMinimal();
        $consorziate = $this->conn->query("SELECT id, name FROM bb_companies WHERE consorziata = 1 ORDER BY name ASC")->fetchAll(\PDO::FETCH_ASSOC);
        $errors      = [];

        if ($request->isPost()) {
            $data = [
                'type'                      => $_POST['type'] ?? 'restaurant',
                'struttura_id'              => $_POST['struttura_id'] ?? '',
                'struttura_nome'            => trim($_POST['struttura_nome'] ?? ''),
                'struttura_telefono'        => trim($_POST['struttura_telefono'] ?? ''),
                'struttura_indirizzo'       => trim($_POST['struttura_indirizzo'] ?? ''),
                'struttura_citta'           => trim($_POST['struttura_citta'] ?? ''),
                'struttura_provincia'       => trim($_POST['struttura_provincia'] ?? ''),
                'struttura_country'         => trim($_POST['struttura_country'] ?? 'Italia'),
                'struttura_ragione_sociale' => trim($_POST['struttura_ragione_sociale'] ?? ''),
                'worksite_id'               => $_POST['worksite_id'] ?: null,
                'capo_squadra_id'           => $_POST['capo_squadra_id'] ?: null,
                'pranzo'                    => isset($_POST['pranzo']) ? 1 : 0,
                'cena'                      => isset($_POST['cena']) ? 1 : 0,
                'regime'                    => $_POST['regime'] ?? null,
                // pagato is managed exclusively by toggleFatturaPagato → syncBookingPagato
                'note'                      => trim($_POST['note'] ?? ''),
                'a_carico_consorziata'      => isset($_POST['a_carico_consorziata']) ? 1 : 0,
                'consorziata_id'            => $_POST['consorziata_id'] ?: null,
                'periods'                   => $_POST['periods'] ?? [],
            ];

            if ($data['struttura_nome'] === '') {
                $errors[] = 'Il nome della struttura è obbligatorio.';
            }

            $hasValidPeriod = false;
            foreach ($data['periods'] as $p) {
                if (trim((string)($p['prezzo_persona'] ?? '')) !== '') {
                    $hasValidPeriod = true;
                    break;
                }
            }
            if (!$hasValidPeriod) {
                $errors[] = 'Aggiungi almeno un periodo con un prezzo.';
            }

            if (empty($errors)) {
                try {
                    $bookingService->updateFromPayload($bookingId, $data);
                    $_SESSION['success'] = 'Prenotazione aggiornata con successo!';
                    Response::redirect('/bookings');
                } catch (Exception $e) {
                    $errors[] = 'Errore: ' . $e->getMessage();
                }
            }

            $booking = array_merge($booking, $data);
            $periods = array_values($data['periods']);
        }

        Response::view('bookings/edit.html.twig', $request, compact(
            'bookingId', 'booking', 'periods', 'fatture', 'overrides', 'errors', 'workers', 'worksites', 'consorziate'
        ));
    }

    // ── Destroy ────────────────────────────────────────────────────────────────

    public function destroy(Request $request): never
    {
        $bookingService = new BookingService($this->conn);
        $bookingId      = $request->intParam('id');

        if ($bookingId > 0) {
            // Get booking label before deletion
            $labelStmt = $this->conn->prepare('
                SELECT s.nome
                FROM bb_bookings b
                LEFT JOIN bb_strutture s ON s.id = b.struttura_id
                WHERE b.id = :id
                LIMIT 1
            ');
            $labelStmt->execute([':id' => $bookingId]);
            $labelRow = $labelStmt->fetch(\PDO::FETCH_ASSOC);
            $bookingLabel = ($labelRow && !empty($labelRow['nome'])) ? $labelRow['nome'] : "Prenotazione #{$bookingId}";

            $bookingService->delete($bookingId);
            $_SESSION['success'] = 'Prenotazione eliminata con successo.';
            AuditLogger::log($this->conn, $request->user(), 'booking_delete', 'booking', $bookingId, $bookingLabel);
        }

        Response::redirect('/bookings');
    }

    // ── Toggle fattura pagato ──────────────────────────────────────────────────

    public function toggleFatturaPagato(Request $request): never
    {
        $bookingService = new BookingService($this->conn);
        $fatturaId = $request->intParam('fattura_id');

        if ($fatturaId <= 0) {
            Response::json(['ok' => false, 'error' => 'ID non valido'], 400);
        }

        try {
            $newPagato = $bookingService->toggleFatturaPagato($fatturaId);
            Response::json(['ok' => true, 'pagato' => $newPagato]);
        } catch (\Exception $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Add override ──────────────────────────────────────────────────────────

    public function addOverride(Request $request): never
    {
        $bookingService = new BookingService($this->conn);
        $bookingId = $request->intParam('id');

        if ($bookingId <= 0) {
            Response::json(['ok' => false, 'error' => 'ID non valido'], 400);
        }

        $type = $_POST['override_type'] ?? '';
        if (!in_array($type, ['weekday', 'date'], true)) {
            Response::json(['ok' => false, 'error' => 'Tipo non valido'], 400);
        }

        $skipDay = (int)($_POST['skip_day'] ?? 0) === 1 ? 1 : 0;

        $data = [
            'override_type' => $type,
            'period_id'     => !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null,
            'weekday'       => $type === 'weekday' ? ($_POST['weekday'] ?? '') : '',
            'data'          => $type === 'date'    ? ($_POST['data']    ?? '') : '',
            'skip_day'      => $skipDay,
            'pranzo'        => $skipDay ? '' : ($_POST['pranzo'] ?? ''),
            'cena'          => $skipDay ? '' : ($_POST['cena']   ?? ''),
            'regime'        => $skipDay ? '' : ($_POST['regime'] ?? ''),
            'note'          => trim($_POST['note'] ?? ''),
        ];

        try {
            $id = $bookingService->addOverride($bookingId, $data);
            Response::json(['ok' => true, 'id' => $id]);
        } catch (\Exception $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Delete override ────────────────────────────────────────────────────────

    public function deleteOverride(Request $request): never
    {
        $bookingService = new BookingService($this->conn);
        $overrideId = $request->intParam('override_id');

        if ($overrideId <= 0) {
            Response::json(['ok' => false, 'error' => 'ID non valido'], 400);
        }

        try {
            $bookingService->deleteOverride($overrideId);
            Response::json(['ok' => true]);
        } catch (\Exception $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Search strutture ───────────────────────────────────────────────────────

    public function searchStrutture(Request $request): never
    {
        $bookingService = new BookingService($this->conn);

        $q    = trim($_GET['q'] ?? '');
        $type = $_GET['type'] ?? null;

        if ($q === '') {
            Response::json([]);
        }

        $results = $bookingService->searchStrutture($q, $type ?: null);
        Response::json($results);
    }

    // ── Get struttura ──────────────────────────────────────────────────────────

    public function getStruttura(Request $request): never
    {
        $bookingService = new BookingService($this->conn);

        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::json(['error' => 'ID mancante'], 400);
        }

        $struttura = $bookingService->getStruttura($id);

        if (!$struttura) {
            Response::json(['error' => 'Struttura non trovata'], 404);
        }

        Response::json($struttura);
    }

    // ── Add fattura ────────────────────────────────────────────────────────────

    public function addFattura(Request $request): never
    {
        $bookingService = new BookingService($this->conn);
        $bookingId      = $request->intParam('id');

        if ($bookingId <= 0) {
            Response::json(['ok' => false, 'error' => 'ID prenotazione non valido'], 400);
        }

        $booking = $bookingService->getById($bookingId);
        if (!$booking) {
            Response::json(['ok' => false, 'error' => 'Prenotazione non trovata'], 404);
        }

        try {
            $data = [
                'numero'       => $_POST['numero'] ?? '',
                'data_fattura' => $_POST['data_fattura'] ?? '',
                'importo'      => $_POST['importo'] ?? '',
                'note'         => $_POST['note'] ?? '',
            ];

            $fatturaId = $bookingService->addFattura($bookingId, $data, $_FILES['fattura_file'] ?? null);
            Response::json(['ok' => true, 'fattura_id' => $fatturaId]);
        } catch (Exception $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Delete fattura ─────────────────────────────────────────────────────────

    public function deleteFattura(Request $request): never
    {
        $bookingService = new BookingService($this->conn);
        $fatturaId      = $request->intParam('fattura_id');
        $bookingId      = (int)($_GET['booking_id'] ?? 0);

        try {
            $bookingService->deleteFattura($fatturaId, $bookingId);
            Response::json(['ok' => true]);
        } catch (Exception $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Serve fattura ──────────────────────────────────────────────────────────

    public function serveFattura(Request $request): never
    {
        $fatturaId = $request->intParam('fattura_id');

        if ($fatturaId <= 0) {
            http_response_code(400);
            exit('ID fattura mancante.');
        }

        $stmt = $this->conn->prepare('SELECT file_path FROM bb_booking_fatture WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $fatturaId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['file_path'])) {
            http_response_code(404);
            exit('File non trovato.');
        }

        $cloudBase = realpath(dirname(APP_ROOT) . '/cloud');
        if (!$cloudBase) {
            http_response_code(500);
            exit('Cartella cloud non trovata.');
        }

        $filePath = realpath($cloudBase . '/' . $row['file_path']);

        if (!$filePath || !file_exists($filePath)) {
            http_response_code(404);
            exit('File non trovato sul disco.');
        }

        // Security: ensure path is within cloud directory
        if (!str_starts_with($filePath, $cloudBase)) {
            http_response_code(403);
            exit('Percorso non valido.');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath) ?: 'application/octet-stream';
        $origName = basename($filePath);

        // Strip the prefix "fattura_123_xxxx_" to get original filename
        $displayName = preg_replace('/^fattura_\d+_[a-f0-9.]+_/', '', $origName) ?: $origName;

        header('Content-Type: '        . $mimeType);
        header('Content-Length: '      . filesize($filePath));
        header('Content-Disposition: inline; filename="' . addslashes($displayName) . '"');
        header('Cache-Control: private, max-age=3600');

        readfile($filePath);
        exit;
    }
}
