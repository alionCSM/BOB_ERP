<?php

declare(strict_types=1);

namespace App\Service\Poti;

use DateTimeImmutable;

/**
 * Come si calcola quanto costa un noleggio.
 *
 * Sta tutto qui perche' lo stesso conto lo fanno in due: il browser mentre
 * si compila il form, e il server quando salva. Il browser serve a far
 * vedere il totale mentre si scrive, ma quello che finisce a database e' il
 * conto fatto qui — un totale calcolato solo dal browser sarebbe modificabile
 * da chiunque sappia aprire gli strumenti per sviluppatori.
 *
 * Il gemello in JavaScript e' in noleggi.js: se si tocca una regola qui va
 * toccata anche li'.
 */
final class Tariffa
{
    /** Percentuale di assicurazione proposta quando si spunta la casella. */
    public const ASSICURAZIONE_PERC = 12.00;

    /**
     * Giorni di un periodo, estremi compresi.
     * Dal 10 al 10 e' un giorno, non zero: il mezzo quel giorno e' fuori.
     */
    public static function giorni(string $dal, string $al): int
    {
        if ($dal === '' || $al === '') {
            return 0;
        }
        $inizio = new DateTimeImmutable($dal);
        $fine   = new DateTimeImmutable($al);
        if ($fine < $inizio) {
            return 0;
        }
        return (int)$inizio->diff($fine)->days + 1;
    }

    /**
     * Un periodo diviso in mesi di calendario piu' i giorni che avanzano.
     *
     * Mesi di calendario e non blocchi di trenta giorni: dal 10 gennaio al
     * 10 febbraio e' un mese, punto, che febbraio ne abbia 28 o 31. E' come
     * si ragiona parlando col cliente, ed e' quello che finisce sul
     * contratto.
     *
     * I giorni che avanzano si contano dal giorno dopo la scadenza
     * dell'ultimo mese: il 10 febbraio e' gia' compreso nel mese, contarlo
     * di nuovo lo farebbe pagare due volte. Quando pero' non si arriva a un
     * mese intero la riga e' a giorni come le altre, estremi compresi.
     *
     * @return array{mesi:int, giorni:int}
     */
    public static function mesiEGiorni(string $dal, string $al): array
    {
        if ($dal === '' || $al === '') {
            return ['mesi' => 0, 'giorni' => 0];
        }
        $inizio = new DateTimeImmutable($dal);
        $fine   = new DateTimeImmutable($al);
        if ($fine < $inizio) {
            return ['mesi' => 0, 'giorni' => 0];
        }

        $mesi = 0;
        while (self::piuMesi($inizio, $mesi + 1) <= $fine) {
            $mesi++;
        }

        if ($mesi === 0) {
            return ['mesi' => 0, 'giorni' => (int)$inizio->diff($fine)->days + 1];
        }

        $scadenza = self::piuMesi($inizio, $mesi);
        return ['mesi' => $mesi, 'giorni' => (int)$scadenza->diff($fine)->days];
    }

    /**
     * La tariffa della riga, presa dalla colonna che corrisponde all'unita'.
     *
     * A database le due colonne restano separate perche' dicono cose
     * diverse — 80 euro al giorno e 80 euro al mese non sono lo stesso
     * numero — ma nel form ne compare una sola, quella che serve.
     *
     * @param array<string, mixed> $riga
     */
    public static function tariffaDi(array $riga): float
    {
        $unita = (string)($riga['unita'] ?? 'giorno');

        if ($unita === 'mese') {
            return (float)($riga['tariffa_mese'] ?? 0);
        }
        // giorno e una tantum leggono lo stesso campo: per la tantum non e'
        // una tariffa al giorno ma l'importo secco, e tenerne un terzo in
        // tabella per una cifra sola non varrebbe la colonna
        return (float)($riga['tariffa_giorno'] ?? 0);
    }

    /**
     * Quanto costa una riga.
     *
     * Una tariffa sola per riga: o si affitta a giornata, o a mese, o a
     * corpo. Prima la riga a mese ne chiedeva due, una al mese e una al
     * giorno per i giorni oltre l'ultimo mese intero, ed era un campo di
     * troppo da compilare ogni volta.
     *
     * Adesso i giorni che avanzano si contano in trentesimi del canone
     * mensile: un mese e cinque giorni a 900 euro al mese fanno 900 piu'
     * cinque trentesimi, cioe' 1050. Non si arrotonda al mese pieno di
     * proposito — farebbe pagare un mese intero per cinque giorni.
     *
     * @param array<string, mixed> $riga
     */
    public static function totaleRiga(array $riga): float
    {
        $unita   = (string)($riga['unita'] ?? 'giorno');
        $tariffa = self::tariffaDi($riga);
        $dal     = (string)($riga['data_inizio'] ?? '');
        $al      = (string)($riga['data_fine'] ?? '');

        // a corpo: la durata non c'entra, si paga quella cifra e basta
        if ($unita === 'tantum') {
            return round($tariffa, 2);
        }

        if ($unita === 'mese') {
            $q = self::mesiEGiorni($dal, $al);
            return round($q['mesi'] * $tariffa + $q['giorni'] * ($tariffa / 30), 2);
        }

        return round(self::giorni($dal, $al) * $tariffa, 2);
    }

    /**
     * Imponibile dei soli mezzi: e' la base su cui si calcola
     * l'assicurazione. Il trasporto non ne fa parte — si assicura il mezzo,
     * non il viaggio del camion.
     *
     * @param array<int, array<string, mixed>> $righe
     */
    public static function totaleMezzi(array $righe): float
    {
        $somma = 0.0;
        foreach ($righe as $r) {
            // il totale scritto a mano vince sul calcolo: capita di
            // concordare una cifra tonda diversa dal conto esatto
            $somma += isset($r['totale']) && $r['totale'] !== '' && $r['totale'] !== null
                ? (float)$r['totale']
                : self::totaleRiga($r);
        }
        return round($somma, 2);
    }

    /** Importo dell'assicurazione: percentuale sui soli mezzi. */
    public static function assicurazione(float $totaleMezzi, float $percentuale): float
    {
        return round($totaleMezzi * $percentuale / 100, 2);
    }

    /**
     * Somma un numero di mesi a una data restando dentro il mese di arrivo.
     *
     * Serve perche' il "+1 month" di PHP sul 31 gennaio risponde 3 marzo:
     * somma il mese, si accorge che il 31 febbraio non esiste e sborda nel
     * mese dopo. Su un noleggio partito a fine mese vorrebbe dire far pagare
     * giorni che il cliente non ha avuto il mezzo.
     */
    private static function piuMesi(DateTimeImmutable $data, int $mesi): DateTimeImmutable
    {
        $giorno = (int)$data->format('j');
        $primo  = $data->modify('first day of this month')->modify("+{$mesi} months");

        return $primo->setDate(
            (int)$primo->format('Y'),
            (int)$primo->format('n'),
            min($giorno, (int)$primo->format('t'))
        );
    }
}
