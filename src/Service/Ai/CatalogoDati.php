<?php

declare(strict_types=1);

namespace App\Service\Ai;

use PDO;

/**
 * Quello che BOB AI sa del database, e solo quello che gli serve adesso.
 *
 * Prima il prompt portava **tutto** lo schema: ogni tabella e ogni colonna
 * del database, prese da INFORMATION_SCHEMA a ogni domanda. Sono oltre cento
 * tabelle, decine di migliaia di caratteri che il modello doveva rileggere
 * da capo ogni volta prima di cominciare a pensare. Era la ragione principale
 * per cui una risposta ci metteva mezzo minuto.
 *
 * Qui il catalogo e' due cose sovrapposte:
 *
 * 1. **Le tabelle ammesse**, scritte a mano in `TABELLE`. E' una lista
 *    positiva: quello che non sta qui, BOB AI non lo interroga. Il contrario
 *    della vecchia lista di tabelle vietate, dove una tabella nuova nasceva
 *    interrogabile e te ne accorgevi dopo.
 *
 * 2. **Le colonne vere**, lette da INFORMATION_SCHEMA e messe in cache. I
 *    nomi e i tipi non si scrivono a mano perche' si scollano dal database:
 *    il vecchio prompt diceva che le fatture avevano una colonna `total`
 *    (e' `totale_imponibile`), che le presenze avevano `user_id` (e'
 *    `worker_id`) e che gli operai stavano in bb_users (stanno in
 *    bb_workers, e bb_users era perfino fra le tabelle vietate — il modello
 *    veniva mandato a sbattere contro il suo stesso guardiano).
 *
 * A mano restano solo le cose che lo schema non puo' dire: cosa significa
 * una tabella, con quale colonna si lega alle altre, e i filtri che un umano
 * da' per scontati (le bozze non si contano, un operaio licenziato non e'
 * un operaio). Quelle note sono il vero valore aggiunto del catalogo.
 *
 * Per ogni domanda si mandano al modello solo le tabelle che c'entrano,
 * scelte per parole chiave: da ~120 tabelle a 4-6.
 */
