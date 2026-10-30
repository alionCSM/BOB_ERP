<?php
declare(strict_types=1);

/**
 * Prova 2 — creare, condividere, compilare, modificare.
 *
 * Usa i repository veri contro MySQL vero: qui si rompe la SQL, non la
 * teoria.
 */

require __DIR__ . '/zona.php';

use App\Repository\Fieldwire\ZoneFileRepository;
use App\Repository\Fieldwire\ZoneFormRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;
use App\Service\Zone\Accesso;
use App\Service\Zone\Condivisione;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

$p    = new Prove();
$acc  = new Accesso($pdo);
$cond = new Condivisione($pdo, $acc);
$u    = utenti($pdo);

$task  = new ZoneTaskRepository($pdo);
$file  = new ZoneFileRepository($pdo);
$form  = new ZoneFormRepository($pdo);

// ── Creare un'attivita' ───────────────────────────────────────────────────
$taskId = $task->create(CANTIERE, ['name' => 'Montaggio travi', 'priority' => 1], MARCO);
$p->check('l\'attivita\' si crea', $taskId > 0);

// nasce condivisa con la squadra, non col cliente: cosa va al committente
// lo decide l'ufficio
$messi = $cond->salva(CANTIERE, 'attivita', $taskId,
                      $cond->predefinite(CANTIERE, Accesso::CAPO), MARCO);
sort($messi);
$p->uguale('nasce per capi e operai, non per il cliente', [MARCO, ILIR], $messi);

$p->check('il capo la vede',      $cond->vede($u['capo'],    CANTIERE, 'attivita', $taskId));
$p->check('l\'operaio la vede',   $cond->vede($u['operaio'], CANTIERE, 'attivita', $taskId));
$p->check('il cliente NO',       !$cond->vede($u['cliente'], CANTIERE, 'attivita', $taskId));
$p->check('l\'ufficio sempre',    $cond->vede($u['ufficio'], CANTIERE, 'attivita', $taskId));
$p->check('chi e\' fuori NO',    !$cond->vede($u['fuori'],   CANTIERE, 'attivita', $taskId));
$p->check('un estraneo NO',      !$cond->vede($u['estraneo'],CANTIERE, 'attivita', $taskId));

// ── L'ufficio la condivide col cliente ────────────────────────────────────
$cond->salva(CANTIERE, 'attivita', $taskId, [MARCO, ILIR, NERI], ANNA);
$cond2 = new Condivisione($pdo, new Accesso($pdo));
$p->check('condivisa, il cliente la vede', $cond2->vede($u['cliente'], CANTIERE, 'attivita', $taskId));

// ── ...e poi gliela toglie ────────────────────────────────────────────────
$cond->salva(CANTIERE, 'attivita', $taskId, [MARCO, ILIR], ANNA);
$cond3 = new Condivisione($pdo, new Accesso($pdo));
$p->check('tolta, il cliente non la vede piu\'', !$cond3->vede($u['cliente'], CANTIERE, 'attivita', $taskId));

// ── Non si condivide con chi nel cantiere non c'e' ────────────────────────
$messi = $cond->salva(CANTIERE, 'attivita', $taskId, [MARCO, ESTRANEO, 9999], ANNA);
$p->uguale('gli estranei vengono scartati', [MARCO], $messi);

// ── Modificare ────────────────────────────────────────────────────────────
$prima = $task->find($taskId);
$task->update($taskId, array_merge($prima, ['name' => 'Montaggio travi - rev. 2', 'status' => 'in_progress']));
$dopo = $task->find($taskId);
$p->uguale('il nome cambia',  'Montaggio travi - rev. 2', $dopo['name']);
$p->uguale('lo stato cambia', 'in_progress', $dopo['status']);

// ── Cartelle e file: il file segue la cartella ────────────────────────────
$cartella = $file->createFolder(CANTIERE, 'Contratti', ANNA);
$cond->salva(CANTIERE, 'cartella', $cartella, [MARCO], ANNA);

$fileId = $file->create([
    'worksite_id' => CANTIERE, 'folder_id' => $cartella,
    'file_name'   => 'contratto.pdf', 'file_path' => 'x/contratto.pdf',
    'file_type'   => 'pdf', 'size_bytes' => 1234, 'uploaded_by' => ANNA,
]);
$p->check('il file si crea', $fileId > 0);

// la regola del controller: il file senza condivisioni sue segue la cartella
$vedeFile = function ($utente) use ($cond, $cartella, $fileId, $acc): bool {
    if ($cond->vede($utente, CANTIERE, 'file', $fileId, ANNA)) return true;
    return $cond->vede($utente, CANTIERE, 'cartella', $cartella);
};
$p->check('il capo vede il file della sua cartella',  $vedeFile($u['capo']));
$p->check('l\'operaio no: la cartella non e\' sua', !$vedeFile($u['operaio']));
$p->check('il cliente no',                          !$vedeFile($u['cliente']));

