<?php
declare(strict_types=1);

/**
 * Prova 7 — svuotare la Zone di un cantiere.
 *
 * E' l'unica cosa in tutta la Zone che non si annulla, quindi le domande
 * sono tre: cancella davvero tutto, lascia in piedi quello che deve
 * restare, e non tocca gli altri cantieri.
 */

require __DIR__ . '/zona.php';

require dirname(__DIR__, 2) . '/src/Service/Zone/Reset.php';

use App\Service\Zone\Reset;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

$p     = new Prove();
$reset = new Reset($pdo);

// ── Due cantieri con dentro le stesse cose ────────────────────────────────
function riempi(PDO $pdo, int $w, int $chi): array
{
    $task = new \App\Repository\Fieldwire\ZoneTaskRepository($pdo);
    $file = new \App\Repository\Fieldwire\ZoneFileRepository($pdo);

    $t = $task->create($w, ['name' => 'Montaggio travi'], $chi);
    $task->addComment($t, 'ci siamo', 'Marco Rossi');

    $cartella = $file->createFolder($w, 'Contratti', $chi);
    $f = $file->create([
        'worksite_id' => $w, 'folder_id' => $cartella,
        'file_name' => 'contratto.pdf', 'file_path' => 'x/contratto.pdf',
        'file_type' => 'pdf', 'size_bytes' => 10, 'uploaded_by' => $chi,
    ]);

    $pdo->prepare("INSERT INTO bb_zone_messaggi (worksite_id, canale, user_id, testo)
                   VALUES (?, 'squadra', ?, 'domani alle sette')")->execute([$w, $chi]);
    $pdo->prepare("INSERT INTO bb_zone_segnalazioni (worksite_id, tipo, gravita, testo, visibilita, created_by)
                   VALUES (?, 'sicurezza', 'blocca', 'manca un tirante', 'squadra', ?)")->execute([$w, $chi]);
    $pdo->prepare("INSERT INTO bb_zone_presenze_manuali
                   (worksite_id, data, nome, azienda, persone, mezza, created_by)
                   VALUES (?, '2026-11-17', 'Impresa Alfa', 'Impresa Alfa', 3, 0, ?)")->execute([$w, $chi]);

    $acc = new \App\Service\Zone\Accesso($pdo);
    (new \App\Service\Zone\Condivisione($pdo, $acc))->salva($w, 'attivita', $t, [MARCO], $chi);

    return ['task' => $t, 'cartella' => $cartella, 'file' => $f];
}

$nostro = riempi($pdo, CANTIERE, ANNA);
$altro  = riempi($pdo, ALTRO, SUPER);

// Quanto c'e' nell'altro cantiere PRIMA: si fotografa adesso invece di
// darlo per uguale a questo. Le condivisioni dipendono da chi ha accesso
// dove, e i due cantieri non hanno la stessa gente dentro — darli per
// uguali vorrebbe dire provare una cosa che non e' vera.
$altroPrima = (new Reset($pdo))->anteprima(ALTRO)['totale'];

$conta = function (string $tabella, string $dove, int $w) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$tabella` WHERE $dove");
    $st->execute([':w' => $w]);
    return (int)$st->fetchColumn();
};

// ── 1. L'anteprima dice quanto c'e' ──────────────────────────────────────
$prima = $reset->anteprima(CANTIERE);
$p->check('l\'anteprima trova qualcosa', $prima['totale'] > 0);

$per = [];
foreach ($prima['righe'] as $r) { $per[$r['cosa']] = $r['quante']; }
$p->uguale('un\'attivita',            1, $per['Attivita'] ?? 0);
$p->uguale('un messaggio sull\'attivita', 1, $per['Messaggi sulle attivita'] ?? 0);
$p->uguale('una cartella',            1, $per['Cartelle'] ?? 0);
$p->uguale('un documento',            1, $per['Documenti'] ?? 0);
$p->uguale('un messaggio in chat',    1, $per['Messaggi in chat'] ?? 0);
$p->uguale('una segnalazione',        1, $per['Segnalazioni'] ?? 0);
$p->uguale('una presenza a mano',     1, $per['Presenze scritte a mano'] ?? 0);
$p->uguale('una condivisione',        1, $per['Chi vede cosa'] ?? 0);

// l'anteprima guarda e basta: non deve cancellare niente
$p->uguale('guardare non cancella', $prima['totale'], $reset->anteprima(CANTIERE)['totale']);

// ── 2. Il reset svuota ────────────────────────────────────────────────────
$fatto = $reset->esegui(CANTIERE, false, ANNA, 'Anna Bianchi');
$p->uguale('ha cancellato quello che aveva contato', $prima['totale'], $fatto['totale']);

foreach ([
    'bb_zone_tasks'            => 'worksite_id = :w',
    'bb_zone_folders'          => 'worksite_id = :w',
    'bb_zone_files'            => 'worksite_id = :w',
    'bb_zone_messaggi'         => 'worksite_id = :w',
    'bb_zone_segnalazioni'     => 'worksite_id = :w',
    'bb_zone_presenze_manuali' => 'worksite_id = :w',
] as $tabella => $dove) {
    $p->uguale("$tabella svuotata", 0, $conta($tabella, $dove, CANTIERE));
}

// anche i figli, che non hanno il numero del cantiere
$p->uguale('i messaggi delle attivita spariscono',
           0, (int)$pdo->query('SELECT COUNT(*) FROM bb_zone_task_comments')->fetchColumn()
              - $conta('bb_zone_task_comments', 'task_id IN (SELECT id FROM bb_zone_tasks WHERE worksite_id <> :w)', CANTIERE));
$p->uguale('le condivisioni spariscono', 0, $reset->anteprima(CANTIERE)['totale']);

// ── 3. Quello che deve restare, resta ────────────────────────────────────
$p->uguale('chi accede resta', 4, $conta('bb_zone_accessi', 'worksite_id = :w', CANTIERE));

$acc = new \App\Service\Zone\Accesso($pdo);
$u   = utenti($pdo);
foreach (['ufficio', 'capo', 'operaio', 'cliente'] as $chi) {
    $p->check("$chi e' ancora dentro il cantiere", $acc->qualcosa($u[$chi], CANTIERE));
}

// ── 4. L'altro cantiere non si tocca ─────────────────────────────────────
$p->uguale('l\'altro cantiere e\' intatto', $altroPrima, $reset->anteprima(ALTRO)['totale']);
$p->uguale('con la sua attivita',  1, $conta('bb_zone_tasks', 'worksite_id = :w', ALTRO));
$p->uguale('e il suo documento',   1, $conta('bb_zone_files', 'worksite_id = :w', ALTRO));

// ── 5. Il registro sopravvive e dice chi e' stato ────────────────────────
$righe = $reset->registro(CANTIERE);
$p->uguale('una riga nel registro', 1, count($righe));
$p->uguale('dice cosa e\' successo', 'reset', $righe[0]['azione'] ?? '');
$p->uguale('e chi e\' stato', 'Anna Bianchi', $righe[0]['user_nome'] ?? '');

$dentro = json_decode((string)($righe[0]['prima'] ?? '{}'), true);
$p->uguale('col conto di quello che c\'era', $prima['totale'], $dentro['totale'] ?? 0);
$p->check('e il dettaglio cosa per cosa', !empty($dentro['righe']));

// un secondo reset su un cantiere gia' vuoto non e' un errore
$vuoto = $reset->esegui(CANTIERE, false, ANNA, 'Anna Bianchi');
$p->uguale('svuotare due volte non rompe niente', 0, $vuoto['totale']);
$p->uguale('ma lascia la sua riga', 2, count($reset->registro(CANTIERE)));

// il registro dell'altro cantiere resta suo
$p->uguale('il registro non si mescola fra cantieri', 0, count($reset->registro(ALTRO)));

// ── 6. La password: il vero cancello ─────────────────────────────────────
$sorgente = file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/FieldwireController.php');
$p->check('il reset verifica la password', str_contains($sorgente, 'passwordGiusta'));
$p->check('e la rilegge dal database, non dalla sessione',
          str_contains($sorgente, 'SELECT password FROM bb_users'));
$p->check('con password_verify, non un confronto a mano',
          str_contains($sorgente, 'password_verify($password, $hash)'));

exit($p->esito('Prova 7 — reset della Zone'));
