<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Societa' del gruppo attiva nella sessione corrente.
 *
 * Punto UNICO da cui leggere "in quale societa' sto lavorando". Serve che sia
 * uno solo: quando i moduli inizieranno a filtrare i dati per societa', il
 * filtro dovra' partire sempre da qui e non da letture sparse di $_SESSION.
 *
 * Regole:
 *  - un utente vede solo le societa' a cui e' assegnato (bb_user_companies);
 *  - se ne ha una sola entra direttamente, senza passaggi aggiuntivi;
 *  - chi non ha nessuna assegnazione ricade sul Consorzio (id 1), cosi' un
 *    utente creato prima di questa funzione continua a lavorare.
 */
final class CurrentCompany
{
    public const SESSION_KEY = 'group_company_id';
    public const CONSORZIO_ID = 1;

    public function __construct(private PDO $conn) {}

    /**
     * Societa' a cui l'utente puo' accedere, ordinate.
     *
     * @return array<int, array{id:int, nome:string, codice:string, colore:string, is_default:bool}>
     */
    public function availableFor(int $userId): array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT c.id, c.nome, c.codice, c.colore, uc.is_default
                FROM   bb_user_companies uc
                JOIN   bb_group_companies c ON c.id = uc.group_company_id
                WHERE  uc.user_id = :uid AND c.attiva = 1
                ORDER BY uc.is_default DESC, c.ordinamento ASC, c.nome ASC
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // migration non ancora applicata: BOB continua a funzionare
            // come mono-azienda invece di bloccarsi
            error_log('[CurrentCompany] ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'nome'       => (string)$r['nome'],
                'codice'     => (string)$r['codice'],
                'colore'     => (string)$r['colore'],
                'is_default' => (bool)$r['is_default'],
            ];
        }
        return $out;
    }

    /** True se l'utente puo' entrare in quella societa'. */
    public function canAccess(int $userId, int $companyId): bool
    {
        foreach ($this->availableFor($userId) as $c) {
            if ($c['id'] === $companyId) return true;
        }
        return false;
    }

    /** Imposta la societa' attiva, verificando che sia consentita. */
    public function select(int $userId, int $companyId): bool
    {
        if (!$this->canAccess($userId, $companyId)) {
            return false;
        }
        $_SESSION[self::SESSION_KEY] = $companyId;

        // moduli e permessi appena letti valgono per la societa' di prima:
        // tenerli vorrebbe dire rispondere con i permessi sbagliati per il
        // resto della richiesta
        \App\Security\AccessControl::svuotaCache();
        return true;
    }

    /** Id della societa' attiva (Consorzio come ripiego). */
    public function id(): int
    {
        return (int)($_SESSION[self::SESSION_KEY] ?? self::CONSORZIO_ID);
    }

    /** Dati della societa' attiva, o null se la tabella non esiste ancora. */
    public function current(): ?array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, nome, codice, colore, moduli, tutti_moduli
                FROM   bb_group_companies WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $this->id()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sceglie la societa' al momento del login.
     * Ritorna true se e' stata decisa da sola (una sola societa' disponibile),
     * false se l'utente deve sceglierla.
     */
    public function autoSelectOnLogin(int $userId): bool
    {
        $lista = $this->availableFor($userId);

        // la societa' sta per cambiare: le cache di AccessControl, se
        // qualcuno le ha gia' riempite in questa richiesta, non valgono piu'
        \App\Security\AccessControl::svuotaCache();

        if (count($lista) === 0) {
            // nessuna assegnazione: comportamento storico, tutto Consorzio
            $_SESSION[self::SESSION_KEY] = self::CONSORZIO_ID;
            return true;
        }
        if (count($lista) === 1) {
            $_SESSION[self::SESSION_KEY] = $lista[0]['id'];
            return true;
        }

        // piu' societa': la scelta la fa l'utente
        unset($_SESSION[self::SESSION_KEY]);
        return false;
    }

    /**
     * True se il modulo e' abilitato per la societa' attiva.
     * La risposta la da' AccessControl: e' l'unico posto che sa leggere
     * `tutti_moduli` e bb_company_modules.
     */
    public function hasModule(string $modulo): bool
    {
        return (new \App\Security\AccessControl($this->conn))
            ->societaHaModulo($this->id(), $modulo);
    }
}
