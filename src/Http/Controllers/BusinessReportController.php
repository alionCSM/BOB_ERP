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
                    $config->businessCodDitta(),
                    $config->businessTabellaDocumenti()
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

        // Se l'anno piu' recente e' quello in corso, il confronto si ferma
        // al mese che stiamo vivendo: un anno a meta' contro uno intero non
        // e' un confronto, e' una percentuale inventata.
        $meseLimite = $aAnno === $annoCorrente ? (int)date('n') : 12;
        $dati = self::buildSeries($rows, $daAnno, $aAnno, $meseLimite);

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
            'meseLimite' => $meseLimite,
            'meseNome'   => self::MESI[$meseLimite - 1] ?? '',
            'annoInCorso' => $aAnno === $annoCorrente,
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

        [$dal, $al, $periodo] = $this->periodo($request);

        $config = new Config();
        if (!$config->businessConfigured()) {
            Response::json(['ok' => false, 'error' => 'Business non configurato'], 503);
        }

        try {
            $repo = new BusinessInvoiceRepository(
                (new BusinessConnection($config))->connect(),
                $config->businessCodDitta(),
                $config->businessTabellaDocumenti()
            );
            Response::json([
                'ok'      => true,
                'periodo' => $periodo,
                'righe'   => $repo->byCausale($dal, $al),
            ]);
        } catch (\Throwable $e) {
            error_log('[BusinessReport::causali] ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'Business non raggiungibile'], 503);
        }
    }

    // ── GET /report/fatturato/controparti?registro=&anno=&mese= (JSON) ───────

    /**
     * Chi ci ha pagato, e chi abbiamo pagato.
     *
     * E' la domanda da cui si parte guardando un fatturato: non "quanto" ma
     * "con chi". Il totale in una riga sola non risponde a niente.
     */
    public function controparti(Request $request): never
    {
        $this->assertAccess($request);

        $registro = $request->get('registro') === 'A'
            ? BusinessInvoiceRepository::ACQUISTI
            : BusinessInvoiceRepository::VENDITE;

        [$dal, $al, $periodo] = $this->periodo($request);

        $config = new Config();
        if (!$config->businessConfigured()) {
            Response::json(['ok' => false, 'error' => 'Business non configurato'], 503);
        }

        try {
            $repo = new BusinessInvoiceRepository(
                (new BusinessConnection($config))->connect(),
                $config->businessCodDitta(),
                $config->businessTabellaDocumenti()
            );
            Response::json([
                'ok'       => true,
                'registro' => $registro,
                'periodo'  => $periodo,
                'righe'    => $repo->byControparte($registro, $dal, $al),
            ]);
        } catch (\Throwable $e) {
            error_log('[BusinessReport::controparti] ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'Business non raggiungibile'], 503);
        }
    }

    // ── Interni ──────────────────────────────────────────────────────────────

    /**
     * Il periodo chiesto: un mese o un anno intero.
     *
     * @return array{0:string, 1:string, 2:string} dal, al (escluso), come si legge
     */
    private function periodo(Request $request): array
    {
        $anno = (int)($request->get('anno') ?: date('Y'));
        $mese = (int)($request->get('mese') ?: 0);

        if ($mese >= 1 && $mese <= 12) {
            return [
                sprintf('%04d-%02d-01', $anno, $mese),
                $mese === 12
                    ? sprintf('%04d-01-01', $anno + 1)
                    : sprintf('%04d-%02d-01', $anno, $mese + 1),
                self::MESI[$mese - 1] . ' ' . $anno,
            ];
        }

        return [
            sprintf('%04d-01-01', $anno),
            sprintf('%04d-01-01', $anno + 1),
            (string)$anno,
        ];
    }

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
    /**
     * @param int $meseLimite fino a che mese contare il confronto fra anni
     *                        (12 = anno intero). Serve a non paragonare un
     *                        anno in corso con uno finito.
     */
    public static function buildSeries(array $rows, int $daAnno, int $aAnno, int $meseLimite = 12): array
    {
        $meseLimite = max(1, min(12, $meseLimite));
        $serie = [];
        for ($y = $daAnno; $y <= $aAnno; $y++) {
            $serie[$y] = [
                'entrate' => array_fill(0, 12, 0.0),
                'uscite'  => array_fill(0, 12, 0.0),
                'saldo'   => array_fill(0, 12, 0.0),
                // L'IVA del registro, al posto di "gia' incassato" e "gia'
                // pagato": quelli stavano sulle testate dei documenti, nel
                // registro IVA non ci sono, e mostrarli a zero sarebbe
                // peggio che non mostrarli.
                'iva_vendite'  => 0.0,
                'iva_acquisti' => 0.0,
            ];
        }

        foreach ($rows as $r) {
            $y = $r['anno'];
            $i = $r['mese'] - 1;
            if (!isset($serie[$y]) || $i < 0 || $i > 11) continue;

            // Si divide per REGISTRO, non per tipo di controparte: nel
            // registro vendite ci sono anche le autofatture in reverse
            // charge, che hanno un fornitore come controparte e finirebbero
            // fra le uscite contando due volte.
            if ($r['registro'] === 'V') {
                $serie[$y]['entrate'][$i] += $r['imponibile'];
                $serie[$y]['iva_vendite'] += $r['iva'];
            } elseif ($r['registro'] === 'A') {
                $serie[$y]['uscite'][$i]   += $r['imponibile'];
                $serie[$y]['iva_acquisti'] += $r['iva'];
            }
        }

        $totali = [];
        foreach ($serie as $y => $s) {
            for ($i = 0; $i < 12; $i++) {
                $serie[$y]['saldo'][$i] = $s['entrate'][$i] - $s['uscite'][$i];
            }
            // Due totali per anno, e sono due cose diverse:
            //
            //  - quello pieno, che e' quanto ha fatto l'anno;
            //  - quello "a pari periodo", fermo allo stesso mese dell'anno in
            //    corso, che e' l'unico con cui ha senso confrontarlo.
            //
            // Prima c'era solo il primo, usato anche per il confronto:
            // l'anno in corso fino a novembre veniva paragonato all'anno
            // prima intero, e usciva sempre un calo che non esiste. Su
            // numeri cosi' e' il genere di percentuale che fa prendere
            // decisioni sbagliate.
            $entratePeriodo = array_sum(array_slice($s['entrate'], 0, $meseLimite));
            $uscitePeriodo  = array_sum(array_slice($s['uscite'],  0, $meseLimite));

            $totali[$y] = [
                'entrate' => array_sum($s['entrate']),
                'uscite'  => array_sum($s['uscite']),
                'saldo'   => array_sum($s['entrate']) - array_sum($s['uscite']),
                'iva_vendite'  => $s['iva_vendite'],
                'iva_acquisti' => $s['iva_acquisti'],

                'entrate_periodo' => $entratePeriodo,
                'uscite_periodo'  => $uscitePeriodo,
                'saldo_periodo'   => $entratePeriodo - $uscitePeriodo,
            ];
        }

        return ['serie' => $serie, 'totali' => $totali];
    }
}
