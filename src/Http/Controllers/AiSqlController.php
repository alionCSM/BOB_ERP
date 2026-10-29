<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Service\Ai\Conversazioni;
use App\Service\AiSqlService;
use App\Service\OllamaClient;
use App\Service\RateLimiter;

/**
 * BOB AI: la pagina, le chat salvate e la risposta in streaming.
 *
 * La risposta non torna piu' come un unico JSON alla fine. Era quello il
 * motivo dei timeout: per trenta o quaranta secondi fra il browser e il
 * server non passava un byte, e a quel punto chiunque sta in mezzo —
 * il proxy, nginx, il browser stesso sulla rete del cantiere — ha tutto il
 * diritto di considerare la connessione morta e chiuderla. L'utente vedeva
 * "Errore di connessione" mentre il modello stava rispondendo benissimo.
 *
 * Adesso la risposta e' un flusso: appena si comincia a lavorare partono gli
 * avvisi di stato ("sto scrivendo la query", "eseguo", "230 righe"), e la
 * risposta vera arriva come ultimo evento. Finche' arrivano byte nessuno
 * chiude niente, e chi aspetta vede a che punto siamo invece di tre
 * puntini uguali per mezzo minuto.
 */
class AiSqlController
{
    /** Quanto si concede in tutto a una domanda, prima di arrendersi. */
    private const TIMEOUT_RISPOSTA = 240;

    public function __construct(
        private \PDO $conn,
        private OllamaClient $client,
    ) {}

    /**
     * Accesso al modulo AI: permesso 'ai_chat' in bb_user_permissions
     * (gestibile da Utenti → Permessi). Il modulo esegue SQL generato
     * dall'LLM: concedere il permesso con criterio. Superadmin (id 1)
     * passa sempre; il menu usa lo stesso check (user.canAccess).
     */
    private function assertAiAccess(Request $request): void
    {
        $user = $request->user();
        if ($user && $user->canAccess('ai_chat')) {
            return;
        }
        Response::error('Accesso negato.', 403);
    }

    private function conversazioni(): Conversazioni
    {
        return new Conversazioni($this->conn);
    }

    // ── La pagina ─────────────────────────────────────────────────────────────

    public function chatPage(Request $request): never
    {
        $this->assertAiAccess($request);

        $userId = (int)$request->user()->id;
        $chat   = $this->conversazioni();

        // ?c=<id> riapre una chat. Un id che non e' tuo si comporta come un
        // id che non esiste: si apre una chat nuova, senza dire che quella
        // esiste ma e' di un altro.
        $aperta   = (int)($request->get('c') ?? 0);
        $messaggi = [];

        if ($aperta > 0 && $chat->appartiene($aperta, $userId)) {
            $messaggi = $chat->messaggi($aperta);
        } else {
            $aperta = 0;
        }

        Response::view('ai/chat.html.twig', $request, [
            'pageTitle'     => 'BOB AI',
            'conversazioni' => $chat->elenco($userId),
            'apertaId'      => $aperta,
            'messaggi'      => $messaggi,
        ]);
    }

    // ── Le chat salvate ───────────────────────────────────────────────────────

    public function elencoConversazioni(Request $request): never
    {
        $this->assertAiAccess($request);

        Response::json([
            'ok'            => true,
            'conversazioni' => $this->conversazioni()->elenco((int)$request->user()->id),
        ]);
    }

    public function apriConversazione(Request $request): never
    {
        $this->assertAiAccess($request);

        $userId = (int)$request->user()->id;
        $id     = (int)($request->params['id'] ?? 0);
        $chat   = $this->conversazioni();

        if ($id <= 0 || !$chat->appartiene($id, $userId)) {
            Response::json(['ok' => false, 'error' => 'Conversazione non trovata.'], 404);
        }

        Response::json(['ok' => true, 'id' => $id, 'messaggi' => $chat->messaggi($id)]);
    }

    public function rinominaConversazione(Request $request): never
    {
        $this->assertAiAccess($request);

        $userId = (int)$request->user()->id;
        $id     = (int)($request->params['id'] ?? 0);
        $titolo = trim((string)($_POST['titolo'] ?? ''));

        if ($id <= 0 || $titolo === '') {
            Response::json(['ok' => false, 'error' => 'Titolo mancante.'], 400);
        }

        if (!$this->conversazioni()->rinomina($id, $userId, $titolo)) {
            Response::json(['ok' => false, 'error' => 'Conversazione non trovata.'], 404);
        }

        Response::json(['ok' => true]);
    }

    public function eliminaConversazione(Request $request): never
    {
        $this->assertAiAccess($request);

        $userId = (int)$request->user()->id;
        $id     = (int)($request->params['id'] ?? 0);

        if ($id <= 0 || !$this->conversazioni()->elimina($id, $userId)) {
            Response::json(['ok' => false, 'error' => 'Conversazione non trovata.'], 404);
        }

        Response::json(['ok' => true]);
    }

