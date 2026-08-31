<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Poti\MacchinaRepository;
use App\Service\CurrentCompany;
use App\Service\Poti\Audit;
use App\Service\Poti\Giornata;
use App\Service\Poti\Tariffa;
use App\Service\Poti\VistaImpegni;

/**
 * Poti Noleggi — macchine (piattaforme, carrelli, telescopici, ...).
 *
 * Sezione distinta dalle autocarrate perche' qui un noleggio puo' contenere
 * piu' macchine, ognuna con il suo periodo e la sua tariffa, piu' un
 * trasporto unico in testata. Timeline e calendario sono gli stessi delle
 * autocarrate e arrivano da VistaImpegni, cosi' le due sezioni non
 * divergono su come si disegnano gli impegni.
 */
final class NoleggiController
{
    private const STATI_MACCHINA = ['attiva', 'manutenzione', 'dismessa'];
    private const STATI_NOLEGGIO = ['confermato', 'annullato'];
    private const PAGAMENTI      = ['da_pagare', 'pagata'];
    private const UNITA          = ['giorno', 'mese', 'tantum'];

    /** Quante schede per pagina nell'elenco noleggi e nell'elenco mezzi. */
    private const PER_PAGINA         = 25;
    private const MEZZI_PER_PAGINA   = 50;

    public function __construct(private \PDO $conn) {}

    // ── GET /noleggi — disponibilita' ────────────────────────────────────────

    public function index(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $dal = VistaImpegni::data($_GET['dal'] ?? '', date('Y-m-d'));
        $al  = VistaImpegni::data($_GET['al'] ?? '', date('Y-m-d', strtotime($dal . ' +29 days')));
        if ($al < $dal) {
            $al = $dal;
        }

        // ── Prima la domanda, poi i mezzi ────────────────────────────────
        // La pagina non parte piu' elencando il parco. Con centinaia di
        // mezzi quell'elenco non risponde a niente: chi arriva qui non
        // vuole sapere quali macchine esistono, vuole sapere cosa e' libero
        // nei giorni che ha in mente. Quindi si chiede prima il periodo, e
        // l'elenco compare solo dopo, gia' ridotto ai mezzi disponibili.
        $cercaDal = VistaImpegni::data($_GET['cerca_dal'] ?? '', '');
        $cercaAl  = VistaImpegni::data($_GET['cerca_al'] ?? '', '');
        if ($cercaDal && $cercaAl && $cercaAl < $cercaDal) {
            [$cercaDal, $cercaAl] = [$cercaAl, $cercaDal];   // date invertite
        }
        $ricercaAttiva = (bool)($cercaDal && $cercaAl);

        $tipo   = trim((string)($_GET['tipo'] ?? ''));
        $cerca  = trim((string)($_GET['q'] ?? ''));
        $stato  = in_array($_GET['stato'] ?? '', self::STATI_MACCHINA, true)
                  ? (string)$_GET['stato'] : '';
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $macchine = [];
        $esito    = ['righe' => [], 'totale' => 0];

        if ($ricercaAttiva) {
            $esito = $repo->elencoMacchine($cid, [
                'q'         => $cerca,
                'tipo'      => $tipo,
                'stato'     => $stato,
                'liberiDal' => $cercaDal,
                'liberiAl'  => $cercaAl,
            ], $pagina, self::MEZZI_PER_PAGINA);
            $macchine = $esito['righe'];
        }

        // Il calendario invece guarda tutto il parco: e' il colpo d'occhio
        // sul periodo e ha senso solo con dentro tutti gli impegni. Non
        // costa come la vecchia griglia, perche' raggruppa per giorno
        // invece di disegnare una riga per mezzo.
        $impegni = $this->impegni($repo->righeNelPeriodo($cid, $dal, $al));

        Response::view('poti/noleggi/disponibilita.html.twig', $request, [
            'macchine'      => $macchine,
            'tipi'          => $repo->tipi($cid),
            'tipo'          => $tipo,
            'cerca'         => $cerca,
            'stato'         => $stato,
            'stati'         => self::STATI_MACCHINA,
            'calendario'    => VistaImpegni::calendario($dal, $al, $impegni),
            'dal'           => $dal,
            'al'            => $al,
            'cercaDal'      => $cercaDal,
            'cercaAl'       => $cercaAl,
            'ricercaAttiva' => $ricercaAttiva,
        ] + $this->paginazione($pagina, $esito['totale'], self::MEZZI_PER_PAGINA, [
            'q' => $cerca, 'tipo' => $tipo, 'stato' => $stato,
            'dal' => $dal, 'al' => $al,
            'cerca_dal' => $cercaDal, 'cerca_al' => $cercaAl,
        ]));
    }

