<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Worksites\WorksiteRepository;
use App\Repository\Workers\WorkerRepository;

final class AttendanceController
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
        $repo    = new AttendanceRepository($this->conn);

        $startDate      = $request->get('start_date', '');
        $endDate        = $request->get('end_date', '');
        $cantiereId     = $request->get('cantiere_id', '');
        $workerId       = $request->get('worker_id', '');
        $consStartDate  = $request->get('cons_start_date', '');
        $consEndDate    = $request->get('cons_end_date', '');
        $consCantiereId = $request->get('cons_cantiere_id', '');
        $consName       = $request->get('cons_name', '');

        $presenze            = $repo->getFiltered($startDate, $endDate, $cantiereId ? (int)$cantiereId : null, $workerId ? (int)$workerId : null, 200);
        $presenzeConsorziate = $repo->getConsorziateFiltered($consStartDate, $consEndDate, $consCantiereId ?: null, $consName ?: null, 200);

        // Resolve filter labels for Twig (Twig cannot call static methods or object methods inline)
        $selectedCantiere = '';
        if (!empty($cantiereId)) {
            $ws = $this->worksiteRepo->findById((int)$cantiereId);
            $selectedCantiere = $ws ? ($ws['worksite_code'] . ' - ' . $ws['name']) : '';
        }

        $selectedWorker = null;
        if (!empty($workerId)) {
            $selectedWorker = $this->workerRepo->getFullById((int)$workerId);
        }

        $pageTitle = 'Presenze';
        Response::view('attendance/index.html.twig', $request, compact(
            'startDate', 'endDate', 'cantiereId', 'workerId',
            'consStartDate', 'consEndDate', 'consCantiereId', 'consName',
            'presenze', 'presenzeConsorziate',
            'selectedCantiere', 'selectedWorker',
            'pageTitle'
        ));
    }

    // ── Create ─────────────────────────────────────────────────────────────────

    public function create(Request $request): void
    {
        $repo        = new AttendanceRepository($this->conn);
        $cantiere_id = $request->get('cantiere_id', null) ?: null;
        $c           = $cantiere_id ? new Worksite($this->conn, (int)$cantiere_id) : null;

        $duplicateDate = $request->get('duplicate_date', null);
        $duplicateData = null;
        if ($cantiere_id && $duplicateDate) {
            $duplicateData = [
                'nostri'      => $repo->getInternalByWorksiteAndDate((int)$cantiere_id, $duplicateDate),
                'consorziate' => $repo->getConsorziateByWorksiteAndDate((int)$cantiere_id, $duplicateDate),
            ];
        }

        $pageTitle = 'Inserisci Presenze';
        Response::view('attendance/add.html.twig', $request, compact('cantiere_id', 'c', 'duplicateData', 'pageTitle'));
    }

    // ── Edit ───────────────────────────────────────────────────────────────────

    public function edit(Request $request): void
    {
        $cantiereId = $request->get('cantiere', null);
        $data       = $request->get('data', null);

        if (!$cantiereId || !$data) {
            http_response_code(400);
            echo "<div class='p-5'>Parametri mancanti: cantiere o data.</div>";
            exit;
        }

        $repo        = new AttendanceRepository($this->conn);
        $c           = new Worksite($this->conn, (int)$cantiereId);
        $nostri      = $repo->getInternalByWorksiteAndDate((int)$cantiereId, $data);
        $consorziate = $repo->getConsorziateByWorksiteAndDate((int)$cantiereId, $data);

        $pageTitle = 'Modifica Presenze';
        Response::view('attendance/edit.html.twig', $request, compact('cantiereId', 'data', 'c', 'nostri', 'consorziate', 'pageTitle'));
    }

    // ── Destroy ────────────────────────────────────────────────────────────────

    public function destroy(Request $request): never
    {
        $repo = new AttendanceRepository($this->conn);
        $type = $_POST['type'] ?? 'nostri';
        $id   = isset($_POST['id']) ? (int)$_POST['id'] : null;

        if (!$id) {
            $_SESSION['error'] = "ID mancante, impossibile eliminare.";
            Response::redirect('/attendance');
        }

        if ($type === 'consorziata') {
            $repo->deleteConsorziataById($id)
                ? $_SESSION['success'] = "Presenza consorziata #{$id} eliminata."
                : $_SESSION['error']   = "Errore durante la cancellazione della presenza consorziata.";
            AuditLogger::log($this->conn, $request->user(), 'attendance_delete', 'attendance', $id, "Presenza consorziata #{$id}");
        } else {
            $repo->deleteInternalById($id)
                ? $_SESSION['success'] = "Presenza #{$id} eliminata."
                : $_SESSION['error']   = "Errore durante la cancellazione della presenza.";
            AuditLogger::log($this->conn, $request->user(), 'attendance_delete', 'attendance', $id, "Presenza #{$id}");
        }

        Response::redirect('/attendance');
    }

    // ── Advances ───────────────────────────────────────────────────────────────

    public function advances(Request $request): void
    {
        $repo    = new AdvanceRepository($this->conn);
        $anticipi = $repo->getAll();
        $pageTitle = 'Anticipi';
        Response::view('attendance/add_anticipo.html.twig', $request, compact('anticipi', 'pageTitle'));
    }

    // ── Fines ──────────────────────────────────────────────────────────────────

    public function fines(Request $request): void
    {
        $repo  = new FineRepository($this->conn);
        $multe = $repo->getAll();
        $pageTitle = 'Multe';
        Response::view('attendance/add_multa.html.twig', $request, compact('multe', 'pageTitle'));
    }

    // ── Refunds ────────────────────────────────────────────────────────────────

    public function refunds(Request $request): void
    {
        $repo     = new RefundRepository($this->conn);
        $rimborsi = $repo->getAll();
        $pageTitle = 'Rimborsi';
        Response::view('attendance/add_rimborso.html.twig', $request, compact('rimborsi', 'pageTitle'));
    }

    // ── Save bulk (POST JSON) ──────────────────────────────────────────────────

    public function saveBulk(Request $request): never
    {
        try {
            $userId    = (int)($request->user()->id ?? 0);
            $overwrite = !empty($_POST['overwrite']);

            $repo      = new AttendanceRepository($this->conn);
            $validator = new AttendanceValidator($repo);
            $service   = new AttendanceService($repo, $validator);

            $service->saveBulk($_POST, $userId, $overwrite);
            Response::json(['success' => true]);
        } catch (\Exception $e) {
            $code    = $e->getCode();
            $message = $e->getMessage();
            $errors  = null;

            if ($code === 422 && json_decode($message, true)) {
                $errors  = json_decode($message, true);
                $message = "Alcune presenze non sono valide. Controlla le righe evidenziate.";
            }

            Response::json(['success' => false, 'message' => $message, 'errors' => $errors]);
        }
    }

    // ── Save advance (POST) ────────────────────────────────────────────────────

    public function saveAdvance(Request $request): never
    {
        $repo = new AdvanceRepository($this->conn);

        try {
            if (isset($_POST['delete_id'])) {
                $repo->delete((int)$_POST['delete_id']);
                $_SESSION['success'] = "Anticipo eliminato.";
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $date     = $_POST['data']        ?? '';
                $workerId = (int)($_POST['operaio_id'] ?? 0);
                $amount   = (float)($_POST['importo']   ?? 0);
                $note     = trim($_POST['note']    ?? '');
                $id       = (int)($_POST['anticipo_id'] ?? 0);

                if ($date && $workerId && $amount > 0) {
                    if ($id > 0) {
                        $repo->update($id, $date, $workerId, $amount, $note);
                        $_SESSION['success'] = "Anticipo aggiornato.";
                    } else {
                        $repo->insert($date, $workerId, $amount, $note);
                        $_SESSION['success'] = "Anticipo aggiunto.";
                    }
                } else {
                    $_SESSION['error'] = "Dati non validi.";
                }
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = "Errore durante l'operazione.";
        }

        Response::redirect('/attendance/advances');
    }

    // ── Save fine (POST) ───────────────────────────────────────────────────────

    public function saveFine(Request $request): never
    {
        $repo = new FineRepository($this->conn);

        try {
            if (isset($_POST['delete_id'])) {
                $repo->delete((int)$_POST['delete_id']);
                $_SESSION['success'] = "Multa eliminata.";
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $date     = $_POST['data']      ?? '';
                $workerId = (int)($_POST['operaio_id'] ?? 0);
                $amount   = (float)($_POST['importo']  ?? 0);
                $targa    = trim($_POST['targa']  ?? '');
                $note     = trim($_POST['note']   ?? '');
                $id       = (int)($_POST['multa_id']   ?? 0);

                if ($date && $workerId && $amount > 0 && $targa) {
                    if ($id > 0) {
                        $repo->update($id, $date, $workerId, $amount, $targa, $note);
                        $_SESSION['success'] = "Multa aggiornata.";
                    } else {
                        $repo->insert($date, $workerId, $amount, $targa, $note);
                        $_SESSION['success'] = "Multa aggiunta.";
                    }
                } else {
                    $_SESSION['error'] = "Dati non validi.";
                }
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = "Errore durante l'operazione.";
        }

        Response::redirect('/attendance/fines');
    }

    // ── Save refund (POST) ─────────────────────────────────────────────────────

    public function saveRefund(Request $request): never
    {
        $repo = new RefundRepository($this->conn);

        try {
            if (isset($_POST['delete_id'])) {
                $repo->delete((int)$_POST['delete_id']);
                $_SESSION['success'] = "Rimborso eliminato.";
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $date     = $_POST['data']        ?? '';
                $workerId = (int)($_POST['operaio_id']  ?? 0);
                $amount   = (float)($_POST['importo']    ?? 0);
                $note     = trim($_POST['note']    ?? '');
                $id       = (int)($_POST['rimborso_id'] ?? 0);

                if ($date && $workerId && $amount > 0) {
                    if ($id > 0) {
                        $repo->update($id, $date, $workerId, $amount, $note);
                        $_SESSION['success'] = "Rimborso aggiornato.";
                    } else {
                        $repo->insert($date, $workerId, $amount, $note);
                        $_SESSION['success'] = "Rimborso aggiunto.";
                    }
                } else {
                    $_SESSION['error'] = "Dati non validi.";
                }
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = "Errore durante l'operazione.";
        }

        Response::redirect('/attendance/refunds');
    }

    // ── Helper (JSON autocomplete API) ────────────────────────────────────────

    public function helper(Request $request): never
    {
        $action = $request->get('action', '');
        $q      = trim($request->get('q', ''));

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        if ($action === 'workers') {
            $stmt = $this->conn->prepare(
                "SELECT id, CONCAT(last_name, ' ', first_name) AS name
                 FROM bb_workers
                 WHERE removed = 'N'
                   AND (
                       first_name LIKE :q1 OR last_name LIKE :q2
                       OR CONCAT_WS(' ', first_name, last_name) LIKE :q3
                       OR CONCAT_WS(' ', last_name, first_name) LIKE :q4
                   )
                 ORDER BY last_name ASC, first_name ASC
                 LIMIT 20"
            );
            $stmt->bindValue(':q1', $like);
            $stmt->bindValue(':q2', $like);
            $stmt->bindValue(':q3', $like);
            $stmt->bindValue(':q4', $like);
            $stmt->execute();
            Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        if ($action === 'companies') {
            $stmt = $this->conn->prepare(
                "SELECT id, name FROM bb_companies WHERE name LIKE :q ORDER BY name ASC LIMIT 20"
            );
            $stmt->bindValue(':q', $like);
            $stmt->execute();
            Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        if ($action === 'clients') {
            $stmt = $this->conn->prepare(
                "SELECT id, name FROM bb_clients WHERE name LIKE :q ORDER BY name ASC LIMIT 20"
            );
            $stmt->bindValue(':q', $like);
            $stmt->execute();
            Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        Response::json([]);
    }

    // ── Exports ────────────────────────────────────────────────────────────────

    public function exportWorker(Request $request): never
    {
        require APP_ROOT . '/views/attendance/export_excel_operaio.php';
        exit;
    }

    public function exportCompany(Request $request): never
    {
        require APP_ROOT . '/views/attendance/export_excel_azienda.php';
        exit;
    }

    public function exportClient(Request $request): never
    {
        require APP_ROOT . '/views/attendance/export_excel_committente.php';
        exit;
    }

    public function exportBulk(Request $request): never
    {
        require APP_ROOT . '/views/attendance/export_excel_bulk_operai.php';
        exit;
    }
}
