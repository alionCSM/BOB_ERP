<?php
declare(strict_types=1);

/**
 * Prova 4 — il cliente, dall'ingresso in BOB a cosa trova nella Zone.
 *
 * E' la prova che conta di piu': e' l'unico tipo di utente che sta fuori
 * dall'azienda.
 */

require __DIR__ . '/zona.php';

$repo = dirname(__DIR__, 2);
require $repo . '/src/Security/RoutePolicyMap.php';

use App\Security\RoutePolicyMap;
use App\Service\Zone\Accesso;
use App\Service\Zone\Condivisione;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

$p    = new Prove();
$acc  = new Accesso($pdo);
$cond = new Condivisione($pdo, $acc);
$u    = utenti($pdo);

// ── 1. Dove puo' andare in BOB ────────────────────────────────────────────
$map = new RoutePolicyMap();
$suoi = ['/worksites/my', '/worksites/10/zone', '/worksites/10/zone/attivita',
         '/worksites/10/disegni/3/view', '/dashboard', '/profile', '/notifications',
         '/change-password', '/logout', '/'];
$altrui = ['/worksites', '/worksites/10', '/worksites/10/edit', '/worksites/create',
           '/billing', '/billing/clients', '/documents', '/users', '/attendance',
           '/clients', '/pianificazione', '/report/fatturato', '/io', '/io/presenze',
           '/companies', '/offers', '/equipment', '/ordini', '/fatturazione/consorziate'];
foreach ($suoi as $x)   { $p->check("il cliente entra in $x",     $map->isClientRouteAllowed($x)); }
foreach ($altrui as $x) { $p->check("il cliente NON entra in $x", !$map->isClientRouteAllowed($x)); }

// ── 2. Quali cantieri sono suoi ───────────────────────────────────────────
// La query vera di ScopeService, presa dal file: se cambia, cambia qui.
$src = file_get_contents($repo . '/src/Security/ScopeService.php');
preg_match("/type === 'client'.*?prepare\('(.*?)'\);/s", $src, $m) or exit("query cliente non trovata\n");
$st = $pdo->prepare($m[1]);

