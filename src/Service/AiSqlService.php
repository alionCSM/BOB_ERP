<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Ai\CatalogoDati;
use App\Service\Ai\GuardiaSql;
use App\Service\Ai\Risposta;
use PDO;

/**
 * BOB AI: dalla domanda in italiano alla risposta.
 *
 * Il giro e' questo, e ogni pezzo e' fatto da chi lo sa fare meglio:
 *
 *   domanda → [modello] una query → [guardia] la query e' lecita?
 *           → [database] le righe → [codice] la risposta formattata
 *
 * Il modello viene chiamato **una volta** e gli si chiede **una riga di
 * JSON**: la query piu' un titolo. Prima gli si chiedeva la query *e* la
 * risposta in italiano, poi la risposta veniva buttata (la tabella la
 * rifaceva il PHP) e si era pagata la generazione di un testo che nessuno
 * leggeva. Con 30 miliardi di parametri su una macchina in ufficio, ogni
 * token generato per niente sono decine di millisecondi regalati.
 *
 * Cosa e' cambiato rispetto a prima, in ordine di quanto si sente:
 *
 * - **Lo schema non si manda piu' tutto.** Era l'intero INFORMATION_SCHEMA a
 *   ogni domanda: oltre cento tabelle da rileggere da capo ogni volta. Ora
 *   ne vanno 4-6, scelte in base alla domanda (CatalogoDati).
 * - **Niente ragionamento.** Il modello scriveva `<think>...</think>` per
 *   migliaia di token prima di arrivare al punto.
 * - **Il contesto dei turni passati e' una riga, non una tabella.** Prima si
 *   rimandava indietro la tabella markdown intera, duecento righe di dati
 *   che spingevano fuori lo schema.
 * - **Se la query sbaglia, si riprova una volta** passando al modello
 *   l'errore di MySQL. Prima un errore di sintassi o una colonna inventata
 *   diventavano "Errore durante l'esecuzione della query" e l'utente doveva
 *   indovinare come riformulare. La maggior parte degli errori si aggiusta
 *   al secondo tentativo, e costa meno di una domanda rifatta a mano.
 */
final class AiSqlService
{
    /** Quanto puo' durare una query prima che MySQL la fermi. */
    private const TIMEOUT_QUERY_MS = 15000;

    /** Quanti tentativi in tutto (il primo piu' i rifacimenti). */
    private const MAX_TENTATIVI = 2;

    private CatalogoDati $catalogo;

    public function __construct(
        private PDO $conn,
        private OllamaClient $client,
        ?CatalogoDati $catalogo = null,
    ) {
        $this->catalogo = $catalogo ?? new CatalogoDati($conn);
    }