    // ── GET /noleggi/elenco ──────────────────────────────────────────────────

    public function elenco(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $dal   = VistaImpegni::data($_GET['dal'] ?? '', date('Y-m-01'));
        $al    = VistaImpegni::data($_GET['al'] ?? '', date('Y-m-d', strtotime($dal . ' +2 months')));
        $cerca = trim((string)($_GET['q'] ?? ''));

        $filtri = [
            'dal'       => $dal,
            'al'        => $al,
            'q'         => $cerca,
            'stato'     => in_array($_GET['stato'] ?? '', self::STATI_NOLEGGIO, true)
                           ? (string)$_GET['stato'] : '',
            'pagamento' => in_array($_GET['pagamento'] ?? '', self::PAGAMENTI, true)
                           ? (string)$_GET['pagamento'] : '',
        ];

        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $esito  = $repo->noleggi($cid, $filtri, $pagina, self::PER_PAGINA);

        Response::view('poti/noleggi/elenco.html.twig', $request, [
            'noleggi'    => $esito['righe'],
            'macchine'   => $repo->macchine($cid, true),
            'stati'      => self::STATI_NOLEGGIO,
            'pagamenti'  => self::PAGAMENTI,
            'utenteNome' => $this->nomeUtente($request),
            'assicPerc'  => Tariffa::ASSICURAZIONE_PERC,
            'dal'        => $dal,
            'al'         => $al,
            'cerca'      => $cerca,
            'filtri'     => $filtri,
            'salvato'    => isset($_GET['salvato']),
            'errore'     => $_GET['errore'] ?? null,
        ] + $this->paginazione($pagina, $esito['totale'], self::PER_PAGINA, [
            'q'         => $cerca,
            'dal'       => $dal,
            'al'        => $al,
            'stato'     => $filtri['stato'],
            'pagamento' => $filtri['pagamento'],
        ]));
    }

    // ── POST /noleggi/salva ──────────────────────────────────────────────────

