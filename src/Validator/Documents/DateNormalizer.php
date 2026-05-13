<?php

declare(strict_types=1);

namespace App\Validator\Documents;

/**
 * Normalizza input data dai form documenti.
 *
 * Accetta diversi formati che l'utente (o BOB AI) può scrivere e ritorna
 * sempre YYYY-MM-DD per le colonne DATE di MySQL.
 *
 * Casi gestiti:
 *   - "23/01/2026"     -> "2026-01-23"
 *   - "23-01-2026"     -> "2026-01-23"
 *   - "23.01.2026"     -> "2026-01-23"
 *   - "2026-01-23"     -> "2026-01-23"   (già ISO, lasciato com'è)
 *   - "" o null        -> null
 *
 * Valori speciali (per colonne VARCHAR come scadenza):
 *   - "INDETERMINATO"          -> "INDETERMINATO"
 *   - "LEGALE RAPPRESENTANTE"  -> "LEGALE RAPPRESENTANTE"
 */
final class DateNormalizer
{
    /**
     * Per colonne DATE strict: ritorna YYYY-MM-DD o null. Mai stringhe libere.
     */
    public static function toIso(?string $value): ?string
    {
        $v = trim((string)$value);
        if ($v === '') return null;

        // ISO già pulito
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v)) {
            return $v;
        }

        // Provo i formati italiani con vari separatori
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $v);
            if ($dt && $dt->format($fmt) === $v) {
                return $dt->format('Y-m-d');
            }
        }

        // Formato non riconosciuto — null (chi chiama decide come reagire)
        return null;
    }

    /**
     * Per colonne VARCHAR-like (es. scadenza che ammette INDETERMINATO):
     * converte le date a YYYY-MM-DD se riconoscibili, altrimenti restituisce
     * il valore come uppercase trimmed (per i valori speciali).
     */
    public static function toIsoOrSpecial(?string $value): ?string
    {
        $v = trim((string)$value);
        if ($v === '') return null;

        // Specials
        $upper = mb_strtoupper($v);
        if (in_array($upper, ['INDETERMINATO', 'INDETERMINATA', 'LEGALE RAPPRESENTANTE'], true)) {
            return $upper;
        }

        $iso = self::toIso($v);
        return $iso !== null ? $iso : $v; // se non parseabile lascia com'è
    }
}
