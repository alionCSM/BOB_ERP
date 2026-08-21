<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\CurrentCompany;
use PDO;

/**
 * Chi puo' fare cosa, in quale societa' del gruppo.
 *
 * Punto unico: prima la stessa domanda veniva risolta in almeno quattro
 * posti diversi (User::canAccess, CurrentCompany::hasModule, il middleware,
 * la dashboard), ognuno con la sua idea di cosa volesse dire un campo
 * `moduli` vuoto. Bastava che uno dei quattro la pensasse diversamente
 * perche' il menu mostrasse una voce che poi dava 403, o perche' la
 * dashboard cambiasse aspetto senza motivo apparente.
 *
 * Il permesso e' il risultato di DUE domande, in quest'ordine:
 *
 *   1. la societa' ha quel modulo?   (bb_company_modules / tutti_moduli)
 *   2. l'utente ha quel permesso li'? (bb_user_company_permissions)
 *
 * La prima vale per tutti, superadmin compreso: dentro Poti non devono
 * comparire i cantieri del Consorzio nemmeno al capo — se gli servono,
 * cambia societa'. La seconda invece il superadmin la salta.
 *
 * Le letture sono in cache per la durata della richiesta: canAccess() viene
 * chiamato decine di volte solo per disegnare il menu. La cache e' indicizzata
 * per societa' e per utente, cosi' due utenti o due societa' diverse nella
 * stessa richiesta non si pestano i piedi (prima era una variabile sola,
 * riempita la prima volta e mai piu' riletta).
 */
final class AccessControl
{
    /** @var array<int, string[]|null> id societa' => moduli, null = tutti */
    private static array $moduliSocieta = [];

    /** @var array<string, array<string,bool>> "utente:societa" => permessi */
    private static array $permessi = [];

    public function __construct(private PDO $conn) {}

    // ── Moduli della societa' ───────────────────────────────────────────────

