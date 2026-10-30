<?php
declare(strict_types=1);

/**
 * Prova 6 — la segnalazione appuntata sul disegno.
 *
 * Due versi: dal punto sul disegno alla segnalazione, e dalla segnalazione
 * al punto. Se funziona uno solo, in cantiere si usa il telefono.
 */

require __DIR__ . '/zona.php';

$repo = dirname(__DIR__, 2);
require $repo . '/src/Service/Push/FcmService.php';
require $repo . '/src/Service/Notifications/NotificationService.php';
require $repo . '/src/Repository/Fieldwire/ZoneAnnotationRepository.php';

use App\Repository\Fieldwire\ZoneAnnotationRepository;
use App\Service\Zone\Messaggi;
use App\Service\Zone\Segnalazioni;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

$p    = new Prove();
$ann  = new ZoneAnnotationRepository($pdo);
$segn = new Segnalazioni($pdo, new Messaggi($pdo));

// ── La colonna c'e' davvero ───────────────────────────────────────────────
$colonne = $pdo->query("
    SELECT column_name FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bb_zone_annotations'
")->fetchAll(PDO::FETCH_COLUMN);
$p->check('bb_zone_annotations ha segnalazione_id', in_array('segnalazione_id', $colonne, true));

// ── Un disegno ────────────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO bb_worksite_documents
               (worksite_id, file_name, file_path, file_type, subcategory, created_by)
               VALUES (?, 'pianta_piano_terra.pdf', 'x/pianta.pdf', 'pdf', 'piante', ?)")
    ->execute([CANTIERE, ANNA]);
$disegno = (int)$pdo->lastInsertId();

// ── Dal punto alla segnalazione ──────────────────────────────────────────
$id = $segn->crea(CANTIERE, 'capo', MARCO, [
    'testo'   => 'Qui manca un tirante',
    'tipo'    => 'sicurezza',
    'gravita' => 'blocca',
]);
$p->check('la segnalazione si crea', $id > 0);

$pin = $ann->create([
    'worksite_id'     => CANTIERE,
    'document_id'     => $disegno,
    'page'            => 2,
    'type'            => 'pin',
    'geom'            => ['x' => 0.42, 'y' => 0.71],
    'segnalazione_id' => $id,
    'color'           => '#ef4444',
    'created_by'      => MARCO,
]);
$p->check('lo spillo si crea', $pin > 0);

$righe = $ann->allForDocument($disegno, 2);
$p->uguale('sulla pagina 2 c\'e\' uno spillo', 1, count($righe));
$r = $righe[0];
$p->uguale('punta alla segnalazione',   $id, $r['segnalazione_id']);
$p->uguale('e si porta dietro il testo', 'Qui manca un tirante', $r['segn_testo']);
$p->uguale('il tipo',                    'sicurezza', $r['segn_tipo']);
$p->uguale('la gravita\'',               'blocca',    $r['segn_gravita']);
$p->uguale('e lo stato',                 'aperta',    $r['segn_stato']);
$p->uguale('la posizione resta dov\'era', ['x' => 0.42, 'y' => 0.71], $r['geom']);

// sull'altra pagina non c'e' niente
$p->uguale('la pagina 1 e\' vuota', 0, count($ann->allForDocument($disegno, 1)));

// ── Dalla segnalazione al punto ──────────────────────────────────────────
$dove = $ann->perSegnalazione($id);
$p->check('la segnalazione sa dov\'e\'', $dove !== null);
$p->uguale('il disegno giusto', $disegno, $dove['disegno']);
$p->uguale('la pagina giusta',  2,        $dove['pagina']);
$p->uguale('col nome del file', 'pianta_piano_terra.pdf', $dove['nome']);

// e l'elenco delle segnalazioni lo dice senza una query in piu'
$elenco = $segn->elenco(CANTIERE, 'ufficio', ANNA);
$mia = array_values(array_filter($elenco, fn($x) => (int)$x['id'] === $id))[0] ?? null;
$p->check('la segnalazione e\' in elenco', $mia !== null);
$p->uguale('con il disegno', $disegno, $mia['disegno_id'] ?? null);
$p->uguale('e la pagina',    2,        $mia['disegno_pagina'] ?? null);

// una segnalazione senza spillo non inventa un disegno
$senza = $segn->crea(CANTIERE, 'capo', MARCO, ['testo' => 'Materiale in ritardo', 'tipo' => 'ritardo']);
$elenco = $segn->elenco(CANTIERE, 'ufficio', ANNA);
$altra = array_values(array_filter($elenco, fn($x) => (int)$x['id'] === $senza))[0] ?? null;
$p->check('la seconda segnalazione compare in elenco', $altra !== null);
$p->check('la colonna del disegno esiste', array_key_exists('disegno_id', $altra ?? []));
$p->uguale('senza spillo, nessun disegno', null, $altra['disegno_id']);
$p->uguale('e nessuna posizione', null, $ann->perSegnalazione($senza));

// ── Lo spillo si sposta, non si moltiplica ───────────────────────────────
$ann->update($pin, [
    'geom'            => ['x' => 0.10, 'y' => 0.20],
    'text'            => null,
    'color'           => '#ef4444',
    'task_id'         => null,
    'segnalazione_id' => $id,
]);
$dopo = $ann->allForDocument($disegno, 2);
$p->uguale('resta uno spillo solo', 1, count($dopo));
$p->uguale('spostato',              ['x' => 0.10, 'y' => 0.20], $dopo[0]['geom']);
$p->uguale('sempre sulla stessa segnalazione', $id, $dopo[0]['segnalazione_id']);

// ── Uno spillo puo' portare un'attivita' invece che una segnalazione ─────
$task = (new \App\Repository\Fieldwire\ZoneTaskRepository($pdo))
    ->create(CANTIERE, ['name' => 'Montare staffa'], MARCO);
$pinTask = $ann->create([
    'worksite_id' => CANTIERE, 'document_id' => $disegno, 'page' => 2,
    'type' => 'pin', 'geom' => ['x' => 0.9, 'y' => 0.1],
    'task_id' => $task, 'created_by' => MARCO,
]);
$righe = $ann->allForDocument($disegno, 2);
$quello = array_values(array_filter($righe, fn($x) => $x['id'] === $pinTask))[0];
$p->uguale('lo spillo dell\'attivita\' ha il nome', 'Montare staffa', $quello['task_name']);
$p->uguale('e nessuna segnalazione', null, $quello['segnalazione_id']);

// ── Il testo dell'interfaccia usa i valori veri ──────────────────────────
$js = file_get_contents($repo . '/public/assets/js/views/fieldwire/annotator.js');
foreach (Segnalazioni::TIPI as $t) {
    $p->check("la tendina dei tipi ha '$t'", str_contains($js, '"' . $t . '"'));
}
foreach (Segnalazioni::GRAVITA as $g) {
    $p->check("la tendina delle gravita' ha '$g'", str_contains($js, '"' . $g . '"'));
}
// e non valori inventati, che il server scarterebbe in silenzio
foreach (['problema', 'media'] as $falso) {
    $p->check("non propone '$falso', che non esiste", !str_contains($js, 'value="' . $falso . '"'));
}

exit($p->esito('Prova 6 — segnalazioni sul disegno'));