final class CatalogoDati
{
    /**
     * Le tabelle che BOB AI puo' interrogare.
     *
     * - `label`  — come la chiama un umano in azienda
     * - `modulo` — il permesso in bb_user_permissions che serve per vederla;
     *              null = visibile a chiunque abbia accesso a BOB AI
     * - `parole` — i termini con cui la gente nomina questa roba, per la
     *              scelta delle tabelle da mandare nel prompt
     * - `nota`   — cosa deve sapere il modello e non e' scritto nello schema
     *
     * @var array<string, array{label:string, modulo:?string, parole:list<string>, nota:string}>
     */
    private const TABELLE = [
        'bb_worksites' => [
            'label'  => 'cantieri',
            'modulo' => 'worksites',
            'parole' => ['cantiere', 'cantieri', 'lavoro', 'commessa', 'sito', 'worksite', 'aperti', 'chiusi'],
            'nota'   => "Un cantiere. is_draft=1 sono bozze non confermate: aggiungi SEMPRE is_draft=0. "
                      . "status e' testo libero con pochi valori ricorrenti: 'In corso' vuol dire aperto. "
                      . "worksite_code e' il codice che la gente usa per nominarlo a voce. "
                      . "Si lega ai clienti con client_id.",
        ],
        'bb_clients' => [
            'label'  => 'clienti',
            'modulo' => 'clients',
            'parole' => ['cliente', 'clienti', 'committente', 'committenti'],
            'nota'   => "Il cliente che commissiona il cantiere. bb_worksites.client_id = bb_clients.id.",
        ],
        'bb_companies' => [
            'label'  => 'aziende e consorziate',
            'modulo' => 'companies',
            'parole' => ['azienda', 'aziende', 'consorziata', 'consorziate', 'ditta', 'ditte', 'fornitore', 'impresa'],
            'nota'   => "Le aziende. consorziata=1 sono le consorziate del consorzio, cioe' quelle che "
                      . "mandano operai nei nostri cantieri; consorziata=0 sono le altre. "
                      . "ATTENZIONE: bb_workers.company e' il NOME dell'azienda come testo, non un id — "
                      . "per legare un operaio alla sua azienda si confronta bb_workers.company con bb_companies.name.",
        ],
        'bb_workers' => [
            'label'  => 'operai',
            'modulo' => 'users',
            'parole' => ['operaio', 'operai', 'lavoratore', 'lavoratori', 'dipendente', 'dipendenti',
                         'persona', 'persone', 'squadra', 'montatore', 'montatori', 'personale'],
            'nota'   => "Gli operai. QUESTA e' la tabella delle persone che lavorano: non bb_users, "
                      . "che sono gli account per entrare in BOB e non e' interrogabile. "
                      . "removed='N' sono quelli ancora in forza: mettilo sempre, se non ti chiedono gli storici. "
                      . "active='Y' (o '1') e' attivo. Il nome sta spezzato in first_name e last_name: "
                      . "usa CONCAT_WS(' ', first_name, last_name) quando serve il nome intero. "
                      . "company e' il nome testuale dell'azienda a cui appartiene.",
        ],
        'bb_presenze' => [
            'label'  => 'presenze dei nostri operai',
            'modulo' => 'attendance',
            'parole' => ['presenza', 'presenze', 'giornata', 'giornate', 'giorni', 'lavorato',
                         'ore', 'turno', 'turni', 'pranzo', 'pasti'],
            'nota'   => "Una riga per operaio per giorno, per i nostri dipendenti. "
                      . "Si lega con worker_id a bb_workers.id e con worksite_id a bb_worksites.id. "
                      . "La data e' `data`. "
                      . "IMPORTANTE per contare le giornate: turno vale 'Intero' o 'Mezzo', quindi "
                      . "non si conta con COUNT(*) ma con "
                      . "SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END).",
        ],
        'bb_presenze_consorziate' => [
            'label'  => 'presenze degli operai delle consorziate',
            'modulo' => 'attendance',
            'parole' => ['presenza', 'presenze', 'consorziata', 'consorziate', 'giornate', 'quantita'],
            'nota'   => "Le presenze degli operai delle consorziate, aggregate per azienda e non per "
                      . "persona: `quantita` e' il numero di operai di quell'azienda in quel giorno. "
                      . "azienda_id = bb_companies.id, worksite_id = bb_worksites.id, la data e' "
                      . "`data_presenza`. Qui le giornate si sommano con SUM(quantita). "
                      . "Le presenze totali di un cantiere sono bb_presenze PIU' questa tabella: "
                      . "se ti chiedono \"quante giornate sul cantiere X\" servono entrambe.",
        ],
        'bb_billing' => [
            'label'  => 'fatturazione',
            'modulo' => 'billing',
            'parole' => ['fattura', 'fatture', 'fatturato', 'fatturazione', 'imponibile', 'iva',
                         'emessa', 'emesse', 'emettere', 'ricavo', 'ricavi'],
            'nota'   => "Le fatture di BOB verso i clienti. L'importo e' `totale_imponibile` (NON esiste "
                      . "una colonna `total`). La data e' `data`. nome_cantiere e nome_cliente sono "
                      . "copie testuali: per filtrare conviene comunque il JOIN su worksite_id. "
                      . "SU \"EMESSA\": bb_billing.emessa dice solo lo stato dentro BOB (1 = segnata "
                      . "emessa in BOB, 0 = da emettere in BOB). Lo stato contabile vero sta in un "
                      . "altro sistema e tu non lo puoi leggere. Se usi questo campo, dillo nel titolo.",
        ],
        'bb_extra' => [
            'label'  => 'extra di cantiere',
            'modulo' => 'worksites',
            'parole' => ['extra', 'extras', 'lavoro aggiuntivo', 'aggiuntivi'],
            'nota'   => "Lavori in piu' rispetto al contratto, legati al cantiere con worksite_id.",
        ],
        'bb_ordini' => [
            'label'  => 'ordini alle consorziate',
            'modulo' => 'ordini',
            'parole' => ['ordine', 'ordini', 'commessa consorziata'],
            'nota'   => "Ordini che emettiamo verso le consorziate. destinatario_id = bb_companies.id, "
                      . "worksite_id = bb_worksites.id.",
        ],
        'bb_pagamenti_consorziate' => [
            'label'  => 'pagamenti alle consorziate',
            'modulo' => 'billing',
            'parole' => ['pagamento', 'pagamenti', 'pagato', 'pagare', 'consorziata'],
            'nota'   => "Quello che paghiamo alle consorziate. azienda_id = bb_companies.id, "
                      . "worksite_id = bb_worksites.id, importo e data_pagamento.",
        ],
        'bb_offers' => [
            'label'  => 'offerte',
            'modulo' => 'offers',
            'parole' => ['offerta', 'offerte', 'preventivo', 'preventivi', 'quotazione'],
            'nota'   => "I preventivi verso i clienti. Le voci stanno in bb_offer_items (offer_id).",
        ],
        'bb_offer_items' => [
            'label'  => 'voci delle offerte',
            'modulo' => 'offers',
            'parole' => ['offerta', 'offerte', 'preventivo', 'voce', 'voci', 'riga', 'righe'],
            'nota'   => "Le righe di una offerta. offer_id = bb_offers.id.",
        ],
        'bb_bookings' => [
            'label'  => 'prenotazioni alloggi',
            'modulo' => 'bookings',
            'parole' => ['prenotazione', 'prenotazioni', 'alloggio', 'alloggi', 'albergo',
                         'hotel', 'dormire', 'trasferta'],
            'nota'   => "Alloggi per gli operai in trasferta. worksite_id = bb_worksites.id, "
                      . "consorziata_id = bb_companies.id, a_carico_consorziata=1 se lo paga la "
                      . "consorziata. I periodi stanno in bb_booking_periods (booking_id).",
        ],
        'bb_booking_periods' => [
            'label'  => 'periodi delle prenotazioni',
            'modulo' => 'bookings',
            'parole' => ['prenotazione', 'alloggio', 'periodo', 'notti', 'dal', 'al'],
            'nota'   => "I periodi di una prenotazione. booking_id = bb_bookings.id.",
        ],
        'bb_equipment' => [
            'label'  => 'mezzi e attrezzature',
            'modulo' => 'equipment',
            'parole' => ['mezzo', 'mezzi', 'attrezzatura', 'attrezzature', 'macchina', 'macchine',
                         'noleggio', 'noleggi', 'gru', 'ponteggio'],
            'nota'   => "Inventario mezzi e attrezzature. L'assegnazione ai cantieri sta in "
                      . "bb_worksite_equipment.",
        ],
        'bb_worksite_equipment' => [
            'label'  => 'mezzi assegnati ai cantieri',
            'modulo' => 'equipment',
            'parole' => ['mezzo', 'mezzi', 'attrezzatura', 'noleggio', 'cantiere'],
            'nota'   => "Quale mezzo sta su quale cantiere. worksite_id e equipment_id.",
        ],
        'bb_meal_tickets' => [
            'label'  => 'buoni pasto',
            'modulo' => 'tickets',
            'parole' => ['buono', 'buoni', 'pasto', 'pasti', 'ticket'],
            'nota'   => "I buoni pasto consegnati.",
        ],
        'bb_worker_documents' => [
            'label'  => 'documenti degli operai',
            'modulo' => 'documents',
            'parole' => ['documento', 'documenti', 'attestato', 'attestati', 'certificato',
                         'scadenza', 'scadenze', 'scaduto', 'scaduti', 'visita', 'idoneita', 'corso'],
            'nota'   => "Documenti e attestati di un operaio. worker_id = bb_workers.id. "
                      . "ATTENZIONE sulle scadenze: la data di scadenza NON e' sempre una data vera, "
                      . "e' testo e puo' essere scritta in piu' formati. Non fare aritmetica sulle "
                      . "date di scadenza: tira fuori le righe e lascia leggere il valore.",
        ],
        'bb_company_documents' => [
            'label'  => 'documenti delle aziende',
            'modulo' => 'documents',
            'parole' => ['documento', 'documenti', 'azienda', 'consorziata', 'durc', 'visura', 'scadenza'],
            'nota'   => "Documenti di un'azienda. company_id = bb_companies.id.",
        ],
        'bb_ferie_permessi' => [
            'label'  => 'ferie, permessi e malattie',
            'modulo' => 'attendance',
            'parole' => ['ferie', 'permesso', 'permessi', 'malattia', 'malattie', 'assenza',
                         'assenze', 'riposo'],
            'nota'   => "Le assenze richieste. worker_id = bb_workers.id, tipo distingue "
                      . "ferie/permesso/malattia, stato vale 'in_attesa' finche' l'ufficio non "
                      . "decide. Il periodo e' data_inizio - data_fine.",
        ],
        'bb_advances' => [
            'label'  => 'anticipi',
            'modulo' => 'attendance',
            'parole' => ['anticipo', 'anticipi', 'acconto'],
            'nota'   => "Anticipi dati agli operai. worker_id = bb_workers.id.",
        ],
        'bb_fines' => [
            'label'  => 'multe',
            'modulo' => 'attendance',
            'parole' => ['multa', 'multe', 'sanzione', 'contravvenzione'],
            'nota'   => "Multe addebitate agli operai. worker_id = bb_workers.id.",
        ],
        'bb_refunds' => [
            'label'  => 'rimborsi',
            'modulo' => 'attendance',
            'parole' => ['rimborso', 'rimborsi', 'spesa', 'spese'],
            'nota'   => "Rimborsi agli operai. worker_id = bb_workers.id.",
        ],
        'bb_pianificazione' => [
            'label'  => 'pianificazione',
            'modulo' => 'pianificazione',
            'parole' => ['pianificazione', 'pianificato', 'programma', 'settimana', 'assegnazione'],
            'nota'   => "Chi e' previsto dove. Si lega ai cantieri con worksite_id.",
        ],
        'bb_programmazione' => [
            'label'  => 'programmazione',
            'modulo' => 'programmazione',
            'parole' => ['programmazione', 'programma', 'trasferta', 'partenza'],
            'nota'   => "La programmazione settimanale delle squadre e delle trasferte.",
        ],
        'bb_worksite_users' => [
            'label'  => 'operai assegnati ai cantieri',
            'modulo' => 'worksites',
            'parole' => ['assegnato', 'assegnati', 'squadra', 'chi lavora', 'cantiere', 'operai'],
            'nota'   => "Legame operaio-cantiere. worksite_id e worker_id (o user_id).",
        ],
        'bb_fleet_vehicles' => [
            'label'  => 'veicoli aziendali',
            'modulo' => 'fleet',
            'parole' => ['veicolo', 'veicoli', 'furgone', 'furgoni', 'auto', 'targa',
                         'flotta', 'macchina aziendale'],
            'nota'   => "I veicoli dell'azienda, identificati dalla targa.",
        ],
        'bb_zone_tasks' => [
            'label'  => 'attivita di cantiere (BOB Zone)',
            'modulo' => 'worksites',
            'parole' => ['attivita', 'task', 'zone', 'lavorazione', 'lavorazioni', 'avanzamento'],
            'nota'   => "Le attivita dentro un cantiere. worksite_id = bb_worksites.id.",
        ],
    ];

