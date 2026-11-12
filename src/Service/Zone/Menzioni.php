<?php

declare(strict_types=1);

namespace App\Service\Zone;

use App\Service\Lingua;
use App\Service\Notifications\NotificationService;
use PDO;

/**
 * Le menzioni con @ nei messaggi della Zone: chi si puo' menzionare e
 * l'avviso a chi e' stato menzionato.
 *
 * Si menziona solo chi legge gia' quella conversazione. Nella chat
 * "Cliente" (CSM e committente) la squadra non compare; in una nota
 * interna compare solo CSM; in un'attivita' solo chi la vede. Una
 * menzione non apre niente a nessuno: avvisa chi c'era gia'.
 *
 * Il client manda gli id scelti dall'elenco; qui si tengono solo quelli
 * che sono davvero fra i lettori e il cui nome compare nel testo come
 * "@Nome Cognome" (chi cancella la menzione dal testo non avvisa).
 */
final class Menzioni
{
    public const CONTESTI = ['chat', 'segnalazione', 'attivita'];

    public function __construct(private PDO $conn) {}

    /**
     * Chi legge una conversazione, tranne chi chiede.
     *
     * @param array{canale?:string, id?:int, interna?:bool} $dove
     * @return array<int, array{id:int, nome:string, ruolo:string, lingua:?string}>
     */
    public function lettori(int $worksiteId, string $contesto, array $dove, int $io): array
    {
        $messaggi = new Messaggi($this->conn);
        $persone  = [];

        if ($contesto === 'chat') {
            $persone = $messaggi->personeCanale($worksiteId, (string)($dove['canale'] ?? ''));
        } elseif ($contesto === 'segnalazione') {
            $s = (new Segnalazioni($this->conn, $messaggi))->trova((int)($dove['id'] ?? 0));
            if ($s && (int)$s['worksite_id'] === $worksiteId) {
                $persone = (new Segnalazioni($this->conn, $messaggi))->persone($s);
            }
        } elseif ($contesto === 'attivita') {
            $persone = $this->lettoriAttivita($worksiteId, (int)($dove['id'] ?? 0), !empty($dove['interna']), $messaggi);
        }

        $fuori = [];
        foreach ($persone as $p) {
            $id = (int)$p['user_id'];
            if ($id === $io || $id <= 0 || trim((string)($p['nome'] ?? '')) === '') {
                continue;
            }
            $fuori[$id] = [
                'id'     => $id,
                'nome'   => trim((string)$p['nome']),
                'ruolo'  => (string)($p['ruolo'] ?? ''),
                'lingua' => $p['lingua'] ?? null,
            ];
        }
        usort($fuori, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
        return array_values($fuori);
    }

    /**
     * Gli id menzionati davvero: fra i lettori, e col nome scritto nel testo.
     *
     * @param int[] $scelti
     * @param array<int, array{id:int, nome:string}> $lettori
     * @return array<int, array{id:int, nome:string, ruolo:string, lingua:?string}>
     */
    public static function valide(string $testo, array $scelti, array $lettori): array
    {
        $scelti = array_flip(array_map('intval', $scelti));
        $fuori  = [];
        foreach ($lettori as $p) {
            if (isset($scelti[$p['id']]) && mb_stripos($testo, '@' . $p['nome']) !== false) {
                $fuori[] = $p;
            }
        }
        return $fuori;
    }

    /**
     * Avvisa i menzionati. Best-effort: chi chiama ha gia' salvato.
     *
     * @param array<int, array{id:int, lingua:?string}> $menzionati
     * @param string $dove ancora della Zone: chat-squadra, segnalazione-5, attivita-12
     */
    public function avvisa(
        NotificationService $n,
        int $worksiteId,
        array $menzionati,
        int $daChi,
        string $dove,
        string $testo
    ): void {
        if (!$menzionati) {
            return;
        }
        $cantiere = $this->valore('SELECT name FROM bb_worksites WHERE id = :x', $worksiteId);
        $chi      = $this->valore(
            "SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) FROM bb_users WHERE id = :x",
            $daChi
        ) ?: 'BOB';
        foreach ($menzionati as $p) {
            $n->create(
                (int)$p['id'],
                Lingua::testo('zona_menzione_t', $p['lingua'] ?? null, ['chi' => $chi]),
                Lingua::testo('zona_menzione', $p['lingua'] ?? null, [
                    'cantiere' => $cantiere,
                    'chi'      => $chi,
                    'testo'    => mb_substr($testo, 0, 140),
                ]),
                Segnalazioni::link($worksiteId, $dove),
                'zone',
                'high',
                $daChi ?: null,
            );
        }
    }

    /**
     * Chi legge i messaggi di un'attivita': CSM sempre; gli altri se la
     * vedono, e non per una nota interna.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lettoriAttivita(int $w, int $taskId, bool $interna, Messaggi $messaggi): array
    {
        $st = $this->conn->prepare('SELECT created_by FROM bb_zone_tasks WHERE id = :t AND worksite_id = :w');
        $st->execute([':t' => $taskId, ':w' => $w]);
        $creatoDa = $st->fetchColumn();
        if ($creatoDa === false) {
            return [];
        }
        $chi = $messaggi->ufficio($w);
        if ($interna) {
            return $chi;
        }
        $cond = new Condivisione($this->conn, new Accesso($this->conn));
        $utenti = $this->conn->prepare('SELECT id, type FROM bb_users WHERE id = :u');
        foreach ($messaggi->personeRuoli($w, [Accesso::CANTIERE, Accesso::CLIENTE]) as $p) {
            $utenti->execute([':u' => (int)$p['user_id']]);
            $u = (object)($utenti->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$p['user_id']]);
            if ($cond->vede($u, $w, 'attivita', $taskId, (int)$creatoDa)) {
                $chi[] = $p;
            }
        }
        return $chi;
    }

    private function valore(string $sql, int $id): string
    {
        $st = $this->conn->prepare($sql);
        $st->execute([':x' => $id]);
        return (string)($st->fetchColumn() ?: '');
    }
}
