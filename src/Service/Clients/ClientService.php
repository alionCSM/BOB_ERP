<?php
declare(strict_types=1);

namespace App\Service\Clients;
use RuntimeException;
use InvalidArgumentException;
use App\Repository\Clients\ClientRepository;
use App\Validator\Clients\ClientValidator;
use App\Domain\User;

final class ClientService
{
    public function __construct(
        private ClientRepository $clientRepository,
        private ClientValidator  $validator
    ) {}

    public function create(User $user, array $data): void
    {
        if ($user->getCompanyId() !== 1) {
            throw new RuntimeException("Access denied");
        }

        $errors = $this->validator->validate($data);

        if (!empty($errors)) {
            throw new InvalidArgumentException(json_encode($errors));
        }

        $this->clientRepository->insert($data);
    }

    public function update(User $user, int $id, array $data): void
    {
        if ($user->getCompanyId() !== 1) {
            throw new RuntimeException("Access denied");
        }

        $errors = $this->validator->validate($data);

        if (!empty($errors)) {
            throw new InvalidArgumentException(json_encode($errors));
        }

        $existing = $this->clientRepository->getById($id);

        if (!$existing) {
            throw new RuntimeException("Client not found");
        }

        $this->clientRepository->update($id, $data);
    }

    public function delete(User $user, int $id): void
    {
        if ($user->getCompanyId() !== 1) {
            throw new RuntimeException("Access denied");
        }

        $existing = $this->clientRepository->getById($id);

        if (!$existing) {
            throw new RuntimeException("Client not found");
        }

        $this->clientRepository->delete($id);
    }

    public function getDetails(User $user, int $id, int $page = 1): array
    {
        if ($user->getCompanyId() !== 1) {
            throw new RuntimeException("Access denied");
        }

        $client = $this->clientRepository->getById($id);

        if (!$client) {
            throw new RuntimeException("Client not found");
        }

        $perPage = 20;
        $worksitesData = $this->clientRepository->getWorksitesByClientId($id, $page, $perPage);

        return [
            'client'        => $client,
            'lastOffer'     => $this->clientRepository->getLastOfferInfoByClientId($id),
            'totalOffers'   => $this->clientRepository->countOffersByClientId($id),
            'totalCantieri' => $this->clientRepository->countWorksitesByClientId($id),
            'cantieri'      => $this->getGroupedWorksites($worksitesData['worksites']),
            'cantieriPage'  => $page,
            'cantieriTotal' => $worksitesData['total'],
        ];
    }

    /**
     * Get worksites grouped by year for display.
     *
     * @param array<int, array<string, mixed>> $worksites
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getGroupedWorksites(array $worksites): array
    {
        $grouped = [];
        foreach ($worksites as $ws) {
            $year = $ws['start_date'] !== null ? date('Y', strtotime($ws['start_date'])) : 'N/A';
            if (!isset($grouped[$year])) {
                $grouped[$year] = [];
            }
            $grouped[$year][] = $ws;
        }
        return $grouped;
    }
}