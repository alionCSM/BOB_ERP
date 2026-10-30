<?php
declare(strict_types=1);

/**
 * Prova 5 — il registro presenze nella Zone.
 *
 * Due cose da dimostrare: che i numeri siano quelli giusti, e che pasti,
 * albergo, auto e costi non escano dall'ufficio.
 */

require __DIR__ . '/zona.php';

require dirname(__DIR__, 2) . '/src/Service/Zone/Presenze.php';

use App\Service\Zone\Accesso;
use App\Service\Zone\Presenze;

$pdo = db();
costruisciSchema($pdo);
semina($pdo);

// Le tabelle delle presenze non hanno migrazioni (sono nate col
// gestionale): qui le colonne sono quelle che il codice legge.
$pdo->exec("
    CREATE TABLE bb_workers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(255) NOT NULL DEFAULT '',
        last_name  VARCHAR(255) NOT NULL DEFAULT '',
        company    VARCHAR(255) NOT NULL DEFAULT '',
        active ENUM('Y','N') NOT NULL DEFAULT 'Y',
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE bb_companies (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        consorziata TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE bb_presenze (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        worker_id INT UNSIGNED NOT NULL,
        worksite_id INT UNSIGNED NOT NULL,
        data DATE NOT NULL,
        turno ENUM('Intero','Mezzo') NOT NULL DEFAULT 'Intero',
        pranzo TINYINT(1) NOT NULL DEFAULT 0,
        pranzo_prezzo DECIMAL(8,2) NULL,
        cena TINYINT(1) NOT NULL DEFAULT 0,
        cena_prezzo DECIMAL(8,2) NULL,
        hotel TINYINT(1) NOT NULL DEFAULT 0,
        targa_auto VARCHAR(32) NULL,
        note TEXT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE bb_presenze_consorziate (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        data_presenza DATE NOT NULL,
        worksite_id INT UNSIGNED NOT NULL,
        azienda_id INT UNSIGNED NOT NULL,
        quantita INT NOT NULL DEFAULT 0,
        costo_unitario DECIMAL(8,2) NULL,
        pasti TINYINT(1) NOT NULL DEFAULT 0,
        auto TINYINT(1) NOT NULL DEFAULT 0,
        hotel TINYINT(1) NOT NULL DEFAULT 0,
        note TEXT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$p   = new Prove();
$pre = new Presenze($pdo);

// ── I dati: due giorni, nostri e consorziate ──────────────────────────────
$pdo->exec("INSERT INTO bb_workers (id, first_name, last_name, company) VALUES
    (1, 'Marco', 'Rossi',   'CS Montaggi'),
    (2, 'Ilir',  'Bequiri', 'CS Montaggi'),
    (3, 'Gigi',  'Verdi',   'Montaggi Sud')");
$pdo->exec("INSERT INTO bb_companies (id, name, consorziata) VALUES
    (1, 'Impresa Alfa', 1), (2, 'Impresa Beta', 1)");

$oggi  = '2026-10-29';
$ieri  = '2026-10-28';

$pdo->exec("INSERT INTO bb_presenze (worker_id, worksite_id, data, turno, pranzo, pranzo_prezzo, hotel, targa_auto, note) VALUES
    (1, " . CANTIERE . ", '$oggi', 'Intero', 1, 12.50, 1, 'AB123CD', 'ha portato il trapano'),
    (2, " . CANTIERE . ", '$oggi', 'Mezzo',  1, 12.50, 0, NULL, NULL),
    (3, " . CANTIERE . ", '$oggi', 'Intero', 0, NULL,  0, NULL, NULL),
    (1, " . CANTIERE . ", '$ieri', 'Intero', 0, NULL,  0, NULL, NULL),
    (1, " . ALTRO    . ", '$oggi', 'Intero', 0, NULL,  0, NULL, NULL)");

// Alfa mandata in due riprese lo stesso giorno: in cantiere sono 5
$pdo->exec("INSERT INTO bb_presenze_consorziate (data_presenza, worksite_id, azienda_id, quantita, costo_unitario) VALUES
    ('$oggi', " . CANTIERE . ", 1, 3, 240.00),
    ('$oggi', " . CANTIERE . ", 1, 2, 240.00),
    ('$oggi', " . CANTIERE . ", 2, 4, 220.00)");

// ── Il giorno ─────────────────────────────────────────────────────────────
$g = $pre->giorno(CANTIERE, $oggi);

$p->uguale('tre dei nostri', 3, count($g['nostri']));
$p->uguale('due imprese',    2, count($g['consorziate']));
$p->uguale('dodici persone in cantiere (3 + 5 + 4)', 12, $g['persone']);
$p->uguale('giornate: 1 + 0,5 + 1 + 9', 11.5, $g['giornate']);

// nome e azienda, in ordine
$p->uguale('il primo e\' di CS Montaggi', 'CS Montaggi', $g['nostri'][0]['azienda']);
$p->uguale('col cognome davanti', 'Bequiri Ilir', $g['nostri'][0]['nome']);

// il turno scritto per esteso
$mezzi = array_values(array_filter($g['nostri'], fn($x) => $x['mezza']));
$p->uguale('uno ha fatto mezza giornata', 1, count($mezzi));
$p->uguale('e lo dice per esteso', 'Mezza giornata', $mezzi[0]['turno']);
$p->uguale('gli altri giornata intera', 'Giornata intera', $g['nostri'][2]['turno']);

// le consorziate: azienda e numero, sommate
$perAzienda = [];
foreach ($g['consorziate'] as $c) { $perAzienda[$c['azienda']] = $c['persone']; }
$p->uguale('Alfa: due righe diventano 5 persone', 5, $perAzienda['Impresa Alfa'] ?? 0);
$p->uguale('Beta: 4 persone',                     4, $perAzienda['Impresa Beta'] ?? 0);

// ── Quello che NON deve uscire ────────────────────────────────────────────
$fuori = json_encode($g);
foreach (['pranzo', 'cena', 'hotel', 'targa', 'auto', 'costo', 'prezzo', 'note',
          '12.5', '240', '220', 'AB123CD', 'trapano'] as $vietato) {
    $p->check("non esce '$vietato'", stripos($fuori, $vietato) === false);
}

// ── Le giornate uomo di tutto il cantiere ────────────────────────────────
$t = $pre->totali(CANTIERE);
// nostri: 1 + 0,5 + 1 (oggi) + 1 (ieri) = 3,5
$p->uguale('giornate nostre', 3.5, $t['nostri']);
// consorziate: 3 + 2 + 4 = 9
$p->uguale('giornate consorziate', 9.0, $t['consorziate']);
$p->uguale('totale giornate uomo', 12.5, $t['giornate']);
$p->uguale('dal primo giorno', $ieri, $t['dal']);
$p->uguale('fino all\'ultimo', $oggi, $t['al']);

// lo stesso conto della scheda cantiere, non un altro
$ufficio = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END), 0)
    FROM bb_presenze WHERE worksite_id = " . CANTIERE)->fetchColumn()
    + (float)$pdo->query('SELECT COALESCE(SUM(quantita), 0)
                          FROM bb_presenze_consorziate WHERE worksite_id = ' . CANTIERE)->fetchColumn();
$p->uguale('lo stesso numero della scheda cantiere', $ufficio, $t['giornate']);

// un cantiere senza presenze non e' un errore
$vuotoTot = $pre->totali(999);
$p->uguale('cantiere senza presenze: zero', 0.0, $vuotoTot['giornate']);
$p->uguale('e nessun periodo', null, $vuotoTot['dal']);

// ── Un altro cantiere e' un altro registro ───────────────────────────────
$altro = $pre->giorno(ALTRO, $oggi);
$p->uguale('nell\'altro cantiere c\'e\' una persona sola', 1, $altro['persone']);
$p->uguale('e nessuna impresa',                            0, count($altro['consorziate']));

// ── Un giorno vuoto ───────────────────────────────────────────────────────
$vuoto = $pre->giorno(CANTIERE, '2026-01-01');
$p->uguale('un giorno senza nessuno non e\' un errore', 0, $vuoto['persone']);
$p->uguale('e non ha righe', [], $vuoto['nostri']);

// ── L'elenco dei giorni ───────────────────────────────────────────────────
$giorni = $pre->giorni(CANTIERE);
$p->uguale('due giorni con presenze', 2, count($giorni));
$p->uguale('il piu\' recente per primo', $oggi, $giorni[0]['data']);
$p->uguale('col totale di quel giorno', 12, $giorni[0]['persone']);
$p->uguale('e il giorno prima', $ieri, $giorni[1]['data']);
$p->uguale('con una persona sola', 1, $giorni[1]['persone']);

// un giorno con solo consorziate deve comparire lo stesso
$pdo->exec("INSERT INTO bb_presenze_consorziate (data_presenza, worksite_id, azienda_id, quantita)
            VALUES ('2026-10-27', " . CANTIERE . ", 1, 2)");
$giorni = $pre->giorni(CANTIERE);
$p->uguale('un giorno di sole imprese c\'e\'', 3, count($giorni));
$p->uguale('con le sue persone', 2, $giorni[2]['persone']);

// ── Chi lo puo' leggere ───────────────────────────────────────────────────
$acc = new Accesso($pdo);
$u   = utenti($pdo);
foreach (['ufficio', 'capo', 'operaio', 'cliente'] as $chi) {
    $p->check("$chi legge il registro", $acc->qualcosa($u[$chi], CANTIERE));
}
foreach (['fuori', 'estraneo'] as $chi) {
    $p->check("$chi NON legge il registro", !$acc->qualcosa($u[$chi], CANTIERE));
}

// la regola nel controller e' quella, non un'altra
$src = file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/FieldwireController.php');
$p->check('il registro usa la regola "dentro"',
          str_contains($src, "'presenze'              => 'dentro'"));

exit($p->esito('Prova 5 — presenze'));