    /**
     * @param array{
     *   username:string, full_name:string, is_superadmin:bool,
     *   allowed_modules:list<string>|null, can_see_prices:bool
     * } $utente
     * @param list<array{role:string, content:string}> $contesto turni precedenti
     * @param list<string> $tabelleRecenti tabelle usate prima in questa chat
     * @param null|callable(string, array):void $onProgresso fase, dati
     *
     * @return array{
     *   ok:bool, answer?:string, riassunto?:string, query?:?string,
     *   righe?:int, tabelle?:list<string>, error?:string
     * }
     */
    public function chiedi(
        string $domanda,
        array $utente,
        array $contesto = [],
        array $tabelleRecenti = [],
        ?callable $onProgresso = null,
    ): array {
        $avvisa = $onProgresso ?? static function (): void {};

        $vedePrezzi = (bool)($utente['can_see_prices'] ?? false);
        $moduli     = $utente['allowed_modules'] ?? [];
        $permesse   = CatalogoDati::tabellePerUtente(
            ($utente['is_superadmin'] ?? false) ? null : $moduli
        );

        if ($permesse === []) {
            return $this->ko('Non hai accesso a nessun modulo di cui BOB AI possa leggere i dati.');
        }

        $guardia = new GuardiaSql($permesse, $vedePrezzi, $this->catalogo->tabelleDelDatabase());

        $catalogo = $this->catalogo->perDomanda($domanda, $permesse, $tabelleRecenti);
        $extra    = [];   // messaggi aggiunti al modello fra un tentativo e l'altro
        $chiesta  = null; // tabella richiesta con "serve_tabella", una volta sola

        for ($tentativo = 1; $tentativo <= self::MAX_TENTATIVI; $tentativo++) {
            $avvisa($tentativo === 1 ? 'penso' : 'riprovo', ['tentativo' => $tentativo]);

            $messaggi = $this->messaggi($domanda, $utente, $catalogo['testo'], $contesto, $extra);
            $esito    = $this->generaSql($messaggi, $avvisa);

            if (!$esito['ok']) {
                return $this->ko($esito['error']);
            }

            $decisione = $this->leggiDecisione($esito['response']);

            // ── Il modello non vuole fare una query ──────────────────────────
            if (isset($decisione['risposta'])) {
                return [
                    'ok'        => true,
                    'answer'    => (string)$decisione['risposta'],
                    'riassunto' => mb_substr((string)$decisione['risposta'], 0, 200),
                    'query'     => null,
                    'righe'     => 0,
                    'tabelle'   => [],
                ];
            }

            if (isset($decisione['chiarimento'])) {
                return [
                    'ok'        => true,
                    'answer'    => (string)$decisione['chiarimento'],
                    'riassunto' => 'Ho chiesto un chiarimento.',
                    'query'     => null,
                    'righe'     => 0,
                    'tabelle'   => [],
                ];
            }

            // ── Gli servono le colonne di un'altra tabella ───────────────────
            if (isset($decisione['serve_tabella']) && $chiesta === null) {
                $tabella = strtolower(trim((string)$decisione['serve_tabella']));
                $chiesta = $tabella;

                if (in_array($tabella, $permesse, true)) {
                    $catalogo = $this->catalogo->perDomanda(
                        $domanda . ' ' . str_replace('bb_', '', $tabella),
                        $permesse,
                        array_merge($tabelleRecenti, [$tabella])
                    );
                    $tentativo--; // chiedere lo schema non e' un tentativo sbagliato
                    continue;
                }

                return $this->ko("Per rispondere servirebbe {$tabella}, a cui non hai accesso.");
            }

            if (empty($decisione['sql'])) {
                $extra = [[
                    'role'    => 'user',
                    'content' => 'La tua risposta non era un JSON valido con una chiave "sql". '
                               . 'Rispondi SOLO con il JSON, su una riga.',
                ]];
                continue;
            }

            // ── Guardia ──────────────────────────────────────────────────────
            $controllo = $guardia->controlla((string)$decisione['sql']);

            if (!$controllo['ok']) {
                // Un rifiuto per permessi non si riprova: la risposta non
                // cambia al secondo giro, e insistere vuol dire solo far
                // aspettare di piu' per lo stesso no.
                if ($this->rifiutoDefinitivo($controllo['errore'])) {
                    return $this->ko($controllo['errore']);
                }

                $extra = [[
                    'role'    => 'user',
                    'content' => 'La query e\' stata rifiutata: ' . $controllo['errore']
                               . ' Riscrivila rispettando le regole.',
                ]];
                continue;
            }

            // ── Esecuzione ───────────────────────────────────────────────────
            $avvisa('eseguo', []);
            $esecuzione = $this->esegui($controllo['sql']);

            if (!$esecuzione['ok']) {
                // L'errore di MySQL torna al modello: "Unknown column 'x'" e'
                // esattamente l'informazione che gli serve per aggiustare.
                $extra = [[
                    'role'    => 'user',
                    'content' => 'La query ha dato errore da MySQL: ' . $esecuzione['errore']
                               . ' Riscrivila usando solo colonne che esistono nello schema che ti ho dato.',
                ]];
                continue;
            }

            $formattata = Risposta::formatta(
                (string)($decisione['titolo'] ?? 'Risultati'),
                (string)($decisione['tipo'] ?? 'elenco'),
                $esecuzione['colonne'],
                $esecuzione['righe'],
                $vedePrezzi
            );

            $avvisa('pronto', ['righe' => count($esecuzione['righe'])]);

            return [
                'ok'        => true,
                'answer'    => $formattata['testo'],
                'riassunto' => $formattata['riassunto'],
                'query'     => $controllo['sql'],
                'righe'     => count($esecuzione['righe']),
                'tabelle'   => $catalogo['tabelle'],
            ];
        }

        // Finiti i tentativi: si dice cosa e' andato storto, non "errore".
        $ultimo = $extra[0]['content'] ?? '';
        return $this->ko(
            "Non sono riuscito a scrivere una query che funzionasse per questa domanda. "
            . "Prova a dirla in modo piu' concreto: quale cantiere, quale periodo, quale azienda."
            . ($ultimo !== '' ? "\n\n_Ultimo problema: " . mb_substr($ultimo, 0, 200) . '_' : '')
        );
    }

