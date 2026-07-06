<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Calcolo del costo dei noleggi mezzi sollevamento (bb_worksite_lifting).
 *
 * Il costo NON dipende piu' dalle presenze: si calcola sul calendario reale
 * del noleggio, da data_inizio a data_fine (o a oggi se ancora attivo).
 *
 * tipo_noleggio:
 *   - 'Una Tantum'  → costo × quantita (servizio, es. Trasporto A/R)
 *   - 'Giornaliero' → costo × giorni conteggiati × quantita
 *                     I giorni si contano secondo `calendario`:
 *                       lun_ven | lun_sab | lun_dom | sab_dom
 *                     e `festivi_inclusi` (festivita' nazionali italiane).
 *   - 'Settimanale' → costo × settimane COMPLETE (floor giorni/7) × quantita
 *   - 'Mensile'     → costo × mesi COMPLETI × quantita
 *
 * Nota: per Settimanale/Mensile si contano solo periodi interi (decisione
 * di business: 10 giorni di settimanale = 1 settimana).
 */
final class RentalCostCalculator
{
    /** @var array<int, array<string,bool>> cache festivi per anno: [anno => ['m-d' => true]] */
    private static array $holidayCache = [];

    /** Costo maturato di una riga di bb_worksite_lifting. */
    public static function cost(array $rental, ?string $today = null): float
    {
        $costo    = (float)($rental['costo_giornaliero'] ?? 0);
        $quantita = (int)($rental['quantita'] ?? 1) ?: 1;
        $tipo     = (string)($rental['tipo_noleggio'] ?? 'Giornaliero');

        if ($tipo === 'Una Tantum') {
            return $costo * $quantita;
        }

        $start = (string)($rental['data_inizio'] ?? '');
        if ($start === '') return 0.0;
        $end = self::effectiveEnd($rental, $today);
        if ($end < $start) return 0.0;

        $units = match ($tipo) {
            'Settimanale' => self::completeWeeks($start, $end),
            'Mensile'     => self::completeMonths($start, $end),
            default       => self::countDays(
                $start,
                $end,
                (string)($rental['calendario'] ?? 'lun_ven'),
                !empty($rental['festivi_inclusi'])
            ) + max(0, (int)($rental['giorni_extra'] ?? 0)), // aggiunti a mano dal gestore mezzi
        };

        return $costo * $units * $quantita;
    }

    /** Unita' maturate (giorni/settimane/mesi) — utile per mostrarle in UI. */
    public static function units(array $rental, ?string $today = null): int
    {
        $tipo = (string)($rental['tipo_noleggio'] ?? 'Giornaliero');
        if ($tipo === 'Una Tantum') return 1;

        $start = (string)($rental['data_inizio'] ?? '');
        if ($start === '') return 0;
        $end = self::effectiveEnd($rental, $today);
        if ($end < $start) return 0;

        return match ($tipo) {
            'Settimanale' => self::completeWeeks($start, $end),
            'Mensile'     => self::completeMonths($start, $end),
            default       => self::countDays(
                $start,
                $end,
                (string)($rental['calendario'] ?? 'lun_ven'),
                !empty($rental['festivi_inclusi'])
            ) + max(0, (int)($rental['giorni_extra'] ?? 0)),
        };
    }

    /** Fine effettiva del conteggio: data_fine se presente, altrimenti oggi. */
    private static function effectiveEnd(array $rental, ?string $today): string
    {
        $today = $today ?? date('Y-m-d');
        $fine  = (string)($rental['data_fine'] ?? '');
        if ($fine !== '' && $fine !== '0000-00-00') {
            return substr($fine, 0, 10);
        }
        return $today;
    }

    // ── Giornaliero ─────────────────────────────────────────────────────────────

    /**
     * Giorni conteggiabili tra $start e $end inclusi.
     * Un giorno conta se rientra nel calendario e (se festivo nazionale)
     * solo quando $festiviInclusi e' true.
     */
    public static function countDays(string $start, string $end, string $calendario, bool $festiviInclusi): int
    {
        $d   = new DateTimeImmutable(substr($start, 0, 10));
        $to  = new DateTimeImmutable(substr($end, 0, 10));
        $cnt = 0;

        while ($d <= $to) {
            if (self::dayCounts($d, $calendario, $festiviInclusi)) {
                $cnt++;
            }
            $d = $d->modify('+1 day');
        }
        return $cnt;
    }

    /** True se il singolo giorno rientra nel calendario di conteggio. */
    public static function dayCounts(DateTimeImmutable|string $day, string $calendario, bool $festiviInclusi): bool
    {
        $d   = is_string($day) ? new DateTimeImmutable(substr($day, 0, 10)) : $day;
        $dow = (int)$d->format('N'); // 1=lun ... 7=dom
        $inCal = match ($calendario) {
            'lun_sab' => $dow <= 6,
            'lun_dom' => true,
            'sab_dom' => $dow >= 6,
            default   => $dow <= 5, // lun_ven
        };
        return $inCal && ($festiviInclusi || !self::isItalianHoliday($d));
    }

    /** Festivita' nazionali italiane (incluso Lunedi' dell'Angelo). */
    public static function isItalianHoliday(DateTimeImmutable $d): bool
    {
        $year = (int)$d->format('Y');
        if (!isset(self::$holidayCache[$year])) {
            $fixed = [
                '01-01', // Capodanno
                '01-06', // Epifania
                '04-25', // Liberazione
                '05-01', // Festa del Lavoro
                '06-02', // Repubblica
                '08-15', // Ferragosto
                '11-01', // Ognissanti
                '12-08', // Immacolata
                '12-25', // Natale
                '12-26', // Santo Stefano
            ];
            $map = array_fill_keys($fixed, true);

            // Lunedi' dell'Angelo (Pasquetta): Pasqua + 1 giorno.
            // easter_days() e' disponibile solo con ext-calendar: fallback
            // all'algoritmo di Gauss/Butcher se assente.
            $easter = function_exists('easter_days')
                ? (new DateTimeImmutable("{$year}-03-21"))->modify('+' . easter_days($year) . ' days')
                : self::easterByButcher($year);
            $map[$easter->modify('+1 day')->format('m-d')] = true;

            self::$holidayCache[$year] = $map;
        }
        return isset(self::$holidayCache[$year][$d->format('m-d')]);
    }

    private static function easterByButcher(int $y): DateTimeImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day));
    }

    // ── Settimanale / Mensile: solo periodi completi ────────────────────────────

    /** Settimane complete tra le due date (incluse): floor(giorni/7). */
    public static function completeWeeks(string $start, string $end): int
    {
        $d  = new DateTimeImmutable(substr($start, 0, 10));
        $to = new DateTimeImmutable(substr($end, 0, 10));
        $days = (int)$d->diff($to)->days + 1;
        return intdiv($days, 7);
    }

    /**
     * Mesi COMPLETI: quante volte si puo' avanzare di un mese esatto da
     * data_inizio restando entro data_fine (5 giu → 4 lug = 1 mese).
     * L'avanzamento clampa al fine mese per evitare l'overflow di PHP
     * (31 gen + 1 mese = 3 mar): 31 gen → 28 feb → 31 mar → 30 apr.
     */
    public static function completeMonths(string $start, string $end): int
    {
        $startD = new DateTimeImmutable(substr($start, 0, 10));
        $to     = new DateTimeImmutable(substr($end, 0, 10));
        $months = 0;
        // il mese N e' completo quando (inizio + N mesi - 1 giorno) <= fine
        while (self::addMonthsClamped($startD, $months + 1)->modify('-1 day') <= $to) {
            $months++;
            if ($months > 1200) break; // guardia
        }
        return $months;
    }

    /** start + N mesi, con il giorno clampato all'ultimo giorno del mese target. */
    private static function addMonthsClamped(DateTimeImmutable $start, int $n): DateTimeImmutable
    {
        $firstOfTarget = $start->modify('first day of this month')->modify("+{$n} months");
        $day = min((int)$start->format('j'), (int)$firstOfTarget->format('t'));
        return $firstOfTarget->setDate((int)$firstOfTarget->format('Y'), (int)$firstOfTarget->format('n'), $day);
    }
}
