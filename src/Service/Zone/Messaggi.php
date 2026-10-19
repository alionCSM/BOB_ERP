<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * I messaggi della Zone: la chat del cantiere e il filo di ogni segnalazione.
 *
 * La chat ha tre canali, uno per chi legge:
 *   - squadra: ufficio, capi e operai — "domani si attacca alle sette"
 *   - capi:    ufficio e capi — le cose da coordinare
 *   - cliente: ufficio, capi e cliente — solo se l'ufficio ha acceso
 *              "Condividi col cliente" sul cantiere
 * Un operaio non vede il canale dei capi, il cliente vede solo il suo.
 *
 * Cose da cantiere che una chat qualsiasi non ha:
 *   - avvisi fissati in alto (capo e ufficio): "gru il 12 alle 7";
 *   - messaggi rapidi ("Materiale arrivato", "Finito per oggi"): si salva
 *     la chiave e ognuno lo legge nella sua lingua, in una squadra dove si
 *     parla italiano, albanese e rumeno;
 *   - "letto da": chi ha visto l'avviso e chi no.
 *
 * I push: uno per canale finche' uno non apre, non uno per messaggio. Una
 * chat di cantiere che suona trenta volte al giorno si silenzia, e poi non
 * si sente nemmeno l'avviso che serviva. Gli avvisi fissati suonano sempre.
 */
final class Messaggi
{
    public const CANALI = ['squadra', 'capi', 'cliente'];

    /** Le chiavi dei messaggi rapidi: il testo lo mette chi legge. */
    public const RAPIDI = [
        'materiale_arrivato', 'serve_materiale', 'finito_oggi', 'arrivati',
        'ritardo', 'pausa_meteo', 'serve_aiuto', 'ok',
    ];

    public function __construct(private PDO $conn) {}

    /**
     * I canali che un ruolo apre su questo cantiere.
     *
     * @return string[]
     */
    public function canali(string $ruolo, int $worksiteId): array
    {
        $conCliente = $this->clienteAcceso($worksiteId);
        $tutti = match ($ruolo) {
            Accesso::UFFICIO, Accesso::CAPO => ['squadra', 'capi', 'cliente'],
            Accesso::OPERAIO => ['squadra'],
            Accesso::CLIENTE => ['cliente'],
            default => [],
        };
        return array_values(array_filter($tutti, fn($c) => $c !== 'cliente' || $conCliente));
    }

    public static function puoFissare(string $ruolo): bool
    {
        return in_array($ruolo, [Accesso::UFFICIO, Accesso::CAPO], true);
    }

    // ── Leggere ─────────────────────────────────────────────────────────────

