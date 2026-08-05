<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Infrastructure\SqlServerConnection;
use App\Repository\Billing\BillingRepository;
use App\Repository\Billing\BillingDraftRepository;
use App\Repository\Worksites\WorksiteFinanceNotesRepository;
use App\Service\Billing\BillingDraftService;

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

        // ── Prospetto fatto ────────────────────────────────────────────────
        // Suggested period: if today is within day 1–10 of a month, we're
        // probably still catching up on the previous month's prospetto,
        // so suggest THAT. From day 11 onwards, suggest the current month.
        $today    = new \DateTime();
        $todayDay = (int)$today->format('j');
        if ($todayDay <= 10) {
            $suggested = (clone $today)->modify('first day of last month');
        } else {
            $suggested = (clone $today)->modify('first day of this month');
        }
        $suggestedYear  = (int)$suggested->format('Y');
        $suggestedMonth = (int)$suggested->format('n');

        // Map of clients that already have the prospetto marked done for
        // the suggested period.
        $prospettiDone = $this->billingRepo->getProspettiDoneForPeriod(
            $suggestedYear, $suggestedMonth
        );

        // Map of LAST done per client (any period) — for the
        // "Ultimo: <Mese Anno>" indicator.
        $lastProspettoDone = $this->billingRepo->getLastProspettoDonePerClient();

        // Split into two groups and sort each A→Z (the SQL already orders
        // by name, we just need the partition).
        $clientsConImporto = [];
        $clientsSenzaImporto = [];
        foreach ($clients as $c) {
            if ((float)($c['da_emettere_euro'] ?? 0) > 0) {
                $clientsConImporto[] = $c;
            } else {
                $clientsSenzaImporto[] = $c;
            }
        }

        // KPI cards: current-year only
        $totDaEmettere = array_sum(array_column($clients, 'da_emettere_count_yr'));
        $totEmesse     = array_sum(array_column($clients, 'emesse_count_yr'));
        $totEuroDa     = array_sum(array_column($clients, 'da_emettere_euro_yr'));
        $totEuroEm     = array_sum(array_column($clients, 'emesse_euro_yr'));

        // "Emesse reale" cards always show current + previous month from Yard
        // (SQL Server). The picker triggers an AJAX fetch (see
        // emesseMonthFragment below) — no page reload, no ?month query param
        // needed on this action.
        $monthLabels = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

        $thisYear   = (int)date('Y');
        $thisMonth  = (int)date('n');
        $prevYear   = $thisMonth === 1 ? $thisYear - 1 : $thisYear;
        $prevMonth  = $thisMonth === 1 ? 12            : $thisMonth - 1;

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

        // Raggruppa le righe di brogliaccio in fatture — logica condivisa con
        // il fragment del mese e con l'export Excel (vedi groupEmesseRows).
        $emessRealCurFatture    = self::groupEmesseRows($emessRealCurRows);
        $emessRealPrevFatture   = self::groupEmesseRows($emessRealPrevRows);

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
        // Default value for the picker input (today)
        $defaultPickerValue = sprintf('%04d-%02d', $thisYear, $thisMonth);

        Response::view('billing/clients.html.twig', $request, compact(
            'clients', 'totDaEmettere', 'totEmesse', 'totEuroDa', 'totEuroEm', 'currentYear',
            'emessRealCur', 'emessRealPrev', 'emessRealCurLabel', 'emessRealPrevLabel',
            'emessRealCurRows', 'emessRealPrevRows',
            'emessRealCurRowsTotal', 'emessRealPrevRowsTotal',
            'emessRealCurFatture', 'emessRealPrevFatture',
            'defaultPickerValue',
            // Prospetto-fatto feature
            'clientsConImporto', 'clientsSenzaImporto',
            'prospettiDone', 'lastProspettoDone',
            'suggestedYear', 'suggestedMonth'
        ));
    }

    /**
     * POST /billing/client/{id}/mark-prospetto-done
     * JSON: { year, month, note? }
     */
    public function markProspettoDone(Request $request): never
    {
        $clientId = $request->intParam('id');
        if (!$clientId) Response::json(['ok' => false, 'error' => 'Cliente mancante.'], 400);

        $payload = $this->readJsonBody();
        $year    = (int)($payload['year']  ?? 0);
        $month   = (int)($payload['month'] ?? 0);
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            Response::json(['ok' => false, 'error' => 'Periodo non valido.'], 422);
        }
        $userId = (int)($request->user()->id ?? 0);

        try {
            $this->billingRepo->markProspettoDone($clientId, $year, $month, $userId);
            Response::json(['ok' => true, 'client_id' => $clientId, 'year' => $year, 'month' => $month]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /billing/client/{id}/unmark-prospetto-done
     * JSON: { year, month }
     */
    public function unmarkProspettoDone(Request $request): never
    {
        $clientId = $request->intParam('id');
        if (!$clientId) Response::json(['ok' => false, 'error' => 'Cliente mancante.'], 400);

        $payload = $this->readJsonBody();
        $year    = (int)($payload['year']  ?? 0);
        $month   = (int)($payload['month'] ?? 0);
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            Response::json(['ok' => false, 'error' => 'Periodo non valido.'], 422);
        }

        try {
            $this->billingRepo->unmarkProspettoDone($clientId, $year, $month);
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── GET /billing/clients/emesse-month?month=YYYY-MM (HTML fragment) ───────

    public function emesseMonthFragment(Request $request): never
    {
        $monthLabels = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

        $raw = (string)($request->get('month') ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)
            || (int)$m[2] < 1 || (int)$m[2] > 12
            || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            Response::error('Mese non valido', 400);
        }
        $year  = (int)$m[1];
        $month = (int)$m[2];

        $rows = [];
        try {
            $yardBilling = new \App\Domain\YardWorksiteBilling(new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()));
            $rows = $yardBilling->getEmesseRowsForMonth($year, $month);
        } catch (\Throwable $e) {
            error_log('[BillingController::emesseMonthFragment] Yard unreachable: ' . $e->getMessage());
        }

        $groups = self::groupEmesseRows($rows);

        $total = 0.0;
        foreach ($rows as $r) { $total += (float)($r['totale_imponibile'] ?? 0); }

        $label = $monthLabels[$month - 1] . ' ' . $year;

        Response::view('billing/_emesse_month_fragment.html.twig', $request, [
            'fatture' => $groups,
            'total'   => $total,
            'label'   => $label,
            'month'   => sprintf('%04d-%02d', $year, $month),
        ]);
    }

    /**
     * Raggruppa le righe di brogliaccio Yard in fatture per (tm_anno,
     * tm_numdoc). Le righe senza numero documento finiscono in un unico
     * gruppo "senza numero" cosi' restano comunque visibili.
     *
     * Usata dalla pagina clienti, dal fragment del mese e dall'export Excel.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function groupEmesseRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $anno = (int)($r['tm_anno'] ?? 0);
            $num  = (int)($r['tm_numdoc'] ?? 0);
            $key  = ($anno > 0 && $num > 0) ? ($anno . '-' . $num) : 'senza-numero';
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tm_anno'            => $anno,
                    'tm_numdoc'          => $num,
                    'numero_label'       => ($anno > 0 && $num > 0) ? ($num . '/' . $anno) : 'Senza numero',
                    'data'               => $r['data'] ?? null,
                    'clienti'            => [],
                    'cliente_principale' => (string)($r['nome_cliente'] ?? ''),
                    'totale'             => 0.0,
                    'rows'               => [],
                ];
            }
            $g =& $groups[$key];
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
        usort($groups, function ($a, $b) {
            $c = strcasecmp($a['cliente_principale'] ?: 'zzz', $b['cliente_principale'] ?: 'zzz');
            if ($c !== 0) return $c;
            if ($a['tm_anno'] !== $b['tm_anno']) return $a['tm_anno'] <=> $b['tm_anno'];
            return $a['tm_numdoc'] <=> $b['tm_numdoc'];
        });
        return $groups;
    }

    // ── Export Excel del dettaglio mese (fatture emesse da Yard) ─────────────

    public function exportEmesseMonth(Request $request): never
    {
        $raw = (string)($request->get('month') ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)
            || (int)$m[2] < 1 || (int)$m[2] > 12
            || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            Response::error('Mese non valido', 400);
        }
        $year  = (int)$m[1];
        $month = (int)$m[2];

        try {
            $yardBilling = new \App\Domain\YardWorksiteBilling(new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()));
            $rows = $yardBilling->getEmesseRowsForMonth($year, $month);
        } catch (\Throwable $e) {
            error_log('[BillingController::exportEmesseMonth] Yard unreachable: ' . $e->getMessage());
            Response::error('Yard non raggiungibile: impossibile generare l\'export.', 503);
        }

        $fatture     = self::groupEmesseRows($rows);
        $monthLabels = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                        'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        $label = $monthLabels[$month - 1] . ' ' . $year;

        require APP_ROOT . '/views/billing/export_emesse_month_excel.php';
        exit;
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

        // Active draft (Fatturazione editable workflow — Phase 1)
        $activeDraft = $this->draftService()->getActiveDraft($clientId);

        Response::view('billing/client_detail.html.twig', $request, compact(
            'client', 'daEmettere', 'totalDaEmettere',
            'emesse', 'totalEmesse', 'totalEmesseEuro', 'perPage',
            'currentYear', 'daEmettereCountYr', 'daEmettereEuroYr', 'emesseCountYr', 'emesseEuroYr',
            'activeDraft'
        ));
    }

    // ── Fatturazione draft (editable invoice draft) ──────────────────────────

    private function draftService(): BillingDraftService
    {
        return new BillingDraftService(
            $this->conn,
            new BillingDraftRepository($this->conn),
            $this->billingRepo,
        );
    }

    /**
     * POST /billing/client/{id}/draft — create a new draft snapshotting all
     * emessa=0 rows for this client. Redirects to the draft view on success.
     */
    public function createDraft(Request $request): never
    {
        $user     = $request->user();
        $clientId = $request->intParam('id');
        if (!$clientId) {
            Response::error('Cliente non specificato.', 400);
        }

        $periodLabel = trim((string)($_POST['period_label'] ?? '')) ?: null;

        try {
            $draftId = $this->draftService()->createDraftForClient(
                $clientId,
                $periodLabel,
                (int)$user->id
            );
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }

        Response::redirect('/billing/client/' . $clientId . '/draft/' . $draftId);
    }

    /**
     * GET /billing/client/{clientId}/draft/{draftId} — read-only view of the
     * draft. Phase 2 will replace this with the editable grid.
     */
    public function showDraft(Request $request): void
    {
        $clientId = $request->intParam('clientId');
        $draftId  = $request->intParam('draftId');
        if (!$clientId || !$draftId) {
            Response::redirect('/billing/clients');
        }

        $stmt = $this->conn->prepare('SELECT id, name FROM bb_clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $clientId]);
        $client = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$client) {
            Response::redirect('/billing/clients');
        }

        try {
            $view = $this->draftService()->getDraftView($draftId);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 404);
        }

        // Sanity check: draft belongs to this client
        if ((int)$view['draft']['client_id'] !== $clientId) {
            Response::redirect('/billing/client/' . $clientId);
        }

        // Open finance notes for all cantieri of this client, restricted
        // to billing-relevant tipi → banner on top of the bozza editor.
        $clientNotes = (new WorksiteFinanceNotesRepository($this->conn))
            ->getOpenForClient($clientId, WorksiteFinanceNotesRepository::TIPI_BILLING);

        Response::view('billing/client_draft.html.twig', $request, [
            'client'        => $client,
            'draft'         => $view['draft'],
            'lines'         => $view['lines'],
            'totals'        => $view['totals'],
            'newRowsCount'  => $view['new_rows_count'],
            'yardSummary'   => $view['yard_summary'] ?? null,
            'vatCodes'      => $view['vat_codes'] ?? [],
            'clientNotes'   => $clientNotes,
        ]);
    }

    /**
     * POST /billing/client/{clientId}/draft/{draftId}/finalize
     * No body required — confirmation only. Applies the draft's edits to
     * bb_billing (BOB) and CNT_cantieri_brogliacci (Yard), marks the draft
     * as fatturata (internal label for "applicata"). The real fattura is
     * generated downstream by accounting on Yard.
     */
    public function finalizeDraft(Request $request): never
    {
        $clientId = $request->intParam('clientId');
        $draftId  = $request->intParam('draftId');
        if (!$clientId || !$draftId) {
            Response::json(['ok' => false, 'error' => 'Parametri mancanti.'], 400);
        }

        try {
            $result = $this->draftService()->commitInvoice($draftId);
            Response::json(['ok' => true] + $result);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /billing/client/{clientId}/draft/{draftId}/retry-yard-sync
     * Re-attempts Yard sync for any failed lines on a fatturata draft.
     */
    public function retryYardSync(Request $request): never
    {
        $clientId = $request->intParam('clientId');
        $draftId  = $request->intParam('draftId');
        if (!$clientId || !$draftId) {
            Response::json(['ok' => false, 'error' => 'Parametri mancanti.'], 400);
        }
        try {
            $result = $this->draftService()->retryYardSync($draftId);
            Response::json(['ok' => true, 'yard' => $result]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /billing/client/{clientId}/draft/{draftId}/transition
     * JSON body: { to: 'inviata_cliente'|'da_modificare'|'approvata'|'annullata' }
     * Returns: { ok, draft } or { ok:false, error }
     */
    public function transitionDraft(Request $request): never
    {
        $clientId = $request->intParam('clientId');
        $draftId  = $request->intParam('draftId');
        if (!$clientId || !$draftId) {
            Response::json(['ok' => false, 'error' => 'Parametri mancanti.'], 400);
        }

        $payload = $this->readJsonBody();
        $to      = trim((string)($payload['to'] ?? ''));
        if ($to === '') {
            Response::json(['ok' => false, 'error' => 'Stato di destinazione mancante.'], 400);
        }

        try {
            $draft = $this->draftService()->transitionDraft($draftId, $to);
            Response::json(['ok' => true, 'draft' => $draft]);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /billing/client/{clientId}/draft/{draftId}/export — Excel download
     * of the editable draft (only non-excluded rows).
     */
    public function exportDraftExcel(Request $request): never
    {
        $clientId = $request->intParam('clientId');
        $draftId  = $request->intParam('draftId');
        if (!$clientId || !$draftId) {
            Response::error('Parametri mancanti.', 400);
        }
        $conn = $this->conn;
        require APP_ROOT . '/views/billing/export_draft_excel.php';
        exit;
    }

    /**
     * POST /billing/draft-lines/{id}/update — inline-edit a single field.
     * JSON body: { field: "data"|"descrizione"|"totale_imponibile"|"aliquota_iva",
     *              value: <string|number> }
     * Returns JSON: { ok, line, totals, modified } or { ok:false, error }.
     */
    public function updateDraftLine(Request $request): never
    {
        $lineId = $request->intParam('id');
        if (!$lineId) {
            Response::json(['ok' => false, 'error' => 'Riga non specificata.'], 400);
        }

        $payload = $this->readJsonBody();
        $field   = (string)($payload['field'] ?? '');
        if ($field === '' || !array_key_exists('value', $payload)) {
            Response::json(['ok' => false, 'error' => 'Parametri mancanti.'], 400);
        }

        try {
            $result = $this->draftService()->updateLineField($lineId, $field, $payload['value']);
            Response::json([
                'ok'       => true,
                'line'     => $result['line'],
                'totals'   => $result['totals'],
                'modified' => $result['modified'],
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /billing/draft-lines/{id}/exclude — toggle the excluded flag.
     * JSON body: { excluded: bool, reason?: string }
     */
    public function toggleDraftLineExcluded(Request $request): never
    {
        $lineId = $request->intParam('id');
        if (!$lineId) {
            Response::json(['ok' => false, 'error' => 'Riga non specificata.'], 400);
        }

        $payload  = $this->readJsonBody();
        $excluded = (bool)($payload['excluded'] ?? false);
        $reason   = isset($payload['reason']) ? trim((string)$payload['reason']) : null;
        if ($reason === '') $reason = null;

        try {
            $result = $this->draftService()->setLineExcluded($lineId, $excluded, $reason);
            Response::json([
                'ok'     => true,
                'line'   => $result['line'],
                'totals' => $result['totals'],
            ]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * POST /billing/client/{clientId}/draft/{draftId}/cancel — cancel a draft.
     */
    public function cancelDraft(Request $request): never
    {
        $clientId = $request->intParam('clientId');
        $draftId  = $request->intParam('draftId');
        if (!$clientId || !$draftId) {
            Response::error('Parametri mancanti.', 400);
        }

        try {
            $this->draftService()->cancelDraft($draftId);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }

        Response::redirect('/billing/client/' . $clientId);
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
