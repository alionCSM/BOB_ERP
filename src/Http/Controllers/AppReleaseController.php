<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

/**
 * Distribuzione dell'app Android.
 *
 * Tre cose, con tre livelli di accesso diversi e voluti:
 *
 *   /app                     riservato: si carica una versione nuova
 *   /app/scarica/{token}     aperto:    il telefono scarica l'APK
 *   /api/v1/app/versione     aperto:    l'app chiede "c'e' qualcosa di nuovo?"
 *
 * I due indirizzi aperti lo sono per forza. Il telefono scarica fuori dal
 * browser (gestore download di Android, senza i cookie della sessione) e la
 * verifica dell'aggiornamento deve funzionare anche quando il token
 * dell'utente e' scaduto — altrimenti l'unico modo per aggiornare sarebbe
 * riuscire prima ad accedere, cioe' proprio quello che magari non funziona
 * piu'. Al posto del login c'e' un token lungo e non indovinabile, e una
 * versione si puo' spegnere in qualsiasi momento disattivandola.
 */
final class AppReleaseController
{
    /** Dove finiscono gli APK: fuori da public/, non raggiungibili da soli. */
    private const CARTELLA = APP_ROOT . '/storage/app_releases';

    /** 150 MB: un APK che supera questa soglia e' quasi sempre un errore. */
    private const MAX_BYTE = 157286400;

    public function __construct(private \PDO $conn) {}

    // ── Pagina riservata ─────────────────────────────────────────────────────

    /** GET /app */
    public function index(Request $request): void
    {
        $this->assertGestione($request);

        Response::view('app/index.html.twig', $request, [
            'versioni' => $this->tutte(),
            'ultima'   => $this->ultimaAttiva(),
            'salvato'  => isset($_GET['salvato']),
            'errore'   => $_GET['errore'] ?? null,
            'baseUrl'  => $this->baseUrl(),
        ]);
    }

