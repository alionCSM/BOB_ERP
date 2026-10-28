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
        // Analytics: heartbeat only — user-activity exposes staff data and
        // must not be reachable by company-scoped users
        '#^/api/analytics/heartbeat$#',
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
        '#^/api/analytics/heartbeat$#',
    ];

    /** @var string[] */
    /**
     * Dove puo' andare un operaio.
     *
     * Lista bianca: tutto il resto e' 403. Un operaio in BOB vede le sue
     * cose e basta — niente preventivi, niente fatture, niente noleggi.
     */
    private array $workerAllowedPatterns = [
        '#^/$#',
        '#^/dashboard#',
        '#^/change-password$#',
        '#^/profile#',
        '#^/logout#',
        '#^/notifications#',
        // le sue giornate, le sue ferie, la sua squadra
        '#^/io(/|$)#',
        // i cantieri che gli sono stati assegnati, e la Zone di quelli.
        // Solo /worksites/my e /worksites/{id}/zone: il resto della scheda
        // cantiere — preventivi, fatture, ordini — resta dell'ufficio.
        '#^/worksites/my(/|$)#',
        '#^/worksites/\d+/zone(/|$)#',
        // il file di un disegno della Zone: chi lo apre lo decide il
        // controller, con la visibilita' del disegno (o la condivisione)
        '#^/worksites/\d+/disegni/\d+/view$#',
        // c'era /my_worksites, che non e' mai esistito come rotta: il menu
        // ha sempre puntato a /worksites/my, quindi "I miei cantieri" dava
        // "Access denied" da sempre. Nessuno se n'era accorto perche'
        // nessun operaio era mai entrato in BOB.
    ];

    /**
     * Dove puo' andare un cliente.
     *
     * Come per gli operai: lista bianca, tutto il resto e' 403. Un cliente
     * entra nella Zone dei cantieri che gli sono stati condivisi, e in
     * nient'altro — la scheda cantiere dell'ufficio (preventivi, fatture,
     * ordini, margini) non e' qui dentro, e non ci deve essere.
     *
     * Attenzione a /worksites/\d+/zone: la lista bianca dice soltanto che
     * l'indirizzo e' suo da aprire, non che quel cantiere sia suo. Quale
     * cantiere puo' aprire lo decide il controllo sul numero
     * nell'indirizzo, nel middleware, con i cantieri che gli sono stati
     * condivisi. Senza quello, un cliente cambiava il numero a mano ed
     * entrava nella Zone di chiunque.
     */
    private array $clientAllowedPatterns = [
        '#^/$#',
        '#^/dashboard#',
        '#^/change-password$#',
        '#^/profile#',
        '#^/logout#',
        '#^/notifications#',
        // i cantieri condivisi con lui, e la Zone di quelli
        '#^/worksites/my(/|$)#',
        '#^/worksites/\d+/zone(/|$)#',
        // il file di un disegno: chi lo apre lo decide il controller, con
        // la condivisione del disegno
        '#^/worksites/\d+/disegni/\d+/view$#',
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

    public function isClientRouteAllowed(string $uri): bool
    {
        return $this->matchesAny($uri, $this->clientAllowedPatterns);
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
