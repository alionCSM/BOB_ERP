<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Contracts\OrdineRepositoryInterface;
use App\Service\Ordini\OrdineManagementService;

final class OrdiniController
{
    private OrdineManagementService $svc;

    public function __construct(OrdineRepositoryInterface $repo)
    {
        $this->svc = new OrdineManagementService($repo);
    }

    private function requireCompany(object $user): void
    {
        if ($user->getCompanyId() === null) {
            Response::redirect('/logout');
        }
    }

    // ── List ─────────────────────────────────────────────────────────────────
    public function index(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $ordini = $this->svc->getAll((int)$user->getCompanyId());
        Response::view('ordini/index.html.twig', $request, [
            'ordini'    => $ordini,
            'pageTitle' => 'Ordini Consorziata',
        ]);
    }

    // ── Create form ──────────────────────────────────────────────────────────
    public function create(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $companyId = (int)$user->getCompanyId();
        Response::view('ordini/create.html.twig', $request, [
            'consorziate'        => $this->svc->getConsorziate(),
            'worksites'          => $this->svc->getWorksites($companyId),
            'prefillWorksiteId'  => (int)($request->get('worksite_id') ?? 0),
            'pageTitle'          => 'Nuovo Ordine',
        ]);
    }

    // ── Store ────────────────────────────────────────────────────────────────
    public function store(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $result = $this->svc->create($request->allPost(), (int)$user->getCompanyId());
        if ($result['success']) {
            $_SESSION['success'] = 'Ordine n° ' . $result['order_number'] . ' creato con successo!';
            Response::redirect('/ordini/' . $result['id']);
        } else {
            $_SESSION['error'] = $result['message'];
            Response::redirect('/ordini/create');
        }
    }

    // ── Show ─────────────────────────────────────────────────────────────────
    public function show(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $id      = $request->intParam('id');
        $ordine  = $this->svc->getById($id, (int)$user->getCompanyId());
        if (!$ordine) {
            $_SESSION['error'] = 'Ordine non trovato o accesso negato.';
            Response::redirect('/ordini');
        }
        $items = $this->svc->getItems($id);
        Response::view('ordini/show.html.twig', $request, [
            'ordineId'  => $id,
            'ordine'    => $ordine,
            'items'     => $items,
            'pageTitle' => 'Ordine n° ' . $ordine['order_number'],
        ]);
    }

    // ── Edit form ────────────────────────────────────────────────────────────
    public function edit(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $id        = $request->intParam('id');
        $companyId = (int)$user->getCompanyId();
        $ordine    = $this->svc->getById($id, $companyId);
        if (!$ordine) {
            $_SESSION['error'] = 'Ordine non trovato o accesso negato.';
            Response::redirect('/ordini');
        }
        $items = $this->svc->getItems($id);
        Response::view('ordini/edit.html.twig', $request, [
            'ordineId'    => $id,
            'ordine'      => $ordine,
            'items'       => $items,
            'consorziate' => $this->svc->getConsorziate(),
            'worksites'   => $this->svc->getWorksites($companyId),
            'pageTitle'   => 'Modifica Ordine n° ' . $ordine['order_number'],
        ]);
    }

    // ── Update ───────────────────────────────────────────────────────────────
    public function update(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $id     = $request->intParam('id');
        $result = $this->svc->update($request->allPost(), $id, (int)$user->getCompanyId());
        if ($result['success']) {
            $_SESSION['success'] = 'Ordine aggiornato con successo!';
            Response::redirect('/ordini/' . $id);
        } else {
            $_SESSION['error'] = $result['message'];
            Response::redirect('/ordini/' . $id . '/edit');
        }
    }

    // ── Delete ───────────────────────────────────────────────────────────────
    public function delete(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $id = $request->intParam('id');
        $ok = $this->svc->delete($id, (int)$user->getCompanyId());
        if ($ok) {
            $_SESSION['success'] = 'Ordine eliminato.';
            Response::redirect('/ordini');
        } else {
            $_SESSION['error'] = 'Errore durante l\'eliminazione.';
            Response::redirect('/ordini');
        }
    }

    // ── Status update (AJAX) ─────────────────────────────────────────────────
    public function updateStatus(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);
        $id     = $request->intParam('id');
        $status = trim($request->allPost()['status'] ?? '');
        $ok     = $this->svc->updateStatus($id, $status, (int)$user->getCompanyId());
        Response::json(['success' => $ok]);
    }

    // ── PDF ──────────────────────────────────────────────────────────────────
    public function pdf(Request $request): void
    {
        require APP_ROOT . '/vendor/autoload.php';
        $user = $request->user();
        $this->requireCompany($user);
        $id     = $request->intParam('id');
        $ordine = $this->svc->getById($id, (int)$user->getCompanyId());
        if (!$ordine) {
            Response::error('Ordine non trovato o accesso negato!', 404);
        }
        $items = $this->svc->getItems($id);
        foreach ($items as &$item) {
            $item['importo_fmt'] = number_format((float)$item['importo'], 2, ',', '.');
            $item['prezzo_fmt']  = number_format((float)$item['prezzo_unitario'], 2, ',', '.');
            $item['qta_fmt']     = number_format((float)$item['qta'], 3, ',', '.');
        }
        unset($item);

        $ivaPerc     = (float)($ordine['iva_percentage'] ?? 22);
        $totaleMerce = array_sum(array_column($items, 'importo'));
        $ivaAmount   = round($totaleMerce * $ivaPerc / 100, 2);
        $totaleDoc   = round($totaleMerce + $ivaAmount, 2);

        $renderer = new \App\View\TwigRenderer(null);
        $html = $renderer->render('ordini/pdf.html.twig', [
            'ordine'    => $ordine,
            'items'     => $items,
            'ivaPerc'   => $ivaPerc,
            'fmtMerce'  => number_format($totaleMerce, 2, ',', '.'),
            'fmtIva'    => number_format($ivaAmount,   2, ',', '.'),
            'fmtTotale' => number_format($totaleDoc,   2, ',', '.'),
        ]);

        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas    = $dompdf->getCanvas();
        $w         = $canvas->get_width();
        $h         = $canvas->get_height();
        $font      = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
        $text      = 'Pagina {PAGE_NUM} di {PAGE_COUNT}';
        $textWidth = $dompdf->getFontMetrics()->getTextWidth($text, $font, 7);
        $canvas->page_text(($w - $textWidth) / 1.7, $h - 22, $text, $font, 7, [0.4, 0.4, 0.4]);

        $filename = 'Ordine_n' . ($ordine['order_number'] ?? $id) . '_' .
                    preg_replace('/[^a-zA-Z0-9_]/', '_', $ordine['destinatario_name'] ?? 'Ordine') . '.pdf';
        header('X-Frame-Options: SAMEORIGIN');
        $dompdf->stream($filename, ['Attachment' => false]);
        exit;
    }
}
