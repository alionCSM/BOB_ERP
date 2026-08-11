<?php
/**
 * BOB — Import delle autocarrate e dei noleggi dal vecchio database
 *
 * Di default NON scrive: mostra cosa farebbe. Per scrivere davvero serve
 * --esegui. E' voluto: su un import si sbaglia una volta sola.
 *
 * Uso:
 *   php db/import/importa_noleggi_officina.php --societa=2
 *   php db/import/importa_noleggi_officina.php --societa=2 --esegui
 *
 * Opzioni:
 *   --societa=N        id della societa' del gruppo di destinazione (obbligatorio)
 *   --descr=parola     quali mezzi sono autocarrate, per parola in descr.
 *                      Default "autocarr": nel vecchio database le
 *                      autocarrate hanno descr = AUTOCARRATA, e la parola
 *                      parziale prende anche eventuali AUTOCARRATE o
 *                      varianti scritte diversamente.
 *   --tipo=N           in alternativa, filtra i movimenti per mov_mezzi.tipo
 *   --stato-annullato=N  valore di mov_mezzi.stato da importare come annullata
 *   --esegui           scrive davvero
 *
 * Rilanciarlo e' sicuro: le righe gia' importate si riconoscono da
 * origine_id e vengono saltate, non duplicate.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;

// ── Opzioni ──────────────────────────────────────────────────────────────────

$opt = getopt('', ['societa:', 'descr::', 'tipo::', 'stato-annullato::', 'esegui']);

$societaId = (int)($opt['societa'] ?? 0);
if ($societaId <= 0) {
    fwrite(STDERR, "Manca --societa=N (id della societa' del gruppo di destinazione).\n");
    fwrite(STDERR, "Lo trovi nella pagina Societa' del gruppo.\n");
    exit(1);
}

$parolaDescr    = trim((string)($opt['descr'] ?? 'autocarr'));
$tipoMovimento  = isset($opt['tipo']) && $opt['tipo'] !== '' ? (int)$opt['tipo'] : null;
$statoAnnullato = isset($opt['stato-annullato']) && $opt['stato-annullato'] !== ''
    ? (int)$opt['stato-annullato'] : null;
$esegui         = array_key_exists('esegui', $opt);

// ── Connessioni ──────────────────────────────────────────────────────────────

$cfg     = new Config();
$oldName = $_ENV['OLD_DB_NAME'] ?? 'noleggi_officina';

try {
    $old = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg->dbHost(), $cfg->dbPort(), $oldName),
        $cfg->dbUser(), $cfg->dbPass(),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $bob = (new Database())->connect();
} catch (Throwable $e) {
    fwrite(STDERR, 'Connessione fallita: ' . $e->getMessage() . "\n");
    exit(1);
}

$soc = $bob->prepare('SELECT nome FROM bb_group_companies WHERE id = ?');
$soc->execute([$societaId]);
$nomeSocieta = $soc->fetchColumn();
if (!$nomeSocieta) {
    fwrite(STDERR, "La societa' {$societaId} non esiste.\n");
    exit(1);
}

echo "Origine:      {$oldName}\n";
echo "Destinazione: {$nomeSocieta} (id {$societaId})\n";
echo 'Modalita:     ', $esegui ? "SCRITTURA\n" : "prova a vuoto (aggiungi --esegui per scrivere)\n";
echo "Filtro mezzi: descr contiene \"{$parolaDescr}\"";
echo $tipoMovimento !== null ? " oppure tipo = {$tipoMovimento}\n" : "\n";
echo str_repeat('-', 70), "\n";

// ── Funzioni di appoggio ─────────────────────────────────────────────────────

/**
 * Importo scritto a mano -> numero.
 * Nel vecchio database e' un VARCHAR, quindi puo' contenere simboli di
 * valuta, punti di migliaia e virgole decimali. Se non se ne cava un numero
 * si restituisce null invece di inventare uno zero, che sarebbe un dato
 * falso indistinguibile da un importo davvero a zero.
 */
function importoNumerico(?string $grezzo): ?string
{
    if ($grezzo === null) return null;

    $v = trim($grezzo);
    if ($v === '') return null;

    $v = preg_replace('/[^0-9,.\-]/', '', $v) ?? '';
    if ($v === '') return null;

    // 1.234,56 (italiano) -> 1234.56 ; 1234.56 resta com'e'
    if (str_contains($v, ',')) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }

    return is_numeric($v) ? $v : null;
}

