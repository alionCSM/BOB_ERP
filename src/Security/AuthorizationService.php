<?php

declare(strict_types=1);

namespace App\Security;
use App\Domain\User;
use App\Security\AccessProfileResolver;

class AuthorizationService
{
    private AccessProfileResolver $profileResolver;

    public function __construct(?AccessProfileResolver $profileResolver = null)
    {
        $this->profileResolver = $profileResolver ?? new AccessProfileResolver();
    }

    public function isSuperAdmin(User $user): bool
    {
        return (int)$user->id === 1;
    }

    public function canAccessModule(User $user, string $module): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->canAccess($module);
    }

    public function isCompanyScopedUser(User $user, array $companyScopedIds = []): bool
    {
        $profile = $this->profileResolver->resolve($user, $companyScopedIds);
        return $profile === AccessProfileResolver::COMPANY || $profile === AccessProfileResolver::CLIENT;
    }

    public function allowedCompanyIds(User $user, array $companyScopedIds = []): array
    {
        $ids = array_map('intval', $companyScopedIds);

        if (empty($ids) && !empty($user->company_id)) {
            $ids = [(int)$user->company_id];
        }

        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
    }

    public function canAccessCompany(User $user, int $companyId, array $companyScopedIds = []): bool
    {
        if (!$this->isCompanyScopedUser($user, $companyScopedIds)) {
            return true;
        }

        return in_array($companyId, $this->allowedCompanyIds($user, $companyScopedIds), true);
    }
}
