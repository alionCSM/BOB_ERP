<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface OfferRepositoryInterface
{
    public function getOfferNumbersByYear(string $yearSuffix): array;

    public function countRevisionsForBaseNumber(string $baseOfferNumber): int;

    public function offerNumberExists(string $offerNumber): bool;

    public function createOffer(array $data, int $companyId, int $creatorId, ?string $pdfPath): int;

    public function replaceOfferItems(int $offerId, array $items): void;

    public function updateOffer(int $offerId, array $data, int $creatorId, ?string $pdfPath): void;

    public function getOfferById(int $offerId, int $userCompanyId): ?array;

    public function getOfferWithClientById(int $offerId, int $userCompanyId): ?array;

    public function getOfferItems(int $offerId): array;

    public function getClientList(): array;

    public function getVisibleOffers(int $userCompanyId): array;

    public function searchOfferNumbers(string $query, int $userCompanyId): array;

    public function updateStatus(int $offerId, string $status, int $userCompanyId): bool;

    public function getFollowups(int $offerId): array;

    public function createFollowup(int $offerId, string $type, string $note, string $date, int $createdBy): int;

    public function deleteFollowup(int $followupId, int $offerId): bool;
}
