<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Http\Response;
use PDO;

/**
 * Chi puo' fare cosa dentro la Zone di un cantiere.
 *
 * Un posto solo che risponde alla domanda, perche' la domanda arriva da
 * quaranta endpoint diversi — web e app passano dallo stesso controller — e
 * un controllo copiato quaranta volte e' un controllo che prima o poi in un
 * punto manca. Quello e' il punto da cui si entra.
 *
 * Tre livelli e non un sì/no: "vede" e "vede e modifica" sono due cose
 * diverse, e un disegno che chiunque puo' annotare diventa illeggibile in
 * una settimana.
 *
 * Chi ha il modulo `zone` in BOB resta come prima: vede tutto ovunque. Sono
 * quelli dell'ufficio, ed e' il permesso che gia' decide cosa possono fare.
 * Gli accessi per cantiere servono a chi quel modulo non ce l'ha — i capi
 * squadra, gli operai — e per loro l'assegnazione e' l'unica porta.
 *
 * Niente a che vedere con la pianificazione: li' si decide chi lavora dove,
 * qui chi legge cosa. Uno puo' essere in squadra su un cantiere senza
 * vederne la Zone, e puo' vedere la Zone di un cantiere dove non mette piede.
 */
final class Accesso
{
    public const NIENTE   = 0;
    public const VEDE     = 1;
    public const MODIFICA = 2;

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

    /** @var array<string, array<string, int>> cache per richiesta: utente|cantiere */
    private array $cache = [];

    public function __construct(private PDO $conn) {}

    /**
     * Il livello di una persona su una famiglia di un cantiere.
     *
     * @param object|null $utente l'utente collegato
     */
    public function livello(?object $utente, int $worksiteId, string $famiglia): int
    {
        if (!$utente) {
            return self::NIENTE;
        }

        // L'ufficio non passa di qui: il modulo `zone` e' gia' la risposta,
        // e chiedergli anche l'assegnazione vorrebbe dire assegnare a mano
        // ogni impiegato a ogni cantiere.
        if ($this->daUfficio($utente)) {
            return self::MODIFICA;
        }

        $riga = $this->riga((int)$utente->id, $worksiteId);
        return (int)($riga[$famiglia] ?? self::NIENTE);
    }

    /** Vede almeno? */
    public function vede(?object $utente, int $worksiteId, string $famiglia): bool
    {
        return $this->livello($utente, $worksiteId, $famiglia) >= self::VEDE;
    }

    /** Puo' anche toccare? */
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
            Response::json([
                'success' => false,
                'message' => $minimo >= self::MODIFICA
                    ? 'Puoi guardare ma non modificare'
                    : 'Non hai accesso a questa parte del cantiere',
            ], 403);
        }
    }

    /** Uno dell'ufficio, con il modulo Zone di BOB? */
    public function daUfficio(?object $utente): bool
    {
        return $utente !== null
            && ((int)$utente->id === 1 || (bool)$utente->canAccess('zone'));
    }

    /**
     * Vede almeno una sezione di questo cantiere?
     *
     * Serve per decidere se la pagina si apre: dentro, ogni sezione si
     * difende da sola, ma chi non vede proprio niente non deve nemmeno
     * trovarsi davanti un guscio vuoto.
     */
    public function qualcosa(?object $utente, int $worksiteId): bool
    {
        foreach (array_keys(self::FAMIGLIE) as $f) {
            if ($this->livello($utente, $worksiteId, $f) >= self::VEDE) {
                return true;
            }
        }
        return false;
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

    /** @return array<string, int> */
    private function riga(int $userId, int $worksiteId): array
    {
        $chiave = $userId . '|' . $worksiteId;
        if (isset($this->cache[$chiave])) {
            return $this->cache[$chiave];
        }

        $campi = '`' . implode('`, `', array_keys(self::FAMIGLIE)) . '`';
        $stmt  = $this->conn->prepare(
            "SELECT $campi FROM bb_zone_accessi WHERE user_id = :u AND worksite_id = :w"
        );
        $stmt->execute([':u' => $userId, ':w' => $worksiteId]);

        $riga = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return $this->cache[$chiave] = array_map('intval', $riga);
    }
}
