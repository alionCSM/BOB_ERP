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
    public const CONTESTI = ['chat', 'segnalazione', 'segnalazione_nuova', 'attivita', 'cartella', 'richiesta', 'file'];

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
        } elseif ($contesto === 'segnalazione_nuova') {
            // una segnalazione che si sta scrivendo: chi la vedra' con quella visibilita'
            $s = ['worksite_id' => $worksiteId, 'visibilita' => (string)($dove['vis'] ?? 'tutti'), 'created_by' => $io];
            $persone = (new Segnalazioni($this->conn, $messaggi))->persone($s);
        } elseif ($contesto === 'cartella') {
            $persone = $this->lettoriCartella($worksiteId, (int)($dove['id'] ?? 0) ?: null, $messaggi);
        } elseif ($contesto === 'richiesta') {
            $st = $this->conn->prepare('SELECT folder_id, created_by FROM bb_zone_richieste WHERE id = :r AND worksite_id = :w');
            $st->execute([':r' => (int)($dove['id'] ?? 0), ':w' => $worksiteId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $persone = $this->lettoriCartella($worksiteId, $r['folder_id'] !== null ? (int)$r['folder_id'] : null, $messaggi);
                // chi l'ha aperta la segue sempre
                foreach ($messaggi->personeRuoli($worksiteId, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE]) as $p) {
                    if ((int)$p['user_id'] === (int)$r['created_by']) $persone[] = $p;
                }
            }
        } elseif ($contesto === 'file') {
            $persone = $this->lettoriFile($worksiteId, (int)($dove['id'] ?? 0), $messaggi);
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

    /** Un utente per le domande di Condivisione: basta id e tipo. */
    private function utente(int $id): object
    {
        static $cache = [];
        if (!isset($cache[$id])) {
            $st = $this->conn->prepare('SELECT id, type FROM bb_users WHERE id = :u');
            $st->execute([':u' => $id]);
            $cache[$id] = (object)($st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id]);
        }
        return $cache[$id];
    }

    /** La cartella e tutte quelle che la contengono. @return array<int, array> */
    private function catena(int $w, int $folderId): array
    {
        $st = $this->conn->prepare('SELECT id, parent_id, created_by FROM bb_zone_folders WHERE id = :c AND worksite_id = :w');
        $fuori = [];
        for ($id = $folderId, $i = 0; $id && $i < 20; $i++) {
            $st->execute([':c' => $id, ':w' => $w]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) break;
            $fuori[] = $c;
            $id = $c['parent_id'] ? (int)$c['parent_id'] : 0;
        }
        return $fuori;
    }

    /** Chi vede una cartella; in cima ai Documenti (null) CSM e il committente. */
    private function lettoriCartella(int $w, ?int $folderId, Messaggi $messaggi): array
    {
        if ($folderId === null) {
            return $messaggi->personeRuoli($w, [Accesso::UFFICIO, Accesso::CLIENTE]);
        }
        $catena = $this->catena($w, $folderId);
        if (!$catena) {
            return [];
        }
        $cond = new Condivisione($this->conn, new Accesso($this->conn));
        $chi = [];
        foreach ($messaggi->personeRuoli($w, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE]) as $p) {
            foreach ($catena as $c) {
                if (!$cond->vede($this->utente((int)$p['user_id']), $w, 'cartella', (int)$c['id'], (int)($c['created_by'] ?? 0))) continue 2;
            }
            $chi[] = $p;
        }
        return $chi;
    }

    /** Chi vede un documento: condiviso con lui, o chi vede la sua cartella. */
    private function lettoriFile(int $w, int $fileId, Messaggi $messaggi): array
    {
        $st = $this->conn->prepare('SELECT id, folder_id, uploaded_by FROM bb_zone_files WHERE id = :f AND worksite_id = :w');
        $st->execute([':f' => $fileId, ':w' => $w]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return [];
        }
        $cond = new Condivisione($this->conn, new Accesso($this->conn));
        $dallaCartella = [];
        if ($f['folder_id'] !== null) {
            foreach ($this->lettoriCartella($w, (int)$f['folder_id'], $messaggi) as $p) $dallaCartella[(int)$p['user_id']] = true;
        }
        $chi = [];
        foreach ($messaggi->personeRuoli($w, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE]) as $p) {
            $uid = (int)$p['user_id'];
            if (isset($dallaCartella[$uid]) || $cond->vede($this->utente($uid), $w, 'file', $fileId, (int)($f['uploaded_by'] ?? 0))) {
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
