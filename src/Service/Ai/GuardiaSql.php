<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Il controllo sulla query prima di eseguirla.
 *
 * Il modello e' bravo ma non e' un'autorita': quello che scrive passa da qui
 * prima di toccare il database, e qui si decide di no.
 *
 * Tre cambiamenti rispetto al controllo di prima:
 *
 * 1. **Lista positiva al posto di lista negativa.** Prima c'era un elenco di
 *    tabelle vietate: tutto il resto era interrogabile, e una tabella nuova
 *    nasceva aperta. Ora si parte dalle tabelle che l'utente puo' vedere
 *    (CatalogoDati, filtrato sui suoi moduli) e si rifiuta tutto il resto.
 *    Una tabella nuova nasce chiusa.
 *
 * 2. **Le stringhe non sono codice.** Prima le parole vietate si cercavano
 *    nel testo della query intero, virgolette comprese: un cantiere che si
 *    chiama "Deposito Secret" faceva rifiutare la query perche' dentro
 *    c'era `secret`, e cercare un'azienda con "Delete" nel nome era
 *    impossibile. Qui le stringhe letterali e i commenti si togliono prima
 *    di guardare le parole, e i commenti SQL fanno rifiutare la query: in
 *    una query generata non servono, e sono il modo classico di nascondere
 *    roba a un controllo per parole.
 *
 * 3. **Il LIMIT si mette d'ufficio.** Se manca, lo aggiunge il guardiano:
 *    una SELECT senza limite su una tabella da milioni di righe e' il modo
 *    piu' facile di mettere in ginocchio il database.
 */
final class GuardiaSql
{
    /** Oltre questo numero di righe non si va, qualunque cosa chieda la query. */
    public const MAX_RIGHE = 500;

    /**
     * Colonne che non si leggono mai, nemmeno da superadmin. Confrontate con
     * gli identificatori veri, non col testo della query.
     */
    private const COLONNE_VIETATE = [
        'password', 'password_hash', 'pwd', 'token', 'auth_token',
        'remember_token', 'secret', 'api_key', 'private_key',
    ];

    /** Colonne economiche: visibili solo a chi ha il permesso view_prices. */
    public const COLONNE_PREZZO = [
        'importo', 'total', 'totale', 'totale_imponibile', 'costo', 'costo_unitario',
        'prezzo', 'prezzo_persona', 'ricavo', 'margine', 'budget',
        'ext_total_offer', 'total_offer', 'imponibile', 'aliquota_iva',
    ];