    /**
     * Moduli abilitati su una societa'.
     *
     * @return string[]|null null = tutti (flag tutti_moduli, o tabelle non
     *                       ancora migrate: in quel caso BOB deve continuare
     *                       a funzionare come prima, non chiudersi)
     */
    public function moduliSocieta(int $companyId): ?array
    {
        if (array_key_exists($companyId, self::$moduliSocieta)) {
            return self::$moduliSocieta[$companyId];
        }

        $moduli = null;
        try {
            $stmt = $this->conn->prepare(
                'SELECT tutti_moduli FROM bb_group_companies WHERE id = :id'
            );
            $stmt->execute([':id' => $companyId]);
            $riga = $stmt->fetchColumn();

            if ($riga === false) {
                // societa' inesistente: nessun modulo, non "tutti"
                $moduli = [];
            } elseif (!(int)$riga) {
                $stmt = $this->conn->prepare(
                    'SELECT modulo FROM bb_company_modules WHERE group_company_id = :id'
                );
                $stmt->execute([':id' => $companyId]);
                $moduli = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (\Throwable $e) {
            error_log('[AccessControl] moduliSocieta: ' . $e->getMessage());
            $moduli = null;
        }

        return self::$moduliSocieta[$companyId] = $moduli;
    }

    /** True se la societa' ha quel modulo abilitato. */
    public function societaHaModulo(int $companyId, string $modulo): bool
    {
        $moduli = $this->moduliSocieta($companyId);
        return $moduli === null || in_array($modulo, $moduli, true);
    }

    /**
     * Riscrive i moduli di una societa'.
     *
     * @param string[] $moduli ignorati se $tutti e' true
     */
    public function salvaModuliSocieta(int $companyId, bool $tutti, array $moduli): void
    {
        $validi = self::moduliEsistenti();

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                'UPDATE bb_group_companies SET tutti_moduli = :t WHERE id = :id'
            );
            $stmt->execute([':t' => $tutti ? 1 : 0, ':id' => $companyId]);

            $this->conn->prepare('DELETE FROM bb_company_modules WHERE group_company_id = :id')
                       ->execute([':id' => $companyId]);

            if (!$tutti) {
                $ins = $this->conn->prepare(
                    'INSERT INTO bb_company_modules (group_company_id, modulo) VALUES (:id, :m)'
                );
                // si accettano solo codici che esistono davvero: un campo
                // manomesso non deve finire in tabella
                foreach (array_unique($moduli) as $m) {
                    if (isset($validi[$m])) {
                        $ins->execute([':id' => $companyId, ':m' => $m]);
                    }
                }
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        unset(self::$moduliSocieta[$companyId]);
    }

    // ── Permessi dell'utente ────────────────────────────────────────────────

    /**
     * Permessi di un utente in una societa'.
     *
     * Con $companyId = 0 (fuori da una richiesta web: cron, mail di avviso)
     * si legge la proiezione bb_user_permissions, cioe' "ha il permesso in
     * almeno una societa'". E' l'unica risposta sensata quando non c'e'
     * nessuna societa' attiva da cui partire.
     *
     * @return array<string,bool>
     */
    public function permessi(int $userId, int $companyId): array
    {
        $chiave = $userId . ':' . $companyId;
        if (isset(self::$permessi[$chiave])) {
            return self::$permessi[$chiave];
        }

        $out = [];
        try {
            if ($companyId > 0) {
                $stmt = $this->conn->prepare('
                    SELECT module, allowed FROM bb_user_company_permissions
                    WHERE user_id = :uid AND group_company_id = :cid
                ');
                $stmt->execute([':uid' => $userId, ':cid' => $companyId]);
            } else {
                $stmt = $this->conn->prepare(
                    'SELECT module, allowed FROM bb_user_permissions WHERE user_id = :uid'
                );
                $stmt->execute([':uid' => $userId]);
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['module']] = (bool)$r['allowed'];
            }
        } catch (\Throwable $e) {
            error_log('[AccessControl] permessi: ' . $e->getMessage());
        }

        return self::$permessi[$chiave] = $out;
    }

    /** Le due domande insieme: societa' + utente. */
    public function puo(int $userId, int $companyId, string $modulo): bool
    {
        if (!$this->societaHaModulo($companyId, $modulo)) {
            return false;
        }
        if ($userId === 1) {
            return true;
        }
        return !empty($this->permessi($userId, $companyId)[$modulo]);
    }

    /**
     * Riscrive i permessi di un utente in una societa'.
     *
     * @param array<string,mixed> $perms modulo => vero/falso
     */
    public function salvaPermessi(int $userId, int $companyId, array $perms): void
    {
        $this->conn->beginTransaction();
        try {
            $del = $this->conn->prepare('
                DELETE FROM bb_user_company_permissions
                WHERE user_id = :uid AND group_company_id = :cid
            ');
            $del->execute([':uid' => $userId, ':cid' => $companyId]);

            $ins = $this->conn->prepare('
                INSERT INTO bb_user_company_permissions (user_id, group_company_id, module, allowed)
                VALUES (:uid, :cid, :m, :a)
            ');
            foreach ($perms as $modulo => $allowed) {
                $ins->execute([
                    ':uid' => $userId,
                    ':cid' => $companyId,
                    ':m'   => $modulo,
                    ':a'   => $allowed ? 1 : 0,
                ]);
            }

            $this->aggiornaProiezione($userId);
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        unset(self::$permessi[$userId . ':' . $companyId], self::$permessi[$userId . ':0']);
    }

    /**
     * Rifa' bb_user_permissions dalla somma delle societa'.
     *
     * Quella tabella non e' piu' la fonte: e' il riassunto "ha il permesso
     * da qualche parte", che serve ai lavori notturni e alle mail di avviso,
     * dove non esiste una societa' attiva. Va riscritta a ogni salvataggio,
     * altrimenti le notifiche continuerebbero a seguire permessi vecchi.
     *
     * Unico punto che scrive quella tabella: se salta fuori un altro punto
     * che la scrive, e' un bug.
     */
    public function aggiornaProiezione(int $userId): void
    {
        $this->conn->prepare('DELETE FROM bb_user_permissions WHERE user_id = :uid')
                   ->execute([':uid' => $userId]);

        $this->conn->prepare('
            INSERT INTO bb_user_permissions (user_id, module, allowed)
            SELECT user_id, module, MAX(allowed)
            FROM   bb_user_company_permissions
            WHERE  user_id = :uid
            GROUP BY user_id, module
        ')->execute([':uid' => $userId]);
    }

    /**
     * Copia i permessi che l'utente ha altrove sulla societa' indicata, se
     * li' non ne ha ancora nessuno.
     *
     * Serve quando si assegna un utente a una nuova societa': senza questo
     * entrerebbe e non troverebbe niente, e chi lo ha assegnato penserebbe
     * a un guasto. Se dei permessi ci sono gia', non si tocca niente.
     */
    public function ereditaPermessi(int $userId, int $companyId): void
    {
        $stmt = $this->conn->prepare('
            SELECT COUNT(*) FROM bb_user_company_permissions
            WHERE user_id = :uid AND group_company_id = :cid
        ');
        $stmt->execute([':uid' => $userId, ':cid' => $companyId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $stmt = $this->conn->prepare('
            INSERT IGNORE INTO bb_user_company_permissions (user_id, group_company_id, module, allowed)
            SELECT user_id, :cid, module, allowed
            FROM   bb_user_permissions
            WHERE  user_id = :uid AND allowed = 1
        ');
        $stmt->execute([':uid' => $userId, ':cid' => $companyId]);

        unset(self::$permessi[$userId . ':' . $companyId]);
    }

    // ── Registro dei moduli ─────────────────────────────────────────────────

    /**
     * Tutti i codici modulo esistenti.
     *
     * L'elenco vero e proprio (etichette, icone, raggruppamenti) sta in
     * UsersController::buildPermissionGroups: tenerne una seconda copia qui
     * vorrebbe dire vederle divergere al primo modulo nuovo.
     *
     * @return array<string,bool>
     */
    public static function moduliEsistenti(): array
    {
        $out = [];
        foreach (\UsersController::buildPermissionGroups() as $g) {
            foreach (array_keys($g['perms']) as $codice) {
                $out[$codice] = true;
            }
        }
        return $out;
    }

    /** Societa' attiva nella richiesta corrente, 0 se non c'e'. */
    public static function societaAttiva(): int
    {
        return (int)($_SESSION[CurrentCompany::SESSION_KEY] ?? 0);
    }

    /** Svuota le cache: serve dopo un cambio societa' nella stessa richiesta. */
    public static function svuotaCache(): void
    {
        self::$moduliSocieta = [];
        self::$permessi      = [];
    }
}
