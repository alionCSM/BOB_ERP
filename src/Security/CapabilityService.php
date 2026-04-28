<?php

declare(strict_types=1);

namespace App\Security;
use App\Domain\User;
use App\Security\AuthorizationService;

class CapabilityService
{
    /** @var array<string,string> */
    private array $moduleMap = [
        'attendance' => 'attendance',
        'billing'    => 'billing',
        'bookings'   => 'bookings',
        'chat'       => 'chat',
        'clients'    => 'clients',
        'companies'  => 'companies',
        'tickets'         => 'tickets',
        'pianificazione'  => 'pianificazione',
        'programmazione'  => 'programmazione',
        'dashboard'       => 'dashboard',
        'documents'  => 'documents',
        'equipment'  => 'equipment',
        'share'      => 'share',
        'offers'     => 'offers',
        'presenze'   => 'presenze',
        'users'      => 'users',
        'worksites'  => 'worksites',
    ];

    public function resolveRequiredModule(?string $resolvedView): ?string
    {
        if (!$resolvedView) {
            return null;
        }

        $folder = explode('/', $resolvedView)[0] ?? null;
        if (!$folder) {
            return null;
        }

        return $this->moduleMap[$folder] ?? null;
    }

    public function canAccessResolvedView(User $user, ?string $resolvedView, AuthorizationService $authorization): bool
    {
        $module = $this->resolveRequiredModule($resolvedView);
        if ($module === null) {
            return true;
        }

        return $authorization->canAccessModule($user, $module);
    }
}
