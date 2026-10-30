<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Http\Response;
use PDO;

/**
 * Chi entra nella Zone di un cantiere, e con che ruolo.
 *
 * Qui si risponde a **una domanda sola**: che ruolo ha questa persona su
 * questo cantiere — ufficio, cantiere, cliente, o nessuno. L'accesso si da'
 * a mano, da "Chi accede", a tutti e tre: anche chi sta in ufficio entra in
 * un cantiere solo se qualcuno ce lo mette.
 *
 * Il modulo `zone` di BOB e' un'altra cosa ({@see gestisce()}): dice chi
 * puo' DARE gli accessi, non chi li ha.
 *
 * Il cliente conta solo se sul cantiere e' acceso "Condividi col cliente":
 * senza, anche assegnato non vede niente.
 *
 * Le altre due domande stanno altrove, ed e' il motivo per cui adesso si
 * capiscono:
 *
 * - **cosa puo' fare un ruolo** → {@see Permessi}, una tabella sola;
 * - **chi vede una cosa** → {@see Condivisione}, per nome, oggetto per
 *   oggetto.
 *
 * Una volta qui dentro c'erano anche sei livelli per persona per sezione e
 * cinque etichette di visibilita'. Non li legge piu' nessuno: le colonne
 * restano nel database finche' non si e' sicuri, ma non decidono niente.
 */
final class Accesso
{
    /** I tre ruoli: stessi nomi di {@see Permessi}, scritti una volta sola. */
    public const UFFICIO  = Permessi::UFFICIO;
    public const CANTIERE = Permessi::CANTIERE;
    public const CLIENTE  = Permessi::CLIENTE;

    /**
     * Le etichette vecchie delle segnalazioni, tradotte.
     *
     * Le segnalazioni hanno ancora una visibilita' scritta a parole
     * (squadra, capi...) perche' sono messaggi: si scrivono di corsa dal
     * telefono e nessuno si fermerebbe a spuntare quindici nomi. Le parole
     * di prima restano nel database, e qui si traducono nei tre ruoli.
     *
     * @var array<string, string[]> etichetta => ruoli che la vedono
     */
    private const CHI_VEDE = [
        'ufficio'  => [],
        'squadra'  => [self::CANTIERE],
        'capi'     => [self::CANTIERE],
        'cantiere' => [self::CANTIERE],
        'cliente'  => [self::CLIENTE],
        'tutti'    => [self::CANTIERE, self::CLIENTE],
    ];

    /** Le visibilita' che si possono scegliere su una segnalazione. */
    public const VISIBILITA = ['ufficio', 'cantiere', 'cliente', 'tutti'];

    /** @var array<string, ?string> cache per richiesta: utente|cantiere => ruolo */
    private array $cache = [];

    public function __construct(private PDO $conn) {}

    // ── Ruolo ───────────────────────────────────────────────────────────────

