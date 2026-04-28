<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Service\Tickets\MealTicketService;
use App\View\TwigRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

final class TicketsController
{
    private MealTicketService $svc;

    public function __construct(private \PDO $conn)
    {
        $this->svc = new MealTicketService($conn);
    }

    // ── GET /tickets ──────────────────────────────────────────────────────────

    public function index(Request $request): never
    {
        $curMonth  = (int)date('m');
        $curYear   = (int)date('Y');
        $prevMonth = $curMonth - 1;
        $prevYear  = $curYear;
        if ($prevMonth === 0) { $prevMonth = 12; $prevYear--; }

        $pastiCurr = $this->svc->countPrintedByMonth($curMonth, $curYear);
        $pastiPrev = $this->svc->countPrintedByMonth($prevMonth, $prevYear);

        $filters = ['printed' => '1'];
        if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];
        $tickets = $this->svc->getAll($filters);

        $pageTitle = 'Bigliettini Pasto';

        Response::view('tickets/index.html.twig', $request, compact(
            'tickets', 'pastiCurr', 'pastiPrev', 'pageTitle'
        ));
    }

    // ── POST /tickets/add ─────────────────────────────────────────────────────

    public function add(Request $request): never
    {
        header('Content-Type: application/json');

        $workerNames = $_POST['worker_names'] ?? [];
        if (empty($workerNames) && !empty($_POST['worker_name'])) {
            $workerNames = [$_POST['worker_name']];
        }
        $workerNames = array_values(array_filter(array_map('trim', (array)$workerNames)));
        $ticketDate  = trim($_POST['ticket_date'] ?? '');

        if (empty($workerNames) || empty($ticketDate)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Seleziona almeno un operaio e una data.']);
            exit;
        }

        $results = [];
        $errors  = [];

        foreach ($workerNames as $name) {
            try {
                $results[] = $this->svc->findOrCreateAndPrint($name, $ticketDate, 0);
            } catch (\RuntimeException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        $ids = array_column($results, 'id');

        echo json_encode([
            'ok'      => !empty($ids),
            'tickets' => $results,
            'ids'     => $ids,
            'errors'  => $errors,
        ]);
        exit;
    }

    // ── POST /tickets/update ──────────────────────────────────────────────────

    public function update(Request $request): never
    {
        header('Content-Type: application/json');

        $id         = (int)($_POST['id'] ?? 0);
        $workerName = trim($_POST['worker_name'] ?? '');
        $ticketDate = trim($_POST['ticket_date'] ?? '');

        if (!$id || !$workerName || !$ticketDate) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Dati mancanti.']);
            exit;
        }

        try {
            $this->svc->update($id, $workerName, $ticketDate);
            echo json_encode(['ok' => true]);
        } catch (\RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── POST /tickets/delete ──────────────────────────────────────────────────

    public function delete(Request $request): never
    {
        header('Content-Type: application/json');

        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ID mancante.']);
            exit;
        }

        try {
            $this->svc->delete($id);
            echo json_encode(['ok' => true]);
        } catch (\RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── GET /tickets/fetch-workers ────────────────────────────────────────────

    public function fetchWorkers(Request $request): never
    {
        header('Content-Type: application/json');

        $query = trim($_GET['q'] ?? '');

        if ($query === '') {
            echo json_encode([]);
            exit;
        }

        $workers = $this->svc->searchWorkers($this->conn, $query);
        echo json_encode($workers);
        exit;
    }

    // ── GET /tickets/print ────────────────────────────────────────────────────

    public function printTicket(Request $request): never
    {
        $ticketIds = [];

        if (!empty($_GET['ids'])) {
            $ticketIds = array_values(array_filter(array_map('intval', explode(',', $_GET['ids']))));
        } elseif (!empty($_GET['id'])) {
            $ticketIds = [(int)$_GET['id']];
        }

        if (empty($ticketIds)) {
            Response::error('Nessun bigliettino specificato.', 400);
        }

        $pages = [];
        foreach ($ticketIds as $tid) {
            $ticket = $this->svc->getById($tid);
            if (!$ticket) continue;

            if (empty($ticket['hash'])) {
                $printData = $this->svc->markPrinted($tid);
                $ticket['hash']        = $printData['hash'];
                $ticket['progressivo'] = $printData['progressivo'];
            }

            $pages[] = $ticket;
        }

        if (empty($pages)) {
            Response::error('Nessun bigliettino trovato.', 404);
        }

        $renderer = new TwigRenderer(null);
        $html     = $renderer->render('tickets/print.html.twig', ['pages' => $pages]);

        $options = new Options();
        $options->set('defaultFont', 'Courier');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, 226.77, 283.46]); // 80mm × 100mm
        $dompdf->loadHtml($html);
        $dompdf->render();

        if (ob_get_level()) ob_end_clean();

        $filename = count($pages) > 1 ? 'Bigliettini_' . date('Ymd') : 'Bigliettino';
        $dompdf->stream("{$filename}.pdf", ['Attachment' => 0]);
        exit;
    }

    // ── GET /tickets/report ───────────────────────────────────────────────────

    public function report(Request $request): never
    {
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate   = trim($_GET['end_date']   ?? '');

        if (!$startDate || !$endDate) {
            Response::error('Date mancanti.', 400);
        }

        $reportData  = $this->svc->getReportByDateRange($startDate, $endDate);
        $totalTickets = array_sum(array_column($reportData, 'total_tickets'));

        $fromFmt = date('d/m/Y', strtotime($startDate));
        $toFmt   = date('d/m/Y', strtotime($endDate));

        $renderer = new TwigRenderer(null);
        $html     = $renderer->render('tickets/report.html.twig', [
            'report'       => $reportData,
            'fromFmt'      => $fromFmt,
            'toFmt'        => $toFmt,
            'totalTickets' => $totalTickets,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        if (ob_get_level()) ob_end_clean();

        $filename = 'Report_Bigliettini_' . date('Ymd');
        $dompdf->stream("{$filename}.pdf", ['Attachment' => 0]);
        exit;
    }
}
