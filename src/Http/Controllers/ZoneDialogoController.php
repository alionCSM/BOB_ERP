<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Service\Notifications\NotificationService;
use App\Service\Zone\Accesso;
use App\Service\Zone\Messaggi;
use App\Service\Zone\Segnalazioni;

/**
 * BOB Zone: la chat del cantiere e le segnalazioni.
 *
 * Gli stessi metodi per il sito (/worksites/{id}/zone/...) e per l'app
 * (/api/v1/zone/{id}/...), come il resto della Zone.
 *
 * Il controllo e' uno: che ruolo hai su questo cantiere. Senza ruolo, 403.
 * Poi ogni metodo guarda la sua regola (canale che puoi leggere,
 * segnalazione che puoi vedere, chi puo' fissare o chiudere) — sempre qui
 * sul server: quello che uno non deve leggere non arriva al telefono.
 */
final class ZoneDialogoController
{
    private Accesso $accesso;
    private Messaggi $messaggi;
    private Segnalazioni $segnalazioni;

    public function __construct(
        private Config $config,
        private \PDO $conn,
    ) {
        $this->accesso      = new Accesso($conn);
        $this->messaggi     = new Messaggi($conn);
        $this->segnalazioni = new Segnalazioni($conn, $this->messaggi);
    }

    // ─── Chat ─────────────────────────────────────────────────────────────────

