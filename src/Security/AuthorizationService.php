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

    /**
     * Il superadmin salta i permessi, ma non i confini fra societa'.
     *
     * User::canAccess() applica il limite dei moduli della societa' attiva
     * prima del proprio bypass: qui si passa sempre da li', altrimenti il
     * bypass di questo metodo lo scavalcherebbe e il capo dentro Poti
     * continuerebbe a raggiungere i moduli del Consorzio.
     */
    public function canAccessModule(User $user, string $module): bool
    {
        return $user->canAccess($module);
    }

    /**
     * Un account di una consorziata: vede solo la scheda della sua azienda.
     *
     * Il cliente non c'entra. Prima contava anche lui, e al login il
     * middleware lo mandava a /companies/my come una consorziata; la lista
     * bianca dei clienti quella pagina non la conosce, e rispondeva "Access
     * denied". Nessun cliente riusciva a entrare nel sito. Il cliente ha
     * le sue regole, piu' sotto nel middleware: i suoi cantieri e la Zone.
     */
    public function isCompanyScopedUser(User $user, array $companyScopedIds = []): bool
    {
        if (($user->type ?? '') === 'client') {
            return false;
        }
        return $this->profileResolver->resolve($user, $companyScopedIds) === AccessProfileResolver::COMPANY;
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
