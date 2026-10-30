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
 *    ufficio, capo, operaio, cliente. L'accesso si da' a mano, da "Chi
 *    accede", **a tutti e quattro**: anche chi sta in ufficio entra in un
 *    cantiere solo se qualcuno ce lo mette.
 *
 *    Il modulo `zone` di BOB e' un'altra cosa: dice chi puo' DARE gli
 *    accessi ({@see gestisce()}), non chi li ha. Prima faceva le due cose
 *    insieme, e il risultato era che tutti quelli col modulo si
 *    ritrovavano dentro ogni cantiere, con le notifiche di ogni cantiere.
 *
 *    Il cliente conta solo se sul cantiere e' acceso "Condividi col
 *    cliente": senza, anche assegnato non vede niente.
 *
 * 2. **Questo contenuto, chi lo vede?**
 *    Non piu' qui: le attivita', le cartelle, i file, i disegni e i moduli
 *    si condividono coi nomi delle persone, e quello sta in
 *    {@see Condivisione}. Le etichette (ufficio, squadra, capi, cliente,
 *    tutti) sono rimaste alle sole **segnalazioni**: sono messaggi scritti
 *    di corsa dal telefono, e fermarsi a spuntare quindici nomi per dire
 *    che manca un tirante vorrebbe dire non scriverla.
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

        $ruolo = $r ? (string)$r['ruolo'] : null;
        // il cliente entra solo se l'ufficio ha acceso la condivisione
        if ($ruolo === self::CLIENTE && empty($r['zone_cliente'])) {
            $ruolo = null;
        }
        return $this->cache[$chiave] = $ruolo;
    }

    /**
     * Dell'ufficio **su questo cantiere**.
     *
     * Prima bastava avere il modulo `zone` e valeva dappertutto. Adesso
     * bisogna essere nell'elenco di quel cantiere col ruolo ufficio: in
     * ufficio il modulo ce l'hanno in otto, ma non tutti e otto seguono
     * tutti i cantieri, e soprattutto non tutti e otto vogliono le
     * notifiche di tutti i cantieri.
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

    // ── Chi vede cosa ───────────────────────────────────────────────────────

    /**
     * Con questo ruolo si vede un contenuto con questa visibilita'?
     *
     * Serve solo alle segnalazioni, le uniche che hanno ancora
     * un'etichetta. Tutto il resto passa da {@see Condivisione}.
     */
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

    // Qui stavano visibilitaConcesse() e visibilitaPer(), che dicevano quali
    // etichette un ruolo poteva mettere su una cosa che creava. Non le
    // chiama piu' nessuno: il limite ("un capo non la manda al cliente") lo
    // tiene Condivisione::predefinite() con i nomi, e le segnalazioni le
    // etichette le scelgono per conto loro.

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
