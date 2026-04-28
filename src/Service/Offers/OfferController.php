<?php

declare(strict_types=1);

namespace App\Service\Offers;

use App\Repository\Offers\OfferRepository;
use App\Service\Offers\OfferManagementService;
use App\Validator\Offers\OfferPayloadValidator;
use PDO;
use Throwable;

/**
 * Thin coordinator between HTTP layer and OfferManagementService.
 * Moved from controllers/offers/ — no behaviour changed.
 */
class OfferController
{
    private OfferManagementService $service;
    private OfferPayloadValidator $validator;

    public function __construct(PDO $conn)
    {
        $repository    = new OfferRepository($conn);
        $this->service   = new OfferManagementService($repository);
        $this->validator = new OfferPayloadValidator();
    }

    public function getNextOfferNumber(): string
    {
        return $this->service->getNextOfferNumber();
    }

    public function createFromRequest(array $post, array $files, int $userCompanyId, int $creatorId): array
    {
        try {
            $data  = $this->validator->validate($post);
            $items = $this->validator->validateItems($post);

            return $this->service->createOffer($data, $items, $userCompanyId, $creatorId, $files['offer_pdf'] ?? []);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getOfferForEdit(int $offerId, int $userCompanyId): ?array
    {
        return $this->service->getOfferById($offerId, $userCompanyId);
    }

    public function getOfferWithClientForPdf(int $offerId, int $userCompanyId): ?array
    {
        return $this->service->getOfferWithClientById($offerId, $userCompanyId);
    }

    public function getOfferItems(int $offerId): array
    {
        return $this->service->getOfferItems($offerId);
    }

    public function getClients(): array
    {
        return $this->service->getClientList();
    }

    public function updateFromRequest(int $offerId, array $post, array $files, int $userCompanyId, int $creatorId): bool
    {
        $data  = $this->validator->validate($post);
        $items = $this->validator->validateItems($post);

        return $this->service->updateOffer($offerId, $data, $items, $userCompanyId, $creatorId, $files['offer_pdf'] ?? []);
    }

    public function createRevisionFromRequest(
        array $post,
        array $files,
        array $originalData,
        string $baseNumber,
        int $userCompanyId,
        int $creatorId
    ): array {
        $newData = $this->validator->validate($post);
        $newData['client']             = $newData['client'] > 0 ? $newData['client'] : (int)$originalData['client_id'];
        $newData['riferimento']        = $newData['riferimento'] !== '' ? $newData['riferimento'] : (string)$originalData['reference'];
        $newData['cortese_att']        = $newData['cortese_att'] !== '' ? $newData['cortese_att'] : (string)$originalData['cortese_att'];
        $newData['oggetto']            = $newData['oggetto'] !== '' ? $newData['oggetto'] : (string)$originalData['subject'];
        $newData['offer_date']         = $newData['offer_date'] !== '' ? $newData['offer_date'] : date('Y-m-d');
        $newData['total_amount']       = $newData['total_amount'] !== '' ? $newData['total_amount'] : (string)$originalData['total_amount'];
        $newData['termini_pagamento']  = $newData['termini_pagamento'] !== '' ? $newData['termini_pagamento'] : (string)$originalData['termini_pagamento'];
        $newData['condizioni']         = $newData['condizioni'] !== '' ? $newData['condizioni'] : (string)$originalData['condizioni'];
        $newData['additional']         = $newData['additional'] !== '' ? $newData['additional'] : (string)($originalData['note'] ?? '');
        $newData['offer_template']     = null;
        $newData['is_revision']        = 1;
        $newData['base_offer_number']  = $baseNumber;

        $items = $this->validator->validateItems($post);

        return $this->service->createOffer($newData, $items, $userCompanyId, $creatorId, $files['offer_pdf'] ?? []);
    }

    public function getNextRevisionNumber(string $baseNumber): string
    {
        return $this->service->getNextRevisionNumber($baseNumber);
    }

    public function getVisibleOffers(int $userCompanyId): array
    {
        return $this->service->getVisibleOffers($userCompanyId);
    }

    public function searchOfferNumbers(string $query, int $userCompanyId): array
    {
        return $this->service->searchOfferNumbers($query, $userCompanyId);
    }

    public function updateStatus(int $offerId, string $status, int $userCompanyId): bool
    {
        return $this->service->updateStatus($offerId, $status, $userCompanyId);
    }

    public function getFollowups(int $offerId): array
    {
        return $this->service->getFollowups($offerId);
    }

    public function addFollowup(int $offerId, string $type, string $note, string $date, int $createdBy): int
    {
        return $this->service->createFollowup($offerId, $type, $note, $date, $createdBy);
    }

    public function deleteFollowup(int $followupId, int $offerId): bool
    {
        return $this->service->deleteFollowup($followupId, $offerId);
    }
}