// un file condiviso per conto suo vale anche fuori dalla cartella
$cond->salva(CANTIERE, 'file', $fileId, [ILIR], ANNA);
$cond4 = new Condivisione($pdo, new Accesso($pdo));
$p->check('il file condiviso a parte si vede',
          $cond4->vede($u['operaio'], CANTIERE, 'file', $fileId, ANNA));

// ── Moduli: assegnare e compilare ─────────────────────────────────────────
$tpl = $form->create([
    'worksite_id' => CANTIERE,
    'name'        => 'Verbale di consegna',
    'fields'      => [['key' => 'note', 'label' => 'Note', 'type' => 'text']],
    'created_by'  => ANNA,
]);
$p->check('il modello si crea', $tpl > 0);

$pdo->prepare("INSERT INTO bb_zone_form_assegnazioni
               (worksite_id, template_id, a_ruolo, frequenza, created_by)
               VALUES (?, ?, 'cliente', 'una_volta', ?)")->execute([CANTIERE, $tpl, ANNA]);
$assegnazione = (int)$pdo->lastInsertId();

// chi legge le risposte: l'ufficio decide. Qui: nessuno fuori dall'ufficio.
$cond->salva(CANTIERE, 'modulo', $assegnazione, [], ANNA);

$sub = $form->createSubmission([
    'template_id'    => $tpl,
    'worksite_id'    => CANTIERE,
    'template_name'  => 'Verbale di consegna',
    'values'         => ['note' => 'tutto a posto'],
    'submitter_name' => 'Geom. Neri',
    'submitted_by'   => NERI,
    'source'         => 'internal',
]);
$p->check('il cliente compila il modulo', $sub > 0);

// la risposta eredita chi legge dall'assegnazione: nessuno, cioe' solo ufficio
$persone = $cond->conChi('modulo', [$assegnazione])[$assegnazione] ?? [];
$cond->salva(CANTIERE, 'risposta', $sub, $persone, NERI);

$cond5 = new Condivisione($pdo, new Accesso($pdo));
$p->check('la risposta la legge l\'ufficio',  $cond5->vede($u['ufficio'], CANTIERE, 'risposta', $sub, NERI));
$p->check('chi l\'ha compilata la rilegge',   $cond5->vede($u['cliente'], CANTIERE, 'risposta', $sub, NERI));
$p->check('il capo NON legge la risposta',   !$cond5->vede($u['capo'],    CANTIERE, 'risposta', $sub, NERI));
$p->check('l\'operaio NON legge la risposta',!$cond5->vede($u['operaio'], CANTIERE, 'risposta', $sub, NERI));

// ── Il pannello "Chi la vede" ─────────────────────────────────────────────
$gruppi = $cond->persone(CANTIERE);
$titoli = array_column($gruppi, 'titolo');
$p->uguale('tre gruppi, senza l\'ufficio', ['Capi squadra', 'Operai', 'Cliente'], $titoli);

$tuttiGliId = [];
foreach ($gruppi as $g) {
    foreach ($g['persone'] as $x) { $tuttiGliId[] = $x['id']; }
}
sort($tuttiGliId);
$p->uguale('si sceglie fra capo, operaio e cliente', [MARCO, ILIR, NERI], $tuttiGliId);

// ── "Chi accede": assegnare scrive tutte e due le tabelle ────────────────
$n = (int)$pdo->query('SELECT COUNT(*) FROM bb_worksite_users WHERE worksite_id = ' . CANTIERE)->fetchColumn();
$m = (int)$pdo->query('SELECT COUNT(*) FROM bb_zone_accessi   WHERE worksite_id = ' . CANTIERE)->fetchColumn();
$p->uguale('le due tabelle degli accessi restano allineate', $n, $m);

// togliere una persona la toglie da tutte e due
$repoAcc = new \App\Repository\Zone\AccessoRepository($pdo);
$repoAcc->elimina(CANTIERE, ILIR);
$n2 = (int)$pdo->query('SELECT COUNT(*) FROM bb_worksite_users WHERE worksite_id = ' . CANTIERE . ' AND user_id = ' . ILIR)->fetchColumn();
$m2 = (int)$pdo->query('SELECT COUNT(*) FROM bb_zone_accessi   WHERE worksite_id = ' . CANTIERE . ' AND user_id = ' . ILIR)->fetchColumn();
$p->uguale('tolto da bb_worksite_users', 0, $n2);
$p->uguale('tolto da bb_zone_accessi',   0, $m2);

$accDopo = new Accesso($pdo);
$p->check('tolto, non entra piu\' nel cantiere', !$accDopo->qualcosa($u['operaio'], CANTIERE));

exit($p->esito('Prova 2 — contenuti'));