    public function salva(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $id      = (int)($_POST['id'] ?? 0) ?: null;
        $cliente = trim((string)($_POST['cliente'] ?? ''));

        if ($cliente === '') {
            $this->tornaConErrore('Il cliente e\' obbligatorio');
        }

        $righe = $this->righeDalForm();
        if (!$righe) {
            $this->tornaConErrore('Serve almeno un mezzo con le sue date');
        }

        // Il doppio impegno si blocca qui, riga per riga: senza questo
        // controllo la stessa macchina finirebbe in due cantieri nello
        // stesso giorno. Si esclude il noleggio che si sta modificando,
        // altrimenti si accavallerebbe con se stesso.
        $stato = in_array($_POST['stato'] ?? '', self::STATI_NOLEGGIO, true)
            ? (string)$_POST['stato']
            : 'confermato';

        if ($stato !== 'annullato') {
            foreach ($righe as $r) {
                $scontri = $repo->sovrapposizioni($cid, (int)$r['macchina_id'],
                                                  $r['data_inizio'], $r['data_fine'], $id);
                if ($scontri) {
                    $m = $repo->macchina($cid, (int)$r['macchina_id']);
                    $this->tornaConErrore(sprintf(
                        '%s gia\' impegnato dal %s al %s per %s',
                        $m['matricola'] ?? 'Mezzo',
                        date('d/m/Y', strtotime($scontri[0]['data_inizio'])),
                        date('d/m/Y', strtotime($scontri[0]['data_fine'])),
                        $scontri[0]['cliente']
                    ));
                }
            }
        }

        // lo stato precedente si legge prima di scrivere: dopo non c'e' piu'
        $prima = $id ? $repo->noleggio($cid, $id) : null;

        // ── Assicurazione ────────────────────────────────────────────────
        // Percentuale sui soli mezzi: il trasporto non si assicura. Il conto
        // lo rifa' il server anche se il browser l'ha gia' mostrato, perche'
        // quello che arriva dal form e' solo una proposta.
        $assicurato = !empty($_POST['assicurazione']);
        $perc       = VistaImpegni::importo($_POST['assicurazione_perc'] ?? '');
        $perc       = $perc !== '' ? (float)$perc : Tariffa::ASSICURAZIONE_PERC;
        $perc       = max(0.0, min(100.0, $perc));

        $totaleMezzi   = Tariffa::totaleMezzi($righe);
        $importoAssic  = $assicurato ? Tariffa::assicurazione($totaleMezzi, $perc) : 0.0;

        $trasporto = VistaImpegni::importo($_POST['trasporto'] ?? '');
        $totale    = VistaImpegni::importo($_POST['totale'] ?? '');

        // Totale non compilato: si somma qui. Se e' stato scritto a mano si
        // lascia stare, e' una cifra concordata col cliente.
        if ($totale === '') {
            $totale = number_format(
                $totaleMezzi + ($trasporto !== '' ? (float)$trasporto : 0.0) + $importoAssic,
                2, '.', ''
            );
        }

        $nuovoId = $repo->salvaNoleggio($cid, $id, [
            'cliente'   => $cliente,
            'telefono'  => trim((string)($_POST['telefono'] ?? '')),
            'luogo'     => trim((string)($_POST['luogo'] ?? '')),
            'contratto' => trim((string)($_POST['contratto'] ?? '')),
            'contratto_firmato' => !empty($_POST['contratto_firmato']),
            'stato'     => $stato,
            'trasporto' => $trasporto,
            'totale'    => $totale,
            'assicurazione'         => $assicurato,
            'assicurazione_perc'    => $assicurato ? number_format($perc, 2, '.', '') : '',
            'assicurazione_importo' => $assicurato ? number_format($importoAssic, 2, '.', '') : '',
            'pagamento' => in_array($_POST['pagamento'] ?? '', self::PAGAMENTI, true)
                           ? (string)$_POST['pagamento'] : 'da_pagare',
            'note'      => trim((string)($_POST['note'] ?? '')),
        ], $righe, (int)$this->utente($request)->id);

        (new Audit($this->conn))->registra(
            $cid, 'noleggio', $nuovoId, $id ? 'modificato' : 'creato',
            $prima, $repo->noleggio($cid, $nuovoId),
            (int)$this->utente($request)->id, $cliente
        );

        Response::redirect('/noleggi/elenco?salvato=1');
    }

    // ── POST /noleggi/elimina ────────────────────────────────────────────────

    public function elimina(Request $request): void
    {
        $this->assertAccess($request);
        $id   = (int)($_POST['id'] ?? 0);
        $cid  = $this->companyId();
        $repo = new MacchinaRepository($this->conn);

        if ($id && ($prima = $repo->noleggio($cid, $id))) {
            $repo->eliminaNoleggio($cid, $id, (int)$this->utente($request)->id);

            // lo stato completo, righe comprese, finisce nel registro: e' da
            // li' che si rimette indietro un noleggio tolto per sbaglio
            (new Audit($this->conn))->registra(
                $cid, 'noleggio', $id, 'eliminato',
                $prima, null,
                (int)$this->utente($request)->id, (string)$prima['cliente']
            );
        }
        Response::redirect('/noleggi/elenco?salvato=1');
    }

    // ── POST /noleggi/ripristina ─────────────────────────────────────────────

