<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Repository\Poti\AutocarrataRepository;
use App\Service\CurrentCompany;

/**
 * Poti Noleggi — autocarrate, prenotazioni e disponibilita'.
 *
 * Primo modulo scritto gia' per il multi-societa': tutte le letture e le
 * scritture passano dall'id della societa' attiva, mai da tutta la tabella.
 */
final class AutocarrateController
{
    private const STATI_MEZZO = ['attiva', 'manutenzione', 'dismessa'];
    private const STATI_PREN  = ['opzione', 'confermata', 'annullata'];

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
            'liberoDal'    => $liberoDal,
            'giorni'       => $giorni,
            'dal'          => $dal,
            'al'           => $al,
            'cercaDal'     => $cercaDal,
            'cercaAl'      => $cercaAl,
            'occupati'     => $occupati,
            'ricercaAttiva'=> (bool)($cercaDal && $cercaAl),
            'canSeePrices' => $this->utente($request)->canSeePrices(),
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

        $repo->salvaMezzo($cid, $id, [
            'targa'         => $targa,
            'modello'       => trim((string)($_POST['modello'] ?? '')),
            'altezza_max_m' => trim((string)($_POST['altezza_max_m'] ?? '')),
            'portata_kg'    => trim((string)($_POST['portata_kg'] ?? '')),
            'note'          => trim((string)($_POST['note'] ?? '')),
            'stato'         => $stato,
        ]);

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

        Response::view('poti/autocarrate/prenotazioni.html.twig', $request, [
            'prenotazioni' => $repo->prenotazioni($cid, $dal, $al, (int)($_GET['mezzo'] ?? 0) ?: null),
            'mezzi'        => $repo->mezzi($cid, true),
            'stati'        => self::STATI_PREN,
            'dal'          => $dal,
            'al'           => $al,
            'mezzoId'      => (int)($_GET['mezzo'] ?? 0),
            'canSeePrices' => $this->utente($request)->canSeePrices(),
            'salvato'      => isset($_GET['salvato']),
            'errore'       => $_GET['errore'] ?? null,
        ]);
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

        $repo->salvaPrenotazione($cid, $id, [
            'autocarrata_id' => $mezzoId,
            'cliente'        => $cliente,
            'telefono'       => trim((string)($_POST['telefono'] ?? '')),
            'luogo'          => trim((string)($_POST['luogo'] ?? '')),
            'data_inizio'    => $dal,
            'data_fine'      => $al,
            'stato'          => $stato,
            'tariffa_giorno' => $this->importo($_POST['tariffa_giorno'] ?? ''),
            'totale'         => $this->importo($_POST['totale'] ?? ''),
            'note'           => trim((string)($_POST['note'] ?? '')),
        ], (int)$this->utente($request)->id);

        Response::redirect('/autocarrate/prenotazioni?salvato=1');
    }

    // ── POST /autocarrate/prenotazioni/elimina ───────────────────────────────

    public function eliminaPrenotazione(Request $request): void
    {
        $this->assertAccess($request);
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            (new AutocarrataRepository($this->conn))->eliminaPrenotazione($this->companyId(), $id);
        }
        Response::redirect('/autocarrate/prenotazioni?salvato=1');
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
    private function importo(mixed $valore): string
    {
        $v = str_replace(',', '.', trim((string)$valore));
        return is_numeric($v) ? $v : '';
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
                    'stato' => $p['stato'],
                    'testo' => $testo,
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
}
