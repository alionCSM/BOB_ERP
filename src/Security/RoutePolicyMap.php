<?php

declare(strict_types=1);

namespace App\Security;

class RoutePolicyMap
{
    /** @var string[] */
    private array $companyScopedAllowedPatterns = [
        // Companies (MVC)
        '#^/companies/my#',
        '#^/companies/\d+#',
        '#^/companies/documents/serve#',
        '#^/serve_company_document\.php#',
        '#^/upload_company_document\.php#',
        '#^/update_company_document\.php#',
        '#^/delete_company_document\.php#',
        // Users / workers (MVC)
        '#^/worker_profile\.php#',
        '#^/users$#',
        '#^/users/workers#',
        '#^/users/create#',
        '#^/users/search-workers#',
        '#^/users/\d+/edit#',
        '#^/users/\d+/update#',
        '#^/users/\d+/photo#',
        '#^/users/\d+/toggle#',
        '#^/users/\d+/company#',
        '#^/users/\d+/account#',
        '#^/users/\d+/delete#',
        '#^/users/\d+/worker-photo$#',
        '#^/users/\d+/user-photo$#',
        // Documents (legacy PHP files)
        '#^/check_mandatory\.php#',
        '#^/delete_document\.php#',
        '#^/documenti_aziendali\.php#',
        '#^/expired\.php#',
        '#^/expired_cv\.php#',
        '#^/serve_document\.php#',
        '#^/update_document\.php#',
        '#^/upload_document\.php#',
        // Documents (MVC routes)
        '#^/documents/expired-cv#',
        '#^/documents/upload$#',
        '#^/documents/\d+/update$#',
        '#^/documents/\d+/delete$#',
        '#^/documents/serve#',
        '#^/documents/check-mandatory#',
        // Auth / profile
        '#^/logout#',
        '#^/profile#',
        '#^/change-password$#',
        // Notifications
        '#^/notifications/unread$#',
        '#^/api/analytics/#',
        '#^/$#',
        '#^/dashboard#',
    ];

    /** @var string[] */
    private array $companyScopedPermissionBypassPatterns = [
        // Companies (MVC)
        '#^/companies/my#',
        '#^/companies/\d+#',
        '#^/companies/documents/serve#',
        '#^/serve_company_document\.php#',
        '#^/upload_company_document\.php#',
        '#^/update_company_document\.php#',
        '#^/delete_company_document\.php#',
        // Users / workers (MVC)
        '#^/worker_profile\.php#',
        '#^/users$#',
        '#^/users/workers#',
        '#^/users/create#',
        '#^/users/search-workers#',
        '#^/users/\d+/edit#',
        '#^/users/\d+/update#',
        '#^/users/\d+/photo#',
        '#^/users/\d+/toggle#',
        '#^/users/\d+/company#',
        '#^/users/\d+/account#',
        '#^/users/\d+/delete#',
        '#^/users/\d+/worker-photo$#',
        '#^/users/\d+/user-photo$#',
        // Documents (legacy PHP files)
        '#^/check_mandatory\.php#',
        '#^/delete_document\.php#',
        '#^/documenti_aziendali\.php#',
        '#^/expired\.php#',
        '#^/expired_cv\.php#',
        '#^/serve_document\.php#',
        '#^/update_document\.php#',
        '#^/upload_document\.php#',
        // Documents (MVC routes)
        '#^/documents/expired-cv#',
        '#^/documents/upload$#',
        '#^/documents/\d+/update$#',
        '#^/documents/\d+/delete$#',
        '#^/documents/serve#',
        '#^/documents/check-mandatory#',
        // Auth / profile
        '#^/logout#',
        '#^/profile#',
        '#^/change-password$#',
        // Notifications
        '#^/notifications/unread$#',
        '#^/api/analytics/#',
    ];

    /** @var string[] */
    private array $workerAllowedPatterns = [
        '#^/$#',
        '#^/dashboard#',
        '#^/change-password$#',
        '#^/profile#',
        '#^/logout#',
        '#^/my_worksites#',
    ];

    public function isCompanyScopedRouteAllowed(string $uri): bool
    {
        return $this->matchesAny($uri, $this->companyScopedAllowedPatterns);
    }

    public function isCompanyScopedPermissionBypassRoute(string $uri): bool
    {
        return $this->matchesAny($uri, $this->companyScopedPermissionBypassPatterns);
    }

    public function isWorkerRouteAllowed(string $uri): bool
    {
        return $this->matchesAny($uri, $this->workerAllowedPatterns);
    }

    /** @param string[] $patterns */
    private function matchesAny(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }
}
