<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Service\Offers\OfferController;
use App\Service\Offers\OfferPdfController;

final class OffersController
{
    private OfferController    $ctrl;
    private OfferPdfController $pdfCtrl;

    public function __construct(\PDO $conn)
    {
        $this->ctrl    = new OfferController($conn);
        $this->pdfCtrl = new OfferPdfController($conn);
    }

    public function index(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offers = $this->ctrl->getVisibleOffers((int)$user->getCompanyId());
        // Normalize Italian number format (e.g., "1.272,00" → 1272.00) for display
        foreach ($offers as &$offer) {
            $offer['total_amount_float'] = floatval(str_replace(['.', ','], ['', '.'], (string)$offer['total_amount']));
        }
        $pageTitle = 'Lista Offerte';

        Response::view('offers/index.html.twig', $request, compact('offers', 'pageTitle'));
    }

    public function create(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $nextOfferNumber = $this->ctrl->getNextOfferNumber();
        $clients         = $this->ctrl->getClients();
        $pageTitle       = 'Crea Offerta';

        Response::view('offers/create.html.twig', $request, compact('nextOfferNumber', 'clients', 'pageTitle'));
    }

    public function store(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $result = $this->ctrl->createFromRequest(
            $request->allPost(),
            $request->allFiles(),
            (int)$user->getCompanyId(),
            (int)($GLOBALS['authenticated_user']['user_id'] ?? 0)
        );

        if ($result['success']) {
            $_SESSION['success'] = 'Offerta creata con successo!';
            Response::redirect('/offers');
        } else {
            $_SESSION['error'] = $result['message'];
            Response::redirect('/offers/create');
        }
    }

    public function show(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offerId   = $request->intParam('id');
        $offerData = $this->ctrl->getOfferWithClientForPdf($offerId, (int)$user->getCompanyId());
        if (!$offerData) {
            $_SESSION['error'] = 'Offerta non trovata o accesso negato.';
            Response::redirect('/offers');
        }

        $followups = $this->ctrl->getFollowups($offerId);
        $pageTitle = 'Offerta ' . ($offerData['offer_number'] ?? '');

        // Release session lock so the embedded PDF iframe request can proceed immediately
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Response::view('offers/show.html.twig', $request, compact('offerId', 'offerData', 'followups', 'pageTitle'));
    }

    public function edit(Request $request): void
    {
        $user    = $request->user();
        $this->requireCompany($user);

        $offerId   = $request->intParam('id');
        $offerData = $this->ctrl->getOfferForEdit($offerId, (int)$user->getCompanyId());
        if (!$offerData) {
            $_SESSION['error'] = 'Offerta non trovata o accesso negato.';
            Response::redirect('/offers');
        }

        $itemsData = $this->ctrl->getOfferItems($offerId);
        $clients   = $this->ctrl->getClients();
        $followups = $this->ctrl->getFollowups($offerId);
        $pageTitle = "Modifica Offerta #{$offerId}";

        Response::view('offers/edit.html.twig', $request, compact('offerId', 'offerData', 'itemsData', 'clients', 'followups', 'pageTitle'));
    }

    public function update(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offerId = $request->intParam('id');
        $this->ctrl->updateFromRequest(
            $offerId,
            $request->allPost(),
            $request->allFiles(),
            (int)$user->getCompanyId(),
            (int)($GLOBALS['authenticated_user']['user_id'] ?? 0)
        );

        $_SESSION['success'] = 'Offerta modificata con successo!';
        Response::redirect('/offers');
    }

    public function revise(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $originalId   = $request->intParam('id');
        $originalData = $this->ctrl->getOfferForEdit($originalId, (int)$user->getCompanyId());
        if (!$originalData) {
            $_SESSION['error'] = 'Offerta non trovata o accesso negato.';
            Response::redirect('/offers');
        }

        $baseNumber        = $originalData['is_revision'] ? $originalData['base_offer_number'] : $originalData['offer_number'];
        $newRevisionNumber = $this->ctrl->getNextRevisionNumber($baseNumber);
        $itemsData         = $this->ctrl->getOfferItems($originalId);
        $clients           = $this->ctrl->getClients();
        $pageTitle         = "Revisione Offerta #{$originalId}";

        Response::view('offers/revisione.html.twig', $request, compact(
            'originalId', 'originalData', 'baseNumber', 'newRevisionNumber', 'itemsData', 'clients', 'pageTitle'
        ));
    }

