<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Infrastructure\SqlServerConnection;
use App\Repository\Billing\BillingRepository;

final class BillingController
{
    public function __construct(
        private \PDO $conn,
        private BillingRepository $billingRepo
    ) {}

    // ── Active Worksites (HTML page) ───────────────────────────────────────────

    public function activeWorksites(Request $request): void
    {
        Response::view('billing/index.html.twig', $request, []);
    }

    // ── Fetch moved worksites (JSON) ───────────────────────────────────────────

    public function fetch(Request $request): never
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        $year  = (int)($request->get('year',  date('Y')));
        $month = (int)($request->get('month', date('n')));

        $yardBilling = new YardWorksiteBilling(new SqlServerConnection(new Config()));

        $this->billingRepo->syncEmessaFromYardForMovedWorksites($companyId, $year, $month, $yardBilling);
        $rows = $this->billingRepo->getMovedWorksitesWithBilling($companyId, $year, $month);

        Response::json($rows);
    }

    // ── Export Excel ───────────────────────────────────────────────────────────

    public function export(Request $request): never
    {
        require APP_ROOT . '/views/billing/export_moved_worksites_excel.php';
        exit;
    }

    // ── Per-client billing: list all clients ──────────────────────────────────

    public function clientList(Request $request): void
    {
        $currentYear   = (int)date('Y');
        $clients       = $this->billingRepo->getClientsWithBillingSummary($currentYear);

        // KPI cards: current-year only
        $totDaEmettere = array_sum(array_column($clients, 'da_emettere_count_yr'));
        $totEmesse     = array_sum(array_column($clients, 'emesse_count_yr'));
        $totEuroDa     = array_sum(array_column($clients, 'da_emettere_euro_yr'));
        $totEuroEm     = array_sum(array_column($clients, 'emesse_euro_yr'));

        Response::view('billing/clients.html.twig', $request, compact(
            'clients', 'totDaEmettere', 'totEmesse', 'totEuroDa', 'totEuroEm', 'currentYear'
        ));
    }

    // ── Per-client billing: detail (da emettere + emesse paginated) ───────────

    public function clientDetail(Request $request): void
    {
        $clientId = $request->intParam('id');
        if (!$clientId) {
            Response::redirect('/billing/clients');
        }

        // Fetch client name
        $stmt = $this->conn->prepare("SELECT id, name FROM bb_clients WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $clientId]);
        $client = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$client) {
            Response::redirect('/billing/clients');
        }

        $yardBilling = new \App\Domain\YardWorksiteBilling(new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()));

        // Sync emessa from Yard for this client's rows that have yard_id
        $this->billingRepo->syncEmessaForClient($clientId, $yardBilling);

        $currentYear     = (int)date('Y');

        $daEmettere      = $this->billingRepo->getDaEmettereByClient($clientId);
        $totalDaEmettere = array_sum(array_column($daEmettere, 'totale_imponibile'));

        // First page of emesse (25 rows)
        $perPage         = 25;
        $emesse          = $this->billingRepo->getEmesseByClient($clientId, $perPage, 0);
        $totalEmesse     = $this->billingRepo->countEmesseByClient($clientId);
        $totalEmesseEuro = $this->billingRepo->getTotalEmesseEuroByClient($clientId);

        // Year-scoped card totals
        $yrTotals          = $this->billingRepo->getYearStatsByClient($clientId, $currentYear);
        $daEmettereCountYr = (int)($yrTotals['da_emettere_count_yr'] ?? 0);
        $daEmettereEuroYr  = (float)($yrTotals['da_emettere_euro_yr'] ?? 0);
        $emesseCountYr     = (int)($yrTotals['emesse_count_yr'] ?? 0);
        $emesseEuroYr      = (float)($yrTotals['emesse_euro_yr'] ?? 0);

        Response::view('billing/client_detail.html.twig', $request, compact(
            'client', 'daEmettere', 'totalDaEmettere',
            'emesse', 'totalEmesse', 'totalEmesseEuro', 'perPage',
            'currentYear', 'daEmettereCountYr', 'daEmettereEuroYr', 'emesseCountYr', 'emesseEuroYr'
        ));
    }

    // ── Per-client billing: export da-emettere Excel ─────────────────────────

    public function exportDaEmettere(Request $request): never
    {
        $clientId = $request->intParam('id');
        $conn     = $this->conn;
        require APP_ROOT . '/views/billing/export_da_emettere_excel.php';
        exit;
    }

    // ── Per-client billing: paginated emesse (JSON) ───────────────────────────

    public function clientEmesse(Request $request): never
    {
        $clientId = $request->intParam('id');
        $page     = max(1, (int)($request->get('page') ?? 1));
        $perPage  = 25;
        $offset   = ($page - 1) * $perPage;

        $rows  = $this->billingRepo->getEmesseByClient($clientId, $perPage, $offset);
        $total = $this->billingRepo->countEmesseByClient($clientId);

        Response::json([
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => ($offset + $perPage) < $total,
        ]);
    }
}