    public function ripristina(Request $request): void
    {
        $this->assertAccess($request);
        $id   = (int)($_POST['id'] ?? 0);
        $cid  = $this->companyId();
        $repo = new MacchinaRepository($this->conn);

        if ($id) {
            $repo->ripristinaNoleggio($cid, $id);
            $dopo = $repo->noleggio($cid, $id);
            (new Audit($this->conn))->registra(
                $cid, 'noleggio', $id, 'ripristinato',
                null, $dopo,
                (int)$this->utente($request)->id, (string)($dopo['cliente'] ?? '')
            );
        }
        Response::redirect('/noleggi/registro?ripristinato=1');
    }

    // ── GET /noleggi/registro ────────────────────────────────────────────────

    public function registro(Request $request): void
    {
        $this->assertAccess($request);
        $audit  = new Audit($this->conn);
        $cid    = $this->companyId();
        $entita = ['macchina', 'noleggio'];

        $filtri = [
            'azione' => trim((string)($_GET['azione'] ?? '')),
            'utente' => (int)($_GET['utente'] ?? 0),
            'dal'    => VistaImpegni::data($_GET['dal'] ?? '', ''),
            'al'     => VistaImpegni::data($_GET['al'] ?? '', ''),
        ];

        $voci = $audit->voci($cid, $entita, $filtri);

        // conteggio per tipo di operazione: fatto qui perche' sommarlo nel
        // template richiederebbe un set dentro un ciclo, che in Twig non si
        // conserva da un giro all'altro
        $conteggi = ['creato' => 0, 'modificato' => 0, 'eliminato' => 0, 'ripristinato' => 0];
        foreach ($voci as $v) {
            if (isset($conteggi[$v['azione']])) {
                $conteggi[$v['azione']]++;
            }
        }

        Response::view('poti/registro.html.twig', $request, [
            'conteggi'     => $conteggi,
            'sezione'      => 'Mezzi sollevamento',
            'tornaA'       => '/noleggi/elenco',
            'urlRipristina'=> '/noleggi/ripristina',
            'voci'         => $voci,
            'utenti'       => $audit->utenti($cid, $entita),
            'filtri'       => $filtri,
            'ripristinato' => isset($_GET['ripristinato']),
        ]);
    }

    // ── GET /noleggi/occupate ────────────────────────────────────────────────
    // Macchine gia' impegnate nel periodo, per toglierle dall'elenco invece
    // di lasciarle scegliere e rifiutarle al salvataggio. E' un aiuto: il
    // controllo che vale resta quello al salvataggio.

    public function occupate(Request $request): void
    {
        $this->assertAccess($request);

        $dal = VistaImpegni::data($_GET['dal'] ?? '', '');
        $al  = VistaImpegni::data($_GET['al'] ?? '', '');

        if (!$dal || !$al || $al < $dal) {
            Response::json(['ok' => false, 'occupate' => []]);
        }

        Response::json([
            'ok'       => true,
            'occupate' => (new MacchinaRepository($this->conn))->occupateTra(
                $this->companyId(), $dal, $al, (int)($_GET['escludi'] ?? 0) ?: null
            ),
        ]);
    }

    // ── GET /noleggi/macchine ────────────────────────────────────────────────

    public function macchine(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $cerca  = trim((string)($_GET['q'] ?? ''));
        $tipo   = trim((string)($_GET['tipo'] ?? ''));
        $stato  = in_array($_GET['stato'] ?? '', self::STATI_MACCHINA, true)
                  ? (string)$_GET['stato'] : '';
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $esito = $repo->elencoMacchine(
            $cid, ['q' => $cerca, 'tipo' => $tipo, 'stato' => $stato],
            $pagina, self::MEZZI_PER_PAGINA
        );

        Response::view('poti/noleggi/macchine.html.twig', $request, [
            'macchine' => $esito['righe'],
            'tipi'     => $repo->tipi($cid),
            'stati'    => self::STATI_MACCHINA,
            'cerca'    => $cerca,
            'tipo'     => $tipo,
            'stato'    => $stato,
            'salvato'  => isset($_GET['salvato']),
            'errore'   => $_GET['errore'] ?? null,
        ] + $this->paginazione($pagina, $esito['totale'], self::MEZZI_PER_PAGINA, [
            'q' => $cerca, 'tipo' => $tipo, 'stato' => $stato,
        ]));
    }

