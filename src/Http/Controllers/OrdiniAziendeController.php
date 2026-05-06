<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\OrdiniAziende\OrdineAziendaRepository;

final class OrdiniAziendeController
{
    public function __construct(
        private \PDO                     $conn,
        private OrdineAziendaRepository  $repo
    ) {}

    // ── GET /ordini-aziende ───────────────────────────────────────────────────

    public function index(Request $request): void
    {
        $year      = (int)($request->get('year') ?: date('Y'));
        $aziendaId = (int)($request->get('azienda_id') ?? 0) ?: null;

        $ordini   = $this->repo->listOrdini($year, $aziendaId);
        $aziende  = $this->repo->listAziendeNonConsorziate();

        // Year list for the filter (last 5 years inclusive)
        $thisYear = (int)date('Y');
        $years    = range($thisYear, $thisYear - 5);

        Response::view('ordini_aziende/index.html.twig', $request, compact(
            'ordini', 'aziende', 'years', 'year', 'aziendaId'
        ));
    }

    // ── GET /ordini-aziende/create ────────────────────────────────────────────

    public function create(Request $request): void
    {
        $aziende = $this->repo->listAziendeNonConsorziate();

        // Default selection: query params, otherwise current month
        $aziendaId = (int)($request->get('azienda_id') ?? 0) ?: null;
        $anno      = (int)($request->get('anno')  ?: date('Y'));
        $mese      = (int)($request->get('mese')  ?: date('n'));

        $cantieri    = [];
        $descrizione = '';
        $aziendaName = '';
        if ($aziendaId) {
            // Find azienda name to feed the bb_presenze.azienda text match
            $stmt = $this->conn->prepare("SELECT name FROM bb_companies WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $aziendaId]);
            $aziendaName = (string)($stmt->fetchColumn() ?: '');
            if ($aziendaName !== '') {
                $cantieri    = $this->repo->getCantieriForAziendaMonth($aziendaName, $anno, $mese);
                $descrizione = $this->repo->buildDescrizione($cantieri, $anno, $mese);
            }
        }

        // For year selector
        $thisYear = (int)date('Y');
        $years    = range($thisYear, $thisYear - 5);

        // Pre-generate the next order number (informational; real one assigned on save)
        $orderNumberPreview = $this->repo->nextOrderNumber($anno);

        Response::view('ordini_aziende/create.html.twig', $request, compact(
            'aziende', 'aziendaId', 'aziendaName',
            'anno', 'mese', 'years',
            'cantieri', 'descrizione', 'orderNumberPreview'
        ));
    }

    // ── POST /ordini-aziende ──────────────────────────────────────────────────

    public function store(Request $request): never
    {
        $auth   = $GLOBALS['authenticated_user'] ?? [];
        $userId = (int)($auth['user_id'] ?? 0);

        $aziendaId = (int)($_POST['azienda_id'] ?? 0);
        $anno      = (int)($_POST['anno'] ?? 0);
        $mese      = (int)($_POST['mese'] ?? 0);
        $orderDate = trim((string)($_POST['order_date'] ?? ''));
        $rawTotal  = trim((string)($_POST['total'] ?? '0'));
        $descr     = (string)($_POST['descrizione'] ?? '');
        $note      = trim((string)($_POST['note'] ?? '')) ?: null;

        if ($aziendaId <= 0 || $anno < 2000 || $mese < 1 || $mese > 12) {
            $_SESSION['error'] = 'Azienda, anno e mese sono obbligatori.';
            Response::redirect('/ordini-aziende/create');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            $_SESSION['error'] = 'Data ordine non valida.';
            Response::redirect('/ordini-aziende/create');
        }

        // Italian-decimal cleanup: "1.234,56" -> 1234.56
        $total = (float)str_replace(['.', ','], ['', '.'], $rawTotal);

        $orderNumber = $this->repo->nextOrderNumber($anno);

        $newId = $this->repo->insert([
            'azienda_id'   => $aziendaId,
            'anno'         => $anno,
            'mese'         => $mese,
            'order_number' => $orderNumber,
            'order_date'   => $orderDate,
            'total'        => $total,
            'descrizione'  => $descr,
            'note'         => $note,
            'created_by'   => $userId,
        ]);

        $_SESSION['success'] = "Ordine {$orderNumber} creato.";
        Response::redirect('/ordini-aziende/' . $newId);
    }

    // ── GET /ordini-aziende/{id} ──────────────────────────────────────────────

    public function show(Request $request): void
    {
        $id = $request->intParam('id');
        $ordine = $this->repo->findById($id);
        if (!$ordine) {
            Response::redirect('/ordini-aziende');
        }
        Response::view('ordini_aziende/show.html.twig', $request, compact('ordine'));
    }

    // ── GET /ordini-aziende/{id}/edit ─────────────────────────────────────────

    public function edit(Request $request): void
    {
        $id = $request->intParam('id');
        $ordine = $this->repo->findById($id);
        if (!$ordine) {
            Response::redirect('/ordini-aziende');
        }

        $aziende = $this->repo->listAziendeNonConsorziate();
        $thisYear = (int)date('Y');
        $years    = range($thisYear, $thisYear - 5);

        // Refresh descrizione preview (cantieri may have changed since last save)
        $cantieri    = $this->repo->getCantieriForAziendaMonth(
            (string)$ordine['azienda_name'],
            (int)$ordine['anno'],
            (int)$ordine['mese']
        );
        $descrizionePreview = $this->repo->buildDescrizione(
            $cantieri,
            (int)$ordine['anno'],
            (int)$ordine['mese']
        );

        Response::view('ordini_aziende/edit.html.twig', $request, compact(
            'ordine', 'aziende', 'years', 'cantieri', 'descrizionePreview'
        ));
    }

    // ── POST /ordini-aziende/{id}/update ──────────────────────────────────────

    public function update(Request $request): never
    {
        $id = $request->intParam('id');
        $ordine = $this->repo->findById($id);
        if (!$ordine) {
            Response::redirect('/ordini-aziende');
        }

        $aziendaId = (int)($_POST['azienda_id'] ?? 0);
        $anno      = (int)($_POST['anno'] ?? 0);
        $mese      = (int)($_POST['mese'] ?? 0);
        $orderDate = trim((string)($_POST['order_date'] ?? ''));
        $rawTotal  = trim((string)($_POST['total'] ?? '0'));
        $descr     = (string)($_POST['descrizione'] ?? '');
        $note      = trim((string)($_POST['note'] ?? '')) ?: null;

        if ($aziendaId <= 0 || $anno < 2000 || $mese < 1 || $mese > 12) {
            $_SESSION['error'] = 'Azienda, anno e mese sono obbligatori.';
            Response::redirect('/ordini-aziende/' . $id . '/edit');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            $_SESSION['error'] = 'Data ordine non valida.';
            Response::redirect('/ordini-aziende/' . $id . '/edit');
        }

        $total = (float)str_replace(['.', ','], ['', '.'], $rawTotal);

        $this->repo->update($id, [
            'azienda_id'  => $aziendaId,
            'anno'        => $anno,
            'mese'        => $mese,
            'order_date'  => $orderDate,
            'total'       => $total,
            'descrizione' => $descr,
            'note'        => $note,
        ]);

        $_SESSION['success'] = 'Ordine aggiornato.';
        Response::redirect('/ordini-aziende/' . $id);
    }

    // ── POST /ordini-aziende/{id}/delete ──────────────────────────────────────

    public function destroy(Request $request): never
    {
        $id = $request->intParam('id');
        if ($this->repo->delete($id)) {
            $_SESSION['success'] = 'Ordine eliminato.';
        } else {
            $_SESSION['error'] = 'Ordine non trovato.';
        }
        Response::redirect('/ordini-aziende');
    }
}
