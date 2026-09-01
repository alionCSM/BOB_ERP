<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Poti\AutocarrataRepository;
use App\Service\CurrentCompany;
use App\Service\Poti\Audit;
use App\Service\Poti\Giornata;
use App\Service\Poti\VistaImpegni;

/**
 * Poti Noleggi — autocarrate, prenotazioni e disponibilita'.
 *
 * Primo modulo scritto gia' per il multi-societa': tutte le letture e le
 * scritture passano dall'id della societa' attiva, mai da tutta la tabella.
 */
final class AutocarrateController
{
    private const STATI_MEZZO = ['attiva', 'manutenzione', 'dismessa'];
    private const STATI_PREN  = ['confermata', 'annullata'];
    private const PAGAMENTI   = ['da_pagare', 'pagata'];

    public function __construct(private \PDO $conn) {}

    // ── GET /autocarrate — disponibilita' ────────────────────────────────────

    public function index(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new AutocarrataRepository($this->conn);
        $cid  = $this->companyId();

        // finestra mostrata nella timeline: di default il mese in corso a
        // partire da oggi, che e' l'orizzonte utile al telefono
        $dal = $this->data($_GET['dal'] ?? '', date('Y-m-d'));
        $al  = $this->data($_GET['al'] ?? '', date('Y-m-d', strtotime($dal . ' +29 days')));
        if ($al < $dal) {
            $al = $dal;
        }

        $mezzi        = $repo->mezzi($cid);
        $prenotazioni = $repo->prenotazioni($cid, $dal, $al);
        $liberoDal    = $repo->primoGiornoLibero($cid, date('Y-m-d'));

        // filtro "mi serve dal … al …": restano solo i mezzi liberi
        $cercaDal = $this->data($_GET['cerca_dal'] ?? '', '');
        $cercaAl  = $this->data($_GET['cerca_al'] ?? '', '');
        $occupati = [];
        if ($cercaDal && $cercaAl && $cercaAl >= $cercaDal) {
            foreach ($repo->prenotazioni($cid, $cercaDal, $cercaAl) as $p) {
                $occupati[(int)$p['autocarrata_id']] = true;
            }
        }

        $giorni = $this->giorni($dal, $al);

        Response::view('poti/autocarrate/disponibilita.html.twig', $request, [
            'mezzi'        => $mezzi,
            'prenotazioni' => $prenotazioni,
            'griglia'      => $this->griglia($prenotazioni, $giorni),
            'calendario'   => $this->calendario($dal, $al, $prenotazioni),
            'liberoDal'    => $liberoDal,
            'giorni'       => $giorni,
            'dal'          => $dal,
            'al'           => $al,
            'cercaDal'     => $cercaDal,
            'cercaAl'      => $cercaAl,
            'occupati'     => $occupati,
            'ricercaAttiva'=> (bool)($cercaDal && $cercaAl),
        ]);
    }

    // ── GET /autocarrate/mezzi ───────────────────────────────────────────────

    public function mezzi(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new AutocarrataRepository($this->conn);

        Response::view('poti/autocarrate/mezzi.html.twig', $request, [
            'mezzi'   => $repo->mezzi($this->companyId()),
            'stati'   => self::STATI_MEZZO,
            'salvato' => isset($_GET['salvato']),
            'errore'  => $_GET['errore'] ?? null,
        ]);
    }

    // ── POST /autocarrate/mezzi/salva ────────────────────────────────────────

