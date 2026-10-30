<?php
declare(strict_types=1);

/**
 * Fa girare tutte le prove della Zone, una dopo l'altra.
 *
 *   php tests/zone/esegui.php
 *
 * Ognuna ricostruisce il database da capo, quindi l'ordine non conta e una
 * che fallisce non sporca le altre.
 */

$prove = ['t1_accessi', 't2_contenuti', 't3_notifiche', 't4_cliente', 't5_presenze', 't6_disegni'];
$php   = PHP_BINARY;
$falliti = 0;

foreach ($prove as $p) {
    $file = __DIR__ . DIRECTORY_SEPARATOR . $p . '.php';
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($file), $esito);
    if ($esito !== 0) {
        $falliti++;
    }
    echo str_repeat('─', 60) . "\n";
}

echo $falliti === 0
    ? "Tutte le prove passate.\n"
    : "$falliti prove fallite.\n";

exit($falliti === 0 ? 0 : 1);
