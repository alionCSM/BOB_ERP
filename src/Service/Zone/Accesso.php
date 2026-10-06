<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Http\Response;
use PDO;

/**
 * Chi puo' fare cosa dentro la Zone di un cantiere, e chi vede cosa.
 *
 * Un posto solo che risponde, perche' la domanda arriva da quaranta
 * endpoint — web e app passano dallo stesso controller — e un controllo
 * copiato quaranta volte e' un controllo che prima o poi in un punto manca.
 *
 * Due domande diverse:
 *
 * 1. **Che ruolo ha questa persona su questo cantiere?**
 *    ufficio (modulo `zone` in BOB: vede e fa tutto ovunque), capo,
 *    operaio, cliente. L'accesso lo da' l'ufficio, a mano, da "Chi accede".
 *    Il cliente conta solo se sul cantiere e' acceso "Condividi col
 *    cliente": senza, anche assegnato non vede niente.
 *
 * 2. **Questo contenuto, chi lo vede?**
 *    Ogni attivita', cartella, disegno, modulo ha una visibilita':
 *    ufficio, squadra, capi, cliente, tutti. Il filtro si fa qui sul
 *    server: quello che uno non deve vedere non arriva nemmeno al telefono.
 *
 * Una volta c'erano sei livelli per persona (per sezione); dicevano quali
 * sezioni uno apriva, non quali cose vedeva. Restano nel database finche'
 * le pagine nuove non sono in uso, ma non decidono piu' niente.
 */
final class Accesso
{
    public const NIENTE   = 0;
    public const VEDE     = 1;
    public const MODIFICA = 2;

    public const UFFICIO = 'ufficio';
    public const CAPO    = 'capo';
    public const OPERAIO = 'operaio';
    public const CLIENTE = 'cliente';

    /** Le visibilita' possibili, dalla piu' stretta alla piu' larga. */
    public const VISIBILITA = ['ufficio', 'squadra', 'capi', 'cliente', 'tutti'];

    /**
     * Chi vede cosa. L'ufficio vede sempre tutto e non sta in tabella.
     *
     * - squadra: capi e operai
     * - capi:    solo i capi (cose da coordinare che all'operaio non servono)
     * - cliente: ufficio e cliente (la squadra no)
     * - tutti:   tutti quelli che entrano nel cantiere
     */
    private const CHI_VEDE = [
        self::CAPO    => ['squadra', 'capi', 'tutti'],
        self::OPERAIO => ['squadra', 'tutti'],
        self::CLIENTE => ['cliente', 'tutti'],
    ];

    /**
     * Le sezioni della Zone, come si chiamano per chi le usa.
     *
     * @var array<string, string>
     */
    public const FAMIGLIE = [
        'attivita' => 'Attivita',
        'file'     => 'File e documenti',
        'moduli'   => 'Moduli',
        'disegni'  => 'Disegni',
        'foto'     => 'Foto',
        'report'   => 'Report',
    ];

    /**
     * Cosa apre e cosa tocca ogni ruolo, sezione per sezione. Il "tocca"
     * qui e' grosso: dentro, ogni azione ha la sua regola (l'operaio
     * commenta ma non crea attivita', solo l'ufficio verifica...).
     */
    private const SEZIONI = [
        self::CAPO    => ['attivita' => 2, 'file' => 2, 'moduli' => 2, 'disegni' => 1, 'foto' => 2, 'report' => 1],
        self::OPERAIO => ['attivita' => 2, 'file' => 1, 'moduli' => 2, 'disegni' => 1, 'foto' => 1, 'report' => 0],
        self::CLIENTE => ['attivita' => 2, 'file' => 1, 'moduli' => 2, 'disegni' => 1, 'foto' => 1, 'report' => 1],
    ];

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
        if ($this->daUfficio($utente)) {
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

        $ruolo = $r ? (string)$r['ruolo'] : null;
        // il cliente entra solo se l'ufficio ha acceso la condivisione
        if ($ruolo === self::CLIENTE && empty($r['zone_cliente'])) {
            $ruolo = null;
        }
        return $this->cache[$chiave] = $ruolo;
    }