    /**
     * Il ruolo di una persona su un cantiere, o null se non entra.
     *
     * @param object|null $utente l'utente collegato
     */
    public function ruolo(?object $utente, int $worksiteId): ?string
    {
        if (!$utente) {
            return null;
        }
        // Il superadmin resta dentro ovunque: e' l'unico modo di non
        // chiudersi fuori da un cantiere in cui non c'e' rimasto nessuno.
        if ((int)$utente->id === 1) {
            return self::UFFICIO;
        }

        $chiave = (int)$utente->id . '|' . $worksiteId;
        if (array_key_exists($chiave, $this->cache)) {
            return $this->cache[$chiave];
        }

        $stmt = $this->conn->prepare("
            SELECT a.ruolo, w.zone_cliente
            FROM   bb_zone_accessi a
            JOIN   bb_worksites w ON w.id = a.worksite_id
            WHERE  a.user_id = :u AND a.worksite_id = :w
        ");
        $stmt->execute([':u' => (int)$utente->id, ':w' => $worksiteId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        $ruolo = $r ? $this->normalizza((string)$r['ruolo']) : null;
        // il cliente entra solo se l'ufficio ha acceso la condivisione
        if ($ruolo === self::CLIENTE && empty($r['zone_cliente'])) {
            $ruolo = null;
        }
        return $this->cache[$chiave] = $ruolo;
    }

    /**
     * I ruoli vecchi letti come quelli nuovi.
     *
     * La migrazione li converte, ma una riga scritta da una versione piu'
     * vecchia del codice — o un database non ancora migrato — non deve
     * lasciare fuori una persona: capo e operaio sono Cantiere.
     */
    public function normalizza(string $ruolo): ?string
    {
        return match ($ruolo) {
            'capo', 'operaio', self::CANTIERE => self::CANTIERE,
            self::UFFICIO                     => self::UFFICIO,
            self::CLIENTE                     => self::CLIENTE,
            default                           => null,
        };
    }

    /**
     * Dell'ufficio **su questo cantiere**.
     *
     * Non "chi ha il modulo Zone": quello vale ovunque e non apre niente.
     */
    public function daUfficio(?object $utente, int $worksiteId): bool
    {
        return $this->ruolo($utente, $worksiteId) === self::UFFICIO;
    }

    /**
     * Chi puo' DARE gli accessi: il modulo `zone` di BOB.
     *
     * Non apre niente da solo. Chi ce l'ha e non e' nell'elenco di un
     * cantiere, di quella Zone vede soltanto "Chi accede" — abbastanza per
     * aggiungersi o aggiungere qualcuno, niente di piu'. Serve a non
     * chiudere fuori nessuno: senza, un cantiere in cui per sbaglio non
     * resta nessuno dell'ufficio non lo riaprirebbe piu' nessuno.
     */
    public function gestisce(?object $utente): bool
    {
        return $utente !== null
            && ((int)$utente->id === 1 || (bool)$utente->canAccess('zone'));
    }

    /** Entra in questo cantiere, con un ruolo qualsiasi? */
    public function qualcosa(?object $utente, int $worksiteId): bool
    {
        return $this->ruolo($utente, $worksiteId) !== null;
    }

    // ── Cosa puo' fare ──────────────────────────────────────────────────────

    /** Questa persona, su questo cantiere, puo' fare questa cosa? */
    public function puo(?object $utente, int $worksiteId, string $azione): bool
    {
        return Permessi::puo($this->ruolo($utente, $worksiteId), $azione);
    }

    /** Lo pretende, o chiude la richiesta. */
    public function pretende(?object $utente, int $worksiteId, string $azione): void
    {
        if ($this->ruolo($utente, $worksiteId) === null) {
            self::nega('Non sei fra chi accede a questo cantiere');
        }
        if (!$this->puo($utente, $worksiteId, $azione)) {
            self::nega('Il tuo ruolo in questo cantiere non lo prevede');
        }
    }

    /** Pretende uno di questi ruoli (l'ufficio passa sempre). */
    public function pretendeRuolo(?object $utente, int $worksiteId, array $ruoli): string
    {
        $ruolo = $this->ruolo($utente, $worksiteId);
        if ($ruolo === null || ($ruolo !== self::UFFICIO && !in_array($ruolo, $ruoli, true))) {
            self::nega('Non hai accesso a questa parte del cantiere');
        }
        return $ruolo;
    }

    // ── Segnalazioni: le etichette, tradotte ────────────────────────────────

    /**
     * Con questo ruolo si vede una segnalazione con questa etichetta?
     *
     * Serve solo alle segnalazioni. Tutto il resto passa da
     * {@see Condivisione}, che sceglie le persone per nome.
     */
    public static function vedeVisibilita(?string $ruolo, ?string $visibilita): bool
    {
        if ($ruolo === null) {
            return false;
        }
        if ($ruolo === self::UFFICIO) {
            return true;
        }
        return in_array($ruolo, self::CHI_VEDE[$visibilita ?? 'cantiere'] ?? [], true);
    }

    public static function nega(string $messaggio): never
    {
        Response::json(['success' => false, 'ok' => false, 'message' => $messaggio, 'error' => $messaggio], 403);
    }
}