    // ── POST /noleggi/macchine/salva ─────────────────────────────────────────

    public function salvaMacchina(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $id        = (int)($_POST['id'] ?? 0) ?: null;
        $matricola = strtoupper(trim((string)($_POST['matricola'] ?? '')));
        $tipo      = trim((string)($_POST['tipo'] ?? ''));

        if ($matricola === '' || $tipo === '') {
            Response::redirect('/noleggi/macchine?errore=' . urlencode('Tipo e matricola sono obbligatori'));
        }
        if ($repo->matricolaInUso($cid, $matricola, $id)) {
            Response::redirect('/noleggi/macchine?errore=' . urlencode("La matricola {$matricola} esiste gia'"));
        }

        $stato = in_array($_POST['stato'] ?? '', self::STATI_MACCHINA, true)
            ? (string)$_POST['stato'] : 'attiva';

        $prima = $id ? $repo->macchina($cid, $id) : null;

        $nuovoId = $repo->salvaMacchina($cid, $id, [
            'tipo'          => $tipo,
            'matricola'     => $matricola,
            'modello'       => trim((string)($_POST['modello'] ?? '')),
            'altezza_max_m' => VistaImpegni::importo($_POST['altezza_max_m'] ?? ''),
            'portata_kg'    => trim((string)($_POST['portata_kg'] ?? '')),
            'note'          => trim((string)($_POST['note'] ?? '')),
            'stato'         => $stato,
        ]);

        (new Audit($this->conn))->registra(
            $cid, 'macchina', $nuovoId, $id ? 'modificato' : 'creato',
            $prima, $repo->macchina($cid, $nuovoId),
            (int)$this->utente($request)->id, $matricola
        );

        Response::redirect('/noleggi/macchine?salvato=1');
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * Righe del noleggio inviate dal form.
     *
     * Le righe senza macchina o senza date si scartano invece di far fallire
     * tutto: nel form ne resta sempre qualcuna vuota in fondo.
     *
     * @return array<int, array<string, string>>
     */
    private function righeDalForm(): array
    {
        $macchine = (array)($_POST['riga_macchina'] ?? []);
        $out      = [];

        foreach ($macchine as $i => $macchinaId) {
            $macchinaId = (int)$macchinaId;
            $dal = VistaImpegni::data($_POST['riga_dal'][$i] ?? '', '');
            $al  = VistaImpegni::data($_POST['riga_al'][$i] ?? '', '');

            if (!$macchinaId || !$dal || !$al) {
                continue;
            }
            if ($al < $dal) {
                [$dal, $al] = [$al, $dal];   // date invertite: si raddrizzano
            }

            $unita = in_array($_POST['riga_unita'][$i] ?? '', self::UNITA, true)
                ? (string)$_POST['riga_unita'][$i]
                : 'giorno';

            // Dal form arriva una tariffa sola: qui si mette nella colonna
            // che le corrisponde. Le due colonne restano separate perche'
            // ottanta euro al giorno e ottanta al mese non sono lo stesso
            // numero, e cambiando unita' la vecchia cifra non deve
            // sopravvivere come se fosse ancora buona.
            $tariffa = VistaImpegni::importo($_POST['riga_tariffa'][$i] ?? '');

            $riga = [
                'macchina_id'    => (string)$macchinaId,
                'data_inizio'    => $dal,
                'data_fine'      => $al,
                'unita'          => $unita,
                'tariffa_giorno' => $unita === 'mese' ? '' : $tariffa,
                'tariffa_mese'   => $unita === 'mese' ? $tariffa : '',
                'totale'         => VistaImpegni::importo($_POST['riga_totale'][$i] ?? ''),
                'note'           => trim((string)($_POST['riga_note'][$i] ?? '')),
            ];

            // Un totale non scritto si calcola qui e non si lascia vuoto: il
            // browser lo compila mentre si scrive, ma il conto che vale e'
            // questo. Se e' stato scritto a mano si rispetta com'e', perche'
            // capita di concordare una cifra tonda diversa dal calcolo.
            if ($riga['totale'] === '') {
                $riga['totale'] = number_format(Tariffa::totaleRiga($riga), 2, '.', '');
            }

            $out[] = $riga;
        }
        return $out;
    }

    /**
     * Righe dei noleggi nella forma attesa da VistaImpegni.
     * @return array<int, array<string, mixed>>
     */
    private function impegni(array $righe): array
    {
        $out = [];
        foreach ($righe as $r) {
            $out[] = [
                'risorsa'     => (int)$r['macchina_id'],
                'etichetta'   => (string)$r['matricola'],
                'cliente'     => (string)$r['cliente'],
                'luogo'       => (string)($r['luogo'] ?? ''),
                'stato'       => (string)$r['stato'],
                'data_inizio' => (string)$r['data_inizio'],
                'data_fine'   => (string)$r['data_fine'],
            ];
        }
        return $out;
    }

    /**
     * I numeri della paginazione, gia' pronti per il template.
     *
     * @return array{pagina:int, pagine:int, totale:int, primo:int, ultimo:int}
     */
    private function paginazione(int $pagina, int $totale, int $perPagina, array $query = []): array
    {
        $pagine = max(1, (int)ceil($totale / $perPagina));
        $pagina = min($pagina, $pagine);

        return [
            'pagina' => $pagina,
            'pagine' => $pagine,
            'totale' => $totale,
            'primo'  => $totale ? ($pagina - 1) * $perPagina + 1 : 0,
            'ultimo' => min($pagina * $perPagina, $totale),
            'qs'     => $this->queryString($query),
        ];
    }

    /**
     * I filtri in forma di query string, pronti da appendere ai link della
     * paginazione. I vuoti si tolgono: un indirizzo pieno di parametri a
     * zero e' illeggibile e non aggiunge niente.
     */
    private function queryString(array $query): string
    {
        $pieni = array_filter($query, static fn($v) => $v !== '' && $v !== null);
        return $pieni ? '&' . http_build_query($pieni) : '';
    }

    private function tornaConErrore(string $messaggio): never
    {
        Response::redirect('/noleggi/elenco?errore=' . urlencode($messaggio));
    }

    private function utente(Request $request): \User
    {
        $user = $request->user();
        if (!$user) {
            Response::error('Accesso negato', 403);
        }
        return $user;
    }

    private function nomeUtente(Request $request): string
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))), ''),
                            username) AS nome
            FROM bb_users WHERE id = :id
        ");
        $stmt->execute([':id' => (int)$this->utente($request)->id]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    private function assertAccess(Request $request): void
    {
        $user = $this->utente($request);
        if ((int)$user->id !== 1 && !$user->canAccess('pn_noleggi')) {
            Response::error("Permesso 'pn_noleggi' richiesto", 403);
        }
    }

    private function companyId(): int
    {
        $service = $GLOBALS['currentCompany'] ?? new CurrentCompany($this->conn);
        return $service->id();
    }

    // ── GET /noleggi/giornata — vista dei tecnici ────────────────────────────

    /**
     * La giornata dei mezzi: cosa esce, cosa rientra, cosa e' fuori e cosa
     * e' in ritardo. Ragiona su un giorno e non mostra importi, come la
     * pagina gemella delle autocarrate.
     */
    public function giornata(Request $request): void
    {
        $this->assertGiornata($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $data = VistaImpegni::data($_GET['data'] ?? '', date('Y-m-d'));
        $user = $this->utente($request);

        // Stessa pagina delle autocarrate: qui l'unita' e' la singola
        // macchina del noleggio, ma la scheda che ne esce e' identica.
        $blocchi = Giornata::blocchi($repo->giornata($cid, $data), Giornata::MACCHINA);

        $collegamenti = [];
        if ($user->canAccess('pn_noleggi')) {
            $collegamenti = [
                ['href' => '/noleggi', 'label' => "Disponibilita'"],
                ['href' => '/noleggi/elenco', 'label' => 'Noleggi'],
                ['href' => '/noleggi/macchine', 'label' => 'Mezzi'],
            ];
        }

        $vista = [
            'titolo'       => 'Giornata mezzi sollevamento',
            'sottotitolo'  => "Cosa esce, cosa rientra e cosa e' ancora fuori.",
            'base'         => '/noleggi/giornata',
            'azione'       => '/noleggi/giornata/segna',
            'collegamenti' => $collegamenti,
            'data'         => $data,
            'ieri'         => date('Y-m-d', strtotime($data . ' -1 day')),
            'domani'       => date('Y-m-d', strtotime($data . ' +1 day')),
            'oggi'         => date('Y-m-d'),
            'blocchi'      => $blocchi,
            'riepilogo'    => Giornata::riepilogo($blocchi),
            'prossime'     => Giornata::prossime(
                $repo->prossimeConsegne($cid, $data, 14), Giornata::MACCHINA
            ),
            'salvato'      => isset($_GET['salvato']),
        ];

        // Dopo un tocco la pagina chiede solo il pezzo che cambia: riepilogo
        // e schede. Rimandare tutto — menu, barra, prossime partenze — per
        // aggiornare una riga sarebbe sprecato su un telefono in officina.
        if (isset($_GET['frammento'])) {
            Response::view('poti/_giornata_corpo.html.twig', $request, $vista);
        }

        Response::view('poti/giornata.html.twig', $request, $vista);
    }

    // ── POST /noleggi/giornata/segna ─────────────────────────────────────────

    /**
     * Consegna e rientro riguardano la RIGA (la singola macchina), la firma
     * del contratto riguarda il NOLEGGIO: per questo arrivano due id diversi.
     */
    public function segna(Request $request): void
    {
        $this->assertGiornata($request);
        $repo = new MacchinaRepository($this->conn);
        $cid  = $this->companyId();

        $cosa       = (string)($_POST['cosa'] ?? '');
        $rigaId     = (int)($_POST['riga_id'] ?? 0);
        $noleggioId = (int)($_POST['noleggio_id'] ?? 0);
        $data       = VistaImpegni::data($_POST['data'] ?? '', date('Y-m-d'));

        $prima = $noleggioId ? $repo->noleggio($cid, $noleggioId) : null;

        if ($prima) {
            if ($cosa === 'firma') {
                $repo->segnaContrattoFirmato($cid, $noleggioId, empty($prima['contratto_firmato']));
            } elseif ($rigaId && in_array($cosa, ['consegnato', 'rientrato'], true)) {
                $repo->segnaMomento($cid, $rigaId, $cosa, (int)$this->utente($request)->id);
            }

            (new Audit($this->conn))->registra(
                $cid, 'noleggio', $noleggioId, 'modificato',
                $prima, $repo->noleggio($cid, $noleggioId),
                (int)$this->utente($request)->id, (string)$prima['cliente']
            );
        }

        // come nelle autocarrate: il tocco arriva via AJAX e la pagina si
        // aggiorna sul posto, senza far ripartire l'elenco da capo
        if ($request->isAjax()) {
            Response::json(['ok' => $prima !== null]);
        }

        Response::redirect('/noleggi/giornata?data=' . $data . '&salvato=1');
    }

    /**
     * Chi puo' vedere la giornata: chi gestisce il modulo, oppure chi ha il
     * solo permesso da tecnico. Quella pagina non mostra importi e non crea
     * ne' elimina noleggi.
     */
    private function assertGiornata(Request $request): void
    {
        $user = $this->utente($request);
        if ((int)$user->id === 1
            || $user->canAccess('pn_noleggi')
            || $user->canAccess('pn_noleggi_giornata')) {
            return;
        }
        Response::error("Permesso 'pn_noleggi_giornata' richiesto", 403);
    }
}