$avvisi = [];
function avvisa(string $riga): void
{
    global $avvisi;
    $avvisi[] = $riga;
}

// ── 1. Mezzi ─────────────────────────────────────────────────────────────────

echo "\n[1/2] Autocarrate\n";

$sql = 'SELECT id, descr, modello, matricola, stato FROM mezzi_soll WHERE descr LIKE :d ORDER BY id';
$stmt = $old->prepare($sql);
$stmt->execute([':d' => '%' . $parolaDescr . '%']);
$mezziVecchi = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '  trovati nel vecchio database: ', count($mezziVecchi), "\n";

$giaPresenti = $bob->prepare(
    'SELECT id FROM pn_autocarrate WHERE group_company_id = :cid AND origine_id = :oid'
);
$perTarga = $bob->prepare(
    'SELECT id FROM pn_autocarrate WHERE group_company_id = :cid AND targa = :t'
);
$insMezzo = $bob->prepare("
    INSERT INTO pn_autocarrate (group_company_id, targa, modello, note, stato, origine_id)
    VALUES (:cid, :targa, :modello, :note, :stato, :oid)
");

$mappaMezzi = [];   // id vecchio => id nuovo
$nuovi = $saltati = $senzaTarga = 0;

foreach ($mezziVecchi as $m) {
    $vecchioId = (int)$m['id'];
    $targa     = strtoupper(trim((string)($m['matricola'] ?? '')));

    if ($targa === '') {
        $senzaTarga++;
        avvisa("mezzo {$vecchioId} \"{$m['descr']}\": matricola vuota, saltato");
        continue;
    }

    $giaPresenti->execute([':cid' => $societaId, ':oid' => $vecchioId]);
    if ($esistente = $giaPresenti->fetchColumn()) {
        $mappaMezzi[$vecchioId] = (int)$esistente;
        $saltati++;
        continue;
    }

    // stessa targa gia' in BOB ma arrivata per altra via: si collega senza
    // duplicare, perche' la targa e' unica per societa'
    $perTarga->execute([':cid' => $societaId, ':t' => $targa]);
    if ($esistente = $perTarga->fetchColumn()) {
        $mappaMezzi[$vecchioId] = (int)$esistente;
        $saltati++;
        avvisa("mezzo {$vecchioId}: targa {$targa} gia' presente in BOB, collegata");
        continue;
    }

    // stato 1 = in uso nel vecchio sistema; qualsiasi altro valore si
    // importa come dismessa, cosi' non finisce fra le prenotabili
    $stato = ((int)$m['stato'] === 1) ? 'attiva' : 'dismessa';

    if ($esegui) {
        $insMezzo->execute([
            ':cid'     => $societaId,
            ':targa'   => $targa,
            ':modello' => trim((string)$m['modello']) ?: null,
            ':note'    => trim((string)$m['descr']) ?: null,
            ':stato'   => $stato,
            ':oid'     => $vecchioId,
        ]);
        $mappaMezzi[$vecchioId] = (int)$bob->lastInsertId();
    } else {
        $mappaMezzi[$vecchioId] = -$vecchioId;   // segnaposto per la prova
    }
    $nuovi++;
}

echo "  da inserire: {$nuovi}\n";
echo "  gia' presenti: {$saltati}\n";
echo "  senza matricola (saltati): {$senzaTarga}\n";

// ── 2. Prenotazioni ──────────────────────────────────────────────────────────

echo "\n[2/2] Prenotazioni\n";

$sqlMov = 'SELECT * FROM mov_mezzi WHERE mezzo_id IN (SELECT id FROM mezzi_soll WHERE descr LIKE :d)';
$args   = [':d' => '%' . $parolaDescr . '%'];

if ($tipoMovimento !== null) {
    $sqlMov = 'SELECT * FROM mov_mezzi WHERE (mezzo_id IN (SELECT id FROM mezzi_soll WHERE descr LIKE :d)
                                              OR tipo = :tipo)';
    $args[':tipo'] = $tipoMovimento;
}
$sqlMov .= ' ORDER BY id';

