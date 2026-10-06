<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Gli avvisi della Zone: un'attivita' assegnata, un modulo da compilare, un
 * cantiere nuovo da vedere.
 *
 * Le stesse regole di chi vede cosa: un'attivita' "solo ufficio" non avvisa
 * nessuno in cantiere, un cliente non viene avvisato dove l'ufficio non ha
 * acceso la condivisione. Chi ha fatto la cosa non avvisa se stesso.
 *
 * Nella lingua di ciascuno, e best-effort: chi chiama lo fa dopo aver
 * salvato, e un avviso che non parte non deve far fallire il salvataggio.
 */
final class AvvisiZona
{
    private const LINK = '/io';

    public function __construct(
        private PDO $conn,
        private NotificationService $notifiche,
    ) {}

    /** Un'attivita' creata o riassegnata: a chi tocca. */
    public function attivita(int $taskId, int $daChi): void
    {
        $st = $this->conn->prepare("
            SELECT t.*, w.name AS cantiere, w.zone_cliente
            FROM bb_zone_tasks t JOIN bb_worksites w ON w.id = t.worksite_id
            WHERE t.id = :id
        ");
        $st->execute([':id' => $taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }

        $ruoli = match ((string)$t['assegnata_a']) {
            'squadra' => [Accesso::CAPO, Accesso::OPERAIO],
            'capi'    => [Accesso::CAPO],
            'cliente' => [Accesso::CLIENTE],
            default   => [],
        };
        $persone = $this->persone((int)$t['worksite_id'], $ruoli, (int)($t['assignee_user_id'] ?? 0) ?: null);

        foreach ($persone as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            if ($p['ruolo'] === Accesso::CLIENTE && empty($t['zone_cliente'])) continue;
            if (!Accesso::vedeVisibilita($p['ruolo'], $t['visibilita'] ?? null)) continue;
            $this->manda($p, 'zona_attivita_t', 'zona_attivita', [
                'cantiere' => $t['cantiere'],
                'nome'     => mb_substr((string)$t['name'], 0, 120),
            ], (int)$t['priority'] >= 2 ? 'high' : 'normal', $daChi);
        }
    }

    /** Un modulo assegnato: a chi lo deve compilare. */
    public function modulo(int $assegnazioneId, int $daChi): void
    {
        $st = $this->conn->prepare("
            SELECT fa.*, t.name AS modulo, w.name AS cantiere, w.zone_cliente
            FROM bb_zone_form_assegnazioni fa
            JOIN bb_zone_form_templates t ON t.id = fa.template_id
            JOIN bb_worksites w ON w.id = fa.worksite_id
            WHERE fa.id = :id
        ");
        $st->execute([':id' => $assegnazioneId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            return;
        }
        $persone = $this->persone(
            (int)$a['worksite_id'],
            $a['a_ruolo'] && $a['a_ruolo'] !== 'ufficio' ? [(string)$a['a_ruolo']] : [],
            (int)($a['a_user_id'] ?? 0) ?: null,
        );
        foreach ($persone as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            if ($p['ruolo'] === Accesso::CLIENTE && empty($a['zone_cliente'])) continue;
            $this->manda($p, 'zona_modulo_t', 'zona_modulo', [
                'cantiere' => $a['cantiere'],
                'modulo'   => $a['modulo'],
            ], 'normal', $daChi);
        }
    }

    /** Qualcuno e' stato aggiunto a un cantiere. */
    public function accesso(int $worksiteId, int $userId, int $daChi): void
    {
        $st = $this->conn->prepare("
            SELECT u.id AS user_id, u.lingua, a.ruolo, w.name AS cantiere, w.zone_cliente
            FROM bb_zone_accessi a
            JOIN bb_users u ON u.id = a.user_id
            JOIN bb_worksites w ON w.id = a.worksite_id
            WHERE a.worksite_id = :w AND a.user_id = :u AND u.active = 'Y' AND u.removed = 'N'
        ");
        $st->execute([':w' => $worksiteId, ':u' => $userId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        // al cliente lo si dice quando l'ufficio accende la condivisione,
        // non prima: un cantiere che non apre niente fa pensare a un guasto
        if (!$p || $userId === $daChi || ($p['ruolo'] === Accesso::CLIENTE && empty($p['zone_cliente']))) {
            return;
        }
        $this->manda($p, 'zona_accesso_t', 'zona_accesso', ['cantiere' => $p['cantiere']], 'normal', $daChi);
    }

    /**
     * Chi del cantiere ha uno di quei ruoli, piu' una persona precisa.
     *
     * @param string[] $ruoli
     * @return array<int, array{user_id:int, lingua:?string, ruolo:string}>
     */
    private function persone(int $worksiteId, array $ruoli, ?int $persona): array
    {
        $cond = [];
        $par  = [':w' => $worksiteId];
        if ($ruoli) {
            $segni = [];
            foreach (array_values($ruoli) as $i => $r) {
                $segni[] = ":r$i";
                $par[":r$i"] = $r;
            }
            $cond[] = 'a.ruolo IN (' . implode(',', $segni) . ')';
        }
        if ($persona) {
            $cond[] = 'a.user_id = :p';
            $par[':p'] = $persona;
        }
        if (!$cond) {
            return [];
        }
        $st = $this->conn->prepare("
            SELECT a.user_id, u.lingua, a.ruolo
            FROM bb_zone_accessi a JOIN bb_users u ON u.id = a.user_id
            WHERE a.worksite_id = :w AND u.active = 'Y' AND u.removed = 'N'
              AND (" . implode(' OR ', $cond) . ")
        ");
        $st->execute($par);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function manda(array $p, string $titolo, string $corpo, array $valori, string $priorita, int $daChi): void
    {
        $this->notifiche->create(
            (int)$p['user_id'],
            Lingua::testo($titolo, $p['lingua'] ?? null),
            Lingua::testo($corpo, $p['lingua'] ?? null, $valori),
            self::LINK,
            'zone',
            $priorita,
            $daChi ?: null,
        );
    }
}