    /** POST /app/carica */
    public function carica(Request $request): void
    {
        $this->assertGestione($request);

        $file = $_FILES['apk'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->torna($this->erroreUpload((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        if ((int)$file['size'] > self::MAX_BYTE) {
            $this->torna('Il file supera i 150 MB.');
        }
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'apk') {
            $this->torna('Si caricano solo file .apk.');
        }

        // Un APK e' uno zip: i primi due byte lo dicono. Non e' una verifica
        // forte, ma intercetta il caso vero — il file sbagliato trascinato
        // nella casella — invece di accorgersene sul telefono.
        $inizio = (string)@file_get_contents((string)$file['tmp_name'], false, null, 0, 2);
        if ($inizio !== 'PK') {
            $this->torna("Il file non sembra un APK (dovrebbe essere un pacchetto zip).");
        }

        // Versione letta DALL'APK, non digitata.
        //
        // Finche' quel numero si scriveva a mano, sbagliarlo metteva in
        // ginocchio tutti i telefoni: l'app confronta il proprio versionCode
        // con quello dichiarato qui, quindi dichiarandone uno piu' alto di
        // quello vero ogni telefono si aggiorna, si riavvia, si ritrova
        // ancora "vecchio" e richiede di aggiornare, all'infinito. Con
        // l'aggiornamento obbligatorio non c'era nemmeno modo di chiudere.
        $letto = \App\Service\ApkInfo::leggi((string)$file['tmp_name']);
        if ($letto === null) {
            $this->torna(
                "Non riesco a leggere la versione dall'APK: controlla che sia "
                . "un pacchetto Android valido e non un file rinominato."
            );
        }

        $versionCode = $letto['version_code'];
        $versionName = $letto['version_name'] !== ''
            ? $letto['version_name']
            : trim((string)($_POST['version_name'] ?? ''));

        if ($versionName === '') {
            $versionName = (string)$versionCode;
        }
        if ($this->versionCodeEsiste($versionCode)) {
            $this->torna(
                "La versione {$versionCode} ({$versionName}) e' gia' caricata: "
                . "alza il versionCode in build.gradle.kts e ricompila."
            );
        }

        if (!is_dir(self::CARTELLA) && !@mkdir(self::CARTELLA, 0775, true) && !is_dir(self::CARTELLA)) {
            $this->torna('Non riesco a creare la cartella storage/app_releases.');
        }

        // nome su disco casuale: quello originale potrebbe contenere
        // qualsiasi cosa, e due caricamenti con lo stesso nome si
        // sovrascriverebbero a vicenda
        $token    = bin2hex(random_bytes(16));
        $salvato  = 'bob-' . $versionCode . '-' . $token . '.apk';
        $completo = self::CARTELLA . '/' . $salvato;

        if (!@move_uploaded_file((string)$file['tmp_name'], $completo)) {
            $this->torna('Non riesco a salvare il file caricato.');
        }

        $utente = $request->user();
        $stmt = $this->conn->prepare('
            INSERT INTO bb_app_releases
                (version_code, version_name, file_nome, file_salvato, dimensione,
                 sha256, note, obbligatorio, attiva, token, caricato_da, caricato_nome)
            VALUES (:vc, :vn, :fn, :fs, :dim, :sha, :note, :obb, 1, :tok, :uid, :unome)
        ');
        $stmt->execute([
            ':vc'    => $versionCode,
            ':vn'    => $versionName,
            ':fn'    => mb_substr((string)$file['name'], 0, 200),
            ':fs'    => $salvato,
            ':dim'   => (int)$file['size'],
            ':sha'   => hash_file('sha256', $completo) ?: null,
            ':note'  => trim((string)($_POST['note'] ?? '')) ?: null,
            ':obb'   => !empty($_POST['obbligatorio']) ? 1 : 0,
            ':tok'   => $token,
            ':uid'   => (int)$utente->id,
            ':unome' => mb_substr((string)($utente->username ?? ''), 0, 120),
        ]);

        Response::redirect('/app?salvato=1');
    }

    /** POST /app/stato — accende o spegne una versione */
    public function stato(Request $request): void
    {
        $this->assertGestione($request);

        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $this->conn->prepare('UPDATE bb_app_releases SET attiva = 1 - attiva WHERE id = :id')
                       ->execute([':id' => $id]);
        }
        Response::redirect('/app?salvato=1');
    }

    /**
     * POST /app/elimina
     *
     * Toglie anche il file dal disco: un APK e' decine di MB, e tenerne
     * venti che nessuno scarichera' piu' riempie il server per niente.
     */
    public function elimina(Request $request): void
    {
        $this->assertGestione($request);

        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $this->conn->prepare('SELECT file_salvato FROM bb_app_releases WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $nome = (string)$stmt->fetchColumn();

            if ($nome !== '' && is_file(self::CARTELLA . '/' . $nome)) {
                @unlink(self::CARTELLA . '/' . $nome);
            }
            $this->conn->prepare('DELETE FROM bb_app_releases WHERE id = :id')->execute([':id' => $id]);
        }
        Response::redirect('/app?salvato=1');
    }

    // ── Indirizzi aperti ─────────────────────────────────────────────────────

    /**
     * GET /app/scarica/{token} — l'APK vero e proprio.
     *
     * Serve al gestore download di Android, che non porta con se' i cookie
     * della sessione. Vedi la nota in cima alla classe.
     */
    public function scarica(Request $request): void
    {
        $token = (string)$request->param('token', '');
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            Response::error('Non trovato', 404);
        }

        $stmt = $this->conn->prepare('
            SELECT file_nome, file_salvato, dimensione
            FROM   bb_app_releases
            WHERE  token = :t AND attiva = 1
            LIMIT  1
        ');
        $stmt->execute([':t' => $token]);
        $riga = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$riga) {
            Response::error('Versione non disponibile', 404);
        }

        $percorso = self::CARTELLA . '/' . $riga['file_salvato'];
        if (!is_file($percorso)) {
            Response::error('File mancante sul server', 404);
        }

        // il buffer va svuotato: su un file da 40 MB tenerlo in memoria
        // farebbe superare il memory_limit
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Length: ' . (string)filesize($percorso));
        header('Content-Disposition: attachment; filename="' . basename((string)$riga['file_nome']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($percorso);
        exit;
    }

    /**
     * GET /api/v1/app/versione[?version_code=N]
     *
     * Quello che l'app chiede all'avvio. Con version_code risponde anche
     * "aggiornamento: si/no", cosi' il telefono non deve confrontare niente.
     */
    public function versione(Request $request): void
    {
        $ultima = $this->ultimaAttiva();

        if (!$ultima) {
            Response::json([
                'success'       => true,
                'aggiornamento' => false,
                'versione'      => null,
            ]);
        }

        $suo = (int)($_GET['version_code'] ?? 0);

        Response::json([
            'success'       => true,
            'aggiornamento' => $suo > 0 && (int)$ultima['version_code'] > $suo,
            'versione'      => [
                'version_code' => (int)$ultima['version_code'],
                'version_name' => (string)$ultima['version_name'],
                'note'         => (string)($ultima['note'] ?? ''),
                'obbligatorio' => (bool)$ultima['obbligatorio'],
                'dimensione'   => (int)$ultima['dimensione'],
                'sha256'       => (string)($ultima['sha256'] ?? ''),
                'url'          => $this->baseUrl() . '/app/scarica/' . $ultima['token'],
                'pubblicata'   => (string)$ultima['created_at'],
            ],
        ]);
    }

    // ── Aiutanti ─────────────────────────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    private function tutte(): array
    {
        return $this->conn->query('
            SELECT * FROM bb_app_releases ORDER BY version_code DESC
        ')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function ultimaAttiva(): ?array
    {
        $riga = $this->conn->query('
            SELECT * FROM bb_app_releases
            WHERE  attiva = 1
            ORDER BY version_code DESC
            LIMIT 1
        ')->fetch(\PDO::FETCH_ASSOC);

        return $riga ?: null;
    }

    private function versionCodeEsiste(int $versionCode): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM bb_app_releases WHERE version_code = :vc LIMIT 1');
        $stmt->execute([':vc' => $versionCode]);
        return (bool)$stmt->fetchColumn();
    }

    /** Indirizzo pubblico di BOB, per costruire il link di scaricamento. */
    private function baseUrl(): string
    {
        $configurato = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        if ($configurato !== '') {
            return $configurato;
        }
        // ripiego: si ricostruisce dalla richiesta
        $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $schema . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function erroreUpload(int $codice): string
    {
        return match ($codice) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                "Il file e' piu' grande di quanto PHP accetti "
                . '(upload_max_filesize / post_max_size).',
            UPLOAD_ERR_NO_FILE    => 'Non hai scelto nessun file.',
            UPLOAD_ERR_PARTIAL    => 'Il caricamento si e\' interrotto a meta\'.',
            UPLOAD_ERR_NO_TMP_DIR => 'Manca la cartella temporanea sul server.',
            UPLOAD_ERR_CANT_WRITE => 'Il server non riesce a scrivere il file.',
            default               => 'Caricamento non riuscito.',
        };
    }

    private function torna(string $errore): never
    {
        Response::redirect('/app?errore=' . urlencode($errore));
    }

    /**
     * Chi puo' pubblicare una versione.
     *
     * Tenuto stretto: da qui esce il pacchetto che finisce sui telefoni di
     * tutti, quindi non e' una pagina da permesso di modulo qualunque.
     */
    private function assertGestione(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            Response::error('Accesso negato', 403);
        }
        if ((int)$user->id === 1 || $user->canAccess('users')) {
            return;
        }
        Response::error("Permesso 'users' richiesto per pubblicare l'app", 403);
    }
}