    /**
     * I messaggi di un canale o di una segnalazione, dal piu' vecchio.
     * Con $dopo solo quelli nuovi (l'app chiede ogni pochi secondi).
     *
     * @return array<int, array<string, mixed>>
     */
    public function elenco(int $worksiteId, ?string $canale, ?int $segnalazioneId, int $dopo = 0, int $quanti = 80): array
    {
        [$dove, $par] = $this->dove($worksiteId, $canale, $segnalazioneId);
        $par[':dopo'] = $dopo;
        $st = $this->conn->prepare("
            SELECT * FROM (
                SELECT m.id, m.user_id, m.testo, m.rapido, m.foto IS NOT NULL AS ha_foto, m.sistema,
                       m.fissato, m.created_at, m.eliminato_at,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS autore,
                       u.type AS autore_tipo
                FROM   bb_zone_messaggi m
                LEFT JOIN bb_users u ON u.id = m.user_id
                WHERE  $dove AND m.id > :dopo
                ORDER BY m.id DESC
                LIMIT " . max(1, min(200, $quanti)) . "
            ) x ORDER BY id
        ");
        $st->execute($par);
        return array_map([$this, 'pulisci'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> gli avvisi fissati del canale */
    public function fissati(int $worksiteId, string $canale): array
    {
        $st = $this->conn->prepare("
            SELECT m.id, m.user_id, m.testo, m.rapido, m.foto IS NOT NULL AS ha_foto, m.sistema,
                   m.fissato, m.created_at, m.eliminato_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS autore,
                   u.type AS autore_tipo
            FROM   bb_zone_messaggi m LEFT JOIN bb_users u ON u.id = m.user_id
            WHERE  m.worksite_id = :w AND m.canale = :c AND m.fissato = 1 AND m.eliminato_at IS NULL
            ORDER BY m.id DESC
        ");
        $st->execute([':w' => $worksiteId, ':c' => $canale]);
        return array_map([$this, 'pulisci'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function trova(int $id): ?array
    {
        $st = $this->conn->prepare('SELECT * FROM bb_zone_messaggi WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Scrivere ────────────────────────────────────────────────────────────

    public function scrivi(
        int $worksiteId,
        ?string $canale,
        ?int $segnalazioneId,
        ?int $userId,
        ?string $testo,
        ?string $rapido = null,
        ?string $foto = null,
        bool $sistema = false,
    ): int {
        $testo = $testo !== null ? trim($testo) : null;
        // le righe di BOB ("Presa in carico da...") hanno chiavi loro
        if ($rapido !== null && !in_array($rapido, self::RAPIDI, true)
            && !($sistema && in_array($rapido, ['sys_presa', 'sys_risolta', 'sys_aperta'], true))) {
            $rapido = null;
        }
        if (($testo === null || $testo === '') && $rapido === null && $foto === null) {
            throw new \RuntimeException('Il messaggio e\' vuoto');
        }
        $this->conn->prepare("
            INSERT INTO bb_zone_messaggi (worksite_id, canale, segnalazione_id, user_id, testo, rapido, foto, sistema)
            VALUES (:w, :c, :s, :u, :t, :r, :f, :si)
        ")->execute([
            ':w' => $worksiteId, ':c' => $canale, ':s' => $segnalazioneId, ':u' => $userId,
            ':t' => $testo !== null && $testo !== '' ? mb_substr($testo, 0, 4000) : null,
            ':r' => $rapido, ':f' => $foto, ':si' => $sistema ? 1 : 0,
        ]);
        $id = (int)$this->conn->lastInsertId();
        // chi scrive ha letto fino al suo messaggio
        if ($userId) {
            $this->segnaLetto($userId, $worksiteId, self::chiave($canale, $segnalazioneId), $id);
        }
        return $id;
    }

    public function fissa(int $id, bool $si, int $daChi): void
    {
        $this->conn->prepare('UPDATE bb_zone_messaggi SET fissato = :f, fissato_da = :u WHERE id = :id')
            ->execute([':f' => $si ? 1 : 0, ':u' => $si ? $daChi : null, ':id' => $id]);
    }

    /** Il messaggio resta (con "eliminato"), sparisce il contenuto. */
    public function elimina(int $id): void
    {
        $this->conn->prepare("
            UPDATE bb_zone_messaggi SET eliminato_at = NOW(), fissato = 0 WHERE id = :id
        ")->execute([':id' => $id]);
    }

    // ── Letture ─────────────────────────────────────────────────────────────

    public static function chiave(?string $canale, ?int $segnalazioneId): string
    {
        return $segnalazioneId ? 's' . $segnalazioneId : (string)$canale;
    }

    public function segnaLetto(int $userId, int $worksiteId, string $chiave, int $finoA): void
    {
        $this->conn->prepare("
            INSERT INTO bb_zone_letture (user_id, worksite_id, chiave, ultimo_id, letto_at)
            VALUES (:u, :w, :k, :id, NOW())
            ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(ultimo_id, VALUES(ultimo_id)), letto_at = NOW()
        ")->execute([':u' => $userId, ':w' => $worksiteId, ':k' => $chiave, ':id' => $finoA]);
    }

    /**
     * Quanti messaggi non letti per canale (e per segnalazione, se date).
     * I propri non contano.
     *
     * @param string[] $chiavi
     * @return array<string, int>
     */
    public function nonLetti(int $userId, int $worksiteId, array $chiavi): array
    {
        $fuori = [];
        foreach ($chiavi as $k) {
            $segn  = str_starts_with($k, 's') ? (int)substr($k, 1) : null;
            $canale = $segn ? null : $k;
            [$dove, $par] = $this->dove($worksiteId, $canale, $segn);
            $par[':u'] = $userId;
            $par[':u2'] = $userId;
            $par[':w2'] = $worksiteId;
            $par[':k'] = $k;
            $st = $this->conn->prepare("
                SELECT COUNT(*) FROM bb_zone_messaggi m
                WHERE $dove AND m.eliminato_at IS NULL
                  AND (m.user_id IS NULL OR m.user_id <> :u)
                  AND m.id > COALESCE((SELECT l.ultimo_id FROM bb_zone_letture l
                                       WHERE l.user_id = :u2 AND l.worksite_id = :w2 AND l.chiave = :k), 0)
            ");
            $st->execute($par);
            $fuori[$k] = (int)$st->fetchColumn();
        }
        return $fuori;
    }

    /**
     * Chi ha letto un messaggio, fra quelli che lo potevano leggere.
     *
     * @return array{letto:string[], non_letto:string[]}
     */
    public function lettoDa(array $messaggio, array $persone): array
    {
        $chiave = self::chiave($messaggio['canale'] ?? null, isset($messaggio['segnalazione_id']) ? (int)$messaggio['segnalazione_id'] : null);
        $st = $this->conn->prepare("
            SELECT user_id FROM bb_zone_letture
            WHERE worksite_id = :w AND chiave = :k AND ultimo_id >= :id
        ");
        $st->execute([':w' => (int)$messaggio['worksite_id'], ':k' => $chiave, ':id' => (int)$messaggio['id']]);
        $hanno = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);

        $letto = $non = [];
        foreach ($persone as $p) {
            if ((int)$p['user_id'] === (int)($messaggio['user_id'] ?? 0)) continue;
            if (isset($hanno[(int)$p['user_id']])) {
                $letto[] = $p['nome'];
            } else {
                $non[] = $p['nome'];
            }
        }
        return ['letto' => $letto, 'non_letto' => $non];
    }

    /**
     * Si puo' mandare un push a questa persona per questo canale? Si', se dal
     * push precedente ha letto; altrimenti ne ha gia' uno che aspetta.
     * Se si', segna che e' partito.
     */
    public function prenotaAvviso(int $userId, int $worksiteId, string $chiave, bool $sempre = false): bool
    {
        $st = $this->conn->prepare("
            SELECT avvisato_at, letto_at FROM bb_zone_letture
            WHERE user_id = :u AND worksite_id = :w AND chiave = :k
        ");
        $st->execute([':u' => $userId, ':w' => $worksiteId, ':k' => $chiave]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        $giaInAttesa = $r && $r['avvisato_at'] !== null
            && ($r['letto_at'] === null || $r['letto_at'] < $r['avvisato_at']);
        if ($giaInAttesa && !$sempre) {
            return false;
        }
        $this->conn->prepare("
            INSERT INTO bb_zone_letture (user_id, worksite_id, chiave, ultimo_id, avvisato_at)
            VALUES (:u, :w, :k, 0, NOW())
            ON DUPLICATE KEY UPDATE avvisato_at = NOW()
        ")->execute([':u' => $userId, ':w' => $worksiteId, ':k' => $chiave]);
        return true;
    }

    // ── Chi c'e' ────────────────────────────────────────────────────────────

    /**
     * Chi legge un canale: le persone del cantiere coi ruoli giusti, piu'
     * l'ufficio del cantiere.
     *
     * @return array<int, array{user_id:int, nome:string, lingua:?string, ruolo:string}>
     */
    public function personeCanale(int $worksiteId, string $canale): array
    {
        $ruoli = match ($canale) {
            'squadra' => [Accesso::CAPO, Accesso::OPERAIO],
            'capi'    => [Accesso::CAPO],
            'cliente' => [Accesso::CAPO, Accesso::CLIENTE],
            default   => [],
        };
        return array_merge($this->personeRuoli($worksiteId, $ruoli), $this->ufficio($worksiteId));
    }

    /**
     * @param string[] $ruoli
     * @return array<int, array{user_id:int, nome:string, lingua:?string, ruolo:string}>
     */
    public function personeRuoli(int $worksiteId, array $ruoli): array
    {
        if (!$ruoli) {
            return [];
        }
        $segni = [];
        $par = [':w' => $worksiteId];
        foreach (array_values($ruoli) as $i => $r) {
            $segni[] = ":r$i";
            $par[":r$i"] = $r;
        }
        $st = $this->conn->prepare("
            SELECT a.user_id, a.ruolo, u.lingua,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS nome
            FROM   bb_zone_accessi a
            JOIN   bb_users u ON u.id = a.user_id
            JOIN   bb_worksites w ON w.id = a.worksite_id
            WHERE  a.worksite_id = :w AND u.active = 'Y' AND u.removed = 'N'
              AND  a.ruolo IN (" . implode(',', $segni) . ")
              AND  (a.ruolo <> 'cliente' OR w.zone_cliente = 1)
            ORDER BY nome
        ");
        $st->execute($par);
        return array_map(fn($p) => ['user_id' => (int)$p['user_id']] + $p, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * L'ufficio di un cantiere: chi ha il permesso Zone ed e' assegnato al
     * cantiere in BOB. Se non c'e' nessuno, tutti quelli col permesso Zone:
     * una segnalazione che non arriva a nessuno e' peggio di una in piu'.
     *
     * @return array<int, array{user_id:int, nome:string, lingua:?string, ruolo:string}>
     */
    public function ufficio(int $worksiteId): array
    {
        $conZone = "
            SELECT user_id FROM bb_user_company_permissions WHERE module = 'zone' AND allowed = 1
            UNION
            SELECT user_id FROM bb_user_permissions WHERE module = 'zone' AND allowed = 1
        ";
        $base = "
            SELECT DISTINCT u.id AS user_id, u.lingua, 'ufficio' AS ruolo,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS nome
            FROM   bb_users u
            WHERE  u.active = 'Y' AND u.removed = 'N'
              AND  u.id IN ($conZone)
              AND  u.id NOT IN (SELECT user_id FROM bb_zone_accessi WHERE worksite_id = :w0)
        ";
        $st = $this->conn->prepare($base . ' AND u.id IN (SELECT user_id FROM bb_worksite_users WHERE worksite_id = :w)');
        $st->execute([':w0' => $worksiteId, ':w' => $worksiteId]);
        $chi = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$chi) {
            $st = $this->conn->prepare($base);
            $st->execute([':w0' => $worksiteId]);
            $chi = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return array_map(fn($p) => ['user_id' => (int)$p['user_id']] + $p, $chi);
    }

    // ── Interni ─────────────────────────────────────────────────────────────

    /** @return array{0:string, 1:array<string, mixed>} */
    private function dove(int $worksiteId, ?string $canale, ?int $segnalazioneId): array
    {
        if ($segnalazioneId) {
            return ['m.worksite_id = :w AND m.segnalazione_id = :s', [':w' => $worksiteId, ':s' => $segnalazioneId]];
        }
        return ['m.worksite_id = :w AND m.canale = :c AND m.segnalazione_id IS NULL', [':w' => $worksiteId, ':c' => (string)$canale]];
    }

    private function clienteAcceso(int $worksiteId): bool
    {
        $st = $this->conn->prepare('SELECT zone_cliente FROM bb_worksites WHERE id = :w');
        $st->execute([':w' => $worksiteId]);
        return (bool)$st->fetchColumn();
    }

    /** Un messaggio eliminato non porta piu' niente, solo che c'era. */
    private function pulisci(array $m): array
    {
        $m['id']       = (int)$m['id'];
        $m['user_id']  = $m['user_id'] !== null ? (int)$m['user_id'] : null;
        $m['ha_foto']  = (bool)$m['ha_foto'];
        $m['sistema']  = (bool)$m['sistema'];
        $m['fissato']  = (bool)$m['fissato'];
        $m['eliminato'] = $m['eliminato_at'] !== null;
        unset($m['eliminato_at']);
        if ($m['eliminato']) {
            $m['testo'] = null;
            $m['rapido'] = null;
            $m['ha_foto'] = false;
        }
        $m['autore'] = trim((string)$m['autore']) ?: null;
        return $m;
    }
}