    public function salvaMezzo(Request $request): void
    {
        $this->assertAccess($request);
        $repo  = new AutocarrataRepository($this->conn);
        $cid   = $this->companyId();

        $id    = (int)($_POST['id'] ?? 0) ?: null;
        $targa = strtoupper(trim((string)($_POST['targa'] ?? '')));

        if ($targa === '') {
            Response::redirect('/autocarrate/mezzi?errore=' . urlencode('La targa e\' obbligatoria'));
        }
        if ($repo->targaInUso($cid, $targa, $id)) {
            Response::redirect('/autocarrate/mezzi?errore=' . urlencode("La targa {$targa} esiste gia'"));
        }

        $stato = (string)($_POST['stato'] ?? 'attiva');
        if (!in_array($stato, self::STATI_MEZZO, true)) {
            $stato = 'attiva';
        }

        // lo stato precedente si legge prima di scrivere: dopo non c'e' piu'
        $prima = $id ? $repo->mezzo($cid, $id) : null;

        $nuovoId = $repo->salvaMezzo($cid, $id, [
            'targa'         => $targa,
            'modello'       => trim((string)($_POST['modello'] ?? '')),
            'altezza_max_m' => trim((string)($_POST['altezza_max_m'] ?? '')),
            'portata_kg'    => trim((string)($_POST['portata_kg'] ?? '')),
            'note'          => trim((string)($_POST['note'] ?? '')),
            'stato'         => $stato,
        ]);

        (new Audit($this->conn))->registra(
            $cid, 'autocarrata', $nuovoId, $id ? 'modificato' : 'creato',
            $prima, $repo->mezzo($cid, $nuovoId),
            (int)$this->utente($request)->id, $targa
        );

        Response::redirect('/autocarrate/mezzi?salvato=1');
    }

    // ── GET /autocarrate/prenotazioni ────────────────────────────────────────

    public function prenotazioni(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new AutocarrataRepository($this->conn);
        $cid  = $this->companyId();

        $dal = $this->data($_GET['dal'] ?? '', date('Y-m-01'));
        $al  = $this->data($_GET['al'] ?? '', date('Y-m-d', strtotime($dal . ' +2 months')));

        $cerca = trim((string)($_GET['q'] ?? ''));

        Response::view('poti/autocarrate/prenotazioni.html.twig', $request, [
            'prenotazioni' => $repo->prenotazioni($cid, $dal, $al, (int)($_GET['mezzo'] ?? 0) ?: null, $cerca),
            'mezzi'        => $repo->mezzi($cid, true),
            'stati'        => self::STATI_PREN,
            'pagamenti'    => self::PAGAMENTI,
            'utenteNome'   => $this->nomeUtente($request),
            'dal'          => $dal,
            'al'           => $al,
            'mezzoId'      => (int)($_GET['mezzo'] ?? 0),
            'cerca'        => $cerca,
            'salvato'      => isset($_GET['salvato']),
            'errore'       => $_GET['errore'] ?? null,
        ]);
    }

    // ── GET /autocarrate/prenotazioni/occupati ───────────────────────────────
    // Chi e' gia' impegnato nel periodo, per togliere dall'elenco i mezzi
    // che non si possono prenotare. E' solo un aiuto: il controllo che vale
    // resta quello al salvataggio, che vede anche cosa e' cambiato nel
    // frattempo da un altro utente.

    public function occupati(Request $request): void
    {
        $this->assertAccess($request);

        $dal = $this->data($_GET['dal'] ?? '', '');
        $al  = $this->data($_GET['al'] ?? '', '');

        if (!$dal || !$al || $al < $dal) {
            Response::json(['ok' => false, 'occupati' => []]);
        }

        $occupati = (new AutocarrataRepository($this->conn))->occupatiTra(
            $this->companyId(),
            $dal,
            $al,
            (int)($_GET['escludi'] ?? 0) ?: null
        );

        Response::json(['ok' => true, 'occupati' => $occupati]);
    }

    // ── POST /autocarrate/prenotazioni/salva ─────────────────────────────────