$stmt = $old->prepare($sqlMov);
$stmt->execute($args);
$movimenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '  trovate nel vecchio database: ', count($movimenti), "\n";

$movGia = $bob->prepare(
    'SELECT id FROM pn_prenotazioni WHERE group_company_id = :cid AND origine_id = :oid'
);
$insMov = $bob->prepare("
    INSERT INTO pn_prenotazioni
        (group_company_id, autocarrata_id, cliente, luogo, data_inizio, data_fine,
         stato, importo, note, contratto, commerciale_testo, pagamento, origine_id)
    VALUES
        (:cid, :mid, :cliente, :luogo, :dal, :al,
         :stato, :importo, :note, :contratto, :comm, 'da_pagare', :oid)
");

$movNuovi = $movSaltati = $senzaData = $senzaMezzo = $importiPersi = 0;

foreach ($movimenti as $r) {
    $vecchioId = (int)$r['id'];

    $movGia->execute([':cid' => $societaId, ':oid' => $vecchioId]);
    if ($movGia->fetchColumn()) { $movSaltati++; continue; }

    $mezzoVecchio = (int)$r['mezzo_id'];
    if (!isset($mappaMezzi[$mezzoVecchio])) {
        $senzaMezzo++;
        avvisa("noleggio {$vecchioId} ({$r['cliente']}): mezzo {$mezzoVecchio} non importato, saltato");
        continue;
    }

    $dal = $r['inizio'];
    $al  = $r['fine'];

    // senza date la prenotazione non sta ne' in calendario ne' in timeline:
    // importarla vorrebbe dire nasconderla per sempre
    if (!$dal || !$al) {
        $senzaData++;
        avvisa("noleggio {$vecchioId} ({$r['cliente']}): date mancanti, saltato");
        continue;
    }
    if ($al < $dal) {
        avvisa("noleggio {$vecchioId} ({$r['cliente']}): fine {$al} prima di inizio {$dal}, invertite");
        [$dal, $al] = [$al, $dal];
    }

    $importo = importoNumerico($r['importo'] ?? null);
    if ($importo === null && trim((string)($r['importo'] ?? '')) !== '') {
        $importiPersi++;
        avvisa("noleggio {$vecchioId}: importo \"{$r['importo']}\" non leggibile come numero");
    }

    $stato = ($statoAnnullato !== null && (int)$r['stato'] === $statoAnnullato)
        ? 'annullata'
        : 'confermata';

    if ($esegui) {
        $insMov->execute([
            ':cid'       => $societaId,
            ':mid'       => $mappaMezzi[$mezzoVecchio],
            ':cliente'   => trim((string)$r['cliente']) ?: 'senza nome',
            ':luogo'     => trim((string)$r['cantiere']) ?: null,
            ':dal'       => $dal,
            ':al'        => $al,
            ':stato'     => $stato,
            ':importo'   => $importo,
            ':note'      => trim((string)$r['note']) ?: null,
            ':contratto' => trim((string)$r['contratto']) ?: null,
            ':comm'      => trim((string)$r['commerc']) ?: null,
            ':oid'       => $vecchioId,
        ]);
    }
    $movNuovi++;
}

echo "  da inserire: {$movNuovi}\n";
echo "  gia' presenti: {$movSaltati}\n";
echo "  senza date (saltate): {$senzaData}\n";
echo "  con mezzo non importato (saltate): {$senzaMezzo}\n";
echo "  importi non leggibili: {$importiPersi}\n";

// ── Riepilogo ────────────────────────────────────────────────────────────────

if ($avvisi) {
    echo "\n", str_repeat('-', 70), "\n";
    echo 'Da guardare (', count($avvisi), "):\n";
    foreach (array_slice($avvisi, 0, 60) as $a) {
        echo "  - {$a}\n";
    }
    if (count($avvisi) > 60) {
        echo '  ... e altri ', count($avvisi) - 60, "\n";
    }
}

echo "\n";
if ($esegui) {
    echo "Import eseguito.\n";
    echo "Le prenotazioni importate risultano 'da pagare': il vecchio sistema\n";
    echo "non teneva questa informazione, quindi va sistemata a mano.\n";
} else {
    echo "Nessuna scrittura: era una prova. Rilancia con --esegui quando i numeri tornano.\n";
}