    /**
     * Esempi di come si scrivono le query qui. Pochi e corti, ma coprono le
     * trappole vere: il filtro sulle bozze, il mezzo turno, il nome spezzato,
     * il nome dell'azienda come testo.
     */
    private const ESEMPI = <<<'TXT'
ESEMPI (lo stile da seguire):

D: quanti cantieri aperti abbiamo
R: {"sql":"SELECT COUNT(*) AS cantieri_aperti FROM bb_worksites WHERE is_draft = 0 AND status = 'In corso'","titolo":"Cantieri aperti","tipo":"conteggio"}

D: elenco operai della Rossi Montaggi
R: {"sql":"SELECT CONCAT_WS(' ', w.first_name, w.last_name) AS operaio, w.fiscal_code AS codice_fiscale, w.type_worker AS mansione FROM bb_workers w WHERE w.removed = 'N' AND w.company = 'Rossi Montaggi' ORDER BY w.last_name LIMIT 200","titolo":"Operai della Rossi Montaggi","tipo":"elenco"}

D: giornate fatte dai nostri sul cantiere C-104 a settembre
R: {"sql":"SELECT SUM(CASE p.turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END) AS giornate FROM bb_presenze p JOIN bb_worksites c ON c.id = p.worksite_id WHERE c.worksite_code = 'C-104' AND p.data >= '2026-09-01' AND p.data < '2026-10-01'","titolo":"Giornate dei nostri sul cantiere C-104 a settembre","tipo":"conteggio"}

D: che lavori abbiamo per il cliente Acme
R: {"sql":"SELECT c.worksite_code AS codice, c.name AS cantiere, c.status AS stato FROM bb_worksites c JOIN bb_clients cl ON cl.id = c.client_id WHERE c.is_draft = 0 AND cl.name LIKE '%Acme%' ORDER BY c.worksite_code LIMIT 200","titolo":"Cantieri del cliente Acme","tipo":"elenco"}
TXT;

