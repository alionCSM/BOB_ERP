<?php

declare(strict_types=1);

/**
 * Controllo di struttura sui template Twig, senza Twig.
 *
 *   php tests/twig_struttura.php
 *
 * Non sostituisce Twig: non valuta espressioni e non conosce i filtri. Fa
 * due sole cose, che sono quelle che si sbagliano davvero modificando un
 * template a mano:
 *
 *  1. **i tag aperti e chiusi tornano?** block/endblock, if/endif,
 *     for/endfor, nell'ordine giusto;
 *
 *  2. **in un template che ne estende un altro, esce del testo fuori dai
 *     blocchi?** Twig lo rifiuta — "A template that extends another one
 *     cannot include content outside Twig blocks" — e lo scopre solo
 *     aprendo la pagina. E' il genere di errore che finisce in produzione
 *     senza accorgersene, perche' il file "sembra" giusto. E' successo.
 *
 * Sul secondo punto conta la regola vera di Twig: fuori dai blocchi non
 * puo' esserci **output** — testo o {{ ... }}. I tag invece si possono
 * mettere: `set`, `import`, `use`, `macro`, e anche un `for` che contiene
 * solo assegnazioni, perche' non stampa niente. Il primo tentativo di
 * questo controllo segnalava anche quelli, e dava tre allarmi falsi su sei.
 *
 * Serve perche' qui vendor/ non c'e' e Twig non si puo' eseguire. Dove
 * c'e', `lint:twig` fa molto di piu': questo e' il minimo che gira ovunque.
 */

$radice = dirname(__DIR__) . '/templates';

/** I tag che aprono qualcosa, col loro tag di chiusura. */
const COPPIE = [
    'block'      => 'endblock',
    'if'         => 'endif',
    'for'        => 'endfor',
    'macro'      => 'endmacro',
    'set'        => 'endset',      // solo nella forma senza '='
    'apply'      => 'endapply',
    'autoescape' => 'endautoescape',
    'embed'      => 'endembed',
    'filter'     => 'endfilter',
    'spaceless'  => 'endspaceless',
    'verbatim'   => 'endverbatim',
    'with'       => 'endwith',
    'cache'      => 'endcache',
];

$problemi = 0;
$file     = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice));
$elenco = [];
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.twig')) {
        $elenco[] = $f->getPathname();
    }
}
sort($elenco);

foreach ($elenco as $percorso) {
    $file++;
    $nome = str_replace('\\', '/', substr($percorso, strlen($radice) + 1));
    $src  = (string)file_get_contents($percorso);

    // I commenti si tolgono subito: dentro un {# #} ci si scrive di tutto,
    // compresi esempi di tag. Le righe pero' devono restare quelle, se no
    // i numeri che stampiamo non corrispondono al file.
    $pulito = (string)preg_replace_callback(
        '/\{#.*?#\}/s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    );

    // ── 1. I tag si chiudono? ───────────────────────────────────────────
    preg_match_all('/\{%-?\s*(\w+)/', $pulito, $m, PREG_OFFSET_CAPTURE);
    $pila = [];
    foreach ($m[1] as [$tag, $pos]) {
        $riga = substr_count(substr($pulito, 0, $pos), "\n") + 1;

        // {% set x = 1 %} non apre niente: solo {% set x %}...{% endset %}
        if ($tag === 'set') {
            $fine  = strpos($pulito, '%}', $pos);
            $corpo = substr($pulito, $pos, $fine === false ? 40 : $fine - $pos);
            if (str_contains($corpo, '=')) {
                continue;
            }
        }

        if (isset(COPPIE[$tag])) {
            $pila[] = [$tag, $riga];
            continue;
        }
        if (in_array($tag, COPPIE, true)) {
            $atteso = array_search($tag, COPPIE, true);
            $ultimo = array_pop($pila);
            if ($ultimo === null) {
                echo "  $nome:$riga  {% $tag %} senza il suo {% $atteso %}\n";
                $problemi++;
            } elseif ($ultimo[0] !== $atteso) {
                echo "  $nome:$riga  {% $tag %} chiude {% {$ultimo[0]} %} aperto alla riga {$ultimo[1]}\n";
                $problemi++;
            }
        }
    }
    foreach ($pila as [$tag, $riga]) {
        echo "  $nome:$riga  {% $tag %} non viene mai chiuso\n";
        $problemi++;
    }

    // ── 2. Output fuori dai blocchi, in un template che estende ─────────
    if (!preg_match('/\{%-?\s*extends\s/', $pulito)) {
        continue;
    }

    // Via i blocchi interi, dal piu' interno al piu' esterno, e le macro
    // (che fuori dai blocchi ci possono stare).
    $fuori = $pulito;
    foreach (['block', 'macro'] as $contenitore) {
        do {
            $prima = $fuori;
            $fuori = (string)preg_replace(
                '/\{%-?\s*' . $contenitore . '\s+\w+[^%]*-?%\}'
                . '(?:(?!\{%-?\s*' . $contenitore . '\s).)*?'
                . '\{%-?\s*end' . $contenitore . '[^%]*-?%\}/s',
                '',
                $fuori
            );
        } while ($fuori !== $prima);
    }
    // la forma breve: {% block titolo 'x' %} senza endblock
    $fuori = (string)preg_replace('/\{%-?\s*block\s+\w+\s+[^%]*-?%\}/s', '', $fuori);

    // Quello che resta: contano solo le cose che STAMPANO. I tag no — set,
    // import, use e un for di sole assegnazioni non producono output, e
    // Twig li accetta fuori dai blocchi.
    $resto = (string)preg_replace('/\{%.*?%\}/s', '', $fuori);

    if (trim($resto) !== '') {
        $pezzo = trim((string)preg_split('/\R/', trim($resto))[0]);
        echo "  $nome  esce del testo fuori dai blocchi (Twig lo rifiuta): "
           . mb_substr($pezzo, 0, 70) . "\n";
        $problemi++;
    }
}

echo "\n$file template controllati, $problemi problemi.\n";
exit($problemi === 0 ? 0 : 1);
