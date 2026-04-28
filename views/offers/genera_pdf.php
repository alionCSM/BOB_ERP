<?php
require '../../vendor/autoload.php';
require_once '../../includes/middleware.php';
require_once '../../includes/config/Database.php';
require_once '../../controllers/offers/OfferPdfController.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$db = new Database();
$conn = $db->connect();
$pdfController = new OfferPdfController($conn);

$offer_id = $_GET['offer_id'] ?? null;
if (!$offer_id || !is_numeric($offer_id)) {
    die("ID offerta non fornito o non valido!");
}

// Check user
$user->id = $authenticated_user['user_id'];
$companyId = $user->getCompanyId();
if ($companyId === null) {
    header("Location: ../auth/logout.php");
    exit();
}

$payload = $pdfController->getPdfPayload((int)$offer_id, (int)$companyId);
if (!$payload) {
    die("Offerta non trovata o accesso negato!");
}
$offer = $payload['offer'];
$items = $payload['items'];
$templateFile = $payload['template_file'];

// Carica HTML con variabili PHP
ob_start();
require $templateFile; // includo template PHP con variabili già definite
$html = ob_get_clean();

// Opzioni DOMPDF
$options = new Options();
$options->setIsRemoteEnabled(true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->render();

// Numerazione pagine
$canvas = $dompdf->getCanvas();
$w = $canvas->get_width();
$h = $canvas->get_height();
$font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
$fontSize = 7;
$text = "Pagina {PAGE_NUM} di {PAGE_COUNT}";
$textWidth = $dompdf->getFontMetrics()->getTextWidth($text, $font, $fontSize);
$x = ($w - $textWidth) / 1.7; // centrato perfettamente
$y = $h - 30;
$canvas->page_text($x, $y, $text, $font, $fontSize, [0, 0, 0]);

// Output PDF
$offer_number = preg_replace('/[^a-zA-Z0-9-_]/', '_', $offer['offer_number'] ?? 'Sconosciuto');
$client_name = preg_replace('/[^a-zA-Z0-9-_]/', '_', $offer['client_name'] ?? 'Cliente');

$filename = "Offerta n {$offer_number} - {$client_name}.pdf";
$dompdf->stream($filename, ["Attachment" => false]);