    /** Colonne che non aiutano il modello e occupano solo spazio nel prompt. */
    private const COLONNE_RUMORE = [
        'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by',
        'uuid', 'guid', 'remember_token', 'password', 'password_hash', 'token',
    ];

    /** Oltre questo numero di colonne la tabella si taglia: sono tabelle larghe e il resto e' coda. */
    private const MAX_COLONNE = 34;

    /** Quante tabelle al massimo finiscono nel prompt. */
    private const MAX_TABELLE = 6;

    /**
     * Tabelle che entrano quasi sempre nelle risposte: un cantiere o
     * un'azienda si nominano in ogni domanda, anche quando l'argomento e'
     * un altro.
     */
    private const SEMPRE_UTILI = ['bb_worksites'];

    /** @var array{colonne: array<string, list<array{nome:string, tipo:string}>>, tabelle: list<string>}|null */
    private ?array $cache = null;

    public function __construct(private PDO $conn, private ?string $cacheDir = null) {}

    /** I nomi di tutte le tabelle che BOB AI puo' interrogare, permessi a parte. */
    public static function tabelleAmmesse(): array
    {
        return array_keys(self::TABELLE);
    }

    /**
     * Le tabelle che questo utente puo' interrogare.
     *
     * @param list<string>|null $moduliPermessi null = nessun limite (superadmin)
     * @return list<string>
     */
    public static function tabellePerUtente(?array $moduliPermessi): array
    {
        if ($moduliPermessi === null) {
            return self::tabelleAmmesse();
        }

        $out = [];
        foreach (self::TABELLE as $tabella => $meta) {
            if ($meta['modulo'] === null || in_array($meta['modulo'], $moduliPermessi, true)) {
                $out[] = $tabella;
            }
        }
        return $out;
    }

