<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Consorziate\ConsorziataFatturazioneRepository;

final class ConsorziataFatturazioneController
{
    public function __construct(
        private \PDO                               $conn,
        private ConsorziataFatturazioneRepository  $repo
    ) {}

    // ── GET /fatturazione/consorziate ─────────────────────────────────────────

    public function index(Request $request): void
    {
        $consorziate = $this->repo->listConsorziate();

        $totalConsorziate = count($consorziate);
        $totalPresenzeAll = array_sum(array_column($consorziate, 'totale_presenze'));
        $totalCostoAll    = array_sum(array_column($consorziate, 'totale_costo_presenze'));
        $totalPagatoAll   = array_sum(array_column($consorziate, 'totale_pagato'));

        Response::view('fatturazione/consorziate/index.html.twig', $request, compact(
            'consorziate',
            'totalConsorziate', 'totalPresenzeAll', 'totalCostoAll', 'totalPagatoAll'
        ));
    }

    // ── GET /fatturazione/consorziate/{id}?from=&to= ──────────────────────────

    public function show(Request $request): void
    {
        $id = $request->intParam('id');

        $consorziata = $this->repo->findConsorziata($id);
        if (!$consorziata) {
            Response::error('Consorziata non trovata.', 404);
        }

        // Default to current month if no dates supplied
        $from = $request->get('from') ?: date('Y-m-01');
        $to   = $request->get('to')   ?: date('Y-m-t');

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-t');  }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $rows     = $this->repo->getDetailRows($id, $from, $to);
        $payments = $this->repo->getPayments($id);

        // Pre-compute totals for Twig (avoids |sum(attribute=...) filter issues)
        $totalPresenze     = array_sum(array_column($rows, 'presenze_gg'));
        $totalCosto        = array_sum(array_column($rows, 'costo_presenze'));
        $totalOrdine       = array_sum(array_column($rows, 'valore_ordine'));
        $totalGiaPagato    = array_sum(array_column($rows, 'gia_pagato'));
        $totalSpese        = array_sum(array_column($rows, 'spese_consorziata'));
        $totalStorico      = array_sum(array_column($payments, 'importo'));

        $fromLabel = \DateTime::createFromFormat('Y-m-d', $from)?->format('d/m/Y') ?? $from;
        $toLabel   = \DateTime::createFromFormat('Y-m-d', $to)?->format('d/m/Y')   ?? $to;

        Response::view('fatturazione/consorziate/show.html.twig', $request, compact(
            'consorziata', 'from', 'to', 'fromLabel', 'toLabel',
            'rows', 'payments',
            'totalPresenze', 'totalCosto', 'totalOrdine', 'totalGiaPagato', 'totalSpese', 'totalStorico'
        ));
    }

    // ── GET /fatturazione/consorziate/{id}/export?from=&to= ──────────────────

    public function export(Request $request): never
    {
        $id = $request->intParam('id');

        $consorziata = $this->repo->findConsorziata($id);
        if (!$consorziata) {
            Response::error('Consorziata non trovata.', 404);
        }

        $from = $request->get('from') ?: date('Y-m-01');
        $to   = $request->get('to')   ?: date('Y-m-t');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-t');  }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $aziendaId = $id;
        $rows     = $this->repo->getDetailRows($id, $from, $to);
        $payments = $this->repo->getPayments($id);

        require APP_ROOT . '/views/fatturazione/export_consorziata_excel.php';
        exit;
    }

    // ── POST /fatturazione/consorziate/{id}/pay ───────────────────────────────

    public function storePayments(Request $request): never
    {
        $id = $request->intParam('id');

        $consorziata = $this->repo->findConsorziata($id);
        if (!$consorziata) {
            Response::error('Consorziata non trovata.', 404);
        }

        $auth      = $GLOBALS['authenticated_user'];
        $userId    = (int)($auth['user_id'] ?? 0);

        $from          = $_POST['from']           ?? date('Y-m-01');
        $to            = $_POST['to']             ?? date('Y-m-t');
        $dataPagamento = trim($_POST['data_pagamento'] ?? '');
        $nota          = trim($_POST['nota'] ?? '') ?: null;
        $worksiteIds   = $_POST['worksite_id'] ?? [];
        $importi       = $_POST['importo']     ?? [];

        if (!$dataPagamento || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPagamento)) {
            $_SESSION['error'] = 'Data pagamento non valida.';
            Response::redirect("/fatturazione/consorziate/{$id}?from={$from}&to={$to}");
        }

        $saved = 0;
        foreach ($worksiteIds as $idx => $worksiteId) {
            $worksiteId = (int)$worksiteId;
            $rawImporto = str_replace(['.', ','], ['', '.'], trim((string)($importi[$idx] ?? '')));
            $importo    = (float)$rawImporto;

            if ($worksiteId <= 0 || $importo <= 0) {
                continue;
            }

            $this->repo->insertPayment($id, $worksiteId, $importo, $dataPagamento, $nota, $userId);
            $saved++;
        }

        if ($saved === 0) {
            $_SESSION['error'] = 'Nessun importo valido inserito.';
        } else {
            $_SESSION['success'] = $saved === 1
                ? '1 pagamento registrato.'
                : "{$saved} pagamenti registrati.";
        }

        Response::redirect("/fatturazione/consorziate/{$id}?from={$from}&to={$to}");
    }

    // ── POST /fatturazione/consorziate/{id}/payment/{pid}/delete ─────────────

    public function deletePayment(Request $request): never
    {
        $id  = $request->intParam('id');
        $pid = $request->intParam('pid');

        $from = $_POST['from'] ?? date('Y-m-01');
        $to   = $_POST['to']   ?? date('Y-m-t');

        if ($this->repo->deletePayment($pid, $id)) {
            $_SESSION['success'] = 'Pagamento eliminato.';
        } else {
            $_SESSION['error'] = 'Pagamento non trovato.';
        }

        Response::redirect("/fatturazione/consorziate/{$id}?from={$from}&to={$to}");
    }
}
