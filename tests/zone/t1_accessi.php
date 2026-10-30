<?php
declare(strict_types=1);

/**
 * Prova 1 — gli accessi: i 57 endpoint della Zone per ogni tipo di utente.
 *
 * La mappa dei permessi (FieldwireController::ACCESSI) si legge dal file
 * vero: se qualcuno aggiunge un endpoint e si scorda la riga, qui si vede.
 */

require __DIR__ . '/zona.php';

use App\Service\Zone\Accesso;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

$p   = new Prove();
$acc = new Accesso($pdo);
$u   = utenti($pdo);

// ── La mappa vera ──────────────────────────────────────────────────────────
$src = file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/FieldwireController.php');
preg_match('/const ACCESSI = \[(.*?)\n    \];/s', $src, $m) or exit("mappa ACCESSI non trovata\n");
$ACCESSI = eval('return [' . $m[1] . '];');

$p->check('la mappa ha almeno 50 endpoint', count($ACCESSI) >= 50);

// Ogni endpoint pubblico deve stare nella mappa: uno dimenticato risponde
// "Non consentito" a tutti, ma e' meglio accorgersene qui.
preg_match_all('/public function (\w+)\(Request \$request\)/', $src, $mm);
$pubblici = array_values(array_diff($mm[1], ['webhook']));
$senzaRegola = array_values(array_diff($pubblici, array_keys($ACCESSI)));
$p->uguale('nessun endpoint senza regola', [], $senzaRegola);

// ── Il controllo, come lo fa la guardia ───────────────────────────────────
$regoleViste = [];
$decide = function ($utente, int $w, $regola) use ($acc, &$regoleViste): bool {
    $regoleViste[is_array($regola) ? 'livello' : $regola] = true;
    if ($regola === 'gestione') {
        return $acc->gestisce($utente);
    }
    if ($regola === 'pagina') {
        return $acc->daUfficio($utente, $w) || $acc->qualcosa($utente, $w) || $acc->gestisce($utente);
    }
    if ($regola === 'ufficio') {
        return $acc->daUfficio($utente, $w);
    }
    [$famiglia, $minimo] = $regola;
    return $acc->livello($utente, $w, $famiglia) >= $minimo;
};

// ── La matrice ────────────────────────────────────────────────────────────
$esito = [];
foreach ($ACCESSI as $endpoint => $regola) {
    foreach ($u as $chi => $utente) {
        $esito[$endpoint][$chi] = $decide($utente, CANTIERE, $regola);
    }
}

// se domani qualcuno inventa una regola nuova e non la gestisce, si vede
$p->uguale('le regole sono quelle che il test sa controllare',
           ['gestione', 'livello', 'pagina', 'ufficio'],
           (function () use ($regoleViste) { $k = array_keys($regoleViste); sort($k); return $k; })());

// ── Quello che non deve succedere, mai ────────────────────────────────────
$apertiAEstraneo = array_keys(array_filter($esito, fn($r) => $r['estraneo']));
$p->uguale('a un estraneo non si apre niente', [], $apertiAEstraneo);

$ufficioOGestione = array_keys(array_filter($ACCESSI, fn($r) => $r === 'ufficio' || $r === 'gestione'));

foreach (['cliente', 'operaio', 'capo'] as $chi) {
    $rotti = array_values(array_filter($ufficioOGestione, fn($e) => $esito[$e][$chi]));
    $p->uguale("niente roba da ufficio per: $chi", [], $rotti);
}

// Chi ha il modulo ma non e' nel cantiere: solo dare accessi e aprire la
// pagina (che gli mostra il pannello e basta).
$apertiAFuori = array_keys(array_filter($esito, fn($r) => $r['fuori']));
sort($apertiAFuori);
$attesiFuori = array_keys(array_filter($ACCESSI, fn($r) => $r === 'gestione' || $r === 'pagina'));
sort($attesiFuori);
$p->uguale('chi ha il modulo e non e\' dentro apre solo gli accessi e la pagina',
           $attesiFuori, $apertiAFuori);

// L'ufficio del cantiere entra ovunque
$chiusiAUfficio = array_keys(array_filter($esito, fn($r) => !$r['ufficio']));
$p->uguale('all\'ufficio del cantiere non si chiude niente', [], $chiusiAUfficio);

// ── Le regole che contano, una per una ────────────────────────────────────
$p->check('un operaio compila i moduli',        $acc->livello($u['operaio'], CANTIERE, 'moduli')   >= 2);
$p->check('un operaio NON vede i report',       $acc->livello($u['operaio'], CANTIERE, 'report')   === 0);
$p->check('un operaio guarda i disegni',        $acc->livello($u['operaio'], CANTIERE, 'disegni')  === 1);
$p->check('un operaio non carica file',         $acc->livello($u['operaio'], CANTIERE, 'file')     === 1);
$p->check('un capo carica file',                $acc->livello($u['capo'],    CANTIERE, 'file')     >= 2);
$p->check('un capo non modifica i disegni',     $acc->livello($u['capo'],    CANTIERE, 'disegni')  === 1);
$p->check('un cliente non vede i file',         $acc->livello($u['cliente'], CANTIERE, 'file')     === 1);
$p->check('un cliente compila i moduli',        $acc->livello($u['cliente'], CANTIERE, 'moduli')   >= 2);

// ── Il cliente senza "Condividi col cliente" non entra ────────────────────
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 0 WHERE id = ' . CANTIERE);
$acc2 = new Accesso($pdo);
$p->check('spenta la condivisione, il cliente non ha piu\' ruolo',
          $acc2->ruolo($u['cliente'], CANTIERE) === null);
$p->check('...e non apre nemmeno la pagina',
          !$acc2->qualcosa($u['cliente'], CANTIERE));
$p->check('...mentre la squadra resta dentro',
          $acc2->qualcosa($u['operaio'], CANTIERE) && $acc2->qualcosa($u['capo'], CANTIERE));
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 1 WHERE id = ' . CANTIERE);

// ── Un altro cantiere non e' il suo ───────────────────────────────────────
$acc3 = new Accesso($pdo);
foreach (['ufficio', 'capo', 'operaio', 'cliente'] as $chi) {
    $p->check("$chi non entra nel cantiere di un altro", !$acc3->qualcosa($u[$chi], ALTRO));
}

// ── La tabella, per leggerla a occhio ─────────────────────────────────────
echo "\nEndpoint aperti, per tipo di utente:\n";
foreach (array_keys($u) as $chi) {
    printf("  %-9s %2d / %d\n", $chi, count(array_filter($esito, fn($r) => $r[$chi])), count($ACCESSI));
}

exit($p->esito('Prova 1 — accessi'));