$st->execute([':uid' => NERI, ':cid' => 1]);
$p->uguale('vede il cantiere condiviso, e solo quello', [CANTIERE],
           array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

// un cantiere suo ma non condiviso non lo vede
$pdo->exec("INSERT INTO bb_worksites (id, worksite_code, name, client_id, status, zone_cliente)
            VALUES (30, 'C-600', 'Magazzino', 1, 'In corso', 1)");
$st->execute([':uid' => NERI, ':cid' => 1]);
$p->uguale('un cantiere suo ma non assegnato non compare', [CANTIERE],
           array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

// assegnato ma con la condivisione spenta: neanche
$pdo->exec('INSERT INTO bb_worksite_users (worksite_id, user_id) VALUES (30, ' . NERI . ')');
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 0 WHERE id = 30');
$st->execute([':uid' => NERI, ':cid' => 1]);
$p->uguale('assegnato ma non condiviso: non compare', [CANTIERE],
           array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

// acceso: adesso si'
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 1 WHERE id = 30');
$st->execute([':uid' => NERI, ':cid' => 1]);
$p->uguale('acceso "Condividi col cliente": compare', [CANTIERE, 30],
           array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

// il cantiere di un altro committente, anche se per sbaglio assegnato
$pdo->exec('INSERT INTO bb_worksite_users (worksite_id, user_id) VALUES (' . ALTRO . ', ' . NERI . ')');
$st->execute([':uid' => NERI, ':cid' => 1]);
$rimasti = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$p->check('il cantiere di un altro committente resta fuori', !in_array(ALTRO, $rimasti, true));

// ── 3. Il controllo sul numero nell'indirizzo ────────────────────────────
$mw = file_get_contents($repo . '/includes/middleware.php');
preg_match("/preg_match\('(#\^\/worksites[^']+#)'/", $mw, $mm) or exit("regex del cantiere non trovata\n");
$p->check('da /worksites/20/zone si estrae il 20',
          preg_match($mm[1], '/worksites/20/zone', $x) === 1 && $x[1] === '20');
$p->check('/worksites/my non e\' un numero', preg_match($mm[1], '/worksites/my') === 0);

// ── 4. Dentro la Zone: cosa vede davvero ─────────────────────────────────
$task = new \App\Repository\Fieldwire\ZoneTaskRepository($pdo);
$file = new \App\Repository\Fieldwire\ZoneFileRepository($pdo);

$interna  = $task->create(CANTIERE, ['name' => 'Rifare saldature'], MARCO);
$pubblica = $task->create(CANTIERE, ['name' => 'Consegna piano terra'], ANNA);
$cond->salva(CANTIERE, 'attivita', $interna,  [MARCO, ILIR], ANNA);
$cond->salva(CANTIERE, 'attivita', $pubblica, [MARCO, NERI], ANNA);

$c = new Condivisione($pdo, new Accesso($pdo));
$p->check('il cliente vede quella condivisa con lui',  $c->vede($u['cliente'], CANTIERE, 'attivita', $pubblica));
$p->check('e NON vede quella della squadra',          !$c->vede($u['cliente'], CANTIERE, 'attivita', $interna));
$p->check('la squadra vede la sua',                    $c->vede($u['operaio'], CANTIERE, 'attivita', $interna));
$p->check('l\'operaio non vede quella del cliente',   !$c->vede($u['operaio'], CANTIERE, 'attivita', $pubblica));

// un documento riservato
$cartella = $file->createFolder(CANTIERE, 'Contratti', ANNA);
$contratto = $file->create([
    'worksite_id' => CANTIERE, 'folder_id' => $cartella,
    'file_name' => 'contratto.pdf', 'file_path' => 'x.pdf',
    'file_type' => 'pdf', 'size_bytes' => 1, 'uploaded_by' => ANNA,
]);
// nessuna condivisione: resta all'ufficio
$p->check('un contratto senza condivisioni resta all\'ufficio',
          !$c->vede($u['cliente'], CANTIERE, 'file', $contratto, ANNA)
          && !$c->vede($u['cliente'], CANTIERE, 'cartella', $cartella));
$p->check('e nemmeno la squadra lo vede',
          !$c->vede($u['capo'], CANTIERE, 'file', $contratto, ANNA)
          && !$c->vede($u['capo'], CANTIERE, 'cartella', $cartella));

// ── 5. Cosa puo' toccare ─────────────────────────────────────────────────
$p->check('il cliente non crea attivita\' (serve essere capo)',
          $acc->ruolo($u['cliente'], CANTIERE) !== Accesso::CAPO
          && $acc->ruolo($u['cliente'], CANTIERE) !== Accesso::UFFICIO);
// il report lo scarica, ma dentro c'e' solo quello che gli e' condiviso:
// il filtro delle attivita' passa dalle condivisioni, non dal PDF
$p->check('il cliente scarica il report (filtrato su quello che vede)',
          $acc->livello($u['cliente'], CANTIERE, 'report') === 1);
$p->check('il cliente puo\' compilare i moduli che gli diamo',
          $acc->livello($u['cliente'], CANTIERE, 'moduli') >= 2);
$p->check('il cliente non modifica i file',
          $acc->livello($u['cliente'], CANTIERE, 'file') < 2);

// ── 6. L'elenco delle persone: non deve uscire l'azienda ─────────────────
$sorgente = file_get_contents($repo . '/src/Http/Controllers/FieldwireController.php');
preg_match('/public function bobUsers.*?\n    \}/s', $sorgente, $bu);
$p->check('l\'elenco utenti e\' limitato al cantiere per chi non e\' ufficio',
          str_contains($bu[0], 'bb_zone_accessi WHERE worksite_id'));

exit($p->esito('Prova 4 — il cliente'));
