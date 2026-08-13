<?php

declare(strict_types=1);

namespace App\Service\Poti;

/**
 * Timeline e calendario degli impegni, per qualsiasi tipo di mezzo.
 *
 * Autocarrate e macchine sono due sezioni distinte ma disegnano le stesse
 * due viste. Tenerne una copia per sezione vorrebbe dire vederle divergere
 * al primo ritocco: qui c'e' la versione unica, che lavora su una forma
 * normalizzata degli impegni e non sa niente di targhe o matricole.
 *
 * Ogni impegno in ingresso:
 *   ['risorsa' => int, 'etichetta' => string, 'cliente' => string,
 *    'luogo' => string, 'stato' => string,
 *    'data_inizio' => 'Y-m-d', 'data_fine' => 'Y-m-d']
 */
final class VistaImpegni
{
    private const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                          'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

    /** Oltre questo numero di giorni la timeline diventa illeggibile. */
    private const MAX_GIORNI = 120;

    /** Oltre questo numero di mesi la pagina diventa lunghissima. */
    private const MAX_MESI = 6;

    /**
     * Giorni della timeline.
     * @return array<int, array{iso:string, g:string, wd:string, festivo:bool}>
     */
    public static function giorni(string $dal, string $al): array
    {
        $out = [];
        $cur = strtotime($dal);
        $fin = strtotime($al);

        while ($cur <= $fin && count($out) < self::MAX_GIORNI) {
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

    /**
     * Celle occupate della timeline, calcolate qui e non nel template:
     * cercare l'impegno per ogni cella dentro Twig vorrebbe dire
     * risorse x giorni x impegni giri a ogni caricamento.
     *
     * @param array<int, array<string, mixed>> $impegni
     * @param array<int, array<string, mixed>> $giorni
     * @return array<int, array<string, array<string, mixed>>>
     */
    public static function griglia(array $impegni, array $giorni): array
    {
        if (!$giorni) {
            return [];
        }
        $primo  = $giorni[0]['iso'];
        $ultimo = $giorni[count($giorni) - 1]['iso'];

        $out = [];
        foreach ($impegni as $i) {
            $da = max($i['data_inizio'], $primo);
            $a  = min($i['data_fine'], $ultimo);

            $testo = $i['cliente']
                . ' — ' . date('d/m', strtotime($i['data_inizio']))
                . '/' . date('d/m', strtotime($i['data_fine']))
                . (!empty($i['luogo']) ? ' — ' . $i['luogo'] : '');

            for ($g = $da; $g <= $a; $g = date('Y-m-d', strtotime($g . ' +1 day'))) {
                $out[(int)$i['risorsa']][$g] = [
                    'stato'   => $i['stato'],
                    'testo'   => $testo,
                    'cliente' => $i['cliente'],
                    // servono ad arrotondare solo i capi della barra: i giorni
                    // in mezzo restano squadrati e si saldano fra loro
                    'inizio'  => $g === $i['data_inizio'],
                    'fine'    => $g === $i['data_fine'],
                ];
            }
        }
        return $out;
    }

    /**
     * Calendario a mesi con gli impegni di ogni giorno.
     *
     * Le settimane partono da lunedi' e i mesi sono completati con i giorni
     * vuoti agli estremi, altrimenti la griglia risulterebbe sfalsata.
     *
     * @param array<int, array<string, mixed>> $impegni
     * @return array<int, array{titolo:string, celle:array}>
     */
    public static function calendario(string $dal, string $al, array $impegni): array
    {
        // impegni indicizzati per giorno: cosi' il template non deve
        // scorrerli tutti per ogni casella
        $perGiorno = [];
        foreach ($impegni as $i) {
            for ($g = $i['data_inizio']; $g <= $i['data_fine']; $g = date('Y-m-d', strtotime($g . ' +1 day'))) {
                $perGiorno[$g][] = [
                    'etichetta' => $i['etichetta'],
                    'cliente'   => $i['cliente'],
                    'stato'     => $i['stato'],
                    'luogo'     => $i['luogo'] ?? '',
                ];
            }
        }

        $oggi = date('Y-m-d');
        $mesi = [];
        $cur  = strtotime(date('Y-m-01', strtotime($dal)));
        $fine = strtotime(date('Y-m-01', strtotime($al)));

        while ($cur <= $fine && count($mesi) < self::MAX_MESI) {
            $anno   = (int)date('Y', $cur);
            $mese   = (int)date('n', $cur);
            $giorni = (int)date('t', $cur);
            $celle  = [];

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

            $mesi[] = ['titolo' => self::MESI[$mese] . ' ' . $anno, 'celle' => $celle];
            $cur = strtotime('+1 month', $cur);
        }

        return $mesi;
    }

    /**
     * Importo scritto all'italiana -> numero.
     *
     * Accetta 1.234,56 (punto migliaia, virgola decimali), 1234,56 e anche
     * 1234.56. Sostituire solo la virgola col punto non basta: "1.234,56"
     * diventerebbe "1.234.56", cioe' un valore da buttare.
     */
    public static function importo(mixed $valore): string
    {
        $v = trim((string)$valore);
        if ($v === '') {
            return '';
        }

        $v = preg_replace('/[^0-9,.\-]/u', '', $v) ?? '';
        if ($v === '') {
            return '';
        }

        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? $v : '';
    }

    /** Data in formato ISO, o il ripiego se assente o non valida. */
    public static function data(mixed $valore, string $ripiego): string
    {
        $v = trim((string)$valore);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $ripiego;
    }
}