    public function reviseStore(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $originalId   = $request->intParam('id');
        $originalData = $this->ctrl->getOfferForEdit($originalId, (int)$user->getCompanyId());
        if (!$originalData) {
            $_SESSION['error'] = 'Offerta non trovata.';
            Response::redirect('/offers');
        }

        $baseNumber = $originalData['is_revision'] ? $originalData['base_offer_number'] : $originalData['offer_number'];

        $result = $this->ctrl->createRevisionFromRequest(
            $request->allPost(),
            $request->allFiles(),
            $originalData,
            $baseNumber,
            (int)$user->getCompanyId(),
            (int)($GLOBALS['authenticated_user']['user_id'] ?? 0)
        );

        if ($result['success']) {
            $_SESSION['success'] = 'Revisione offerta creata con successo!';
            Response::redirect('/offers');
        } else {
            $_SESSION['error'] = $result['message'];
            Response::redirect('/offers/' . $originalId . '/revise');
        }
    }

    public function updateStatus(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offerId = $request->intParam('id');
        $status  = (string)($request->allPost()['status'] ?? '');

        $success = $this->ctrl->updateStatus($offerId, $status, (int)$user->getCompanyId());
        Response::json(['success' => $success]);
    }

    public function addFollowup(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offerId   = $request->intParam('id');
        $post      = $request->allPost();
        $type      = (string)($post['type'] ?? 'nota');
        $note      = trim((string)($post['note'] ?? ''));
        $date      = (string)($post['date'] ?? date('Y-m-d'));
        $createdBy = (int)($GLOBALS['authenticated_user']['user_id'] ?? 0);

        $newId = $this->ctrl->addFollowup($offerId, $type, $note, $date, $createdBy);
        Response::json(['success' => $newId > 0, 'id' => $newId]);
    }

    public function deleteFollowup(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offerId    = $request->intParam('id');
        $followupId = $request->intParam('followupId');

        $success = $this->ctrl->deleteFollowup($followupId, $offerId);
        Response::json(['success' => $success]);
    }

    public function search(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $query   = (string)($request->get('query') ?? '');
        $results = $this->ctrl->searchOfferNumbers($query, (int)$user->getCompanyId());

        Response::json($results);
    }

    public function pdf(Request $request): void
    {
        require APP_ROOT . '/vendor/autoload.php';

        $user = $request->user();
        $this->requireCompany($user);

        $offerId = $request->intParam('id');
        $payload = $this->pdfCtrl->getPdfPayload($offerId, (int)$user->getCompanyId());
        if (!$payload) {
            Response::error('Offerta non trovata o accesso negato!', 404);
        }

        $offer        = $payload['offer'];
        $items        = $payload['items'];
        $templateFile = $payload['template_file'];

        ob_start();
        require $templateFile;
        $html = ob_get_clean();

        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();

        $canvas    = $dompdf->getCanvas();
        $w         = $canvas->get_width();
        $h         = $canvas->get_height();
        $font      = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
        $text      = 'Pagina {PAGE_NUM} di {PAGE_COUNT}';
        $textWidth = $dompdf->getFontMetrics()->getTextWidth($text, $font, 7);
        $canvas->page_text(($w - $textWidth) / 1.7, $h - 30, $text, $font, 7, [0, 0, 0]);

        $offerNumber = preg_replace('/[^a-zA-Z0-9-_]/', '_', $offer['offer_number'] ?? 'Sconosciuto');
        $clientName  = preg_replace('/[^a-zA-Z0-9-_]/', '_', $offer['client_name'] ?? 'Cliente');

        header('X-Frame-Options: SAMEORIGIN');
        $dompdf->stream("Offerta n {$offerNumber} - {$clientName}.pdf", ['Attachment' => false]);
        exit;
    }

    public function serveDoc(Request $request): void
    {
        $user = $request->user();
        $this->requireCompany($user);

        $offerId = $request->intParam('id');
        $offer   = $this->ctrl->getOfferForEdit($offerId, (int)$user->getCompanyId());
        if (!$offer || empty($offer['doc_path'])) {
            Response::error('Documento non trovato.', 404);
        }

        $cloudRoot = rtrim(\CloudPath::getRoot(), DIRECTORY_SEPARATOR);
        $filePath  = realpath($cloudRoot . DIRECTORY_SEPARATOR . $offer['doc_path']);

        if (!$filePath || !file_exists($filePath) || strpos($filePath, $cloudRoot) !== 0) {
            Response::error('File non trovato.', 404);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Cache-Control: private, no-store');
        readfile($filePath);
        exit;
    }

    private function requireCompany(object $user): void
    {
        if ($user->getCompanyId() === null) {
            Response::redirect('/logout');
        }
    }
}
