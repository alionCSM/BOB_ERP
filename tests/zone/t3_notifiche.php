<?php
declare(strict_types=1);

/**
 * Prova 3 — le notifiche: chi le riceve e chi no.
 *
 * Si usa il NotificationService vero, che scrive in bb_notifications: i
 * destinatari si contano leggendo la tabella, non fidandosi di quello che
 * il codice dice di aver fatto.
 */

require __DIR__ . '/zona.php';

$repo = dirname(__DIR__, 2);
require $repo . '/src/Service/Push/FcmService.php';
require $repo . '/src/Service/Notifications/NotificationService.php';

use App\Infrastructure\Config;
use App\Service\Notifications\NotificationService;
use App\Service\Zone\Accesso;
use App\Service\Zone\AvvisiZona;
use App\Service\Zone\Condivisione;
use App\Service\Zone\Messaggi;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

$p    = new Prove();
$acc  = new Accesso($pdo);
$cond = new Condivisione($pdo, $acc);
$u    = utenti($pdo);

$notifiche = new NotificationService($pdo, new Config());
$avvisi    = new AvvisiZona($pdo, $notifiche);
$messaggi  = new Messaggi($pdo);

/** A chi e' arrivata l'ultima infornata di notifiche. */
function destinatari(PDO $pdo): array
{
    $v = $pdo->query('SELECT user_id FROM bb_notifications ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('DELETE FROM bb_notifications');
    return array_values(array_unique(array_map('intval', $v)));
}

// ── Un'attivita' assegnata alla squadra ───────────────────────────────────
$task = new \App\Repository\Fieldwire\ZoneTaskRepository($pdo);
$id = $task->create(CANTIERE, ['name' => 'Montaggio travi'], ANNA);
$pdo->exec("UPDATE bb_zone_tasks SET assegnata_a = 'squadra' WHERE id = $id");
$cond->salva(CANTIERE, 'attivita', $id, [MARCO, ILIR], ANNA);

$avvisi->attivita($id, ANNA);
$p->uguale('la squadra viene avvisata, il cliente no', [MARCO, ILIR], destinatari($pdo));

// ── ...ma solo chi la vede ────────────────────────────────────────────────
$cond->salva(CANTIERE, 'attivita', $id, [MARCO], ANNA);
(new AvvisiZona($pdo, $notifiche))->attivita($id, ANNA);
$p->uguale('chi non la vede non viene avvisato', [MARCO], destinatari($pdo));

// ── Un'attivita' per il cliente ───────────────────────────────────────────
$idC = $task->create(CANTIERE, ['name' => 'Sopralluogo col committente'], ANNA);
$pdo->exec("UPDATE bb_zone_tasks SET assegnata_a = 'cliente' WHERE id = $idC");
$cond->salva(CANTIERE, 'attivita', $idC, [NERI], ANNA);
(new AvvisiZona($pdo, $notifiche))->attivita($idC, ANNA);
$p->uguale('il cliente viene avvisato', [NERI], destinatari($pdo));

// ── ...ma non se "Condividi col cliente" e' spento ────────────────────────
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 0 WHERE id = ' . CANTIERE);
(new AvvisiZona($pdo, $notifiche))->attivita($idC, ANNA);
$p->uguale('con la condivisione spenta il cliente non riceve niente', [], destinatari($pdo));
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 1 WHERE id = ' . CANTIERE);

// ── Chi fa la cosa non avvisa se stesso ───────────────────────────────────
$cond->salva(CANTIERE, 'attivita', $id, [MARCO, ILIR], ANNA);
(new AvvisiZona($pdo, $notifiche))->attivita($id, MARCO);
$p->uguale('chi l\'ha fatta non si auto-avvisa', [ILIR], destinatari($pdo));

// ── Un messaggio nella chat di cantiere ───────────────────────────────────
$canale = $messaggi->personeCanale(CANTIERE, 'squadra');
$ids = array_map(fn($x) => (int)$x['user_id'], $canale);
sort($ids);
$p->uguale('la chat squadra: ufficio del cantiere, capo e operaio', [ANNA, MARCO, ILIR], $ids);
$p->check('Luca, che ha il modulo ma non e\' dentro, non c\'e\'', !in_array(LUCA, $ids, true));
$p->check('e nemmeno l\'estraneo',                               !in_array(ESTRANEO, $ids, true));

// Il canale "cliente" e' noi e il committente, nessun altro in mezzo:
// prima c'erano anche i capi.
$cli = array_map(fn($x) => (int)$x['user_id'], $messaggi->personeCanale(CANTIERE, 'cliente'));
sort($cli);
$p->uguale('la chat cliente: solo ufficio e cliente', [ANNA, NERI], $cli);
$p->check('il capo resta fuori dalla chat del cliente', !in_array(MARCO, $cli, true));

// "tutti" e' il posto dove si parla insieme
$tutti = array_map(fn($x) => (int)$x['user_id'], $messaggi->personeCanale(CANTIERE, 'tutti'));
sort($tutti);
$p->uguale('la chat tutti: ufficio, capo, operaio e cliente', [ANNA, MARCO, ILIR, NERI], $tutti);

// il canale "capi" non esiste piu'
$p->uguale('i canali sono tre', ['squadra', 'cliente', 'tutti'], Messaggi::CANALI);
$p->uguale('chi chiede "capi" non trova nessuno', [], $messaggi->personeCanale(CANTIERE, 'capi'));

// e ognuno apre i suoi
$acc = new Accesso($pdo);
$apre = fn($chi) => $messaggi->canali((string)$acc->ruolo($u[$chi], CANTIERE), CANTIERE);
$p->uguale('ufficio: apre tutti e tre', ['squadra', 'cliente', 'tutti'], $apre('ufficio'));
$p->uguale('il capo: squadra e tutti',     ['squadra', 'tutti'],            $apre('capo'));
$p->uguale('operaio: squadra e tutti',    ['squadra', 'tutti'],            $apre('operaio'));
$p->uguale('il cliente: cliente e tutti',  ['cliente', 'tutti'],            $apre('cliente'));

// ── Il canale cliente sparisce se la condivisione e' spenta ───────────────
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 0 WHERE id = ' . CANTIERE);
$cliOff = array_map(fn($x) => (int)$x['user_id'], (new Messaggi($pdo))->personeCanale(CANTIERE, 'cliente'));
$p->check('spenta la condivisione, il cliente esce dal suo canale',
          !in_array(NERI, $cliOff, true));
$pdo->exec('UPDATE bb_worksites SET zone_cliente = 1 WHERE id = ' . CANTIERE);

// ── L'ufficio di un cantiere non e' tutto l'ufficio ──────────────────────
$uff = array_map(fn($x) => (int)$x['user_id'], (new Messaggi($pdo))->ufficio(CANTIERE));
$p->uguale('l\'ufficio del cantiere e\' solo chi ci abbiamo messo', [ANNA], $uff);

$altro = (new Messaggi($pdo))->ufficio(ALTRO);
$p->uguale('un cantiere senza ufficio non avvisa nessuno', [], $altro);

$ripiego = array_map(fn($x) => (int)$x['user_id'], (new Messaggi($pdo))->ufficio(ALTRO, true));
sort($ripiego);
$p->uguale('col ripiego (solo segnalazioni) prende chi ha il modulo', [ANNA, LUCA], $ripiego);

// ── Un modulo da compilare ────────────────────────────────────────────────
$form = new \App\Repository\Fieldwire\ZoneFormRepository($pdo);
$tpl = $form->create(['worksite_id' => CANTIERE, 'name' => 'DPI', 'fields' => [], 'created_by' => ANNA]);
$pdo->prepare("INSERT INTO bb_zone_form_assegnazioni (worksite_id, template_id, a_ruolo, frequenza, created_by)
               VALUES (?, ?, 'operaio', 'giornaliera', ?)")->execute([CANTIERE, $tpl, ANNA]);
$ass = (int)$pdo->lastInsertId();

(new AvvisiZona($pdo, $notifiche))->modulo($ass, ANNA);
$p->uguale('il modulo avvisa chi lo deve compilare', [ILIR], destinatari($pdo));

// ── Aggiunto al cantiere ──────────────────────────────────────────────────
(new AvvisiZona($pdo, $notifiche))->accesso(CANTIERE, MARCO, ANNA);
$p->uguale('chi viene aggiunto lo sa', [MARCO], destinatari($pdo));

(new AvvisiZona($pdo, $notifiche))->accesso(CANTIERE, ANNA, ANNA);
$p->uguale('chi si aggiunge da solo non si avvisa', [], destinatari($pdo));

// ── La notifica e' nella lingua di chi la riceve ──────────────────────────
$pdo->exec("UPDATE bb_users SET lingua = 'sq' WHERE id = " . ILIR);
$cond->salva(CANTIERE, 'attivita', $id, [ILIR], ANNA);
(new AvvisiZona($pdo, $notifiche))->attivita($id, ANNA);
$riga = $pdo->query('SELECT title FROM bb_notifications WHERE user_id = ' . ILIR)->fetch();
$itaTitolo = \App\Service\Lingua::testo('zona_attivita_t', 'it');
$p->check('all\'operaio albanese non arriva il titolo italiano',
          $riga !== false && $riga['title'] !== $itaTitolo && $riga['title'] !== '');
$pdo->exec('DELETE FROM bb_notifications');

exit($p->esito('Prova 3 — notifiche'));
