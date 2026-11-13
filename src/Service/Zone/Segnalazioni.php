<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Le segnalazioni dal cantiere: un problema, scritto, con foto, che
 * qualcuno prende in carico e chiude.
 *
 * Chi la fa: chiunque entri nel cantiere (capo, operaio, cliente).
 * Chi la vede: l'ufficio, chi l'ha fatta, e la visibilita' scelta. Di
 * base "tutti" — noi, il cliente e la squadra — perche' la Zone e' lo
 * spazio comune del cantiere. L'ufficio la stringe quando serve (solo
 * ufficio, ufficio e cantiere, ufficio e cliente).
 *
 * Chi la muove: l'ufficio e il capo. Aperta → presa in carico → risolta.
 * Ogni passaggio scrive una riga nel filo ("Presa in carico da Rossi") e
 * avvisa chi l'ha fatta: il problema vero di una segnalazione e' non sapere
 * se qualcuno l'ha letta.
 *
 * "Blocca il lavoro" e "sicurezza" arrivano come avviso urgente.
 */
final class Segnalazioni
{
    public const TIPI     = ['sicurezza', 'materiale', 'danno', 'ritardo', 'qualita', 'altro'];
    public const GRAVITA  = ['bassa', 'alta', 'blocca'];
    public const STATI    = ['aperta', 'presa', 'risolta'];

    public function __construct(
        private PDO $conn,
        private Messaggi $messaggi,
    ) {}

