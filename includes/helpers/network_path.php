<?php
/**
 * BOB – Network path resolver
 * Converte un percorso Windows salvato in DB
 * in un percorso Linux sicuro sotto /mnt/BOB
 */

function resolveNetworkPath(string $relativePath): string
{
    $basePath = '/mnt/BOB';

    // Normalizza separatori (accetta sia \ che /)
    $relativePath = trim($relativePath);
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = preg_replace('#/+#', '/', $relativePath);

    // Costruzione path senza realpath (CIFS-safe)
    $fullPath = $basePath . '/' . ltrim($relativePath, '/');


    if (strpos($fullPath, $basePath) !== 0) {
        throw new Exception('Percorso di rete non autorizzato');
    }

    // Verifica reale esistenza cartella/file
    if (!file_exists($fullPath)) {
        throw new Exception('Percorso di rete non esistente');
    }

    return $fullPath;
}
