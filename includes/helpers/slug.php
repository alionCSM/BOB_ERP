<?php

/**
 * BOB – Generatore di slug filesystem-safe
 * Usato per client e cantieri
 */
function makeSlug(string $value): string
{
    // Minuscole
    $value = mb_strtolower($value, 'UTF-8');

    // Rimozione accenti
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    // Rimuove caratteri non validi
    $value = preg_replace('/[^a-z0-9\s-]/', '', $value);

    // Spazi multipli → trattino
    $value = preg_replace('/[\s-]+/', '-', trim($value));

    return $value;
}
