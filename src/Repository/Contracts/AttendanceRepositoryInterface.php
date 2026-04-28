<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface AttendanceRepositoryInterface
{
    public function getExistingPresencesByWorkerAndDate(int $workerId, string $day): array;

    public function getWorksiteLabel(int $worksiteId): string;

    public function deleteByIds(array $ids): void;

    public function getInternalByWorksiteAndDate(int $worksiteId, string $date): array;

    public function getConsorziateByWorksiteAndDate(int $worksiteId, string $date): array;

    public function deleteByWorkerAndDate(int $workerId, string $day): void;

    public function updatePresenza(int $id, array $params): void;

    public function insertPresenza(array $params): void;

    public function getWorkerInfo(int $workerId): ?array;

    public function getWorkerCompanyFromHistory(string $fiscalCode, string $day): ?string;

    public function getWorkerFullName(int $workerId): string;

    public function deleteConsorziateByIdsForWorksite(array $ids, int $worksiteId): void;

    public function deleteConsorziateByWorksiteAndDay(int $worksiteId, string $day): void;

    public function updateConsorziata(int $id, int $worksiteId, string $day, array $params): void;

    public function insertConsorziata(int $worksiteId, string $day, array $params): void;

    public function resolveCompanyId(string $nomeOrId): int;

    public function getFiltered(
        ?string $startDate,
        ?string $endDate,
        ?int $cantiereId,
        ?int $workerId,
        int $limit = 200
    ): array;

    public function getConsorziateFiltered(
        ?string $startDate,
        ?string $endDate,
        ?int $cantiereId,
        ?string $consName,
        int $limit = 200
    ): array;

    public function deleteInternalById(int $id): bool;

    public function deleteConsorziataById(int $id): bool;
}
