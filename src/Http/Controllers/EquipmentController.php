<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Equipment\EquipmentRepository;

final class EquipmentController
{
    public function __construct(
        private \PDO $conn,
        private EquipmentRepository $equipmentRepo
    ) {}

    // ── Index — equipment list + inline add/edit/delete ────────────────────────

    public function index(Request $request): void
    {
        if ($request->isPost()) {
            $action = $_POST['action'] ?? '';

            if ($action === 'add') {
                $this->equipmentRepo->create(trim($_POST['descrizione']), (float)$_POST['costo_giornaliero']);
                Response::redirect('/equipment');
            }
            if ($action === 'edit' && isset($_POST['id'])) {
                $this->equipmentRepo->update((int)$_POST['id'], trim($_POST['descrizione']), (float)$_POST['costo_giornaliero']);
                Response::redirect('/equipment');
            }
            if ($action === 'delete' && isset($_POST['id'])) {
                $this->equipmentRepo->delete((int)$_POST['id']);
                $_SESSION['success'] = 'Mezzo eliminato con successo.';
                Response::redirect('/equipment');
            }
        }

        $listaMezzi = $this->equipmentRepo->getAll();
        Response::view('equipment/index.html.twig', $request, compact('listaMezzi'));
    }

    // ── Manage — full-page CRUD (add_lifting_equipment) ───────────────────────

    public function manage(Request $request): void
    {
        $successMessage = '';
        $editing        = false;
        $editData       = null;

        if ($request->isPost()) {
            if (isset($_POST['delete_item'])) {
                $this->equipmentRepo->delete((int)$_POST['delete_id']);
                $successMessage = '🗑 Mezzo eliminato correttamente.';
            } elseif (isset($_POST['update_item'])) {
                $this->equipmentRepo->update((int)$_POST['edit_id'], $_POST['descrizione'], (float)$_POST['costo_giornaliero']);
                $successMessage = '✏️ Mezzo aggiornato correttamente.';
            } elseif (isset($_POST['create_item'])) {
                $this->equipmentRepo->create($_POST['descrizione'], (float)$_POST['costo_giornaliero']);
                $successMessage = '✅ Mezzo inserito correttamente.';
            }
        }

        $search = $_GET['search'] ?? '';
        $all    = $this->equipmentRepo->getAll();
        $listaMezzi = $search !== ''
            ? array_values(array_filter($all, fn($m) => stripos($m['descrizione'], $search) !== false))
            : $all;

        if (isset($_GET['edit_id'])) {
            $editing = true;
            foreach ($all as $item) {
                if ($item['id'] == $_GET['edit_id']) {
                    $editData = $item;
                    break;
                }
            }
        }

        Response::view('equipment/create.html.twig', $request, compact('successMessage', 'editing', 'editData', 'listaMezzi'));
    }

    // ── Assign — assign equipment to a worksite ────────────────────────────────

    public function assign(Request $request): void
    {
        if ($request->isPost() && isset($_POST['save_all'])) {
            $worksiteId  = (int)$_POST['worksite_id'];
            $mezziIds    = $_POST['mezzo_id']       ?? [];
            $tipi        = $_POST['tipo_noleggio']  ?? [];
            $costi       = $_POST['costo']          ?? [];
            $quantita    = $_POST['quantita']       ?? [];
            $dataInizio  = $_POST['data_inizio']    ?? [];

            for ($i = 0; $i < count($mezziIds); $i++) {
                $this->equipmentRepo->assignToWorksite(
                    $worksiteId,
                    (int)$mezziIds[$i],
                    $tipi[$i]       ?? 'Giornaliero',
                    (float)($costi[$i] ?? 0),
                    (int)($quantita[$i] ?? 1),
                    $dataInizio[$i] ?? date('Y-m-d')
                );
            }

            $_SESSION['success'] = 'Attrezzatura assegnata con successo.';
            Response::redirect("/equipment/assign?worksite_id={$worksiteId}");
        }

        $mezzi            = $this->equipmentRepo->getAll();
        $selectedWorksite = $_GET['worksite_id'] ?? '';
        $selectedOption   = null;

        if ($selectedWorksite) {
            $stmt = $this->conn->prepare("SELECT id, worksite_code, name FROM bb_worksites WHERE id = :id");
            $stmt->execute([':id' => $selectedWorksite]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $selectedOption = [
                    'value' => $row['id'],
                    'text'  => $row['worksite_code'] . ' - ' . $row['name'],
                ];
            }
        }

        Response::view('equipment/assign.html.twig', $request, compact('mezzi', 'selectedWorksite', 'selectedOption'));
    }

