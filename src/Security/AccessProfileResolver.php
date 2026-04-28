<?php

declare(strict_types=1);

namespace App\Security;
use App\Domain\User;

class AccessProfileResolver
{
    public const INTERNAL = 'INTERNAL';
    public const CLIENT = 'CLIENT';
    public const COMPANY = 'COMPANY';
    public const WORKER = 'WORKER';

    /**
     * Legacy adapter: resolve canonical access profile from current user fields.
     */
    public function resolve(User $user, array $companyScopedIds = []): string
    {

        $explicitProfile = strtoupper(trim((string)($user->access_profile ?? '')));
        if (in_array($explicitProfile, [self::INTERNAL, self::CLIENT, self::COMPANY, self::WORKER], true)) {
            return $explicitProfile;
        }

        if ($user->type === 'worker') {
            return self::WORKER;
        }

        if ($user->type === 'client' || !empty($user->client_id)) {
            return self::CLIENT;
        }

        if (($user->role ?? '') === 'company_viewer') {
            return self::COMPANY;
        }

        if (!empty($user->permissions['companies_viewer']) && !empty($companyScopedIds)) {
            return self::COMPANY;
        }

        return self::INTERNAL;
    }
}