    public function salvaPrenotazione(Request $request): void
    {
        $this->assertAccess($request);
        $repo = new AutocarrataRepository($this->conn);
        $cid  = $this->companyId();

        $id      = (int)($_POST['id'] ?? 0) ?: null;
        $mezzoId = (int)($_POST['autocarrata_id'] ?? 0);
        $cliente = trim((string)($_POST['cliente'] ?? ''));
        $dal     = $this->data($_POST['data_inizio'] ?? '', '');
        $al      = $this->data($_POST['data_fine'] ?? '', '');

        if (!$mezzoId || $cliente === '' || !$dal || !$al) {
            Response::redirect('/autocarrate/prenotazioni?errore=' . urlencode('Mezzo, cliente e date sono obbligatori'));
        }
        if ($al < $dal) {
            Response::redirect('/autocarrate/prenotazioni?errore=' . urlencode('La data di fine precede quella di inizio'));
        }
        if (!$repo->mezzo($cid, $mezzoId)) {
            Response::redirect('/autocarrate/prenotazioni?errore=' . urlencode('Autocarrata non valida'));
        }

        $stato = (string)($_POST['stato'] ?? 'confermata');
        if (!in_array($stato, self::STATI_PREN, true)) {
            $stato = 'confermata';
        }

        // il doppio impegno si blocca qui: senza questo controllo la stessa
        // autocarrata finirebbe in due cantieri nello stesso giorno
        if ($stato !== 'annullata') {
            $scontri = $repo->sovrapposizioni($cid, $mezzoId, $dal, $al, $id);
            if ($scontri) {
                $primo = $scontri[0];
                Response::redirect('/autocarrate/prenotazioni?errore=' . urlencode(sprintf(
                    'Mezzo gia\' impegnato dal %s al %s per %s',
                    date('d/m/Y', strtotime($primo['data_inizio'])),
                    date('d/m/Y', strtotime($primo['data_fine'])),
                    $primo['cliente']
                )));
            }
        }

        $prima = $id ? $repo->prenotazione($cid, $id) : null;

        $nuovoId = $repo->salvaPrenotazione($cid, $id, [
            'autocarrata_id' => $mezzoId,
            'cliente'        => $cliente,
            'telefono'       => trim((string)($_POST['telefono'] ?? '')),
            'luogo'          => trim((string)($_POST['luogo'] ?? '')),
            'data_inizio'    => $dal,
            'data_fine'      => $al,
            'stato'          => $stato,
            'tariffa_giorno' => $this->importo($_POST['tariffa_giorno'] ?? ''),
            'totale'         => $this->importo($_POST['totale'] ?? ''),
            'contratto'      => trim((string)($_POST['contratto'] ?? '')),
            'contratto_firmato' => !empty($_POST['contratto_firmato']),
            'pagamento'      => in_array($_POST['pagamento'] ?? '', self::PAGAMENTI, true)
                                ? (string)$_POST['pagamento']
                                : 'da_pagare',
            'note'           => trim((string)($_POST['note'] ?? '')),
        ], (int)$this->utente($request)->id);

        (new Audit($this->conn))->registra(
            $cid, 'prenotazione', $nuovoId, $id ? 'modificato' : 'creato',
            $prima, $repo->prenotazione($cid, $nuovoId),
            (int)$this->utente($request)->id, $cliente
        );

        Response::redirect('/autocarrate/prenotazioni?salvato=1');
    }

    // ── POST /autocarrate/prenotazioni/elimina ───────────────────────────────

    public function eliminaPrenotazione(Request $request): void
    {
        $this->assertAccess($request);
        $id   = (int)($_POST['id'] ?? 0);
        $cid  = $this->companyId();
        $repo = new AutocarrataRepository($this->conn);

        if ($id && ($prima = $repo->prenotazione($cid, $id))) {
            $repo->eliminaPrenotazione($cid, $id, (int)$this->utente($request)->id);

            // lo stato completo finisce nel registro: e' da li' che si
            // rimette indietro una prenotazione tolta per sbaglio
            (new Audit($this->conn))->registra(
                $cid, 'prenotazione', $id, 'eliminato',
                $prima, null,
                (int)$this->utente($request)->id, (string)$prima['cliente']
            );
        }
        Response::redirect('/autocarrate/prenotazioni?salvato=1');
    }

    // ── POST /autocarrate/ripristina ─────────────────────────────────────────

    public function ripristina(Request $request): void
    {
        $this->assertAccess($request);
        $id   = (int)($_POST['id'] ?? 0);
        $cid  = $this->companyId();
        $repo = new AutocarrataRepository($this->conn);

        if ($id) {
            $repo->ripristinaPrenotazione($cid, $id);
            $dopo = $repo->prenotazione($cid, $id);
            (new Audit($this->conn))->registra(
                $cid, 'prenotazione', $id, 'ripristinato',
                null, $dopo,
                (int)$this->utente($request)->id, (string)($dopo['cliente'] ?? '')
            );
        }
        Response::redirect('/autocarrate/registro?ripristinato=1');
    }