    // ── Rentals — list all rentals ─────────────────────────────────────────────

    public function rentals(Request $request): void
    {
        $noleggi = $this->equipmentRepo->getAllRentals();
        Response::view('equipment/rental_list.html.twig', $request, compact('noleggi'));
    }

    // ── Edit rentals for one worksite ──────────────────────────────────────────

    public function editRentals(Request $request): void
    {
        $worksiteId = $request->intParam('worksite_id');

        if (!$worksiteId) {
            echo "<div class='p-5'>Parametri mancanti: worksite_id.</div>";
            include APP_ROOT . '/includes/template/footer.php';
            exit;
        }

        $stmt = $this->conn->prepare("SELECT worksite_code, name FROM bb_worksites WHERE id = :id");
        $stmt->execute([':id' => $worksiteId]);
        $worksite = $stmt->fetch(\PDO::FETCH_ASSOC);

        $message = '';

        if ($request->isPost() && isset($_POST['save_changes'])) {
            $userId = (int)($request->user()->id ?? 0);
            $this->equipmentRepo->updateMultipleRentals($_POST, $userId);
            $message = 'Modifiche salvate con successo!';
        }

        $mezzi = $this->equipmentRepo->getByWorksite($worksiteId);

        Response::view('equipment/edit.html.twig', $request, compact('worksiteId', 'worksite', 'message', 'mezzi'));
    }

    // ── Mark rentals complete ──────────────────────────────────────────────────

    public function markComplete(Request $request): void
    {
        $worksiteId = $request->intParam('worksite_id');

        if (!$worksiteId) {
            echo "<div class='p-5'>Parametri mancanti: worksite_id.</div>";
            include APP_ROOT . '/includes/template/footer.php';
            exit;
        }

        $message = '';

        if ($request->isPost() && isset($_POST['mark_completed'])) {
            $selected  = $_POST['selected']        ?? [];
            $ids       = $_POST['id']              ?? [];
            $qtaFinire = $_POST['quantita_finire'] ?? [];
            $dateFine  = $_POST['data_fine']       ?? [];

            foreach ($selected as $idx) {
                if (
                    !isset($ids[$idx]) ||
                    !isset($qtaFinire[$idx]) ||
                    !isset($dateFine[$idx]) ||
                    $dateFine[$idx] === ''
                ) continue;

                $id = (int)$ids[$idx];
                if (!$id) continue;

                $mezzo = $this->equipmentRepo->getRentalById($id);
                if (!$mezzo) continue;
                if (($mezzo['tipo_noleggio'] ?? '') === 'Una Tantum') continue;

                $qta      = (int)$qtaFinire[$idx];
                $dataFine = $dateFine[$idx];
                $qOrig    = (int)$mezzo['quantita'];

                if ($qta > 0 && $qta < $qOrig) {
                    $this->equipmentRepo->updateQuantity($id, $qOrig - $qta);
                    $this->equipmentRepo->createSplitRecord($mezzo, $qta, $dataFine);
                } else {
                    $this->equipmentRepo->markAsFinishedWithDate($id, $dataFine);
                }
            }

            $message = 'Operazione completata!';
        }

        $mezzi  = $this->equipmentRepo->getByWorksite($worksiteId);
        $attivi = array_values(array_filter($mezzi, fn($m) =>
            ($m['stato'] ?? '') === 'Attivo' && ($m['tipo_noleggio'] ?? '') === 'Giornaliero'
        ));

        Response::view('equipment/mark_completed.html.twig', $request, compact('worksiteId', 'message', 'mezzi', 'attivi'));
    }

    // ── Search worksites (JSON) ────────────────────────────────────────────────

    public function searchWorksites(Request $request): never
    {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 3) {
            Response::json([]);
        }

        $context = $_GET['context'] ?? '';

        $sql = "
            SELECT w.id,
                   CONCAT(w.worksite_code, ' - ', w.name,
                       CASE WHEN w.location IS NOT NULL AND w.location != ''
                            THEN CONCAT(' (', w.location, ')') ELSE '' END
                   ) AS label
            FROM bb_worksites w
            WHERE (w.name LIKE ? OR w.worksite_code LIKE ?)
        ";
        $params = ['%' . $q . '%', '%' . $q . '%'];

        if ($context === 'attendance') {
            $sql .= " AND w.status != 'Completato'";
        }
        $sql .= " ORDER BY w.created_at DESC LIMIT 50";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = ['value' => $row['id'], 'text' => $row['label']];
        }

        Response::json($results);
    }
}