    /** @return array{ok:false, error:string} */
    private function ko(string $motivo): array
    {
        return ['ok' => false, 'error' => $motivo];
    }

    /**
     * Un rifiuto che non ha senso riprovare: dipende da chi e' l'utente,
     * non da come e' scritta la query.
     */
    private function rifiutoDefinitivo(string $errore): bool
    {
        return str_contains($errore, 'Non hai accesso')
            || str_contains($errore, 'non e\' interrogabile')
            || str_contains($errore, 'dati economici')
            || str_contains($errore, 'non e\' leggibile');
    }

    // ── Il modello ────────────────────────────────────────────────────────────

    /**
     * @param list<array{role:string, content:string}> $contesto
     * @param list<array{role:string, content:string}> $extra
     * @return list<array{role:string, content:string}>
     */
    private function messaggi(
        string $domanda,
        array $utente,
        string $catalogo,
        array $contesto,
        array $extra,
    ): array {
        $messaggi = [['role' => 'system', 'content' => $this->prompt($utente, $catalogo)]];

        foreach ($contesto as $turno) {
            if (($turno['role'] ?? '') !== 'system') {
                $messaggi[] = ['role' => $turno['role'], 'content' => (string)$turno['content']];
            }
        }

        $messaggi[] = ['role' => 'user', 'content' => $domanda];

        foreach ($extra as $m) {
            $messaggi[] = $m;
        }

        return $messaggi;
    }

    /**
     * Il prompt di sistema.
     *
     * L'ordine non e' casuale: prima le parti che non cambiano mai (le
     * regole, gli esempi), poi quelle che cambiano. I server dei modelli
     * tengono in cache il prefisso dei prompt che hanno gia' visto, e un
     * prefisso identico fra una domanda e l'altra e' tempo di elaborazione
     * risparmiato per davvero.
     */
    private function prompt(array $utente, string $catalogo): string
    {
        $oggi     = date('Y-m-d');
        $nome     = (string)($utente['full_name'] ?? 'Utente');
        $prezzi   = (bool)($utente['can_see_prices'] ?? false);

        $regole = <<<TXT
        Sei BOB AI. Il tuo unico lavoro e' trasformare una domanda in italiano in UNA query
        SELECT su MySQL. Non scrivi la risposta per l'utente: a quella pensa il sistema dopo
        aver eseguito la query.

        RISPONDI SOLO CON UN OGGETTO JSON SU UNA RIGA. Niente testo prima o dopo, niente
        blocchi di codice, niente ragionamento. Quattro forme possibili:

        1) Hai la query:
        {"sql":"SELECT ...","titolo":"cosa hai contato o elencato, in italiano, senza numeri","tipo":"conteggio"}
           tipo: "conteggio" per un numero, "elenco" per una lista, "dettaglio" per una riga sola.
           Il titolo diventa la prima frase che legge l'utente: scrivilo come glielo diresti
           tu ("Cantieri aperti", "Giornate dei nostri sul cantiere C-104 a settembre").
           Non metterci dentro i numeri: non li sai ancora.