    /** Il modulo che serve per interrogare una tabella, o null se non ne serve nessuno. */
    public static function moduloDi(string $tabella): ?string
    {
        return self::TABELLE[$tabella]['modulo'] ?? null;
    }

    public static function esempi(): string
    {
        return self::ESEMPI;
    }

    /**
     * Il pezzo di prompt con le tabelle che c'entrano con questa domanda.
     *
     * @param list<string> $permesse
     * @return array{testo:string, tabelle:list<string>}
     */
    public function perDomanda(string $domanda, array $permesse, array $tabelleGiaViste = []): array
    {
        $scelte = $this->scegliTabelle($domanda, $permesse, $tabelleGiaViste);

        $testo = "TABELLE DISPONIBILI PER QUESTA DOMANDA\n"
               . "(sono le uniche che puoi interrogare; se ti serve altro, dillo invece di inventare)\n\n";

        foreach ($scelte as $tabella) {
            $meta = self::TABELLE[$tabella];
            $testo .= "{$tabella} — {$meta['label']}\n";

            $cols = $this->colonneDi($tabella);
            if ($cols === []) {
                $testo .= "  (colonne non leggibili)\n";
            } else {
                $righe = array_map(fn($c) => $c['nome'] . ' ' . $c['tipo'], $cols);
                $testo .= '  ' . implode(' · ', $righe) . "\n";
            }

            $testo .= '  → ' . $meta['nota'] . "\n\n";
        }

        $altre = array_values(array_diff($permesse, $scelte));
        if ($altre !== []) {
            $testo .= "Altre tabelle a cui hai accesso ma di cui non hai qui le colonne: "
                    . implode(', ', $altre) . ".\n"
                    . "Se la risposta sta in una di queste, non tirare a indovinare i nomi delle "
                    . "colonne: rispondi con {\"serve_tabella\":\"nome_tabella\"} e te le mando.\n";
        }

        return ['testo' => $testo, 'tabelle' => $scelte];
    }