    /** Uno dell'ufficio, con il modulo Zone di BOB? */
    public function daUfficio(?object $utente): bool
    {
        return $utente !== null
            && ((int)$utente->id === 1 || (bool)$utente->canAccess('zone'));
    }

    // ── Chi vede cosa ───────────────────────────────────────────────────────

    /** Con questo ruolo si vede un contenuto con questa visibilita'? */
    public static function vedeVisibilita(?string $ruolo, ?string $visibilita): bool
    {
        if ($ruolo === null) {
            return false;
        }
        if ($ruolo === self::UFFICIO) {
            return true;
        }
        return in_array($visibilita ?? 'squadra', self::CHI_VEDE[$ruolo] ?? [], true);
    }

    /**
     * Le visibilita' che un ruolo puo' dare a una cosa che crea. Il capo non
     * puo' mettere una cosa "solo ufficio" (non la vedrebbe piu' lui) ne'
     * mostrarla al cliente: quello lo decide l'ufficio.
     *
     * @return string[]
     */
    public static function visibilitaConcesse(string $ruolo): array
    {
        return match ($ruolo) {
            self::UFFICIO => self::VISIBILITA,
            self::CAPO    => ['squadra', 'capi'],
            self::OPERAIO => ['squadra'],
            self::CLIENTE => ['cliente'],
            default       => [],
        };
    }

    /** La visibilita' chiesta, se il ruolo puo' darla; se no quella di base. */
    public static function visibilitaPer(string $ruolo, ?string $chiesta): string
    {
        $concesse = self::visibilitaConcesse($ruolo);
        if ($chiesta !== null && in_array($chiesta, $concesse, true)) {
            return $chiesta;
        }
        return $concesse[0] ?? 'ufficio';
    }

    // ── Sezioni (come prima, ma dal ruolo) ──────────────────────────────────

    public function livello(?object $utente, int $worksiteId, string $famiglia): int
    {
        $ruolo = $this->ruolo($utente, $worksiteId);
        if ($ruolo === null) {
            return self::NIENTE;
        }
        if ($ruolo === self::UFFICIO) {
            return self::MODIFICA;
        }
        return self::SEZIONI[$ruolo][$famiglia] ?? self::NIENTE;
    }

    public function vede(?object $utente, int $worksiteId, string $famiglia): bool
    {
        return $this->livello($utente, $worksiteId, $famiglia) >= self::VEDE;
    }

    public function modifica(?object $utente, int $worksiteId, string $famiglia): bool
    {
        return $this->livello($utente, $worksiteId, $famiglia) >= self::MODIFICA;
    }

    /**
     * Pretende il livello, o chiude la richiesta.
     *
     * Risponde 403 in JSON perche' tutta la Zone, anche nel web, parla per
     * chiamate: la pagina e' un guscio e il contenuto arriva dopo.
     */
    public function pretende(?object $utente, int $worksiteId, string $famiglia, int $minimo): void
    {
        if ($this->livello($utente, $worksiteId, $famiglia) < $minimo) {
            self::nega($minimo >= self::MODIFICA
                ? 'Puoi guardare ma non modificare'
                : 'Non hai accesso a questa parte del cantiere');
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

    public static function nega(string $messaggio): never
    {
        Response::json(['success' => false, 'ok' => false, 'message' => $messaggio, 'error' => $messaggio], 403);
    }

    /** Vede almeno una sezione di questo cantiere? */
    public function qualcosa(?object $utente, int $worksiteId): bool
    {
        return $this->ruolo($utente, $worksiteId) !== null;
    }

    /**
     * Tutti i livelli di una persona su un cantiere, per l'app.
     *
     * @return array<string, int>
     */
    public function tutti(?object $utente, int $worksiteId): array
    {
        $fuori = [];
        foreach (array_keys(self::FAMIGLIE) as $f) {
            $fuori[$f] = $this->livello($utente, $worksiteId, $f);
        }
        return $fuori;
    }
}
