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

        // "Emesse reale" pulled from Yard (SQL Server) for the current and
        // previous month. Defensive try/catch — if Yard is unreachable we
        // still want the page to render, just with zeros.
        $monthLabels = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
        $thisYear    = (int)date('Y');
        $thisMonth   = (int)date('n');
        $prevYear    = $thisMonth === 1 ? $thisYear - 1 : $thisYear;
        $prevMonth   = $thisMonth === 1 ? 12            : $thisMonth - 1;

        $emessRealCur     = ['count' => 0, 'imponibile' => 0.0];
        $emessRealPrev    = ['count' => 0, 'imponibile' => 0.0];
        $emessRealCurRows  = [];
        $emessRealPrevRows = [];
        try {
            $yardBilling       = new \App\Domain\YardWorksiteBilling(new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()));
            $emessRealCur      = $yardBilling->getEmesseTotalsForMonth($thisYear, $thisMonth);
            $emessRealPrev     = $yardBilling->getEmesseTotalsForMonth($prevYear, $prevMonth);
            $emessRealCurRows  = $yardBilling->getEmesseRowsForMonth($thisYear, $thisMonth);
            $emessRealPrevRows = $yardBilling->getEmesseRowsForMonth($prevYear, $prevMonth);
        } catch (\Throwable $e) {
            error_log('[BillingController::clientList] Yard unreachable: ' . $e->getMessage());
        }

        // Group brogliaccio line items into fatture by (tm_anno, tm_numdoc),
        // sort the resulting fatture by primary cliente (A-Z), then by
        // numero. Rows without tm_numdoc collapse under a single "senza
        // numero" sentinel group so they're still visible.
        $groupFn = function (array $rows): array {
            $groups = [];
            foreach ($rows as $r) {
                $anno  = (int)($r['tm_anno']   ?? 0);
                $num   = (int)($r['tm_numdoc'] ?? 0);
                $key   = ($anno > 0 && $num > 0) ? ($anno . '-' . $num) : 'senza-numero';

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'key'                => $key,
                        'tm_anno'            => $anno,
                        'tm_numdoc'          => $num,
                        'numero_label'       => ($anno > 0 && $num > 0) ? ($num . '/' . $anno) : 'Senza numero',
                        'data'               => $r['data'] ?? null,   // will become MAX
                        'clienti'            => [],                    // distinct, preserves first-seen order
                        'cliente_principale' => (string)($r['nome_cliente'] ?? ''),
                        'totale'             => 0.0,
                        'rows'               => [],
                    ];
                }
                $g =& $groups[$key];

                // MAX(data)
                if (!empty($r['data']) && (empty($g['data']) || $r['data'] > $g['data'])) {
                    $g['data'] = $r['data'];
                }

                $cliente = (string)($r['nome_cliente'] ?? '');
                if ($cliente !== '' && !in_array($cliente, $g['clienti'], true)) {
                    $g['clienti'][] = $cliente;
                }

                $g['totale'] += (float)($r['totale_imponibile'] ?? 0);
                $g['rows'][]  = $r;
                unset($g);
            }

            // Sort: primary cliente A-Z, then by numero ascending
            usort($groups, function ($a, $b) {
                $c = strcasecmp($a['cliente_principale'] ?: 'zzz', $b['cliente_principale'] ?: 'zzz');
                if ($c !== 0) return $c;
                if ($a['tm_anno'] !== $b['tm_anno']) return $a['tm_anno'] <=> $b['tm_anno'];
                return $a['tm_numdoc'] <=> $b['tm_numdoc'];
            });

            return $groups;
        };

        $emessRealCurFatture  = $groupFn($emessRealCurRows);
        $emessRealPrevFatture = $groupFn($emessRealPrevRows);

        // Authoritative footer totals computed in PHP — independent of the
        // Yard aggregate query above. If they disagree, something's off.
        $emessRealCurRowsTotal  = 0.0;
        foreach ($emessRealCurRows as $r) {
            $emessRealCurRowsTotal += (float)($r['totale_imponibile'] ?? 0);
        }
        $emessRealPrevRowsTotal = 0.0;
        foreach ($emessRealPrevRows as $r) {
            $emessRealPrevRowsTotal += (float)($r['totale_imponibile'] ?? 0);
        }

        $emessRealCurLabel  = $monthLabels[$thisMonth - 1] . ' ' . $thisYear;
        $emessRealPrevLabel = $monthLabels[$prevMonth - 1] . ' ' . $prevYear;

        Response::view('billing/clients.html.twig', $request, compact(
            'clients', 'totDaEmettere', 'totEmesse', 'totEuroDa', 'totEuroEm', 'currentYear',
            'emessRealCur', 'emessRealPrev', 'emessRealCurLabel', 'emessRealPrevLabel',
            'emessRealCurRows', 'emessRealPrevRows',
            'emessRealCurRowsTotal', 'emessRealPrevRowsTotal',
            'emessRealCurFatture', 'emessRealPrevFatture'
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