    // ── La domanda ────────────────────────────────────────────────────────────

    public function chat(Request $request): never
    {
        $this->assertAiAccess($request);

        $user    = $request->user();
        $userId  = (int)$user->id;
        $domanda = trim((string)($_POST['domanda'] ?? $this->domandaVecchioFormato()));
        $convId  = (int)($_POST['conversazione'] ?? 0);
        $flusso  = ($_POST['stream'] ?? '1') !== '0';

        if (mb_strlen($domanda) < 2) {
            $this->fallisci($flusso, 'Scrivi una domanda.', 400);
        }
        if (mb_strlen($domanda) > 1000) {
            $this->fallisci($flusso, 'Domanda troppo lunga (massimo 1000 caratteri).', 400);
        }

        if (!(new RateLimiter($this->conn, 30, 5))->allow($userId)['ok']) {
            $this->fallisci($flusso, 'Troppe domande di seguito. Riprova tra qualche minuto.', 429);
        }

        $chat = $this->conversazioni();

        // Chat nuova, oppure quella che si stava usando. Un id non tuo non
        // da' errore: si apre una chat nuova, e nessuno scopre niente.
        if ($convId <= 0 || !$chat->appartiene($convId, $userId)) {
            $convId = $chat->crea($userId, \App\Security\AccessControl::societaAttiva(), $domanda);
        }

        $contesto = $chat->contesto($convId);
        $tabelle  = $chat->tabelleRecenti($convId);

        $chat->aggiungiDomanda($convId, $domanda);

        // Il lucchetto della sessione si rilascia prima di chiamare il
        // modello: finche' PHP lo tiene, ogni altra richiesta dello stesso
        // browser resta in coda, e BOB sembrava bloccato per intero mentre
        // l'AI pensava. Nessuno scrive piu' in sessione da qui in poi.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @set_time_limit(self::TIMEOUT_RISPOSTA);

        // Si va avanti anche se chi ha chiesto chiude la pagina o ha premuto
        // "ferma". Il lavoro del modello e' gia' pagato: portarlo a termine
        // vuol dire che la risposta finisce nella chat e la si trova
        // riaprendola, invece di aver aspettato venti secondi per niente.
        // Senza questo, la domanda restava salvata senza la sua risposta.
        ignore_user_abort(true);

        $onProgresso = $flusso ? $this->apriFlusso() : null;

        $inizio = microtime(true);
        try {
            $esito = (new AiSqlService($this->conn, $this->client))->chiedi(
                $domanda,
                $this->contestoUtente($user, $userId),
                $contesto,
                $tabelle,
                $onProgresso
            );
        } catch (\Throwable $e) {
            error_log('[AI_SQL] eccezione: ' . $e->getMessage());
            $esito = ['ok' => false, 'error' => 'Errore interno di BOB AI.'];
        }
        $durata = (int)round((microtime(true) - $inizio) * 1000);

        // ── Memoria e log ────────────────────────────────────────────────────
        if ($esito['ok']) {
            $chat->aggiungiRisposta(
                $convId,
                (string)$esito['answer'],
                $esito['riassunto'] ?? null,
                $esito['query'] ?? null,
                $esito['righe'] ?? null,
                $durata
            );
        } else {
            $chat->aggiungiRisposta(
                $convId,
                (string)$esito['error'],
                null,
                null,
                null,
                $durata,
                (string)$esito['error']
            );
        }

        $this->logga($userId, (string)($user->username ?? ''), $domanda, $esito, $durata);

        // ── Risposta ─────────────────────────────────────────────────────────
        $payload = [
            'ok'            => (bool)$esito['ok'],
            'conversazione' => $convId,
            'titolo'        => $chat->titoloDa($domanda),
            'durata_ms'     => $durata,
        ];

        if ($esito['ok']) {
            $payload['answer'] = $esito['answer'];
            $payload['query']  = $esito['query'] ?? null;
            $payload['righe']  = $esito['righe'] ?? 0;
        } else {
            $payload['error'] = $esito['error'];
        }

        if ($flusso) {
            $this->evento('fine', $payload);
            exit;
        }

        Response::json($payload, $esito['ok'] ? 200 : 400);
    }

