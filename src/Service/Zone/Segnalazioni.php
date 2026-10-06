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
 * Chi la vede: l'ufficio, chi l'ha fatta, e la visibilita' scelta:
 *   - da capo o operaio nasce "capi" (ufficio e capi): il capo deve saperlo,
 *     il resto della squadra no finche' l'ufficio non decide;
 *   - dal cliente nasce "cliente" (ufficio e cliente): un reclamo del
 *     cliente non lo legge la squadra.
 * L'ufficio puo' allargarla (alla squadra, a tutti) quando serve.
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
        return in_array($ruolo, [Accesso::UFFICIO, Accesso::CAPO], true);
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
                   (SELECT COUNT(*) FROM bb_zone_messaggi m
                     WHERE m.segnalazione_id = s.id AND m.foto IS NOT NULL AND m.eliminato_at IS NULL) AS foto,
                   (SELECT MAX(m.id) FROM bb_zone_messaggi m WHERE m.segnalazione_id = s.id) AS ultimo_msg
            FROM   bb_zone_segnalazioni s
            JOIN   bb_users u ON u.id = s.created_by
            LEFT JOIN bb_users p ON p.id = s.presa_da
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
                   0 AS foto, NULL AS ultimo_msg
            FROM   bb_zone_segnalazioni s
            JOIN   bb_users u ON u.id = s.created_by
            LEFT JOIN bb_users p ON p.id = s.presa_da
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
        $vis = match ($ruolo) {
            Accesso::CLIENTE => 'cliente',
            Accesso::UFFICIO => in_array($dati['visibilita'] ?? '', Accesso::VISIBILITA, true) ? $dati['visibilita'] : 'capi',
            default          => 'capi',
        };

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
            [Accesso::CAPO, Accesso::OPERAIO, Accesso::CLIENTE],
            fn($r) => Accesso::vedeVisibilita($r, $s['visibilita'])
        ));
        $chi = [];
        foreach (array_merge($this->messaggi->ufficio($w), $this->messaggi->personeRuoli($w, $ruoli)) as $p) {
            $chi[$p['user_id']] = $p;
        }
        if (!isset($chi[(int)$s['created_by']])) {
            $st = $this->conn->prepare("
                SELECT u.id AS user_id, u.lingua, COALESCE(a.ruolo, 'ufficio') AS ruolo,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS nome
                FROM bb_users u LEFT JOIN bb_zone_accessi a ON a.user_id = u.id AND a.worksite_id = :w
                WHERE u.id = :u
            ");
            $st->execute([':w' => $w, ':u' => (int)$s['created_by']]);
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
        foreach ($this->persone($s) as $p) {
            if ($p['user_id'] === (int)$s['created_by']) continue;
            // una segnalazione da squadra la legge chi la gestisce, non tutti:
            // l'operaio la vedra' se l'ufficio la allarga, senza un push
            if (!in_array($p['ruolo'], [Accesso::UFFICIO, Accesso::CAPO], true)) continue;
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
        unset($s['ultimo_msg']);
        return $s;
    }
}
