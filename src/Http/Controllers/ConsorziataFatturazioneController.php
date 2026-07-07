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

        // Orders + bb_billing righe per cantiere, used by the row-per-ordine
        // layout and the "Nostra fattura" expanded list.
        $worksiteIds        = array_map('intval', array_column($rows, 'worksite_id'));
        $ordiniByWorksite   = $this->repo->getOrdiniByWorksite($id, $worksiteIds, $to);
        $righeByWorksite    = $this->repo->getRigheFattureByWorksite($worksiteIds, $from, $to);

        // Pre-compute totals for Twig (avoids |sum(attribute=...) filter issues)
        $totalPresenze       = array_sum(array_column($rows, 'presenze_gg'));
        $totalContratto      = array_sum(array_column($rows, 'totale_contratto'));
        $totalNostraFattura  = array_sum(array_column($rows, 'nostra_fattura'));
        $totalNostraBozza    = array_sum(array_column($rows, 'nostra_fattura_bozza'));
        $totalOrdine         = array_sum(array_column($rows, 'valore_ordine'));
        $totalGiaPagato      = array_sum(array_column($rows, 'gia_pagato'));
        $totalSpese          = array_sum(array_column($rows, 'spese_consorziata'));
        $totalStorico        = array_sum(array_column($payments, 'importo'));

        $fromLabel = \DateTime::createFromFormat('Y-m-d', $from)?->format('d/m/Y') ?? $from;
        $toLabel   = \DateTime::createFromFormat('Y-m-d', $to)?->format('d/m/Y')   ?? $to;

        Response::view('fatturazione/consorziate/show.html.twig', $request, compact(
            'consorziata', 'from', 'to', 'fromLabel', 'toLabel',
            'rows', 'payments', 'ordiniByWorksite', 'righeByWorksite',
            'totalPresenze', 'totalContratto', 'totalNostraFattura', 'totalNostraBozza',
            'totalOrdine', 'totalGiaPagato', 'totalSpese', 'totalStorico'
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
        $ordineIds     = $_POST['ordine_id']   ?? [];

        if (!$dataPagamento || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPagamento)) {
            $_SESSION['error'] = 'Data pagamento non valida.';
            Response::redirect("/fatturazione/consorziate/{$id}?from={$from}&to={$to}");
        }

        $saved = 0;
        foreach ($worksiteIds as $idx => $worksiteId) {
            $worksiteId = (int)$worksiteId;
            $rawImporto = str_replace(['.', ','], ['', '.'], trim((string)($importi[$idx] ?? '')));
            $importo    = (float)$rawImporto;
            $ordineId   = (int)($ordineIds[$idx] ?? 0);
            $ordineId   = $ordineId > 0 ? $ordineId : null;

            if ($worksiteId <= 0 || $importo <= 0) {
                continue;
            }

            $this->repo->insertPayment($id, $worksiteId, $importo, $dataPagamento, $nota, $userId, $ordineId);
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

    // ── POST /fatturazione/consorziate/{id}/gia-pagato ────────────────────────

    /**
     * Impostazione manuale del "Gia' pagato" (saldo iniziale / rettifica).
     *
     * L'utente scrive il NUOVO TOTALE gia' pagato per il cantiere (o per il
     * singolo ordine): BOB registra in bb_pagamenti_consorziate una riga di
     * rettifica pari alla differenza rispetto al totale attuale, cosi' lo
     * storico resta coerente e il residuo torna giusto. Serve per partire
     * ora con il modulo senza dover ricostruire i pagamenti storici.
     */
    public function setGiaPagato(Request $request): never
    {
        $id = $request->intParam('id');

        $consorziata = $this->repo->findConsorziata($id);
        if (!$consorziata) {
            Response::error('Consorziata non trovata.', 404);
        }

        $auth   = $GLOBALS['authenticated_user'];
        $userId = (int)($auth['user_id'] ?? 0);

        $from       = $_POST['from'] ?? date('Y-m-01');
        $to         = $_POST['to']   ?? date('Y-m-t');
        $worksiteId = (int)($_POST['worksite_id'] ?? 0);
        $ordineId   = (int)($_POST['ordine_id'] ?? 0) ?: null;

        // accetta formati it (1.234,56) e standard (1234.56)
        $raw = trim((string)($_POST['nuovo_totale'] ?? ''));
        if (str_contains($raw, ',')) {
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        }
        $nuovoTotale = (float)$raw;

        if ($worksiteId <= 0 || $raw === '' || $nuovoTotale < 0) {
            $_SESSION['error'] = 'Valore "già pagato" non valido.';
            Response::redirect("/fatturazione/consorziate/{$id}?from={$from}&to={$to}");
        }

        $attuale = $this->repo->sumPaid($id, $worksiteId, $ordineId);
        $diff    = round($nuovoTotale - $attuale, 2);

        if (abs($diff) < 0.01) {
            $_SESSION['success'] = 'Il "già pagato" era già a questo valore: nessuna modifica.';
            Response::redirect("/fatturazione/consorziate/{$id}?from={$from}&to={$to}");
        }

        $this->repo->insertPayment(
            $id,
            $worksiteId,
            $diff, // puo' essere negativa: rettifica in diminuzione
            date('Y-m-d'),
            'Impostazione manuale "già pagato" (saldo iniziale/rettifica): totale portato a € ' . number_format($nuovoTotale, 2, ',', '.'),
            $userId,
            $ordineId
        );

        $_SESSION['success'] = '"Già pagato" impostato a € ' . number_format($nuovoTotale, 2, ',', '.')
            . ' (rettifica di € ' . number_format($diff, 2, ',', '.') . ' registrata nello storico).';
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