    /**
     * Il contesto che serve all'AI per sapere chi sta chiedendo.
     *
     * @return array{username:string, full_name:string, is_superadmin:bool,
     *               allowed_modules:list<string>|null, can_see_prices:bool}
     */
    private function contestoUtente(\User $user, int $userId): array
    {
        $superadmin = $userId === 1;

        $nome = trim(((string)($user->first_name ?? '')) . ' ' . ((string)($user->last_name ?? '')));
        if ($nome === '') {
            $stmt = $this->conn->prepare(
                'SELECT first_name, last_name FROM bb_users WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $userId]);
            $riga = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $nome = trim(($riga['first_name'] ?? '') . ' ' . ($riga['last_name'] ?? ''));
        }

        // I permessi sono quelli della societa' in cui si sta lavorando: l'AI
        // non deve poter leggere le tabelle di una societa' diversa da questa.
        $moduli = $superadmin ? null : array_keys(array_filter(
            (new \App\Security\AccessControl($this->conn))
                ->permessi($userId, \App\Security\AccessControl::societaAttiva())
        ));

        return [
            'username'        => (string)($user->username ?? ''),
            'full_name'       => $nome !== '' ? $nome : (string)($user->username ?? 'Utente'),
            'is_superadmin'   => $superadmin,
            'allowed_modules' => $moduli,
            'can_see_prices'  => $user->canSeePrices(),
        ];
    }

    /**
     * Compatibilita' col vecchio frontend, che mandava tutta la cronologia in
     * un campo `messages`. Se arriva ancora, si prende l'ultima domanda: il
     * contesto adesso viene dal database, non dal browser.
     */
    private function domandaVecchioFormato(): string
    {
        $messaggi = json_decode((string)($_POST['messages'] ?? ''), true);
        if (!is_array($messaggi) || $messaggi === []) {
            return '';
        }

        $ultimo = end($messaggi);

        return is_array($ultimo) ? (string)($ultimo['content'] ?? '') : '';
    }

    private function logga(int $userId, string $username, string $domanda, array $esito, int $durata): void
    {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO bb_ai_sql_logs
                    (user_id, username, message, sql_query, db_source, rows_returned, duration_ms, error_msg, created_at)
                 VALUES (:uid, :user, :msg, :sql, 'mysql', :rows, :dur, :err, NOW())"
            );
            $stmt->execute([
                ':uid'  => $userId,
                ':user' => $username,
                ':msg'  => $domanda,
                ':sql'  => $esito['query'] ?? null,
                ':rows' => $esito['righe'] ?? 0,
                ':dur'  => $durata,
                ':err'  => $esito['error'] ?? null,
            ]);
        } catch (\Throwable) {
            // Il log non deve mai far fallire una risposta buona.
        }
    }

    // ── Streaming ─────────────────────────────────────────────────────────────

    /**
     * Apre il flusso SSE e restituisce la funzione con cui il motore manda
     * avanti gli avvisi di stato.
     *
     * @return callable(string, array):void
     */
    private function apriFlusso(): callable
    {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            // Senza questo nginx tiene in pancia tutto il flusso e lo
            // consegna alla fine: cioe' esattamente il problema che lo
            // streaming doveva risolvere.
            header('X-Accel-Buffering: no');
        }

        // La compressione a blocchi e i buffer di PHP vanno via: con quelli
        // attivi un evento da venti byte non parte finche' il buffer non si
        // riempie, e non si riempie mai.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        $this->evento('inizio', ['messaggio' => 'Ci sto pensando…']);

        // Gli avvisi "sto scrivendo" arrivano a ogni frammento del modello,
        // cioe' decine al secondo: mandarli tutti sarebbe traffico inutile.
        // Ne passa uno ogni 300 ms, che e' quanto basta a tenere viva la
        // connessione e a far muovere qualcosa sullo schermo.
        $ultimo = 0.0;

        return function (string $fase, array $dati) use (&$ultimo): void {
            $ora = microtime(true);

            if ($fase === 'scrivo') {
                if ($ora - $ultimo < 0.3) {
                    return;
                }
                $ultimo = $ora;
            }

            $this->evento('stato', ['fase' => $fase] + $dati);
        };
    }

    private function evento(string $nome, array $dati): void
    {
        echo "event: {$nome}\n";
        echo 'data: ' . json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Un errore prima che il lavoro cominci. In streaming non si puo' usare
     * il codice HTTP (gli header sono gia' partiti o stanno per partire con
     * 200), quindi l'errore e' un evento come gli altri.
     */
    private function fallisci(bool $flusso, string $messaggio, int $codice): never
    {
        if (!$flusso) {
            Response::json(['ok' => false, 'error' => $messaggio], $codice);
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
        }

        $this->evento('fine', ['ok' => false, 'error' => $messaggio]);
        exit;
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function exportTable(Request $request): never
    {
        $this->assertAiAccess($request);

        $rawHeaders = $_POST['headers'] ?? '[]';
        $rawRows    = $_POST['rows']    ?? '[]';
        $filename   = $_POST['filename'] ?? ('bob_ai_' . date('Y-m-d') . '.xlsx');

        $headers = json_decode($rawHeaders, true);
        $rows    = json_decode($rawRows,    true);

        if (!is_array($headers) || empty($headers)) {
            http_response_code(400);
            exit('Dati mancanti');
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        // Sanitise filename
        $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);
        if (!str_ends_with($filename, '.xlsx')) {
            $filename .= '.xlsx';
        }

        require_once APP_ROOT . '/views/ai/export_table_excel.php';
        exit;
    }
}
