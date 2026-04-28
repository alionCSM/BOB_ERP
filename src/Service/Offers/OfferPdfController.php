<?php

declare(strict_types=1);

namespace App\Service\Offers;

use PDO;

/**
 * Builds the data payload used to render offer PDF templates.
 * Moved from controllers/offers/ — no behaviour changed.
 * OfferController is in the same namespace; no import needed.
 */
class OfferPdfController
{
    private OfferController $offerController;

    public function __construct(PDO $conn)
    {
        $this->offerController = new OfferController($conn);
    }

    public function getPdfPayload(int $offerId, int $userCompanyId): ?array
    {
        $offer = $this->offerController->getOfferWithClientForPdf($offerId, $userCompanyId);
        if (!$offer) {
            return null;
        }

        $items = $this->offerController->getOfferItems($offerId);
        foreach ($items as &$item) {
            $rawAmount = $item['amount'] ?? null;
            if ($rawAmount !== null && is_numeric((string)$rawAmount)) {
                $item['amount'] = number_format((float)$rawAmount, 2, ',', '.') . ' €';
            }
        }
        unset($item);

        return [
            'offer'         => $offer,
            'items'         => $items,
            'template_file' => $this->resolveTemplateFile((string)($offer['offer_template'] ?? 'template1')),
        ];
    }

    private function resolveTemplateFile(string $templateCode): string
    {
        return match ($templateCode) {
            'template2' => APP_ROOT . '/views/offers/offer_template2.php',
            'template3' => APP_ROOT . '/views/offers/offer_template3.php',
            'template4' => APP_ROOT . '/views/offers/offer_template4.php',
            default     => APP_ROOT . '/views/offers/offer_template.php',
        };
    }
}
