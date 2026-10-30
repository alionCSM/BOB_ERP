<?php

declare(strict_types=1);

namespace App\Service\Zone;

/**
 * Chi puo' fare cosa nella Zone. Una tabella, e basta.
 *
 * Prima la stessa domanda aveva sei risposte sparse: il ruolo
 * sull'accesso, sei livelli per sezione rimasti nel database, le
 * condivisioni, `assegnata_a` sulle attivita', `a_ruolo` sui moduli, la
 * visibilita' delle segnalazioni. Nessuno dei sei sapeva degli altri, e
 * per sapere se un operaio poteva chiudere un'attivita' bisognava leggere
 * quattro file.
 *
 * Adesso c'e' questa tabella. Tre ruoli, un elenco di azioni, una riga
 * per riga leggibile da chiunque — anche da chi non scrive codice.
 *
 * **Chi vede cosa e' un'altra domanda**, e la risponde
 * {@see Condivisione}: li' si scelgono le persone, oggetto per oggetto.
 * Questa tabella dice cosa uno puo' FARE con quello che vede. Tenerle
 * separate e' il motivo per cui si capiscono: una e' "a chi lo mostro",
 * l'altra e' "chi lo tocca".
 */
final class Permessi
{
    // ── I ruoli ─────────────────────────────────────────────────────────────

    /** Noi: vede tutto il cantiere, decide chi accede, verifica, cancella. */
    public const UFFICIO = 'ufficio';

    /**
     * Chi lavora in cantiere.
     *
     * Non si chiama piu' "capo squadra" ne' "operaio": nella Zone non ci
     * entrano tutti gli operai, ci entra chi gli diamo l'accesso, e quello
     * e' il capo. Due ruoli che facevano le stesse cose erano un ruolo
     * solo scritto due volte.
     */
    public const CANTIERE = 'cantiere';

    /**
     * Il committente.
     *
     * Non e' uno che guarda: sui nostri cantieri il committente dirige, e
     * fa quasi tutto quello che facciamo noi. Quello che NON fa e' entrare
     * nelle nostre cose — e quello non lo decide il ruolo, lo decide cosa
     * gli condividiamo.
     */
    public const CLIENTE = 'cliente';

    public const RUOLI = [
        self::UFFICIO  => 'Ufficio',
        self::CANTIERE => 'Cantiere',
        self::CLIENTE  => 'Cliente',
    ];

    // ── Le azioni ───────────────────────────────────────────────────────────

    /** Aprire il cantiere e leggere quello che gli e' condiviso. */
    public const VEDERE = 'vedere';

    /** Scrivere: commenti, foto, messaggi, segnalazioni nuove. */
    public const SCRIVERE = 'scrivere';

    /** Compilare un modulo che gli e' stato assegnato. */
    public const COMPILARE = 'compilare';

    /** Dire che un'attivita' e' fatta, o che una segnalazione e' risolta. */
    public const COMPLETARE = 'completare';

    /** Caricare file e foto nelle cartelle. */
    public const CARICARE = 'caricare';

    /** Creare un'attivita' e dire a chi tocca. */
    public const ATTIVITA = 'attivita';

    /** Caricare un disegno nuovo (annotarlo e' un'altra cosa, vedi sotto). */
    public const DISEGNI = 'disegni';

    /** Mettere uno spillo su un disegno: una nota, un'attivita', un problema. */
    public const ANNOTARE = 'annotare';

    /** Decidere quale modulo si compila, chi lo compila e ogni quanto. */
    public const MODULI = 'moduli';

    /** Verificare: chiudere un'attivita' per davvero. L'ultima parola. */
    public const VERIFICARE = 'verificare';

    /** Cancellare roba scritta da altri. Le proprie si cancellano sempre. */
    public const CANCELLARE = 'cancellare';

    /** Decidere chi accede al cantiere e chi vede cosa. */
    public const GESTIRE = 'gestire';

    /**
     * La tabella.
     *
     * Ufficio e Cliente si somigliano, ed e' voluto: sui nostri cantieri
     * il committente dirige. Le due differenze sono l'ultima parola sulle
     * attivita' (la verifica) e il poter cancellare quello che hanno
     * scritto altri — due cose che restano a noi.
     *
     * Cantiere e' il ruolo stretto: non apre lavoro nuovo (non crea
     * attivita', non carica disegni, non decide i moduli) ma fa tutto
     * quello che serve per portarlo avanti.
     *
     * @var array<string, array<string, bool>>
     */
    private const TABELLA = [
        self::VEDERE => [
            self::UFFICIO => true, self::CANTIERE => true, self::CLIENTE => true,
        ],
        self::SCRIVERE => [
            self::UFFICIO => true, self::CANTIERE => true, self::CLIENTE => true,
        ],
        self::COMPILARE => [
            self::UFFICIO => true, self::CANTIERE => true, self::CLIENTE => true,
        ],
        self::COMPLETARE => [
            self::UFFICIO => true, self::CANTIERE => true, self::CLIENTE => true,
        ],
        self::CARICARE => [
            self::UFFICIO => true, self::CANTIERE => true, self::CLIENTE => true,
        ],
        // Annotare un disegno lo fa anche chi e' in cantiere: appuntare
        // "qui manca un tirante" sul punto giusto e' la ragione per cui il
        // disegno sta nel telefono.
        self::ANNOTARE => [
            self::UFFICIO => true, self::CANTIERE => true, self::CLIENTE => true,
        ],

        self::ATTIVITA => [
            self::UFFICIO => true, self::CANTIERE => false, self::CLIENTE => true,
        ],
        self::DISEGNI => [
            self::UFFICIO => true, self::CANTIERE => false, self::CLIENTE => true,
        ],
        self::MODULI => [
            self::UFFICIO => true, self::CANTIERE => false, self::CLIENTE => true,
        ],

        self::VERIFICARE => [
            self::UFFICIO => true, self::CANTIERE => false, self::CLIENTE => false,
        ],
        self::CANCELLARE => [
            self::UFFICIO => true, self::CANTIERE => false, self::CLIENTE => false,
        ],
        self::GESTIRE => [
            self::UFFICIO => true, self::CANTIERE => false, self::CLIENTE => false,
        ],
    ];

    /**
     * Questo ruolo puo' fare questa cosa?
     *
     * Un ruolo che non c'e' o un'azione che non c'e' rispondono no. Non e'
     * prudenza inutile: il ruolo arriva dal database e l'azione da una
     * mappa scritta a mano, e il giorno che uno dei due si scrive male
     * deve chiudersi, non aprirsi.
     */
    public static function puo(?string $ruolo, string $azione): bool
    {
        return (bool)(self::TABELLA[$azione][$ruolo ?? ''] ?? false);
    }

    /**
     * Tutto quello che un ruolo puo' fare, per l'app e per le pagine: un
     * bottone che risponde 403 sembra un guasto, meglio non disegnarlo.
     *
     * @return array<string, bool>
     */
    public static function tutte(?string $ruolo): array
    {
        $fuori = [];
        foreach (array_keys(self::TABELLA) as $azione) {
            $fuori[$azione] = self::puo($ruolo, $azione);
        }
        return $fuori;
    }

    /** @return string[] le azioni esistenti, per i controlli automatici */
    public static function azioni(): array
    {
        return array_keys(self::TABELLA);
    }
}
