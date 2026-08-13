<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Poti\MacchinaRepository;
use App\Service\CurrentCompany;
use App\Service\Poti\Audit;
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

        $tipo     = trim((string)($_GET['tipo'] ?? ''));
        $macchine = $repo->macchine($cid, false, $tipo);
        $impegni  = $this->impegni($repo->righeNelPeriodo($cid, $dal, $al));
        $giorni   = VistaImpegni::giorni($dal, $al);

        // filtro "mi serve dal ... al ...": restano solo le macchine libere
        $cercaDal = VistaImpegni::data($_GET['cerca_dal'] ?? '', '');
        $cercaAl  = VistaImpegni::data($_GET['cerca_al'] ?? '', '');
        $occupate = ($cercaDal && $cercaAl && $cercaAl >= $cercaDal)
            ? $repo->occupateTra($cid, $cercaDal, $cercaAl)
            : [];

        Response::view('poti/noleggi/disponibilita.html.twig', $request, [
            'macchine'      => $macchine,
            'tipi'          => $repo->tipi($cid),
            'tipo'          => $tipo,
            'giorni'        => $giorni,
            'griglia'       => VistaImpegni::griglia($impegni, $giorni),
            'calendario'    => VistaImpegni::calendario($dal, $al, $impegni),
            'liberaDal'     => $repo->primoGiornoLibero($cid, date('Y-m-d')),
            'dal'           => $dal,
            'al'            => $al,
            'cercaDal'      => $cercaDal,
            'cercaAl'       => $cercaAl,
            'occupate'      => $occupate,
            'ricercaAttiva' => (bool)($cercaDal && $cercaAl),
        ]);
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

        Response::view('poti/noleggi/elenco.html.twig', $request, [
            'noleggi'    => $repo->noleggi($cid, $dal, $al, $cerca),
            'macchine'   => $repo->macchine($cid, true),
            'stati'      => self::STATI_NOLEGGIO,
            'pagamenti'  => self::PAGAMENTI,
            'utenteNome' => $this->nomeUtente($request),
            'dal'        => $dal,
            'al'         => $al,
            'cerca'      => $cerca,
            'salvato'    => isset($_GET['salvato']),
            'errore'     => $_GET['errore'] ?? null,
        ]);
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

        $nuovoId = $repo->salvaNoleggio($cid, $id, [
            'cliente'   => $cliente,
            'telefono'  => trim((string)($_POST['telefono'] ?? '')),
            'luogo'     => trim((string)($_POST['luogo'] ?? '')),
            'contratto' => trim((string)($_POST['contratto'] ?? '')),
            'stato'     => $stato,
            'trasporto' => VistaImpegni::importo($_POST['trasporto'] ?? ''),
            'totale'    => VistaImpegni::importo($_POST['totale'] ?? ''),
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

        Response::view('poti/registro.html.twig', $request, [
            'sezione'      => 'Mezzi sollevamento',
            'tornaA'       => '/noleggi/elenco',
            'urlRipristina'=> '/noleggi/ripristina',
            'voci'         => $audit->voci($cid, $entita, $filtri),
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

        Response::view('poti/noleggi/macchine.html.twig', $request, [
            'macchine' => $repo->macchine($cid),
            'tipi'     => $repo->tipi($cid),
            'stati'    => self::STATI_MACCHINA,
            'salvato'  => isset($_GET['salvato']),
            'errore'   => $_GET['errore'] ?? null,
        ]);
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

            $out[] = [
                'macchina_id'    => (string)$macchinaId,
                'data_inizio'    => $dal,
                'data_fine'      => $al,
                'tariffa_giorno' => VistaImpegni::importo($_POST['riga_tariffa'][$i] ?? ''),
                'totale'         => VistaImpegni::importo($_POST['riga_totale'][$i] ?? ''),
                'note'           => trim((string)($_POST['riga_note'][$i] ?? '')),
            ];
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
}