        2) La domanda non riguarda i dati (un saluto, cosa sai fare, come funzioni):
        {"risposta":"la tua risposta in italiano, breve"}

        3) La domanda e' ambigua e scegliere al posto dell'utente darebbe un numero sbagliato:
        {"chiarimento":"la domanda da fargli, una sola, concreta"}

        4) Ti servono le colonne di una tabella che vedi nominata ma di cui non hai il dettaglio:
        {"serve_tabella":"bb_nome_tabella"}

        REGOLE DELLA QUERY
        - Solo SELECT. Mai INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE.
        - Mai il punto e virgola, mai i commenti SQL (-- /* #).
        - Usa SOLO le tabelle e le colonne scritte qui sotto. Se una colonna non c'e',
          non inventarla: usa la forma 4 o la 3.
        - Dai a ogni colonna selezionata un alias in italiano con AS: quegli alias diventano
          le intestazioni della tabella che legge l'utente. "AS giornate", non "AS tot".
        - Non selezionare id, created_at, updated_at, password, token.
        - Negli elenchi metti sempre un ORDER BY sensato e LIMIT 200.
        - Oggi e' {$oggi}. "Questo mese", "l'anno scorso", "ultimo trimestre" li traduci
          tu in date esplicite nel WHERE.
        - Per cercare un nome usa LIKE '%...%': la gente scrive i nomi a modo suo.
        - Se la domanda e' il seguito della precedente ("e quelli chiusi?", "e a ottobre?"),
          riparti dalla query che trovi nel contesto e cambia solo quello che serve.
        TXT;

        $sezionePrezzi = $prezzi
            ? "DATI ECONOMICI: puoi usare importi, costi, prezzi e margini."
            : "DATI ECONOMICI: NON sei autorizzato a mostrarli a questo utente. Non selezionare\n"
            . "colonne di importi, costi, prezzi, margini o imponibili, e non usarle nemmeno nel\n"
            . "WHERE o in una somma. Se la domanda chiede soldi, usa la forma 2 e spiega che\n"
            . "l'utente non ha i permessi per i dati economici.";

        return $regole . "\n\n"
             . CatalogoDati::esempi() . "\n\n"
             . $catalogo . "\n"
             . "UTENTE: {$nome}\n"
             . $sezionePrezzi . "\n";
    }

    /**
     * @param list<array{role:string, content:string}> $messaggi
     * @return array{ok:true, response:string}|array{ok:false, error:string}
     */
    private function generaSql(array $messaggi, callable $avvisa): array
    {
        $opzioni = [
            // Una query non e' un testo creativo: la stessa domanda deve dare
            // la stessa query, sempre.
            'temperature' => 0.0,
            'top_p'       => 1.0,
            // Una riga di JSON con dentro una SELECT. Il tetto serve a non
            // restare appesi se il modello comincia a divagare.
            'max_tokens'  => 700,
        ];

        $generati = 0;
        $risultato = $this->client->chatStream(
            $messaggi,
            $opzioni,
            function (string $pezzo) use (&$generati, $avvisa): void {
                $generati += max(1, (int)floor(mb_strlen($pezzo) / 4));
                // Serve a due cose: far vedere che sta lavorando, e tenere
                // viva la connessione col browser mentre il modello scrive.
                $avvisa('scrivo', ['token' => $generati]);
            }
        );

        // Se il server del modello ignora `stream` e risponde in un colpo
        // solo, dallo streaming non esce niente. Si riprova senza: un giro in
        // piu' una volta sola e' meglio di un modulo che non funziona su un
        // server che non parla SSE.
        if (!$risultato['ok'] && str_contains((string)($risultato['error'] ?? ''), 'non ha risposto niente')) {
            $risultato = $this->client->chat($messaggi, $opzioni);
        }

        if (!$risultato['ok']) {
            return ['ok' => false, 'error' => (string)($risultato['error'] ?? 'Errore del modello.')];
        }

        return ['ok' => true, 'response' => (string)$risultato['response']];
    }

    /**
     * Tira fuori la decisione dal testo del modello.
     *
     * Si accetta anche il JSON dentro un blocco di codice o con una frase
     * intorno — capita, e rifiutare per questo vorrebbe dire far aspettare
     * l'utente un giro in piu' per niente. Se di JSON non c'e' traccia si
     * ricade sul vecchio formato (un blocco ```sql), cosi' anche un modello
     * che ignora le istruzioni produce qualcosa di utile.
     *
     * @return array<string, mixed>
     */
    private function leggiDecisione(string $testo): array
    {
        $testo = trim(OllamaClient::senzaRagionamento($testo));

        // JSON, eventualmente dentro un recinto di backtick
        if (preg_match('/\{.*\}/s', $testo, $m)) {
            $dati = json_decode($m[0], true);
            if (is_array($dati)) {
                return $dati;
            }

            // Un JSON con dentro una query su piu' righe spesso arriva con i
            // ritorni a capo non protetti: si riprova appiattendoli.
            $piatto = (string)preg_replace('/\s*\R\s*/', ' ', $m[0]);
            $dati   = json_decode($piatto, true);
            if (is_array($dati)) {
                return $dati;
            }
        }

        // Ripiego: il vecchio formato con il blocco ```sql
        if (preg_match('/```sql\s*(.*?)```/is', $testo, $m)) {
            return ['sql' => trim($m[1]), 'titolo' => 'Risultati', 'tipo' => 'elenco'];
        }

        // Ultimo ripiego: una SELECT scritta nuda
        if (preg_match('/\bSELECT\b.+/is', $testo, $m)) {
            return ['sql' => trim($m[0]), 'titolo' => 'Risultati', 'tipo' => 'elenco'];
        }

        return [];
    }

    // ── Il database ───────────────────────────────────────────────────────────

    /**
     * @return array{ok:true, colonne:list<string>, righe:list<array<string,mixed>>}
     *        |array{ok:false, errore:string}
     */
    private function esegui(string $sql): array
    {
        $this->limitaDurata();

        try {
            $stmt = $this->conn->query($sql);

            $colonne = [];
            for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta !== false) {
                    $colonne[] = (string)$meta['name'];
                }
            }

            $righe = [];
            while (($riga = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $righe[] = $riga;
                if (count($righe) >= GuardiaSql::MAX_RIGHE) {
                    break;
                }
            }
            $stmt->closeCursor();

            return ['ok' => true, 'colonne' => $colonne, 'righe' => $righe];
        } catch (\PDOException $e) {
            error_log('[AI_SQL] query=' . $sql . ' errore=' . $e->getMessage());

            return ['ok' => false, 'errore' => $this->errorePulito($e->getMessage())];
        }
    }

    /**
     * Mette un tetto alla durata di una query.
     *
     * Senza questo, una SELECT con un JOIN sbagliato su bb_presenze tiene
     * occupato un thread di MySQL per minuti: la richiesta web muore per
     * timeout ma la query continua a girare, e due o tre di queste mettono in
     * difficolta' il database per tutti.
     */
    private function limitaDurata(): void
    {
        // MySQL 5.7.8+ : millisecondi. MariaDB: max_statement_time, secondi.
        foreach ([
            'SET SESSION max_execution_time = ' . self::TIMEOUT_QUERY_MS,
            'SET SESSION max_statement_time = ' . (self::TIMEOUT_QUERY_MS / 1000),
        ] as $sql) {
            try {
                $this->conn->exec($sql);
                return;
            } catch (\Throwable) {
                // la prossima
            }
        }
    }

    /**
     * L'errore di MySQL ripulito dal rumore, per rimandarlo al modello.
     * Il messaggio utile ("Unknown column 'x' in 'field list'") sta dopo il
     * codice SQLSTATE.
     */
    private function errorePulito(string $messaggio): string
    {
        $pulito = (string)preg_replace('/^SQLSTATE\[\w+\]:?\s*/', '', $messaggio);
        $pulito = (string)preg_replace('/^.*?\d{4}\s/', '', $pulito);

        return mb_substr(trim($pulito) !== '' ? trim($pulito) : $messaggio, 0, 300);
    }
}
