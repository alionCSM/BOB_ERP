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
 *                     `calendario` = set libero di giorni della settimana in
 *                     CSV ISO (1=lun ... 7=dom, es. "1,2,3,4,5"); i festivi
 *                     nazionali contano solo se `festivi_inclusi`.
 *                     Ai giorni calcolati si sommano i giorni extra (date
 *                     specifiche in bb_lifting_extra_days, passate come
 *                     `extra_days_count` nella riga).
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
                (string)($rental['calendario'] ?? '1,2,3,4,5'),
                !empty($rental['festivi_inclusi'])
            ) + max(0, (int)($rental['extra_days_count'] ?? 0)), // date aggiunte a mano
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
                (string)($rental['calendario'] ?? '1,2,3,4,5'),
                !empty($rental['festivi_inclusi'])
            ) + max(0, (int)($rental['extra_days_count'] ?? 0)),
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
        $inCal = in_array($dow, self::weekdaySet($calendario), true);
        return $inCal && ($festiviInclusi || !self::isItalianHoliday($d));
    }

    /**
     * Calendario → set di giorni ISO. Formato nativo: CSV "1,2,3,4,5".
     * Accetta anche i preset legacy della prima versione (lun_ven, ...).
     * @return int[]
     */
    public static function weekdaySet(string $calendario): array
    {
        $legacy = [
            'lun_ven' => [1, 2, 3, 4, 5],
            'lun_sab' => [1, 2, 3, 4, 5, 6],
            'lun_dom' => [1, 2, 3, 4, 5, 6, 7],
            'sab_dom' => [6, 7],
        ];
        if (isset($legacy[$calendario])) return $legacy[$calendario];

        $days = array_values(array_filter(array_map(
            'intval',
            explode(',', $calendario)
        ), fn($n) => $n >= 1 && $n <= 7));

        return !empty($days) ? array_values(array_unique($days)) : [1, 2, 3, 4, 5];
    }

    /** Etichetta leggibile del calendario ("Lun, Mar, Mer, Gio, Ven"). */
    public static function weekdayLabel(string $calendario): string
    {
        $names = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Gio', 5 => 'Ven', 6 => 'Sab', 7 => 'Dom'];
        $set   = self::weekdaySet($calendario);
        sort($set);
        // compatta i range comuni
        if ($set === [1,2,3,4,5])       return 'Lun–Ven';
        if ($set === [1,2,3,4,5,6])     return 'Lun–Sab';
        if ($set === [1,2,3,4,5,6,7])   return 'Tutti i giorni';
        if ($set === [6,7])             return 'Sab–Dom';
        return implode(', ', array_map(fn($n) => $names[$n], $set));
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
