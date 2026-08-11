<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\BusinessConnection;
use App\Infrastructure\Config;
use App\Repository\Business\BusinessInvoiceRepository;

/**
 * Andamento fatturato — dati letti dal gestionale Business (sola lettura).
 *
 * Criterio: COMPETENZA, cioe' i documenti sono contati nel mese della loro
 * data (tm_datdoc), non in quello dell'incasso/pagamento. E' il criterio che
 * risponde a "quest'anno stiamo lavorando piu' dell'anno scorso"; la lettura
 * di cassa (quando i soldi si muovono davvero) e' un'altra cosa e andra' su
 * una vista separata per non mescolare due misure diverse.
 *
 * Pagina riservata alla direzione: permesso 'report_business'.
 */
final class BusinessReportController
{
    private const MESI = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

    public function __construct(private \PDO $conn) {}

    // ── GET /report/fatturato ────────────────────────────────────────────────

    public function index(Request $request): void
    {
        $this->assertAccess($request);

        $config = new Config();
        $anni   = [];
        $rows   = [];
        $errore = null;

        $annoCorrente = (int)date('Y');
        // di default mostriamo l'anno in corso e i due precedenti
        $daAnno = (int)($request->get('da_anno') ?: $annoCorrente - 2);
        $aAnno  = (int)($request->get('a_anno')  ?: $annoCorrente);
        if ($daAnno > $aAnno) { [$daAnno, $aAnno] = [$aAnno, $daAnno]; }
        // limite di sicurezza: 10 anni per volta
        if ($aAnno - $daAnno > 9) { $daAnno = $aAnno - 9; }

        if (!$config->businessConfigured()) {
            $errore = 'Collegamento a Business non configurato (manca BUSINESS_DB nel file .env).';
        } else {
            try {
                $repo = new BusinessInvoiceRepository(
                    (new BusinessConnection($config))->connect(),
                    $config->businessCodDitta()
                );
                $anni = $repo->availableYears();
                $rows = $repo->monthlyTotals($daAnno, $aAnno);
            } catch (\Throwable $e) {
                // La pagina NON deve restare silenziosamente vuota: un fatturato
                // a zero letto come "mese scarso" invece che "connessione giu'"
                // e' un errore che costa.
                error_log('[BusinessReport] ' . $e->getMessage());
                $errore = 'Impossibile leggere i dati da Business in questo momento.';
            }
        }

        $dati = $this->buildSeries($rows, $daAnno, $aAnno);

        Response::view('report/fatturato.html.twig', $request, [
            'pageTitle'  => 'Andamento fatturato',
            'mesi'       => self::MESI,
            'anni'       => $anni,
            'daAnno'     => $daAnno,
            'aAnno'      => $aAnno,
            'serie'      => $dati['serie'],
            'totali'     => $dati['totali'],
            'annoTop'    => $aAnno,
            'annoPrec'   => $aAnno - 1,
            'errore'     => $errore,
        ]);
    }

    // ── GET /report/fatturato/causali?dal=&al= (JSON) ────────────────────────

    /**
     * Dettaglio per causale: serve a verificare che i totali siano corretti
     * (tipicamente: le note di credito sottraggono o sommano?).
     */
    public function causali(Request $request): never
    {
        $this->assertAccess($request);

        $anno = (int)($request->get('anno') ?: date('Y'));
        $mese = (int)($request->get('mese') ?: 0);

        if ($mese >= 1 && $mese <= 12) {
            $dal = sprintf('%04d-%02d-01', $anno, $mese);
            $al  = $mese === 12
                    ? sprintf('%04d-01-01', $anno + 1)
                    : sprintf('%04d-%02d-01', $anno, $mese + 1);
        } else {
            $dal = sprintf('%04d-01-01', $anno);
            $al  = sprintf('%04d-01-01', $anno + 1);
        }

        $config = new Config();
        if (!$config->businessConfigured()) {
            Response::json(['ok' => false, 'error' => 'Business non configurato'], 503);
        }

        try {
            $repo = new BusinessInvoiceRepository(
                (new BusinessConnection($config))->connect(),
                $config->businessCodDitta()
            );
            Response::json([
                'ok'      => true,
                'periodo' => ($mese ? self::MESI[$mese - 1] . ' ' : '') . $anno,
                'righe'   => $repo->byCausale($dal, $al),
            ]);
        } catch (\Throwable $e) {
            error_log('[BusinessReport::causali] ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'Business non raggiungibile'], 503);
        }
    }

    // ── Interni ──────────────────────────────────────────────────────────────

    private function assertAccess(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->canAccess('report_business')) {
            Response::error('Accesso negato', 403);
        }
    }

    /**
     * Trasforma le righe (anno, mese, tipo) in serie pronte per il grafico e
     * per la tabella: per ogni anno 12 valori di entrate, uscite e saldo.
     *
     * 'C' = cliente → entrate, 'F' = fornitore → uscite.
     * Le anagrafiche di tipo 'S' (altri soggetti) restano fuori da entrambi:
     * non sono ne' ricavi ne' costi di fornitura.
     */
    private function buildSeries(array $rows, int $daAnno, int $aAnno): array
    {
        $serie = [];
        for ($y = $daAnno; $y <= $aAnno; $y++) {
            $serie[$y] = [
                'entrate' => array_fill(0, 12, 0.0),
                'uscite'  => array_fill(0, 12, 0.0),
                'saldo'   => array_fill(0, 12, 0.0),
                'incassato' => 0.0,
                'pagato'    => 0.0,
            ];
        }

        foreach ($rows as $r) {
            $y = $r['anno'];
            $i = $r['mese'] - 1;
            if (!isset($serie[$y]) || $i < 0 || $i > 11) continue;

            if ($r['tipo'] === 'C') {
                $serie[$y]['entrate'][$i]  += $r['imponibile'];
                $serie[$y]['incassato']    += $r['pagato'];
            } elseif ($r['tipo'] === 'F') {
                $serie[$y]['uscite'][$i]   += $r['imponibile'];
                $serie[$y]['pagato']       += $r['pagato'];
            }
        }

        $totali = [];
        foreach ($serie as $y => $s) {
            for ($i = 0; $i < 12; $i++) {
                $serie[$y]['saldo'][$i] = $s['entrate'][$i] - $s['uscite'][$i];
            }
            $totali[$y] = [
                'entrate' => array_sum($s['entrate']),
                'uscite'  => array_sum($s['uscite']),
                'saldo'   => array_sum($s['entrate']) - array_sum($s['uscite']),
                'incassato' => $s['incassato'],
                'pagato'    => $s['pagato'],
            ];
        }

        return ['serie' => $serie, 'totali' => $totali];
    }
}