    /**
     * Sceglie le tabelle da mandare: quelle le cui parole compaiono nella
     * domanda, piu' quelle di cui si parlava nei turni precedenti.
     *
     * @param list<string> $permesse
     * @param list<string> $gia tabelle usate nei turni precedenti di questa chat
     * @return list<string>
     */
    private function scegliTabelle(string $domanda, array $permesse, array $gia = []): array
    {
        $testo = ' ' . $this->normalizza($domanda) . ' ';
        $punti = [];

        foreach ($permesse as $tabella) {
            $meta = self::TABELLE[$tabella] ?? null;
            if ($meta === null) {
                continue;
            }

            $p = 0;
            foreach ($meta['parole'] as $parola) {
                if (str_contains($testo, ' ' . $this->normalizza($parola) . ' ')
                    || str_contains($testo, ' ' . $this->normalizza($parola))) {
                    $p += 3;
                }
            }

            // il nome della tabella detto per esteso vale piu' di una parola comune
            if (str_contains($testo, str_replace('bb_', '', $tabella))) {
                $p += 6;
            }

            // di cosa si stava parlando prima: una domanda come "e di quelli
            // chiusi?" non contiene nessuna parola utile, il contesto si'
            if (in_array($tabella, $gia, true)) {
                $p += 4;
            }

            if (in_array($tabella, self::SEMPRE_UTILI, true)) {
                $p += 1;
            }

            if ($p > 0) {
                $punti[$tabella] = $p;
            }
        }

        // Nessuna parola riconosciuta: si mandano le tabelle di cui si parla
        // il 90% delle volte, invece di mandare niente e far inventare.
        if ($punti === []) {
            $ripiego = ['bb_worksites', 'bb_workers', 'bb_presenze', 'bb_billing', 'bb_companies'];
            return array_values(array_slice(array_intersect($ripiego, $permesse), 0, self::MAX_TABELLE));
        }

        arsort($punti);

        return array_slice(array_keys($punti), 0, self::MAX_TABELLE);
    }

    private function normalizza(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        return (string)preg_replace('/[^a-z0-9_ ]+/', ' ', $s);
    }

    /** @return list<array{nome:string, tipo:string}> */
    public function colonneDi(string $tabella): array
    {
        return $this->schema()['colonne'][$tabella] ?? [];
    }

    /**
     * I nomi di **tutte** le tabelle del database, non solo quelle ammesse.
     *
     * Serve alla GuardiaSql per distinguere una tabella da una colonna: in
     * BOB esistono colonne che si chiamano come una tabella (bb_billing_id
     * e' una colonna), e senza questa lista il guardiano rifiuterebbe query
     * buone credendo di vedere una tabella vietata.
     *
     * @return list<string>
     */
    public function tabelleDelDatabase(): array
    {
        return $this->schema()['tabelle'];
    }