    // ── GET /autocarrate/registro ────────────────────────────────────────────

    public function registro(Request $request): void
    {
        $this->assertAccess($request);
        $audit = new Audit($this->conn);
        $cid   = $this->companyId();
        $entita = ['autocarrata', 'prenotazione'];

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
            'sezione'      => 'Autocarrate',
            'tornaA'       => '/autocarrate/prenotazioni',
            'urlRipristina'=> '/autocarrate/ripristina',
            'voci'         => $voci,
            'utenti'       => $audit->utenti($cid, $entita),
            'filtri'       => $filtri,
            'ripristinato' => isset($_GET['ripristinato']),
        ]);
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    private function utente(Request $request): \User
    {
        $user = $request->user();
        if (!$user) {
            Response::error('Accesso negato', 403);
        }
        return $user;
    }

    /** Nome dell'utente collegato, per mostrarlo come commerciale. */
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
        if ((int)$user->id !== 1 && !$user->canAccess('pn_autocarrate')) {
            Response::error("Permesso 'pn_autocarrate' richiesto", 403);
        }
    }

    private function companyId(): int
    {
        $service = $GLOBALS['currentCompany'] ?? new CurrentCompany($this->conn);
        return $service->id();
    }

    /** Data in formato ISO, o il ripiego se assente o non valida. */
    private function data(mixed $valore, string $ripiego): string
    {
        $v = trim((string)$valore);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $ripiego;
    }

    /** Importo con la virgola accettata al posto del punto. */
    /**
     * Importo scritto all'italiana -> numero.
     *
     * Accetta 1.234,56 (punto per le migliaia, virgola per i decimali),
     * 1234,56 e anche 1234.56 per chi digita all'inglese. Prima si
     * sostituiva soltanto la virgola con il punto: "1.234,56" diventava
     * "1.234.56", non era piu' un numero e l'importo veniva buttato via
     * senza dire niente.
     */
    private function importo(mixed $valore): string
    {
        $v = trim((string)$valore);
        if ($v === '') {
            return '';
        }

        // via simboli di valuta e spazi, compreso quello unificatore che
        // arriva dal copia e incolla
        $v = preg_replace('/[^0-9,.\-]/u', '', $v) ?? '';
        if ($v === '') {
            return '';
        }

        if (str_contains($v, ',')) {
            // c'e' la virgola: e' lei il separatore decimale, quindi i punti
            // rimasti sono migliaia
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (substr_count($v, '.') > 1) {
            // piu' punti e nessuna virgola: sono tutti migliaia (1.234.567)
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? $v : '';
    }

    private const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                          'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

    /**
     * Calendario a mesi con gli impegni di ogni giorno.
     *
     * Le settimane partono da lunedi' e i mesi sono completati con i giorni
     * vuoti agli estremi, altrimenti la griglia risulterebbe sfalsata.
     *
     * @return array<int, array{titolo:string, celle:array}>
     */
    private function calendario(string $dal, string $al, array $prenotazioni): array
    {
        // impegni indicizzati per giorno: cosi' il template non deve
        // scorrere tutte le prenotazioni per ogni casella
        $perGiorno = [];
        foreach ($prenotazioni as $p) {
            for ($g = $p['data_inizio']; $g <= $p['data_fine']; $g = date('Y-m-d', strtotime($g . ' +1 day'))) {
                $perGiorno[$g][] = [
                    'targa'   => $p['targa'],
                    'cliente' => $p['cliente'],
                    'stato'   => $p['stato'],
                    'luogo'   => $p['luogo'] ?? '',
                ];
            }
        }

        $oggi  = date('Y-m-d');
        $mesi  = [];
        $cur   = strtotime(date('Y-m-01', strtotime($dal)));
        $fine  = strtotime(date('Y-m-01', strtotime($al)));

        // tetto: oltre sei mesi la pagina diventa lunghissima e poco utile
        while ($cur <= $fine && count($mesi) < 6) {
            $anno   = (int)date('Y', $cur);
            $mese   = (int)date('n', $cur);
            $giorni = (int)date('t', $cur);

            $celle = [];

            // caselle vuote prima del primo giorno, per allineare i lunedi'
            $primoWd = (int)date('N', mktime(0, 0, 0, $mese, 1, $anno));
            for ($i = 1; $i < $primoWd; $i++) {
                $celle[] = ['vuota' => true];
            }

            for ($g = 1; $g <= $giorni; $g++) {
                $iso = sprintf('%04d-%02d-%02d', $anno, $mese, $g);
                $wd  = (int)date('N', strtotime($iso));
                $celle[] = [
                    'vuota'   => false,
                    'iso'     => $iso,
                    'g'       => $g,
                    'festivo' => $wd >= 6,
                    'oggi'    => $iso === $oggi,
                    'eventi'  => $perGiorno[$iso] ?? [],
                ];
            }

            // e dopo l'ultimo, per chiudere l'ultima riga
            while (count($celle) % 7 !== 0) {
                $celle[] = ['vuota' => true];
            }

            $mesi[] = [
                'titolo' => self::MESI[$mese] . ' ' . $anno,
                'celle'  => $celle,
            ];

            $cur = strtotime('+1 month', $cur);
        }

        return $mesi;
    }

    /**
     * Celle occupate della timeline, calcolate qui e non nel template:
     * cercare la prenotazione per ogni cella dentro Twig vorrebbe dire
     * mezzi x giorni x prenotazioni giri a ogni caricamento.
     *
     * @return array<int, array<string, array{stato:string, testo:string}>>
     */
    private function griglia(array $prenotazioni, array $giorni): array
    {
        if (!$giorni) {
            return [];
        }
        $primo = $giorni[0]['iso'];
        $ultimo = $giorni[count($giorni) - 1]['iso'];

        $out = [];
        foreach ($prenotazioni as $p) {
            $da = max($p['data_inizio'], $primo);
            $a  = min($p['data_fine'], $ultimo);

            $testo = $p['cliente']
                . ' — ' . date('d/m', strtotime($p['data_inizio']))
                . '/' . date('d/m', strtotime($p['data_fine']))
                . ($p['luogo'] ? ' — ' . $p['luogo'] : '');

            for ($g = $da; $g <= $a; $g = date('Y-m-d', strtotime($g . ' +1 day'))) {
                $out[(int)$p['autocarrata_id']][$g] = [
                    'stato'   => $p['stato'],
                    'testo'   => $testo,
                    // servono per arrotondare solo i due capi della barra:
                    // i giorni in mezzo restano squadrati e si saldano fra
                    // loro, cosi' un impegno si legge come un blocco unico
                    'inizio'  => $g === $p['data_inizio'],
                    'fine'    => $g === $p['data_fine'],
                    'cliente' => $p['cliente'],
                ];
            }
        }
        return $out;
    }

    /**
     * Giorni della timeline.
     * @return array<int, array{iso:string, g:string, wd:string, festivo:bool}>
     */
    private function giorni(string $dal, string $al): array
    {
        $out = [];
        $cur = strtotime($dal);
        $fin = strtotime($al);
        // tetto di sicurezza: una finestra enorme renderebbe la pagina
        // illeggibile e pesante da generare
        $max = 120;

        while ($cur <= $fin && count($out) < $max) {
            $wd    = (int)date('N', $cur);
            $out[] = [
                'iso'     => date('Y-m-d', $cur),
                'g'       => date('j', $cur),
                'wd'      => ['', 'L', 'M', 'M', 'G', 'V', 'S', 'D'][$wd],
                'festivo' => $wd >= 6,
            ];
            $cur = strtotime('+1 day', $cur);
        }
        return $out;
    }

    // ── GET /autocarrate/giornata — vista dei tecnici ────────────────────────

    /**
     * La giornata: cosa esce, cosa rientra, cosa e' fuori, cosa e' in ritardo.
     *
     * E' la pagina che il tecnico apre la mattina, quindi ragiona su un
     * GIORNO e non su un periodo come le altre. Non mostra importi: al
     * tecnico serve sapere se e' pagato, non quanto.
     */
    public function giornata(Request $request): void
    {
        $this->assertGiornata($request);
        $repo = new AutocarrataRepository($this->conn);
        $cid  = $this->companyId();

        $data = VistaImpegni::data($_GET['data'] ?? '', date('Y-m-d'));
        $user = $this->utente($request);

        // La pagina e' la stessa dei mezzi di sollevamento: le due sezioni
        // raccontano la stessa storia, e Giornata normalizza le righe in
        // schede uguali. Qui resta solo cio' che le distingue davvero.
        $righe   = $repo->giornata($cid, $data);
        $foto    = (new \App\Service\Poti\Foto($this->conn))
                       ->perEntita($cid, 'prenotazione', Giornata::idRighe($righe));
        $blocchi = Giornata::blocchi($righe, Giornata::AUTOCARRATA, $foto);

        $collegamenti = [];
        if ($user->canAccess('pn_autocarrate')) {
            $collegamenti = [
                ['href' => '/autocarrate', 'label' => "Disponibilita'"],
                ['href' => '/autocarrate/prenotazioni', 'label' => 'Prenotazioni'],
            ];
        }

        $vista = [
            'titolo'       => 'Giornata autocarrate',
            'sottotitolo'  => "Cosa esce, cosa rientra e cosa e' ancora fuori.",
            'base'         => '/autocarrate/giornata',
            'azione'       => '/autocarrate/giornata/segna',
            'azioneFoto'   => '/autocarrate/giornata/foto',
            'collegamenti' => $collegamenti,
            'data'         => $data,
            'ieri'         => date('Y-m-d', strtotime($data . ' -1 day')),
            'domani'       => date('Y-m-d', strtotime($data . ' +1 day')),
            'oggi'         => date('Y-m-d'),
            'blocchi'      => $blocchi,
            'riepilogo'    => Giornata::riepilogo($blocchi),
            'prossime'     => Giornata::prossime(
                $repo->prossimeConsegne($cid, $data, 14), Giornata::AUTOCARRATA
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

    // ── POST /autocarrate/giornata/segna ─────────────────────────────────────

    /**
     * Segna consegna, rientro o firma del contratto.
     *
     * Un solo endpoint per le tre azioni: cambiano una colonna e finiscono
     * tutte nel registro allo stesso modo, e tre rotte quasi identiche
     * sarebbero solo tre punti in cui sbagliare il controllo dei permessi.
     */
    public function segna(Request $request): void
    {
        $this->assertGiornata($request);
        $repo = new AutocarrataRepository($this->conn);
        $cid  = $this->companyId();

        $id    = (int)($_POST['id'] ?? 0);
        $cosa  = (string)($_POST['cosa'] ?? '');
        $data  = VistaImpegni::data($_POST['data'] ?? '', date('Y-m-d'));

        $prima = $id ? $repo->prenotazione($cid, $id) : null;

        if ($prima) {
            if ($cosa === 'firma') {
                $repo->segnaContrattoFirmato($cid, $id, empty($prima['contratto_firmato']));
            } elseif (in_array($cosa, ['consegnato', 'rientrato'], true)) {
                $repo->segnaMomento(
                    $cid, $id, $cosa, (int)$this->utente($request)->id,
                    trim((string)($_POST['carburante'] ?? ''))
                );
            }

            (new Audit($this->conn))->registra(
                $cid, 'prenotazione', $id, 'modificato',
                $prima, $repo->prenotazione($cid, $id),
                (int)$this->utente($request)->id, (string)$prima['cliente']
            );
        }

        // Dalla pagina il tocco arriva via AJAX: rispondere con un redirect
        // farebbe ricaricare tutto e perdere il punto dell'elenco in cui il
        // tecnico era arrivato.
        if ($request->isAjax()) {
            Response::json(['ok' => $prima !== null]);
        }

        Response::redirect('/autocarrate/giornata?data=' . $data . '&salvato=1');
    }

    /**
     * Chi puo' vedere la giornata.
     *
     * Oltre a chi gestisce il modulo, anche chi ha il solo permesso da
     * tecnico: quella pagina non mostra importi ne' permette di creare o
     * eliminare prenotazioni, quindi puo' stare in mano a chi prepara i mezzi.
     */
    private function assertGiornata(Request $request): void
    {
        $user = $this->utente($request);
        if ((int)$user->id === 1
            || $user->canAccess('pn_autocarrate')
            || $user->canAccess('pn_autocarrate_giornata')) {
            return;
        }
        Response::error("Permesso 'pn_autocarrate_giornata' richiesto", 403);
    }

    // ── POST .../giornata/foto ───────────────────────────────────────────────

    /**
     * Una foto dell'uscita o del rientro.
     *
     * Serve a chiudere le discussioni: quando il mezzo torna ammaccato, la
     * fotografia fatta il giorno della consegna dice com'era prima. Per
     * questo si carica dalla giornata, sul posto, e non da una schermata di
     * archivio dove nessuno andrebbe mai.
     */
    public function caricaFoto(Request $request): void
    {
        $this->assertGiornata($request);

        $entitaId = (int)($_POST['entita_id'] ?? 0);
        $momento  = (string)($_POST['momento'] ?? '');

        try {
            if (!$entitaId) {
                throw new \RuntimeException('Manca il mezzo a cui legare la foto');
            }
            $esito = (new \App\Service\Poti\Foto($this->conn))->salvaMolte(
                $this->companyId(), 'prenotazione', $entitaId, $momento,
                (array)($_FILES['foto'] ?? []), (int)$this->utente($request)->id
            );
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'messaggio' => $e->getMessage()], 422);
        }

        // basta che ne sia passata una: le altre si dicono, non si buttano
        if (!$esito['foto']) {
            Response::json(['ok' => false, 'messaggio' => implode(' · ', $esito['errori'])], 422);
        }

        Response::json([
            'ok'        => true,
            'foto'      => $esito['foto'],
            'messaggio' => $esito['errori'] ? implode(' · ', $esito['errori']) : null,
        ]);
    }

    // ── GET .../foto/{id} ────────────────────────────────────────────────────

    /**
     * Mostra una foto.
     *
     * I file stanno fuori dalla cartella pubblica: passano da qui perche'
     * qui si controlla che chi guarda abbia il permesso e che la foto sia
     * della sua societa'. Sotto public sarebbe visibile a chiunque
     * indovinasse l'indirizzo.
     */
    public function mostraFoto(Request $request): void
    {
        $this->assertGiornata($request);

        $servizio = new \App\Service\Poti\Foto($this->conn);
        $foto     = $servizio->trova($this->companyId(), (int)$request->param('id'));

        // Solo le foto di QUESTO modulo. Senza il controllo, il permesso
        // delle due sezioni non sarebbe davvero separato: basterebbe
        // indovinare un id per vedere da qui le foto delle mezzi di sollevamento.
        if ($foto && $foto['entita'] !== 'prenotazione') {
            $foto = null;
        }

        $assoluto = $foto ? \CloudPath::fotoAssoluta((string)$foto['percorso']) : null;
        if (!$assoluto) {
            Response::error('Foto non trovata', 404);
        }

        header('Content-Type: ' . ($foto['mime'] ?: 'image/jpeg'));
        header('Content-Length: ' . filesize($assoluto));
        // privata: e' il cantiere di un cliente, non deve finire nelle cache
        // condivise per strada
        header('Cache-Control: private, max-age=86400');
        readfile($assoluto);
        exit;
    }

    // ── POST .../giornata/foto/elimina ───────────────────────────────────────

    public function eliminaFoto(Request $request): void
    {
        $this->assertGiornata($request);

        $servizio = new \App\Service\Poti\Foto($this->conn);
        $foto     = $servizio->trova($this->companyId(), (int)($_POST['id'] ?? 0));

        $ok = $foto && $foto['entita'] === 'prenotazione'
            && $servizio->elimina($this->companyId(), (int)$foto['id']);

        Response::json(['ok' => $ok]);
    }
}