    /** I canali che posso aprire, coi non letti e l'ultimo messaggio. */
    public function chat(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $canali = $this->messaggi->canali($ruolo, $w);
            $nonLetti = $this->messaggi->nonLetti($io, $w, $canali);
            $fuori = [];
            foreach ($canali as $c) {
                $ultimo = $this->messaggi->elenco($w, $c, null, 0, 1);
                $fuori[] = [
                    'canale'    => $c,
                    'non_letti' => $nonLetti[$c] ?? 0,
                    'ultimo'    => $ultimo[0] ?? null,
                    'fissati'   => count($this->messaggi->fissati($w, $c)),
                ];
            }
            return [
                'canali'       => $fuori,
                'puo_fissare'  => Messaggi::puoFissare($ruolo),
                'rapidi'       => Messaggi::RAPIDI,
            ];
        });
    }

    /** I messaggi di un canale. Aprirlo vuol dire averlo letto. */
    public function canale(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, , $io, $canale] = $this->canaleMio($request);
            $dopo = max(0, (int)($_GET['dopo'] ?? 0));
            $msg = $this->miei($this->messaggi->elenco($w, $canale, null, $dopo), $io);
            if ($msg) {
                $this->messaggi->segnaLetto($io, $w, $canale, (int)end($msg)['id']);
            }
            return [
                'messaggi' => $msg,
                'fissati'  => $dopo === 0 ? $this->miei($this->messaggi->fissati($w, $canale), $io) : null,
            ];
        });
    }

    public function scriviCanale(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io, $canale] = $this->canaleMio($request);
            $body = $this->corpo();
            $id = $this->messaggi->scrivi($w, $canale, null, $io, $body['testo'] ?? null, $body['rapido'] ?? null);
            $fissa = !empty($body['fissa']) && Messaggi::puoFissare($ruolo);
            if ($fissa) {
                $this->messaggi->fissa($id, true, $io);
            }
            $this->avvisaChat($w, $canale, $id, $request, $fissa);
            return $this->messaggi->elenco($w, $canale, null, $id - 1, 1)[0] ?? ['id' => $id];
        });
    }

    public function fotoCanale(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, , $io, $canale] = $this->canaleMio($request);
            $foto = $this->salvaFoto($w);
            $id = $this->messaggi->scrivi($w, $canale, null, $io, $_POST['testo'] ?? null, null, $foto);
            $this->avvisaChat($w, $canale, $id, $request, false);
            return $this->messaggi->elenco($w, $canale, null, $id - 1, 1)[0] ?? ['id' => $id];
        });
    }

    /** Fissa in alto (o toglie): capo e ufficio. Un avviso fissato suona a tutti. */
    public function fissa(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $m = $this->messaggioMio($request, $w, $ruolo, $io);
            if (!Messaggi::puoFissare($ruolo) || $m['canale'] === null) {
                Accesso::nega('Solo capo squadra e ufficio fissano gli avvisi');
            }
            $si = !empty($this->corpo()['si']);
            $this->messaggi->fissa((int)$m['id'], $si, $io);
            if ($si && !$m['fissato']) {
                $this->avvisaChat($w, (string)$m['canale'], (int)$m['id'], $request, true);
            }
            return ['fissato' => $si];
        });
    }

    /** I propri messaggi; l'ufficio anche quelli degli altri. */
    public function eliminaMessaggio(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $m = $this->messaggioMio($request, $w, $ruolo, $io);
            if ($m['sistema'] || ($ruolo !== Accesso::UFFICIO && (int)$m['user_id'] !== $io)) {
                Accesso::nega('Puoi cancellare solo i tuoi messaggi');
            }
            $this->messaggi->elimina((int)$m['id']);
            return ['ok' => true];
        });
    }

    /** Chi l'ha letto e chi no, fra chi lo poteva leggere. */
    public function lettoDa(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $m = $this->messaggioMio($request, $w, $ruolo, $io);
            $persone = $m['canale'] !== null
                ? $this->messaggi->personeCanale($w, (string)$m['canale'])
                : $this->segnalazioni->persone($this->segnalazioni->trova((int)$m['segnalazione_id']) ?? []);
            return $this->messaggi->lettoDa($m, $persone);
        });
    }

    /** La foto di un messaggio, a chi puo' leggere il messaggio. */
    public function fotoMessaggio(Request $request): void
    {
        [$w, $ruolo, $io] = $this->chi($request);
        $m = $this->messaggioMio($request, $w, $ruolo, $io);
        if (empty($m['foto']) || $m['eliminato_at'] !== null) {
            http_response_code(404);
            exit('Foto non trovata');
        }
        $root = realpath(\CloudPath::getRoot());
        $real = realpath(\CloudPath::getRoot() . DIRECTORY_SEPARATOR . $m['foto']);
        if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
            http_response_code(404);
            exit('Foto non trovata');
        }
        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic'];
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, max-age=86400');
        readfile($real);
        exit;
    }

    // ─── Segnalazioni ─────────────────────────────────────────────────────────

    public function segnalazioni(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            return [
                'segnalazioni' => $this->segnalazioni->elenco($w, $ruolo, $io),
                'puo_gestire'  => Segnalazioni::puoGestire($ruolo),
                'tipi'         => Segnalazioni::TIPI,
            ];
        });
    }

    public function creaSegnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $id = $this->segnalazioni->crea($w, $ruolo, $io, $this->corpo());
            // l'avviso parte subito; le foto arrivano dopo nel filo
            $this->avvisa(fn(NotificationService $n) => $this->segnalazioni->avvisaNuova($n, $id));
            return $this->segnalazioni->trova($id);
        });
    }

    /** Una segnalazione col suo filo. Aprirla vuol dire averlo letto. */
    public function segnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $s = $this->segnalazioneMia($request, $w, $ruolo, $io);
            $dopo = max(0, (int)($_GET['dopo'] ?? 0));
            $msg = $this->miei($this->messaggi->elenco($w, null, (int)$s['id'], $dopo, 200), $io);
            if ($msg) {
                $this->messaggi->segnaLetto($io, $w, 's' . $s['id'], (int)end($msg)['id']);
            }
            return [
                'segnalazione' => $s,
                'messaggi'     => $msg,
                'puo_gestire'  => Segnalazioni::puoGestire($ruolo),
                'da_ufficio'   => $ruolo === Accesso::UFFICIO,
            ];
        });
    }

    public function scriviSegnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $s = $this->segnalazioneMia($request, $w, $ruolo, $io);
            $id = $this->messaggi->scrivi($w, null, (int)$s['id'], $io, $this->corpo()['testo'] ?? null);
            $this->avvisaFilo($s, $request);
            return $this->messaggi->elenco($w, null, (int)$s['id'], $id - 1, 1)[0] ?? ['id' => $id];
        });
    }

    public function fotoSegnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $s = $this->segnalazioneMia($request, $w, $ruolo, $io);
            $foto = $this->salvaFoto($w);
            $id = $this->messaggi->scrivi($w, null, (int)$s['id'], $io, $_POST['testo'] ?? null, null, $foto);
            // la foto di chi l'ha appena aperta non avvisa di nuovo: l'avviso
            // della segnalazione e' partito un attimo fa
            if ((int)$s['created_by'] !== $io || strtotime((string)$s['created_at']) < time() - 600) {
                $this->avvisaFilo($s, $request);
            }
            return $this->messaggi->elenco($w, null, (int)$s['id'], $id - 1, 1)[0] ?? ['id' => $id];
        });
    }

    /** Presa in carico, risolta, riaperta: capo e ufficio. */
    public function statoSegnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $s = $this->segnalazioneMia($request, $w, $ruolo, $io);
            if (!Segnalazioni::puoGestire($ruolo)) {
                Accesso::nega('La segnalazione la gestiscono capo squadra e ufficio');
            }
            $body = $this->corpo();
            $this->segnalazioni->cambiaStato($s, (string)($body['stato'] ?? ''), $io, $body['esito'] ?? null);
            $this->avvisa(fn(NotificationService $n) => $this->segnalazioni->avvisaStato($n, (int)$s['id'], $io));
            return $this->segnalazioni->trova((int)$s['id']);
        });
    }

    /** Chi la vede: lo decide l'ufficio (allargarla alla squadra, a tutti). */
    public function visibilitaSegnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $s = $this->segnalazioneMia($request, $w, $ruolo, $io);
            if ($ruolo !== Accesso::UFFICIO) {
                Accesso::nega('Chi la vede lo decide l\'ufficio');
            }
            $this->segnalazioni->cambiaVisibilita((int)$s['id'], (string)($this->corpo()['visibilita'] ?? ''));
            return $this->segnalazioni->trova((int)$s['id']);
        });
    }

    /**
     * Ne fa un'attivita' per la squadra: la segnalazione resta, con il
     * collegamento, e passa "presa in carico".
     */
    public function attivitaDaSegnalazione(Request $request): void
    {
        $this->json(function () use ($request) {
            [$w, $ruolo, $io] = $this->chi($request);
            $s = $this->segnalazioneMia($request, $w, $ruolo, $io);
            if (!Segnalazioni::puoGestire($ruolo)) {
                Accesso::nega('Le attivita\' le creano capo squadra e ufficio');
            }
            if ($s['task_id']) {
                return $this->segnalazioni->trova((int)$s['id']);
            }
            $body = $this->corpo();
            $nome = trim((string)($body['name'] ?? '')) ?: mb_substr((string)$s['testo'], 0, 120);

            $repo   = new \App\Repository\Fieldwire\ZoneTaskRepository($this->conn);
            $taskId = $repo->create($w, [
                'name'        => $nome,
                'description' => "Da segnalazione #{$s['id']}: {$s['testo']}",
                'priority'    => $s['gravita'] === 'blocca' ? 2 : ($s['gravita'] === 'alta' ? 1 : 0),
                'category'    => 'Segnalazione',
            ], $io);
            $this->conn->prepare("UPDATE bb_zone_tasks SET visibilita = 'squadra', assegnata_a = 'squadra' WHERE id = :id")
                ->execute([':id' => $taskId]);

            $this->segnalazioni->collegaAttivita((int)$s['id'], $taskId);
            if ($s['stato'] === 'aperta') {
                $this->segnalazioni->cambiaStato($s, 'presa', $io, null);
                $this->avvisa(fn(NotificationService $n) => $this->segnalazioni->avvisaStato($n, (int)$s['id'], $io));
            }
            $this->avvisa(function (NotificationService $n) use ($taskId, $io) {
                (new \App\Service\Zone\AvvisiZona($this->conn, $n))->attivita($taskId, $io);
            });
            return $this->segnalazioni->trova((int)$s['id']);
        });
    }

    // ─── Interni ──────────────────────────────────────────────────────────────

    /** Segna i propri messaggi: il web e l'app li mettono a destra. */
    private function miei(array $msg, int $io): array
    {
        foreach ($msg as &$m) {
            $m['mio'] = $m['user_id'] === $io;
        }
        return $msg;
    }

    /**
     * Cantiere, ruolo, chi sono. Senza ruolo su questo cantiere, 403.
     *
     * @return array{0:int, 1:string, 2:int}
     */
    private function chi(Request $request): array
    {
        $w  = (int)$request->param('id');
        $io = (int)($request->user()->id ?? 0);
        $ruolo = $this->accesso->ruolo($request->user(), $w)
            ?? Accesso::nega('Non hai accesso a questo cantiere');
        return [$w, $ruolo, $io];
    }

    /** @return array{0:int, 1:string, 2:int, 3:string} */
    private function canaleMio(Request $request): array
    {
        [$w, $ruolo, $io] = $this->chi($request);
        $canale = (string)$request->param('canale');
        if (!in_array($canale, $this->messaggi->canali($ruolo, $w), true)) {
            Accesso::nega('Non puoi leggere questo canale');
        }
        return [$w, $ruolo, $io, $canale];
    }

    /** Il messaggio, se e' di questo cantiere e lo posso leggere. */
    private function messaggioMio(Request $request, int $w, string $ruolo, int $io): array
    {
        $m = $this->messaggi->trova((int)$request->param('mid'));
        if (!$m || (int)$m['worksite_id'] !== $w) {
            Response::json(['ok' => false, 'error' => 'Messaggio non trovato'], 404);
        }
        $puo = $m['segnalazione_id'] !== null
            ? (($s = $this->segnalazioni->trova((int)$m['segnalazione_id'])) && Segnalazioni::vede($ruolo, $io, $s))
            : in_array($m['canale'], $this->messaggi->canali($ruolo, $w), true);
        if (!$puo) {
            Response::json(['ok' => false, 'error' => 'Messaggio non trovato'], 404);
        }
        $m['fissato'] = (bool)$m['fissato'];
        $m['sistema'] = (bool)$m['sistema'];
        return $m;
    }

    private function segnalazioneMia(Request $request, int $w, string $ruolo, int $io): array
    {
        $s = $this->segnalazioni->trova((int)$request->param('sid'));
        if (!$s || $s['worksite_id'] !== $w || !Segnalazioni::vede($ruolo, $io, $s)) {
            Response::json(['ok' => false, 'error' => 'Segnalazione non trovata'], 404);
        }
        return $s;
    }

    /** Salva la foto ricevuta e ne da' il percorso relativo. */
    private function salvaFoto(int $w): string
    {
        $f = $_FILES['photo'] ?? $_FILES['foto'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Nessuna foto ricevuta');
        }
        if (($f['size'] ?? 0) > 25 * 1024 * 1024) {
            throw new \RuntimeException('Foto troppo grande (max 25 MB)');
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
            throw new \RuntimeException('Formato immagine non consentito');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
        if (!str_starts_with((string)$mime, 'image/') && $mime !== 'application/octet-stream') {
            throw new \RuntimeException('Il file non e\' un\'immagine');
        }
        $dir  = \CloudPath::ensureZonePhotosDir($w);
        $dest = $dir . DIRECTORY_SEPARATOR . 'm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
            throw new \RuntimeException('Salvataggio foto fallito');
        }
        return \CloudPath::relativeToRoot($dest);
    }

    /**
     * Push per un messaggio della chat: uno per canale finche' uno non apre,
     * gli avvisi fissati sempre.
     */
    private function avvisaChat(int $w, string $canale, int $id, Request $request, bool $fissato): void
    {
        $io = (int)($request->user()->id ?? 0);
        $this->avvisa(function (NotificationService $n) use ($w, $canale, $id, $io, $fissato) {
            $m = $this->messaggi->elenco($w, $canale, null, $id - 1, 1)[0] ?? null;
            if (!$m) return;
            $cantiere = $this->nomeCantiere($w);
            foreach ($this->messaggi->personeCanale($w, $canale) as $p) {
                if ($p['user_id'] === $io) continue;
                if (!$this->messaggi->prenotaAvviso($p['user_id'], $w, $canale, $fissato)) continue;
                $n->create(
                    $p['user_id'],
                    \App\Service\Lingua::testo($fissato ? 'zona_avviso_t' : 'zona_chat_t', $p['lingua'], ['cantiere' => $cantiere]),
                    ($m['autore'] ?? 'BOB') . ': ' . $this->anteprima($m, $p['lingua']),
                    Segnalazioni::link($w, 'chat-' . $canale),
                    'zone',
                    $fissato ? 'high' : 'normal',
                    $io,
                );
            }
        });
    }

    /** Push per un messaggio nel filo di una segnalazione. */
    private function avvisaFilo(array $s, Request $request): void
    {
        $io = (int)($request->user()->id ?? 0);
        $this->avvisa(function (NotificationService $n) use ($s, $io) {
            $w = (int)$s['worksite_id'];
            $chiave = 's' . $s['id'];
            foreach ($this->segnalazioni->persone($s) as $p) {
                if ($p['user_id'] === $io) continue;
                // nel filo scrivono chi l'ha fatta e chi la gestisce: gli
                // altri la leggono, ma non suona a ogni risposta
                if ($p['user_id'] !== (int)$s['created_by']
                    && !in_array($p['ruolo'], [Accesso::UFFICIO, Accesso::CAPO], true)) continue;
                if (!$this->messaggi->prenotaAvviso($p['user_id'], $w, $chiave)) continue;
                $n->create(
                    $p['user_id'],
                    \App\Service\Lingua::testo('zona_segn_msg_t', $p['lingua']),
                    $this->nomeCantiere($w) . ': ' . mb_substr((string)$s['testo'], 0, 100),
                    Segnalazioni::link($w, 'segnalazione-' . $s['id']),
                    'zone',
                    'normal',
                    $io,
                );
            }
        });
    }

    private function anteprima(array $m, ?string $lingua): string
    {
        if (!empty($m['rapido'])) {
            return \App\Service\Lingua::testo('zona_rapido_' . $m['rapido'], $lingua);
        }
        if (!empty($m['testo'])) {
            return mb_substr((string)$m['testo'], 0, 140);
        }
        return \App\Service\Lingua::testo('zona_foto', $lingua);
    }

    private function nomeCantiere(int $w): string
    {
        $st = $this->conn->prepare('SELECT name FROM bb_worksites WHERE id = :w');
        $st->execute([':w' => $w]);
        return (string)$st->fetchColumn();
    }

    /** Un avviso che non parte non deve far fallire quello che si e' salvato. */
    private function avvisa(callable $cosa): void
    {
        try {
            $cosa(new NotificationService($this->conn, $this->config));
        } catch (\Throwable $e) {
            error_log('[Zone dialogo avviso] ' . $e->getMessage());
        }
    }

    private function corpo(): array
    {
        $raw = file_get_contents('php://input');
        $d = $raw ? json_decode($raw, true) : null;
        return is_array($d) ? $d : $_POST;
    }

    /** Risponde {ok, data} come il resto della Zone, o {ok:false, error}. */
    private function json(callable $fn): void
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_start();
        try {
            $data = $fn();
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[ZoneDialogo] ' . $e::class . ': ' . $e->getMessage());
            http_response_code($e instanceof \RuntimeException ? 422 : 500);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'    => false,
                'error' => $e instanceof \RuntimeException ? $e->getMessage() : 'Errore del server',
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