    /**
     * Lo schema vero, letto una volta e tenuto in cache su file: cambia
     * quando passa una migration, non fra due domande.
     *
     * @return array{colonne: array<string, list<array{nome:string, tipo:string}>>, tabelle: list<string>}
     */
    private function schema(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $daCache = $this->leggiCache();
        if ($daCache !== null) {
            return $this->cache = $daCache;
        }

        $dati = ['colonne' => [], 'tabelle' => []];

        try {
            $ammesse = self::tabelleAmmesse();
            $in      = implode(',', array_fill(0, count($ammesse), '?'));

            $stmt = $this->conn->prepare("
                SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
                FROM   INFORMATION_SCHEMA.COLUMNS
                WHERE  TABLE_SCHEMA = DATABASE()
                  AND  TABLE_NAME IN ({$in})
                ORDER BY TABLE_NAME, ORDINAL_POSITION
            ");
            $stmt->execute($ammesse);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nome = (string)$r['COLUMN_NAME'];
                if (in_array(strtolower($nome), self::COLONNE_RUMORE, true)) {
                    continue;
                }

                $tabella = (string)$r['TABLE_NAME'];
                if (count($dati['colonne'][$tabella] ?? []) >= self::MAX_COLONNE) {
                    continue;
                }

                $dati['colonne'][$tabella][] = ['nome' => $nome, 'tipo' => $this->tipoBreve($r)];
            }

            $elenco = $this->conn->query("
                SELECT TABLE_NAME
                FROM   INFORMATION_SCHEMA.TABLES
                WHERE  TABLE_SCHEMA = DATABASE()
            ");
            $dati['tabelle'] = array_map(
                'strtolower',
                array_map('strval', $elenco->fetchAll(PDO::FETCH_COLUMN))
            );
        } catch (\Throwable) {
            // Senza schema si risponde comunque: il modello ha le note e gli
            // esempi. Meglio una risposta imprecisa che una pagina di errore.
            // La guardia, senza l'elenco tabelle, ricade sul controllo per
            // prefisso: piu' severa, non piu' permissiva.
            $dati = ['colonne' => [], 'tabelle' => []];
        }

        $this->scriviCache($dati);

        return $this->cache = $dati;
    }

    /**
     * Il tipo in forma corta. Gli ENUM si portano con i valori dentro: sono
     * la cosa che il modello non puo' indovinare e che fa fallire i WHERE.
     */
    private function tipoBreve(array $r): string
    {
        $tipo  = strtolower((string)$r['DATA_TYPE']);
        $pieno = (string)$r['COLUMN_TYPE'];

        if ($tipo === 'enum' || $tipo === 'set') {
            return $pieno; // enum('a','b') — i valori servono per intero
        }

        return match (true) {
            str_contains($tipo, 'int')                  => 'int',
            $tipo === 'decimal', $tipo === 'float',
            $tipo === 'double'                          => 'num',
            $tipo === 'date'                            => 'date',
            $tipo === 'datetime', $tipo === 'timestamp' => 'datetime',
            $tipo === 'time'                            => 'time',
            str_contains($tipo, 'text')                 => 'text',
            default                                     => 'varchar',
        };
    }

    // ── Cache su file ─────────────────────────────────────────────────────────
    // Si invalida da sola col tempo e si puo' cancellare a mano dopo una
    // migration. Niente APCu: su php-fpm non e' detto che ci sia.

    private const CACHE_TTL = 3600;

    private function percorsoCache(): ?string
    {
        $dir = $this->cacheDir
            ?? (defined('APP_ROOT') ? APP_ROOT . '/storage/cache' : null);

        if ($dir === null) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir . '/ai_catalogo.json';
    }

    private function leggiCache(): ?array
    {
        $file = $this->percorsoCache();
        if ($file === null || !is_file($file)) {
            return null;
        }
        if (time() - (int)@filemtime($file) > self::CACHE_TTL) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $dati = json_decode($raw, true);
        if (!is_array($dati) || !isset($dati['colonne'], $dati['tabelle'])) {
            return null;
        }
        return $dati['colonne'] === [] ? null : $dati;
    }

    private function scriviCache(array $dati): void
    {
        $file = $this->percorsoCache();
        if ($file === null || ($dati['colonne'] ?? []) === []) {
            return;
        }

        // scrittura atomica: due richieste in parallelo non si leggono a meta'
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($dati, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        }
    }
}
