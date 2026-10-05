<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Attendance\LeaveRepository;
use App\Repository\Attendance\RichiestaPresenzaRepository;

/**
 * API dell'app per l'operaio: le sue cose e basta.
 *
 * Sta separato da ApiV1Controller perche' la regola di accesso e' diversa da
 * tutto il resto di BOB. Altrove si controlla un permesso di modulo; qui non
 * c'e' nessun modulo da controllare — un operaio non ne ha — e quello che
 * conta e' una cosa sola: **sono i tuoi dati?**
 *
 * Per questo ogni metodo parte da operaio(), che ricava il worker_id
 * dall'utente collegato e non lo prende mai dalla richiesta. Un id di
 * operaio passato dall'app sarebbe modificabile, e basterebbe cambiare un
 * numero per leggere le presenze e i documenti di un collega.
 *
 * Tutte le risposte hanno la forma { success, ... } come il resto di
 * /api/v1, cosi' l'app non deve distinguere due tipi di risposta.
 */
final class ApiV1OperaioController
{
    public function __construct(private \PDO $conn) {}

    // ── GET /api/v1/me/cantieri ──────────────────────────────────────────────

    /**
     * I cantieri fra cui l'operaio sceglie quando dichiara una giornata.
     *
     * Il problema vero non e' il permesso, e' che uno deve ritrovare il
     * cantiere dove e' stato. Un operaio non ragiona per codici: sa "quello
     * di Lecco", o "lo stesso di ieri". Quindi l'elenco parte da dove ha gia'
     * lavorato — sono quasi sempre quelli, e stanno in cima gia' pronti — e
     * lascia cercare fra i cantieri aperti per il caso in cui lo mandino da
     * un'altra parte.
     *
     * "Dove ha gia' lavorato" si legge da bb_presenze, che e' l'unico posto
     * dove quel dato esiste davvero: l'ufficio lo scrive ogni giorno. Prima
     * qui leggevo bb_worksite_assignments, che in BOB **nessuno scrive mai** —
     * viene solo letta — quindi l'elenco sarebbe uscito vuoto per tutti e
     * nessuno avrebbe potuto dichiarare niente.
     *
     * Parametro `q`: cerca per nome, codice o paese fra i cantieri aperti.
     */
    public function cantieri(Request $request): never
    {
        $elenchi = $this->dati()->cantieri(
            $this->operaio($request),
            trim((string)($_GET['q'] ?? ''))
        );

        Response::json([
            'success' => true,
            // quelli suoi, gia' ordinati dall'ultimo giorno lavorato
            'recenti' => $elenchi['recenti'],
            // tutti gli aperti, per cercare quando lo mandano da un'altra parte
            'aperti'  => $elenchi['aperti'],
        ]);
    }

    // ── GET /api/v1/me/pianificazione ────────────────────────────────────────

    /**
     * Dove l'operaio e' pianificato.
     *
     * E' la risposta alla domanda che l'operaio si fa davvero: non "quale
     * cantiere cerco", ma "domani dove vado". Cercare un cantiere in un
     * elenco e' una cosa che gli si chiede solo quando la pianificazione non
     * dice niente.
     *
     * Senza parametri risponde per oggi e domani: la sera si guarda domani,
     * la mattina si guarda oggi, e mandarle insieme evita la seconda
     * chiamata proprio nell'ora in cui la linea in cantiere e' peggiore.
     *
     * Chi e' capo squadra lo sa da qui: sulla sua riga `sei_capo` e' vero.
     *
     * La squadra la vedono tutti, non solo il capo. Prima arrivava solo a
     * lui, per non spargere i dati di centoquaranta persone su
     * centoquaranta telefoni — ma qui non ci sono centoquaranta persone:
     * ci sono i tre o quattro con cui uno sale in macchina domattina, e che
     * vedra' comunque fra sei ore. Sapere la sera con chi si va, e chi
     * comanda, e' mezzo motivo per cui l'app serve.
     */
    public function pianificazione(Request $request): never
    {
        $dal = $this->data($_GET['dal'] ?? '', date('Y-m-d'));
        $al  = $this->data($_GET['al']  ?? '', date('Y-m-d', strtotime('+1 day')));

        Response::json([
            'success'        => true,
            'dal'            => $dal,
            'al'             => $al,
            'pianificazione' => $this->dati()->pianificazione($this->operaio($request), $dal, $al),
        ]);
    }