    public static function puoGestire(string $ruolo): bool
    {
        return in_array($ruolo, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE], true);
    }

    /** La vede? L'ufficio sempre, chi l'ha fatta sempre, gli altri per visibilita'. */
    public static function vede(string $ruolo, int $userId, array $s): bool
    {
        return $ruolo === Accesso::UFFICIO
            || (int)$s['created_by'] === $userId
            || Accesso::vedeVisibilita($ruolo, $s['visibilita'] ?? 'capi');
    }

    /**
     * Le segnalazioni del cantiere che questa persona vede: prima le aperte,
     * le piu' gravi in cima.
     *
     * @return array<int, array<string, mixed>>
     */
    public function elenco(int $worksiteId, string $ruolo, int $userId): array
    {
        $st = $this->conn->prepare("
            SELECT s.*,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS autore,
                   TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS presa_da_nome,
                   tk.assegnata_a AS task_assegnata_a, tk.name AS task_nome,
                   (SELECT COUNT(*) FROM bb_zone_messaggi m
                     WHERE m.segnalazione_id = s.id AND m.foto IS NOT NULL AND m.eliminato_at IS NULL) AS foto,
                   (SELECT MAX(m.id) FROM bb_zone_messaggi m WHERE m.segnalazione_id = s.id) AS ultimo_msg,
                   -- lo spillo sul disegno, se e' stata segnata li'. Una
                   -- segnalazione con un posto si apre dal punto, e chi
                   -- legge non deve indovinare in che campata siamo.
                   (SELECT a.document_id FROM bb_zone_annotations a
                     WHERE a.segnalazione_id = s.id ORDER BY a.id DESC LIMIT 1) AS disegno_id,
                   (SELECT a.page FROM bb_zone_annotations a
                     WHERE a.segnalazione_id = s.id ORDER BY a.id DESC LIMIT 1) AS disegno_pagina
            FROM   bb_zone_segnalazioni s
            JOIN   bb_users u ON u.id = s.created_by
            LEFT JOIN bb_users p ON p.id = s.presa_da
            LEFT JOIN bb_zone_tasks tk ON tk.id = s.task_id
            WHERE  s.worksite_id = :w
            ORDER BY FIELD(s.stato, 'aperta', 'presa', 'risolta'),
                     FIELD(s.gravita, 'blocca', 'alta', 'bassa'),
                     s.id DESC
        ");
        $st->execute([':w' => $worksiteId]);

        $fuori = [];
        $chiavi = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if (!self::vede($ruolo, $userId, $s)) continue;
            $fuori[] = $this->pulisci($s);
            $chiavi[] = 's' . $s['id'];
        }
        $nonLetti = $this->messaggi->nonLetti($userId, $worksiteId, $chiavi);
        foreach ($fuori as &$s) {
            $s['non_letti'] = $nonLetti['s' . $s['id']] ?? 0;
        }
        return $fuori;
    }

    public function trova(int $id): ?array
    {
        $st = $this->conn->prepare("
            SELECT s.*,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS autore,
                   TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS presa_da_nome,
                   tk.assegnata_a AS task_assegnata_a, tk.name AS task_nome,
                   0 AS foto, NULL AS ultimo_msg
            FROM   bb_zone_segnalazioni s
            JOIN   bb_users u ON u.id = s.created_by
            LEFT JOIN bb_users p ON p.id = s.presa_da
            LEFT JOIN bb_zone_tasks tk ON tk.id = s.task_id
            WHERE  s.id = :id
        ");
        $st->execute([':id' => $id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        return $s ? $this->pulisci($s) : null;
    }

    /** Una segnalazione nuova. Le foto arrivano dopo, una per volta, nel suo filo. */
    public function crea(int $worksiteId, string $ruolo, int $userId, array $dati): int
    {
        $testo = trim((string)($dati['testo'] ?? ''));
        if ($testo === '') {
            throw new \RuntimeException('Scrivi qual e\' il problema');
        }
        $tipo    = in_array($dati['tipo'] ?? '', self::TIPI, true) ? $dati['tipo'] : 'altro';
        $gravita = in_array($dati['gravita'] ?? '', self::GRAVITA, true) ? $dati['gravita'] : 'bassa';
        // Di base la vedono tutti e tre: un problema in cantiere riguarda
        // noi, il cliente e la squadra. L'ufficio puo' sceglierne un'altra
        // mentre la scrive, e chiunque dell'ufficio stringerla dopo.
        $vis = $ruolo === Accesso::UFFICIO && in_array($dati['visibilita'] ?? '', Accesso::VISIBILITA, true)
            ? $dati['visibilita']
            : 'tutti';

        $this->conn->prepare("
            INSERT INTO bb_zone_segnalazioni (worksite_id, tipo, gravita, testo, visibilita, created_by)
            VALUES (:w, :t, :g, :x, :v, :u)
        ")->execute([
            ':w' => $worksiteId, ':t' => $tipo, ':g' => $gravita,
            ':x' => mb_substr($testo, 0, 4000), ':v' => $vis, ':u' => $userId,
        ]);
        $id = (int)$this->conn->lastInsertId();
        $this->messaggi->segnaLetto($userId, $worksiteId, 's' . $id, 0);
        return $id;
    }

    /**
     * Cambia lo stato: presa in carico, risolta, riaperta. Scrive la riga nel
     * filo, che resta come storia.
     */
    public function cambiaStato(array $s, string $stato, int $daChi, ?string $esito): void
    {
        if (!in_array($stato, self::STATI, true) || $stato === $s['stato']) {
            return;
        }
        $set = match ($stato) {
            'presa'   => 'stato = :s, presa_da = :u, presa_at = NOW()',
            'risolta' => 'stato = :s, risolta_da = :u, risolta_at = NOW(), esito = :e,
                          presa_da = COALESCE(presa_da, :u2), presa_at = COALESCE(presa_at, NOW())',
            default   => 'stato = :s, risolta_da = NULL, risolta_at = NULL',
        };
        $par = [':s' => $stato, ':id' => (int)$s['id']];
        if ($stato !== 'aperta') {
            $par[':u'] = $daChi;
        }
        if ($stato === 'risolta') {
            $par[':e'] = $esito !== null && trim($esito) !== '' ? mb_substr(trim($esito), 0, 2000) : null;
            $par[':u2'] = $daChi;
        }
        $this->conn->prepare("UPDATE bb_zone_segnalazioni SET $set WHERE id = :id")->execute($par);

        // la riga del filo: una chiave, che ognuno legge nella sua lingua
        // ("Presa in carico da {autore}"); l'esito, se c'e', come testo
        $this->messaggi->scrivi(
            (int)$s['worksite_id'], null, (int)$s['id'], $daChi,
            $stato === 'risolta' ? ($par[':e'] ?? null) : null,
            'sys_' . $stato, null, true,
        );
    }

    /**
     * Una segnalazione collegata a un'attivita' ne segue lo stato:
     *   attivita' aperta o in corso  -> presa in carico (da chi ha l'attivita')
     *   attivita' completata/verificata -> risolta
     *   attivita' riaperta            -> di nuovo presa in carico
     * "In carico a" e' la persona a cui e' assegnata l'attivita' (non chi l'ha
     * creata); se e' assegnata a un gruppo, il gruppo. Chi ha aperto la
     * segnalazione riceve l'avviso di ogni cambio.
     */
    public function seguiAttivita(int $taskId, int $daChi, ?NotificationService $n = null): void
    {
        $st = $this->conn->prepare('SELECT id, name, status, assignee_user_id, assegnata_a FROM bb_zone_tasks WHERE id = :t');
        $st->execute([':t' => $taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }
        $ids = $this->conn->prepare('SELECT id FROM bb_zone_segnalazioni WHERE task_id = :t');
        $ids->execute([':t' => $taskId]);
        $responsabile = !empty($t['assignee_user_id']) ? (int)$t['assignee_user_id'] : null;
        $voluto = in_array($t['status'], ['complete', 'verified'], true) ? 'risolta' : 'presa';
        foreach (array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)) as $sid) {
            $s = $this->trova($sid);
            if (!$s) continue;
            if ($s['stato'] !== $voluto) {
                // la riga del filo la firma chi ha l'attivita' (o chi l'ha cambiata)
                $this->cambiaStato($s, $voluto, $responsabile ?? $daChi,
                    $voluto === 'risolta' ? 'Risolta con l\'attività «' . $t['name'] . '»' : null);
                if ($n) {
                    try { $this->avvisaStato($n, $sid, $daChi); } catch (\Throwable $e) { error_log('[Segnalazione segue attivita] ' . $e->getMessage()); }
                }
            }
            // in carico a chi ha l'attivita' (NULL = un gruppo: si mostra il gruppo)
            $this->conn->prepare('UPDATE bb_zone_segnalazioni SET presa_da = :p WHERE id = :id')
                ->execute([':p' => $responsabile, ':id' => $sid]);
        }
    }

    /**
     * L'attivita' collegata e' stata eliminata: la segnalazione si scollega e,
     * se non era gia' risolta, torna aperta (non la segue piu' nessuno), con
     * la riga nel filo. Chi l'ha aperta riceve l'avviso.
     */
    public function scollegaAttivita(int $taskId, int $daChi, ?NotificationService $n = null): void
    {
        $st = $this->conn->prepare('SELECT id FROM bb_zone_segnalazioni WHERE task_id = :t');
        $st->execute([':t' => $taskId]);
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $sid) {
            $s = $this->trova($sid);
            if (!$s) continue;
            $this->conn->prepare('UPDATE bb_zone_segnalazioni SET task_id = NULL WHERE id = :id')->execute([':id' => $sid]);
            if ($s['stato'] === 'risolta') continue;
            $this->conn->prepare("UPDATE bb_zone_segnalazioni SET stato = 'aperta', presa_da = NULL, presa_at = NULL WHERE id = :id")
                ->execute([':id' => $sid]);
            $this->messaggi->scrivi((int)$s['worksite_id'], null, $sid, $daChi,
                'Attività collegata eliminata: la segnalazione torna aperta.', null, null, true);
            // l'avviso lo manda chi elimina l'attivita' (AvvisiZona::attivitaEliminata)
        }
    }

    public function cambiaVisibilita(int $id, string $vis): void
    {
        if (!in_array($vis, Accesso::VISIBILITA, true)) {
            throw new \RuntimeException('Visibilita\' non valida');
        }
        $this->conn->prepare('UPDATE bb_zone_segnalazioni SET visibilita = :v WHERE id = :id')
            ->execute([':v' => $vis, ':id' => $id]);
    }

    public function collegaAttivita(int $id, int $taskId): void
    {
        $this->conn->prepare('UPDATE bb_zone_segnalazioni SET task_id = :t WHERE id = :id')
            ->execute([':t' => $taskId, ':id' => $id]);
    }

    /**
     * Chi legge il filo di una segnalazione: l'ufficio del cantiere, chi l'ha
     * fatta, e chi del cantiere la vede per visibilita'.
     *
     * @return array<int, array{user_id:int, nome:string, lingua:?string, ruolo:string}>
     */
    public function persone(array $s): array
    {
        $w = (int)$s['worksite_id'];
        $ruoli = array_values(array_filter(
            [Accesso::CANTIERE, Accesso::CLIENTE],
            fn($r) => Accesso::vedeVisibilita($r, $s['visibilita'])
        ));
        // L'ufficio per ultimo: chi ha il permesso Zone e' dell'ufficio
        // anche se sul cantiere gli hanno messo ruolo operaio, e nel filo di
        // una segnalazione e' l'ufficio che risponde.
        $chi = [];
        foreach (array_merge($this->messaggi->personeRuoli($w, $ruoli), $this->messaggi->ufficio($w)) as $p) {
            $chi[(int)$p['user_id']] = $p;
        }
        if (!isset($chi[(int)$s['created_by']])) {
            // Chi l'ha aperta la segue anche se non e' fra i ruoli che la
            // vedono. Ma un cliente tolto dal cantiere, o con la
            // condivisione spenta, no: prima continuava a ricevere le
            // risposte, anche per email.
            $st = $this->conn->prepare("
                SELECT u.id AS user_id, u.lingua, COALESCE(a.ruolo, 'ufficio') AS ruolo,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS nome
                FROM bb_users u
                LEFT JOIN bb_zone_accessi a ON a.user_id = u.id AND a.worksite_id = :w
                LEFT JOIN bb_worksites w    ON w.id = :w2
                WHERE u.id = :u
                  AND (u.type <> 'client' OR (a.user_id IS NOT NULL AND " . Accesso::SQL_CLIENTE_ENTRA . "))
            ");
            $st->execute([':w' => $w, ':w2' => $w, ':u' => (int)$s['created_by']]);
            if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
                $chi[(int)$p['user_id']] = ['user_id' => (int)$p['user_id']] + $p;
            }
        }
        return array_values($chi);
    }

    // ── Avvisi ──────────────────────────────────────────────────────────────

    /** Nuova segnalazione: all'ufficio del cantiere e a chi la vede. */
    public function avvisaNuova(NotificationService $n, int $id): void
    {
        $s = $this->trova($id);
        if (!$s) return;
        $cantiere = $this->nomeCantiere((int)$s['worksite_id']);
        $urgente  = $s['gravita'] === 'blocca' || $s['tipo'] === 'sicurezza';

        // Qui, e solo qui, il ripiego: se a quel cantiere non e' attaccato
        // nessuno dell'ufficio, la segnalazione va a tutti quelli col
        // permesso Zone. Una che non arriva a nessuno e' peggio di una in
        // piu' — ma vale per la segnalazione, non per i messaggi di tutti i
        // giorni, che prima prendevano la stessa strada.
        $chi = [];
        foreach (array_merge($this->persone($s), $this->messaggi->ufficio((int)$s['worksite_id'], true)) as $p) {
            $chi[(int)$p['user_id']] = $p;
        }

        foreach ($chi as $p) {
            if ($p['user_id'] === (int)$s['created_by']) continue;
            // una segnalazione da squadra la legge chi la gestisce, non tutti:
            // l'operaio la vedra' se l'ufficio la allarga, senza un push
            if (!in_array($p['ruolo'], [Accesso::UFFICIO, Accesso::CANTIERE], true)) continue;
            $n->create(
                $p['user_id'],
                Lingua::testo($urgente ? 'zona_segn_urgente_t' : 'zona_segn_t', $p['lingua']),
                Lingua::testo('zona_segn', $p['lingua'], [
                    'cantiere' => $cantiere,
                    'tipo'     => Lingua::testo('zona_tipo_' . $s['tipo'], $p['lingua']),
                    'testo'    => mb_substr((string)$s['testo'], 0, 120),
                ]),
                self::link((int)$s['worksite_id'], 'segnalazione-' . $id),
                'zone',
                $urgente ? 'high' : 'normal',
                (int)$s['created_by'],
            );
        }
    }

    /** Stato cambiato: a chi l'ha fatta (se non e' lui ad averla mossa). */
    public function avvisaStato(NotificationService $n, int $id, int $daChi): void
    {
        $s = $this->trova($id);
        if (!$s || (int)$s['created_by'] === $daChi) return;
        $st = $this->conn->prepare('SELECT lingua FROM bb_users WHERE id = :u');
        $st->execute([':u' => (int)$s['created_by']]);
        $lingua = $st->fetchColumn() ?: null;
        $n->create(
            (int)$s['created_by'],
            Lingua::testo('zona_segn_' . $s['stato'] . '_t', $lingua),
            Lingua::testo('zona_segn_stato', $lingua, [
                'cantiere' => $this->nomeCantiere((int)$s['worksite_id']),
                'testo'    => mb_substr((string)$s['testo'], 0, 120),
            ]),
            self::link((int)$s['worksite_id'], 'segnalazione-' . $id),
            'zone',
            'normal',
            $daChi,
        );
    }

    public static function link(int $worksiteId, string $dove): string
    {
        return '/worksites/' . $worksiteId . '/zone#' . $dove;
    }

    private function nomeCantiere(int $w): string
    {
        $st = $this->conn->prepare('SELECT name FROM bb_worksites WHERE id = :w');
        $st->execute([':w' => $w]);
        return (string)$st->fetchColumn();
    }

    private function pulisci(array $s): array
    {
        foreach (['id', 'worksite_id', 'created_by', 'foto'] as $k) {
            $s[$k] = (int)$s[$k];
        }
        $s['presa_da']  = $s['presa_da'] !== null ? (int)$s['presa_da'] : null;
        $s['task_id']   = $s['task_id'] !== null ? (int)$s['task_id'] : null;
        $s['presa_da_nome'] = trim((string)$s['presa_da_nome']) ?: null;
        // collegata a un'attivita' assegnata a un gruppo: in carico al gruppo
        if ($s['presa_da_nome'] === null && $s['task_id'] && $s['stato'] !== 'aperta') {
            $s['presa_da_nome'] = match ((string)($s['task_assegnata_a'] ?? '')) {
                'ufficio' => 'CSM',
                'cliente' => 'Committente',
                default   => 'Squadra',
            };
        }
        $s['disegno_id'] = isset($s['disegno_id']) && $s['disegno_id'] !== null
            ? (int)$s['disegno_id'] : null;
        $s['disegno_pagina'] = isset($s['disegno_pagina']) && $s['disegno_pagina'] !== null
            ? (int)$s['disegno_pagina'] : null;
        unset($s['ultimo_msg']);
        return $s;
    }
}