    /** Parole che in una SELECT non hanno niente da fare. */
    private const PAROLE_VIETATE = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'rename', 'grant', 'revoke', 'call', 'handler', 'exec',
        'execute', 'prepare', 'deallocate', 'lock', 'unlock', 'set',
        'outfile', 'dumpfile', 'load_file', 'load', 'into',
        'extractvalue', 'updatexml', 'benchmark', 'sleep', 'get_lock',
        'sys_exec', 'sys_eval',
    ];

    /**
     * @param list<string> $tabellePermesse nomi di tabella che questo utente puo' leggere
     * @param list<string> $tabelleDelDb    tutte le tabelle che esistono nel database;
     *                                      serve a non confondere una colonna con una
     *                                      tabella (bb_billing_id e' una colonna). Vuoto
     *                                      = si ricade sul controllo per prefisso, piu'
     *                                      severo.
     */
    public function __construct(
        private array $tabellePermesse,
        private bool $vedePrezzi,
        private array $tabelleDelDb = [],
    ) {}

    /**
     * Controlla la query e restituisce quella da eseguire (col LIMIT messo
     * a posto), oppure il motivo del rifiuto.
     *
     * Il motivo e' scritto per essere rimandato al modello, che con quello
     * riprova: va bene che sia tecnico.
     *
     * @return array{ok:true, sql:string}|array{ok:false, errore:string}
     */
    public function controlla(string $sql): array
    {
        $sql = trim(rtrim(trim($sql), ';'));

        if ($sql === '') {
            return $this->no('Query vuota.');
        }

        if (!preg_match('/^\s*(SELECT|WITH)\s/i', $sql)) {
            return $this->no('Sono ammesse solo query SELECT.');
        }

        if (str_contains($sql, ';')) {
            return $this->no('Una query alla volta: togli il punto e virgola.');
        }

        if (preg_match('#(--|\#|/\*)#', $sql)) {
            return $this->no('I commenti SQL non sono ammessi: scrivi la query senza commenti.');
        }

        // SELECT * non si usa, e non e' una questione di stile.
        //
        // Con l'asterisco i nomi delle colonne non compaiono nella query,
        // quindi il controllo sui dati economici qui sotto non le vedrebbe
        // passare: un `SELECT * FROM bb_billing` tirerebbe fuori gli importi
        // a chi non puo' vederli. Oltre a questo, una tabella con trenta
        // colonne non si legge: al modello e' chiesto di elencare le colonne
        // che servono e di dargli un nome in italiano.
        if (preg_match('/\*/', (string)preg_replace('/\bcount\s*\(\s*\*\s*\)/i', '', $sql))) {
            return $this->no(
                'SELECT * non e\' ammesso: elenca le colonne che ti servono, '
                . 'ognuna con il suo alias AS in italiano. COUNT(*) va bene.'
            );
        }

        // Da qui in poi si guarda il codice senza le stringhe: cosi' un nome
        // che contiene una parola vietata non fa rifiutare la query.
        $codice = $this->senzaStringhe($sql);

        foreach (self::PAROLE_VIETATE as $parola) {
            if (preg_match('/\b' . preg_quote($parola, '/') . '\b/i', $codice)) {
                return $this->no("La parola '{$parola}' non e' ammessa in una query di sola lettura.");
            }
        }

        // ── Tabelle ──────────────────────────────────────────────────────────
        $riferite = $this->tabelleRiferite($codice);

        if ($riferite === []) {
            return $this->no('Non riesco a capire da quale tabella leggi: scrivi un FROM esplicito.');
        }

        // I nomi definiti da un WITH sono tabelle temporanee della query
        // stessa, non tabelle del database: vanno trattati come permessi,
        // altrimenti `WITH giornate AS (...) SELECT ... FROM giornate` viene
        // rifiutato per una tabella "giornate" che non esiste da nessuna
        // parte. Servono per domande come "giornate dei nostri piu' quelle
        // delle consorziate", che senza CTE diventano illeggibili.
        //
        // Con un'eccezione che va chiusa: in MySQL il nome di una CTE non e'
        // visibile dentro la propria definizione, quindi
        //   WITH bb_users AS (SELECT username FROM bb_users) SELECT ...
        // legge la tabella vera, e il nome del WITH la farebbe passare per
        // temporanea. Un nome di WITH che si chiama come una tabella del
        // database non si accetta, e basta.
        $with = $this->nomiDeiWith($codice);

        foreach ($with as $nome) {
            if (in_array($nome, $this->tabelleDelDb, true)) {
                return $this->no(
                    "Il nome '{$nome}' in un WITH non va bene: si chiama come una tabella "
                    . 'del database. Dai alla tabella temporanea un nome diverso.'
                );
            }
        }

        $ammesse = array_merge($this->tabellePermesse, $with);

        foreach ($riferite as $tabella) {
            if (in_array($tabella, $ammesse, true)) {
                continue;
            }

            if (in_array($tabella, CatalogoDati::tabelleAmmesse(), true)) {
                $modulo = CatalogoDati::moduloDi($tabella);
                return $this->no(
                    "Non hai accesso a {$tabella}"
                    . ($modulo !== null ? " (serve il modulo '{$modulo}')" : '')
                    . ': non e\' fra le tabelle che puoi interrogare.'
                );
            }

            return $this->no("La tabella {$tabella} non e' interrogabile da BOB AI.");
        }

        // ── Colonne ──────────────────────────────────────────────────────────
        $identificatori = $this->identificatori($codice);

        foreach (self::COLONNE_VIETATE as $colonna) {
            if (in_array($colonna, $identificatori, true)) {
                return $this->no("La colonna '{$colonna}' non e' leggibile.");
            }
        }

        if (!$this->vedePrezzi) {
            foreach (self::COLONNE_PREZZO as $colonna) {
                if (in_array($colonna, $identificatori, true)) {
                    return $this->no('Non hai i permessi per vedere i dati economici.');
                }
            }
        }

        return ['ok' => true, 'sql' => $this->conLimite($sql)];
    }

    /** @return array{ok:false, errore:string} */
    private function no(string $motivo): array
    {
        return ['ok' => false, 'errore' => $motivo];
    }

    /**
     * La query con le stringhe letterali sostituite da un segnaposto, cosi'
     * quello che resta e' solo codice SQL e nomi.
     */
    private function senzaStringhe(string $sql): string
    {
        // Prima le stringhe fra virgolette (singole o doppie), gestendo
        // l'escape col backslash e il raddoppio ('' e "").
        $pulito = preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",
                '/"(?:[^"\\\\]|\\\\.|"")*"/s',
            ],
            " '?' ",
            $sql
        );

        return $pulito ?? $sql;
    }

    /**
     * Le tabelle da cui la query legge davvero.
     *
     * Due passaggi che si coprono a vicenda: quello che sta dopo FROM e JOIN,
     * e in piu' qualunque identificatore che somigli a una tabella di BOB. Il
     * secondo prende i casi che il primo non sa leggere — e in un controllo
     * di sicurezza preferisco rifiutare una query buona che far passare una
     * cattiva.
     *
     * @return list<string>
     */
    private function tabelleRiferite(string $codice): array
    {
        $out = [];

        // dopo FROM / JOIN, anche con piu' tabelle separate da virgola
        if (preg_match_all('/\b(?:from|join)\s+((?:[`\w.]+\s*,\s*)*[`\w.]+)/i', $codice, $m)) {
            foreach ($m[1] as $lista) {
                foreach (explode(',', $lista) as $pezzo) {
                    $nome = $this->nomeTabella($pezzo);
                    if ($nome !== null) {
                        $out[] = $nome;
                    }
                }
            }
        }

        // Rete di sicurezza: ogni nome che sembra una tabella dell'applicazione.
        // Si tiene solo se e' una tabella che esiste davvero — altrimenti
        // bb_billing_id, che e' una colonna, verrebbe preso per una tabella e
        // farebbe rifiutare una query buona.
        if (preg_match_all('/\b((?:bb|pn)_[a-z0-9_]+)\b/i', $codice, $m)) {
            foreach ($m[1] as $nome) {
                $nome = strtolower($nome);
                if ($this->tabelleDelDb === [] || in_array($nome, $this->tabelleDelDb, true)) {
                    $out[] = $nome;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * I nomi delle tabelle temporanee definite da un WITH.
     *
     * `nome AS (` e' una forma che in SQL ha un solo significato: la
     * definizione di una CTE. Un alias di colonna non e' mai seguito da una
     * parentesi aperta.
     *
     * @return list<string>
     */
    private function nomiDeiWith(string $codice): array
    {
        if (!preg_match('/^\s*with\s/i', $codice)) {
            return [];
        }

        if (!preg_match_all('/\b([a-z_][a-z0-9_]*)\s+as\s*\(/i', $codice, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    private function nomeTabella(string $pezzo): ?string
    {
        $pezzo = trim(str_replace('`', '', $pezzo));
        if ($pezzo === '' || str_starts_with($pezzo, '(')) {
            return null; // sottoquery: le sue tabelle le prende il FROM interno
        }

        // schema.tabella → tabella conta, ma lo schema va segnalato: se
        // qualcuno scrive information_schema.columns lo vogliamo rifiutare
        // sul nome intero, non sul pezzo finale.
        if (str_contains($pezzo, '.')) {
            return strtolower($pezzo);
        }

        return strtolower($pezzo);
    }

    /**
     * I nomi delle colonne lette dalla query.
     *
     * Gli alias dopo AS si buttano via prima di guardare, e non e' un
     * dettaglio: al modello si chiede di dare un nome italiano a ogni
     * colonna, e un `COUNT(*) AS totale` su una domanda come "quanti
     * cantieri" avrebbe fatto scattare il blocco sui dati economici —
     * 'totale' e' nell'elenco delle colonne di soldi. L'utente si vedeva
     * rifiutare "quanti cantieri abbiamo" per mancanza di permessi
     * economici. Il nome vero della colonna, quello che conta, compare
     * comunque prima dell'AS.
     *
     * @return list<string>
     */
    private function identificatori(string $codice): array
    {
        $codice = (string)preg_replace('/\bas\s+`?[a-z_][a-z0-9_]*`?/i', ' ', $codice);
        $codice = str_replace(['`', '.'], [' ', ' '], $codice);
        preg_match_all('/\b[a-z_][a-z0-9_]*\b/i', $codice, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    /**
     * Mette un LIMIT se manca, e lo abbassa se e' troppo alto.
     *
     * Non si tocca il LIMIT dentro una sottoquery: si guarda solo se la query
     * finisce con un LIMIT suo.
     */
    private function conLimite(string $sql): string
    {
        if (preg_match('/\blimit\s+(\d+)\s*(?:,\s*(\d+)\s*)?$/i', $sql, $m)) {
            $quante = (int)($m[2] ?? $m[1]);
            if ($quante <= self::MAX_RIGHE) {
                return $sql;
            }
            return (string)preg_replace('/\blimit\s+\d+\s*(?:,\s*\d+\s*)?$/i', 'LIMIT ' . self::MAX_RIGHE, $sql);
        }

        return $sql . ' LIMIT ' . self::MAX_RIGHE;
    }
}