    // ── GET /api/v1/me/documenti ─────────────────────────────────────────────

    /**
     * I documenti dell'operaio, con quanto manca alla scadenza.
     *
     * I giorni li conta il server: farlo nell'app vorrebbe dire dipendere
     * dall'orologio del telefono, che in cantiere e' spesso sbagliato, e un
     * documento scaduto che risulta valido e' esattamente il tipo di errore
     * che non ci si puo' permettere su un cantiere.
     */
    public function documenti(Request $request): never
    {
        $operaio = $this->operaio($request);

        $stmt = $this->conn->prepare("
            SELECT id, tipo_documento, data_emissione, scadenza,
                   CASE
                       WHEN scadenza IS NULL THEN NULL
                       ELSE DATEDIFF(scadenza, CURDATE())
                   END AS giorni_alla_scadenza
            FROM   bb_worker_documents
            WHERE  worker_id = :wid
            ORDER BY scadenza IS NULL, scadenza ASC
        ");
        $stmt->execute([':wid' => $operaio]);

        Response::json([
            'success'   => true,
            'documenti' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        ]);
    }

    // ── GET /api/v1/me/presenze ──────────────────────────────────────────────

    /**
     * Tutte le sue giornate, non solo quelle che ha mandato lui.
     *
     * Chi ha lavorato dieci anni prima dell'app non ha nessuna
     * dichiarazione: un elenco che mostra solo quelle gli direbbe che non ha
     * mai lavorato. Le presenze registrate sono la fonte, e alle
     * dichiarazioni non ancora decise si aggiunge lo stato.
     */
    public function presenze(Request $request): never
    {
        $operaio = $this->operaio($request);

        // un mese indietro e uno avanti se non si chiede altro: e' la
        // finestra in cui si guarda quando si apre l'app
        $dal = $this->data($_GET['dal'] ?? '', date('Y-m-d', strtotime('-1 month')));
        $al  = $this->data($_GET['al']  ?? '', date('Y-m-d', strtotime('+1 month')));

        $repo = new RichiestaPresenzaRepository($this->conn);

        Response::json([
            'success'  => true,
            'dal'      => $dal,
            'al'       => $al,
            'presenze' => $repo->diarioOperaio($operaio, $dal, $al),
        ]);
    }

    // ── POST /api/v1/me/presenze ─────────────────────────────────────────────

    /**
     * L'operaio dichiara di aver lavorato.
     *
     * Non nasce una presenza: nasce una dichiarazione che l'ufficio guarda,
     * eventualmente corregge e approva. Da bb_presenze escono i costi e le
     * buste paga, e una riga non verificata li' dentro falserebbe i conti di
     * un cantiere finche' qualcuno non se ne accorge.
     */
    public function creaPresenza(Request $request): never
    {
        $operaio = $this->operaio($request);
        $body    = $this->corpo();

        $worksiteId = (int)($body['worksite_id'] ?? 0);
        $data       = $this->data($body['data'] ?? '', '');
        $turno      = (string)($body['turno'] ?? 'Intero');

        if (!$worksiteId || $data === '') {
            Response::json([
                'success' => false,
                'message' => 'Servono il cantiere e il giorno',
            ], 422);
        }

        // Il futuro no: si dichiara quello che si e' fatto, non quello che si
        // fara'. Senza questo controllo basta sbagliare mese sul calendario
        // del telefono per riempire l'elenco dell'ufficio di giornate che non
        // esistono ancora.
        if ($data > date('Y-m-d')) {
            Response::json([
                'success' => false,
                'message' => 'Non si puo\' dichiarare una giornata futura',
            ], 422);
        }

        // Basta che il cantiere sia aperto. Il controllo vero e'
        // l'approvazione dell'ufficio: e' li' che qualcuno guarda se quella
        // giornata ha senso. Restringere qui ai cantieri "assegnati"
        // sembrava piu' sicuro, ma quell'assegnazione in BOB non la scrive
        // nessuno — avrebbe rifiutato tutto — e comunque un operaio mandato
        // per un giorno da un'altra parte deve poterlo dichiarare.
        if (!$this->dati()->cantiereAperto($worksiteId)) {
            Response::json([
                'success' => false,
                'message' => 'Cantiere non trovato o non aperto',
            ], 422);
        }

        $repo = new RichiestaPresenzaRepository($this->conn);

        if ($repo->giaInAttesa($operaio, $data, $worksiteId)) {
            Response::json([
                'success' => false,
                'message' => 'Hai gia\' dichiarato questo giorno su questo cantiere',
            ], 409);
        }

        // L'app manda le parole dell'operaio, il database tiene quelle
        // dell'ufficio. La traduzione sta qui e non nell'app di proposito:
        // "ho pagato io" diventa "Loro" — dal punto di vista di chi tiene i
        // conti, loro sono gli operai — ed e' un'inversione facilissima da
        // sbagliare. Sbagliata, sposta i costi dei pasti da una parte
        // all'altra senza che nessuno se ne accorga.
        //
        // Tenendola qui, chiunque scriva un'app — questa, quella per iPhone,
        // qualsiasi altra cosa domani — manda quello che ha scelto l'operaio
        // e non puo' invertirla.
        //
        // L'importo non si chiede: lui sa di aver mangiato, non quanto e'
        // costato. Lo mette l'ufficio, dove ci sono le fatture.
        $id = $repo->crea($operaio, [
            'worksite_id' => $worksiteId,
            'data'        => $data,
            'turno'       => $turno,
            'pranzo'      => $this->dati()->chiHaPagato($body['pranzo'] ?? ''),
            'cena'        => $this->dati()->chiHaPagato($body['cena'] ?? ''),
            'hotel'       => trim((string)($body['hotel'] ?? '')),
            'targa_auto'  => strtoupper(trim((string)($body['targa_auto'] ?? ''))),
            'trasferta'   => !empty($body['trasferta']),
            'note'        => trim((string)($body['note'] ?? '')),
        ]);

        Response::json(['success' => true, 'id' => $id, 'stato' => 'in_attesa']);
    }

    // ── POST /api/v1/me/presenze/{id}/ritira ─────────────────────────────────

    /** Finche' l'ufficio non ha deciso, l'operaio puo' ritirare quello che ha mandato. */
    public function ritiraPresenza(Request $request): never
    {
        $operaio = $this->operaio($request);
        $id      = (int)$request->param('id');

        $tolta = (new RichiestaPresenzaRepository($this->conn))->ritira($operaio, $id);

        if (!$tolta) {
            Response::json([
                'success' => false,
                'message' => 'Non trovata, o l\'ufficio l\'ha gia\' decisa',
            ], 409);
        }

        Response::json(['success' => true]);
    }

    // ── GET /api/v1/me/ferie ─────────────────────────────────────────────────

    public function ferie(Request $request): never
    {
        $operaio = $this->operaio($request);

        Response::json([
            'success' => true,
            'ferie'   => (new LeaveRepository($this->conn))->getByWorker($operaio),
        ]);
    }

    // ── POST /api/v1/me/ferie ────────────────────────────────────────────────

    /**
     * Richiesta di ferie, permesso o malattia.
     *
     * Nasce in attesa, al contrario di quelle che inserisce l'ufficio: quelle
     * le mette chi decide, questa la manda chi chiede.
     *
     * La malattia non si "chiede" — uno sta male e basta — ma passa dalla
     * stessa porta perche' l'ufficio deve comunque vederla e riscontrare il
     * certificato. In attesa non e' "forse", e' "non ancora guardata".
     */
    public function creaFerie(Request $request): never
    {
        $operaio = $this->operaio($request);
        $body    = $this->corpo();

        $tipo = in_array($body['tipo'] ?? '', ['ferie', 'permesso', 'malattia'], true)
            ? (string)$body['tipo'] : '';
        $dal  = $this->data($body['dal'] ?? '', '');
        $al   = $this->data($body['al']  ?? '', '');

        if ($tipo === '' || $dal === '') {
            Response::json([
                'success' => false,
                'message' => 'Servono il tipo e il giorno di inizio',
            ], 422);
        }

        // un giorno solo: chi chiede un permesso di mezza giornata compila
        // solo l'inizio, e chiedergli di ripetere la stessa data e' un campo
        // in piu' che non aggiunge niente
        if ($al === '') {
            $al = $dal;
        }
        if ($al < $dal) {
            [$dal, $al] = [$al, $dal];
        }

        $ore = ($body['ore'] ?? '') !== '' ? (float)$body['ore'] : null;

        // Il numero del certificato vale solo sulla malattia. Non si
        // pretende: molti lo mandano su WhatsApp e lo mette l'ufficio, e
        // bloccare la dichiarazione per un campo vuoto vorrebbe dire che
        // uno a letto con la febbre non riesce ad avvisare.
        $protocollo = $tipo === 'malattia'
            ? trim((string)($body['protocollo'] ?? ''))
            : '';

        $stmt = $this->conn->prepare("
            INSERT INTO bb_ferie_permessi
                (worker_id, tipo, data_inizio, data_fine, ore, note,
                 protocollo, stato, richiesta_da_operaio, created_by)
            VALUES (:wid, :tipo, :dal, :al, :ore, :note,
                    :prot, 'in_attesa', 1, NULL)
        ");
        $stmt->execute([
            ':wid'  => $operaio,
            ':tipo' => $tipo,
            ':dal'  => $dal,
            ':al'   => $al,
            ':ore'  => $ore,
            ':note' => trim((string)($body['note'] ?? '')) ?: null,
            ':prot' => $protocollo !== '' ? $protocollo : null,
        ]);

        Response::json([
            'success' => true,
            'id'      => (int)$this->conn->lastInsertId(),
            'stato'   => 'in_attesa',
        ]);
    }

    // ── POST /api/v1/me/lingua ───────────────────────────────────────────────

    /**
     * In che lingua l'operaio vuole le notifiche.
     *
     * Unico endpoint di /me/ che non chiede un operaio collegato: la lingua
     * sta sull'utente, non sul lavoratore, e vale anche per chi usa l'app
     * senza essere un operaio.
     *
     * La mette gia' l'ufficio quando crea l'account, perche' centoquaranta
     * persone che entrano nel web ad aggiustarsela non succede. Questo serve
     * a chi se la ritrova sbagliata: l'alternativa e' una telefonata in
     * ufficio per una tendina.
     */
    public function cambiaLingua(Request $request): never
    {
        $userId = (int)($request->user()->id ?? 0);
        // minuscole: 'IT' e' la stessa lingua di 'it', e rifiutarla
        // vorrebbe dire far sbagliare un client per una maiuscola
        $lingua = strtolower(trim((string)($this->corpo()['lingua'] ?? '')));

        if (!isset(\App\Service\Lingua::DISPONIBILI[$lingua])) {
            Response::json([
                'success'    => false,
                'message'    => 'Lingua non disponibile',
                'disponibili' => \App\Service\Lingua::DISPONIBILI,
            ], 422);
        }

        $stmt = $this->conn->prepare('UPDATE bb_users SET lingua = :l WHERE id = :id');
        $stmt->execute([':l' => $lingua, ':id' => $userId]);

        Response::json(['success' => true, 'lingua' => $lingua]);
    }

    // ── GET /api/v1/me/zone ──────────────────────────────────────────────────

    /**
     * I cantieri di cui puo' aprire la Zone, e fin dove su ognuno.
     *
     * Senza questo l'app non sa da dove cominciare: gli endpoint della Zone
     * sanno dire di no, ma non sanno dire quali cantieri provare, e provarli
     * tutti per scoprirlo sarebbe assurdo.
     *
     * I livelli arrivano insieme all'elenco cosi' l'app disegna solo le
     * schede che servono, invece di mostrarle tutte e farle rispondere 403
     * una per una quando uno ci tocca sopra.
     *
     * Non chiede un operaio collegato: la Zone si da' all'utente.
     */
    public function zone(Request $request): never
    {
        $utente  = $request->user();
        $accesso = new \App\Service\Zone\Accesso($this->conn);

        $cantieri = (new \App\Repository\Zone\AccessoRepository($this->conn))
            ->cantieriDi((int)($utente->id ?? 0));

        foreach ($cantieri as &$c) {
            $c['id']      = (int)$c['id'];
            $c['accessi'] = $accesso->tutti($utente, $c['id']);
        }
        unset($c);

        Response::json([
            'success'  => true,
            'cantieri' => $cantieri,
            'famiglie' => \App\Service\Zone\Accesso::FAMIGLIE,
        ]);
    }

    // ── GET /api/v1/me/home ──────────────────────────────────────────────────

    /**
     * La prima schermata dell'app, in una chiamata sola.
     *
     * /api/v1/dashboard non serve a un operaio: tutto quello che calcola sta
     * dietro ai permessi di modulo, e un operaio non ne ha nessuno — gli
     * torna vuota. Questa risponde alle domande che si fa davvero: dove vado
     * adesso, cosa mi manca, mi hanno risposto.
     *
     * Una chiamata sola perche' la linea in cantiere va piano e tre giri
     * all'apertura si sentono tutti.
     *
     * Il menu lo decide il server, con le etichette gia' tradotte. L'app
     * disegna quello che riceve e **salta le voci che non conosce**: cosi'
     * quando in BOB nasce una sezione nuova le installazioni vecchie la
     * ignorano invece di rompersi, e non si deve costringere centoquaranta
     * persone ad aggiornare per cambiare un permesso.
     */
    public function home(Request $request): never
    {
        $utente  = $request->user();
        $operaio = (int)($utente->worker_id ?? 0);
        $lingua  = \App\Service\Lingua::normalizza($utente->lingua ?? null);

        if (!$operaio) {
            Response::json([
                'success' => false,
                'code'    => 'non_collegato',
                'message' => \App\Service\Lingua::testo('msg_non_collegato', $lingua),
            ], 403);
        }

        $dati = $this->dati();
        $oggi = date('Y-m-d');

        // La giornata che ha in testa: oggi finche' c'e', poi domani.
        $giorni    = $dati->pianificazione($operaio, $oggi, date('Y-m-d', strtotime('+1 day')));
        $prossimo  = $giorni[0] ?? null;

        $repo      = new RichiestaPresenzaRepository($this->conn);
        $daSegnare = $repo->giorniSenzaNiente($operaio, date('Y-m-d', strtotime('-13 days')), $oggi);

        $attesaPresenze = $this->conn->prepare(
            "SELECT COUNT(*) FROM bb_presenze_richieste WHERE worker_id = :w AND stato = 'in_attesa'"
        );
        $attesaPresenze->execute([':w' => $operaio]);

        $attesaAssenze = $this->conn->prepare(
            "SELECT COUNT(*) FROM bb_ferie_permessi WHERE worker_id = :w AND stato = 'in_attesa'"
        );
        $attesaAssenze->execute([':w' => $operaio]);

        $cantieriZone = (new \App\Repository\Zone\AccessoRepository($this->conn))
            ->cantieriDi((int)$utente->id);

        $menu = [
            ['chiave' => 'oggi',      'titolo' => \App\Service\Lingua::testo('menu_oggi', $lingua)],
            ['chiave' => 'presenze',  'titolo' => \App\Service\Lingua::testo('menu_presenze', $lingua)],
            ['chiave' => 'assenze',   'titolo' => \App\Service\Lingua::testo('menu_assenze', $lingua)],
            ['chiave' => 'documenti', 'titolo' => \App\Service\Lingua::testo('documenti', $lingua)],
        ];
        // I cantieri compaiono solo a chi ne ha almeno uno: una voce che
        // porta a un elenco vuoto e' una voce che fa chiedere "e qui cosa
        // dovrei vedere?".
        if ($cantieriZone) {
            $menu[] = ['chiave' => 'cantieri', 'titolo' => \App\Service\Lingua::testo('menu_cantieri', $lingua)];
        }

        Response::json([
            'success' => true,
            'utente'  => [
                'nome'     => trim((string)($utente->first_name ?? '')),
                'lingua'   => $lingua,
                'sei_capo' => (bool)($prossimo['sei_capo'] ?? false),
            ],
            'prossimo'   => $prossimo ? [
                'data'      => $prossimo['data'],
                'e_oggi'    => $prossimo['data'] === $oggi,
                'cantiere'  => $prossimo['cantiere_nome'] ?: $prossimo['cantiere'],
                'codice'    => $prossimo['worksite_code'],
                'luogo'     => $prossimo['location'],
                'auto'      => $prossimo['auto_targa'],
                'trasferta' => (bool)$prossimo['trasferta'],
                'rientro'   => $prossimo['rientro_previsto'] ?? null,
                'squadra'   => $prossimo['squadra'],
            ] : null,
            'da_segnare' => count($daSegnare),
            'in_attesa'  => [
                'presenze' => (int)$attesaPresenze->fetchColumn(),
                'assenze'  => (int)$attesaAssenze->fetchColumn(),
            ],
            'cantieri' => count($cantieriZone),
            'menu'     => $menu,
        ]);
    }

    // ── POST /api/v1/me/password ─────────────────────────────────────────────

    /**
     * Cambia la propria password.
     *
     * Senza questo un operaio nuovo non entra proprio: nasce con
     * must_change_password e il middleware risponde 403 a tutto, mandandolo
     * a una pagina web. Con centoquaranta persone, "apri il browser e
     * cambiala li'" e' il punto dove se ne perde meta'.
     *
     * Stesse regole del web — otto caratteri, conferma, e non una password
     * gia' finita in una violazione nota — perche' due posti che cambiano
     * la stessa cosa con due metri diversi sono un metro solo, il piu'
     * largo dei due.
     *
     * Unico endpoint che risponde anche con must_change_password addosso:
     * e' l'eccezione dichiarata nel middleware, se no non si uscirebbe mai
     * da quella condizione.
     */
    public function cambiaPassword(Request $request): never
    {
        $corpo    = $this->corpo();
        $password = (string)($corpo['password'] ?? '');
        $conferma = (string)($corpo['conferma'] ?? $corpo['confirm_password'] ?? '');

        if (strlen($password) < 8) {
            Response::json([
                'success' => false,
                'message' => 'La password deve avere almeno 8 caratteri',
            ], 422);
        }
        if ($password !== $conferma) {
            Response::json([
                'success' => false,
                'message' => 'Le due password non coincidono',
            ], 422);
        }
        if (function_exists('isPasswordPwned') && isPasswordPwned($password)) {
            Response::json([
                'success' => false,
                'message' => "Questa password e' finita in una violazione nota. Scegline un'altra.",
            ], 422);
        }

        $stmt = $this->conn->prepare(
            'UPDATE bb_users SET password = :pwd, must_change_password = 0 WHERE id = :id'
        );
        $stmt->execute([
            ':pwd' => password_hash($password, PASSWORD_DEFAULT),
            ':id'  => (int)($request->user()->id ?? 0),
        ]);

        Response::json(['success' => true]);
    }

    // ── GET /api/v1/me/documenti/{id}/file ───────────────────────────────────

    /**
     * Il file di un suo documento.
     *
     * L'elenco diceva che il documento c'e' e quando scade, ma il file no:
     * uno in cantiere davanti a un controllo vedeva scritto "Visita medica,
     * valida fino al..." e non aveva niente da far vedere.
     *
     * Solo i suoi: l'id arriva dalla richiesta, quindi il worker_id nella
     * query non e' un filtro di comodo, e' il controllo.
     */
    public function documentoFile(Request $request): never
    {
        $operaio = $this->operaio($request);

        $stmt = $this->conn->prepare(
            'SELECT path FROM bb_worker_documents WHERE id = :id AND worker_id = :wid'
        );
        $stmt->execute([':id' => (int)$request->param('id'), ':wid' => $operaio]);
        $path = $stmt->fetchColumn();

        if (!$path) {
            Response::json(['success' => false, 'message' => 'Documento non trovato'], 404);
        }

        $radice = realpath(dirname(APP_ROOT) . '/cloud');
        $file   = realpath($radice . '/' . $path);

        // Il realpath e il confronto col prefisso non sono formalita': senza,
        // un path con ../ dentro tirerebbe fuori file da mezzo server.
        if (!$file || !is_file($file) || !str_starts_with($file, $radice)) {
            Response::json(['success' => false, 'message' => 'File non disponibile'], 404);
        }

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * L'operaio collegato all'utente.
     *
     * Dall'utente e mai dalla richiesta: un id passato dall'app sarebbe
     * modificabile, e basterebbe cambiare un numero per leggere le presenze e
     * i documenti di un collega.
     */
    private function operaio(Request $request): int
    {
        $user     = $request->user();
        $workerId = (int)($user->worker_id ?? 0);

        if (!$workerId) {
            Response::json([
                'success' => false,
                'message' => 'Questo utente non e\' collegato a nessun operaio',
            ], 403);
        }
        return $workerId;
    }




    /** Data in formato aaaa-mm-gg, o il ripiego se non lo e'. */
    private function data(mixed $valore, string $ripiego): string
    {
        $v = trim((string)$valore);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $ripiego;
        }
        return (new \DateTimeImmutable($v))->format('Y-m-d') === $v ? $v : $ripiego;
    }

    /** @return array<string, mixed> */
    /** I dati dell'operaio, gli stessi che legge il web. */
    private function dati(): \App\Service\Operaio\Dati
    {
        return new \App\Service\Operaio\Dati($this->conn);
    }

    private function corpo(): array
    {
        $grezzo = file_get_contents('php://input') ?: '';
        $dati   = json_decode($grezzo, true);
        return is_array($dati) ? $dati : [];
    }
}
