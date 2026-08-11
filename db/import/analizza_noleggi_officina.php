<?php
/**
 * BOB — Analisi del vecchio database noleggi (sola lettura)
 *
 * Non scrive niente: serve a capire cosa contengono davvero i campi prima
 * di decidere come importarli. Dallo schema non si ricava il significato di
 * mezzi_soll.stato, mov_mezzi.tipo e mov_mezzi.stato, ne' quali mezzi siano
 * autocarrate e quale campo faccia da targa.
 *
 * Uso:
 *   php db/import/analizza_noleggi_officina.php
 *
 * Legge la connessione da .env (le stesse DB_HOST/DB_USER/DB_PASS di BOB) e
 * si collega al database indicato da OLD_DB_NAME (default noleggi_officina).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

use App\Infrastructure\Config;

$cfg     = new Config();
$oldName = $_ENV['OLD_DB_NAME'] ?? 'noleggi_officina';

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg->dbHost(), $cfg->dbPort(), $oldName);
    $old = new PDO($dsn, $cfg->dbUser(), $cfg->dbPass(), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Connessione a {$oldName} fallita: " . $e->getMessage() . "\n");
    exit(1);
}

function titolo(string $t): void
{
    echo "\n", str_repeat('=', 70), "\n", $t, "\n", str_repeat('=', 70), "\n";
}

function tabella(PDO $db, string $sql, array $args = []): void
{
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$righe) {
        echo "  (nessuna riga)\n";
        return;
    }
    $colonne = array_keys($righe[0]);
    echo '  ' . implode(' | ', $colonne) . "\n";
    echo '  ' . str_repeat('-', 66) . "\n";
    foreach ($righe as $r) {
        echo '  ' . implode(' | ', array_map(
            static fn($v) => $v === null ? 'NULL' : (string)$v,
            $r
        )) . "\n";
    }
}

titolo('QUANTITA');
tabella($old, 'SELECT (SELECT COUNT(*) FROM mezzi_soll) AS mezzi,
                      (SELECT COUNT(*) FROM mov_mezzi)  AS movimenti');

titolo('mezzi_soll.stato — quali valori esistono e quanti sono');
tabella($old, 'SELECT stato, COUNT(*) AS quanti FROM mezzi_soll GROUP BY stato ORDER BY quanti DESC');

titolo('mezzi_soll.descr — serve a capire quali sono autocarrate');
tabella($old, 'SELECT descr, COUNT(*) AS quanti FROM mezzi_soll GROUP BY descr ORDER BY quanti DESC LIMIT 40');

titolo('mezzi_soll — prime 15 righe');
tabella($old, 'SELECT id, descr, modello, matricola, stato FROM mezzi_soll ORDER BY id LIMIT 15');

titolo('matricola — quante mancano e quante sono doppie');
tabella($old, "SELECT
                 SUM(matricola IS NULL OR matricola = '') AS vuote,
                 COUNT(*) - COUNT(DISTINCT matricola)      AS doppie_o_nulle
               FROM mezzi_soll");

titolo('mov_mezzi.tipo — quali valori esistono');
tabella($old, 'SELECT tipo, COUNT(*) AS quanti FROM mov_mezzi GROUP BY tipo ORDER BY quanti DESC');

titolo('mov_mezzi.stato — quali valori esistono');
tabella($old, 'SELECT stato, COUNT(*) AS quanti FROM mov_mezzi GROUP BY stato ORDER BY quanti DESC');

titolo('mov_mezzi — prime 15 righe');
tabella($old, 'SELECT id, contratto, cliente, cantiere, inizio, fine, mezzo_id, importo, commerc, tipo, stato
                FROM mov_mezzi ORDER BY id DESC LIMIT 15');

titolo("importo — com'e' scritto davvero (in vecchio DB e' un VARCHAR)");
tabella($old, "SELECT importo, COUNT(*) AS quanti
                FROM mov_mezzi
                WHERE importo IS NOT NULL AND importo <> ''
                GROUP BY importo ORDER BY quanti DESC LIMIT 20");

titolo('importo — quanti NON sono numeri puliti');
tabella($old, "SELECT COUNT(*) AS non_numerici
                FROM mov_mezzi
                WHERE importo IS NOT NULL AND importo <> ''
                  AND REPLACE(REPLACE(importo, '.', ''), ',', '.') NOT REGEXP '^[0-9]+(\\\\.[0-9]+)?$'");

titolo('commerc — nomi da collegare agli utenti di BOB');
tabella($old, "SELECT commerc, COUNT(*) AS quanti
                FROM mov_mezzi
                WHERE commerc IS NOT NULL AND commerc <> ''
                GROUP BY commerc ORDER BY quanti DESC");

titolo('date — quante mancano o sono incoerenti');
tabella($old, 'SELECT
                 SUM(inizio IS NULL)          AS senza_inizio,
                 SUM(fine IS NULL)            AS senza_fine,
                 SUM(fine < inizio)           AS fine_prima_di_inizio,
                 MIN(inizio)                  AS piu_vecchia,
                 MAX(fine)                    AS piu_recente
               FROM mov_mezzi');

titolo('movimenti orfani — puntano a un mezzo che non esiste piu');
tabella($old, 'SELECT COUNT(*) AS orfani
                FROM mov_mezzi m
                LEFT JOIN mezzi_soll s ON s.id = m.mezzo_id
                WHERE s.id IS NULL');

titolo('sovrapposizioni — stesso mezzo impegnato due volte negli stessi giorni');
tabella($old, 'SELECT COUNT(*) AS coppie_sovrapposte
                FROM mov_mezzi a
                JOIN mov_mezzi b
                  ON b.mezzo_id = a.mezzo_id
                 AND b.id > a.id
                 AND a.inizio <= b.fine
                 AND a.fine   >= b.inizio
                WHERE a.inizio IS NOT NULL AND a.fine IS NOT NULL
                  AND b.inizio IS NOT NULL AND b.fine IS NOT NULL');

echo "\n";
echo "Fatto. Manda l'output cosi' com'e': da li' si decide come importare.\n";
