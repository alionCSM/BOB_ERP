<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Confini fra le societa' del gruppo, applicati alle rotte.
 *
 * Menu, dashboard e notifiche gia' seguono la societa' attiva, ma nascondere
 * una voce non basta: chi scrive l'indirizzo a mano entrerebbe lo stesso.
 * Qui si decide, per ogni indirizzo, se appartiene a un modulo abilitato
 * sulla societa' in cui si sta lavorando.
 *
 * Il criterio e' a lista bianca: quello che non si riconosce si blocca.
 * Al contrario (bloccare solo cio' che si riconosce) ogni modulo nuovo
 * nascerebbe accessibile da tutte le societa' fino a che qualcuno non se
 * ne ricorda, ed e' esattamente il tipo di dimenticanza che fa uscire i
 * dati da una societa' all'altra.
 *
 * Vale per tutti, superadmin compreso: se il capo entra in Poti non deve
 * vedere i cantieri del Consorzio, e se gli servono cambia societa'.
 */
final class CompanyModuleGuard
{
    /**
     * Indirizzi raggiungibili sempre, in qualsiasi societa'.
     *
     * Sono le parti che non appartengono a nessuna azienda: la propria
     * scheda utente, le notifiche (gia' filtrate per societa'), il cambio
     * societa'. In particolare /societa deve restare aperto: e' la via per
     * rimettere a posto i moduli quando sono stati configurati male, e
     * senza di essa ci si chiuderebbe fuori da BOB.
     */
    private const SEMPRE_APERTI = [
        '/',
        '/dashboard',
        '/profile',
        '/logout',
        '/change-password',
        '/confirm-email',
        '/select-company',
        '/switch-company',
        '/societa',
        '/notifications',
        '/api',
        '/services',
        '/support',
    ];

    /**
     * Da quale modulo dipende ogni indirizzo.
     * L'ordine conta: il primo prefisso che corrisponde vince, quindi le
     * voci piu' specifiche stanno prima.
     */
    private const MODULO_PER_ROTTA = [
        '/fatturazione/consorziate' => 'billing',
        '/autocarrate'              => 'pn_autocarrate',
        '/noleggi'                  => 'pn_noleggi',
        '/report/fatturato'         => 'report_business',
        '/report'                   => 'report_business',
        '/offers'                   => 'offers',
        '/ordini-aziende'           => 'ordini_aziende',
        '/ordini'                   => 'worksites',
        '/billing'                  => 'billing',
        '/attendance'               => 'attendance',
        '/bookings'                 => 'bookings',
        '/tickets'                  => 'tickets',
        '/share'                    => 'share',
        '/equipment'                => 'equipment',
        '/programmazione'           => 'programmazione',
        '/pianificazione'           => 'pianificazione',
        '/clients'                  => 'clients',
        '/users'                    => 'users',
        '/documents'                => 'documents',
        '/companies'                => 'companies',
        '/worksites'                => 'worksites',
        '/fleet'                    => 'fleet_view',
        '/ai'                       => 'ai_chat',
        '/attestati'                => 'documents',
    ];

    /**
     * L'indirizzo e' consentito nella societa' attiva?
     *
     * @param string[]|null $moduliSocieta null = nessun limite (societa' con
     *                                     il flag "tutti i moduli").
     *                                     L'elenco vuoto significa davvero
     *                                     nessun modulo, non "tutti": e' la
     *                                     confusione che c'era prima, quando
     *                                     una societa' senza moduli spuntati
     *                                     risultava aperta su tutto.
     */
    public function consente(string $uri, ?array $moduliSocieta): bool
    {
        if ($moduliSocieta === null) {
            return true;
        }

        if ($this->sempreAperto($uri)) {
            return true;
        }

        $modulo = $this->moduloDi($uri);

        // indirizzo sconosciuto: si blocca. Vedi la nota in cima sul perche'
        // la lista bianca e' preferibile all'elenco dei divieti.
        if ($modulo === null) {
            return false;
        }

        return in_array($modulo, $moduliSocieta, true);
    }

    /** Modulo a cui appartiene un indirizzo, null se non si riconosce. */
    public function moduloDi(string $uri): ?string
    {
        foreach (self::MODULO_PER_ROTTA as $prefisso => $modulo) {
            if ($uri === $prefisso || str_starts_with($uri, $prefisso . '/')) {
                return $modulo;
            }
        }
        return null;
    }

    private function sempreAperto(string $uri): bool
    {
        foreach (self::SEMPRE_APERTI as $prefisso) {
            if ($uri === $prefisso) {
                return true;
            }
            // la radice va confrontata solo esatta: come prefisso
            // corrisponderebbe a qualsiasi indirizzo e aprirebbe tutto
            if ($prefisso !== '/' && str_starts_with($uri, $prefisso . '/')) {
                return true;
            }
        }
        return false;
    }
}
