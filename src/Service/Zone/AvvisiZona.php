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
            'squadra', 'capi', 'cantiere' => [Accesso::CANTIERE],
            'cliente' => [Accesso::CLIENTE],
            // "all'ufficio" prima non avvisava nessuno: l'ufficio non era un
            // ruolo nell'elenco, era chiunque avesse il modulo, e non si
            // sapeva a chi mandarla. Adesso sono le persone dell'ufficio
            // messe su questo cantiere.
            'ufficio' => [Accesso::UFFICIO],
            default   => [],
        };
        $persone = $this->persone((int)$t['worksite_id'], $ruoli, (int)($t['assignee_user_id'] ?? 0) ?: null);
        // Avvisare qualcuno di una cosa che poi non riesce ad aprire e' il
        // modo piu' sicuro di fargli pensare che BOB sia rotto: la notifica
        // va solo a chi l'attivita' e' stata condivisa.
        $condivisa = $this->condivisaCon('attivita', $taskId);
        $parti     = (new Condivisione($this->conn, new Accesso($this->conn)))
                         ->partiDi('attivita', [$taskId])[$taskId] ?? [];

        foreach ($persone as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            if ($p['ruolo'] === Accesso::CLIENTE && empty($t['zone_cliente'])) continue;
            // l'ufficio vede tutto senza righe di condivisione: senza questa
            // eccezione un'attivita' "per l'ufficio" non avvisava nessuno
            if ($p['ruolo'] !== Accesso::UFFICIO && !isset($condivisa[(int)$p['user_id']])
                && !in_array($p['ruolo'], $parti, true)) continue;
            $p['worksite_id'] = (int)$t['worksite_id'];
            $p['dove'] = 'attivita-' . $taskId;
            $this->manda($p, 'zona_attivita_t', 'zona_attivita', [
                'cantiere' => $t['cantiere'],
                'nome'     => mb_substr((string)$t['name'], 0, 120),
            ], (int)$t['priority'] >= 2 ? 'high' : 'normal', $daChi);
        }
    }

    /**
     * Un messaggio sul filo di un'attivita'.
     *
     * Prima non avvisava nessuno: il cliente scriveva una domanda sotto
     * un'attivita' e nessuno lo sapeva finche' non ci ripassava. Avvisa chi
     * ci e' dentro davvero: chi l'ha creata, la persona a cui tocca, e
     * l'ufficio del cantiere quando scrive qualcuno da fuori. Non tutto il
     * gruppo: una squadra di dieci con un avviso a ogni "ok" la silenzia.
     * Una nota interna resta all'ufficio anche qui.
     */
    public function commento(int $taskId, int $daChi, string $testo, bool $interna, ?int $commentoId = null): void
    {
        $st = $this->conn->prepare("
            SELECT t.*, w.name AS cantiere FROM bb_zone_tasks t JOIN bb_worksites w ON w.id = t.worksite_id
            WHERE t.id = :id
        ");
        $st->execute([':id' => $taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }
        $w = (int)$t['worksite_id'];
        $acc = new Accesso($this->conn);
        $ruoloAutore = $this->ruoloDi($w, $daChi);

        $persone = [];
        foreach ([(int)($t['created_by'] ?? 0), (int)($t['assignee_user_id'] ?? 0)] as $uid) {
            if ($uid > 0) {
                foreach ($this->persone($w, [], $uid) as $p) $persone[(int)$p['user_id']] = $p;
            }
        }
        if ($ruoloAutore !== Accesso::UFFICIO) {
            foreach ($this->persone($w, [Accesso::UFFICIO], null) as $p) $persone[(int)$p['user_id']] = $p;
        }

        $cond   = new Condivisione($this->conn, $acc);
        $chi    = $this->nome($daChi);
        foreach ($persone as $uid => $p) {
            if ($uid === $daChi) continue;
            if ($interna && $p['ruolo'] !== Accesso::UFFICIO) continue;
            // solo chi vede l'attivita'
            if ($p['ruolo'] !== Accesso::UFFICIO
                && !$cond->vede($this->utente($uid), $w, 'attivita', $taskId, (int)($t['created_by'] ?? 0))) continue;
            $p['worksite_id'] = $w;
            // al commento preciso: attivita-12.c45
            $p['dove'] = 'attivita-' . $taskId . ($commentoId ? '.c' . $commentoId : '');
            $this->manda($p, 'zona_commento_t', 'zona_commento', [
                'cantiere' => $t['cantiere'],
                'nome'     => mb_substr((string)$t['name'], 0, 80),
                'chi'      => $chi,
                'testo'    => mb_substr($testo, 0, 140),
            ], 'normal', $daChi);
        }
    }

    private function ruoloDi(int $w, int $userId): ?string
    {
        $st = $this->conn->prepare('SELECT ruolo FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u');
        $st->execute([':w' => $w, ':u' => $userId]);
        return (new Accesso($this->conn))->normalizza((string)$st->fetchColumn());
    }

    private function nome(int $userId): string
    {
        $st = $this->conn->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) FROM bb_users WHERE id = :u");
        $st->execute([':u' => $userId]);
        return (string)($st->fetchColumn() ?: 'BOB');
    }

    /** Un utente per le domande di Accesso: basta id e tipo. */
    private function utente(int $userId): object
    {
        $st = $this->conn->prepare('SELECT id, type FROM bb_users WHERE id = :u');
        $st->execute([':u' => $userId]);
        return (object)($st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId]);
    }

    /**
     * Una versione nuova di un disegno: a chi lo vede (squadra, cliente,
     * le persone a cui e' condiviso). L'ufficio l'ha caricata lui.
     */
    public function disegno(int $worksiteId, int $docId, int $daChi): void
    {
        $st = $this->conn->prepare('
            SELECT d.file_name, d.created_by, w.name AS cantiere
            FROM bb_worksite_documents d JOIN bb_worksites w ON w.id = d.worksite_id
            WHERE d.id = :d AND d.worksite_id = :w
        ');
        $st->execute([':d' => $docId, ':w' => $worksiteId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return;
        }
        $cond = new Condivisione($this->conn, new Accesso($this->conn));
        foreach ($this->persone($worksiteId, [Accesso::CANTIERE, Accesso::CLIENTE], null) as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            if (!$cond->vede($this->utente((int)$p['user_id']), $worksiteId, 'disegno', $docId, (int)($d['created_by'] ?? 0))) continue;
            $p['worksite_id'] = $worksiteId;
            $p['dove'] = 'disegno-' . $docId;
            $this->manda($p, 'zona_disegno_t', 'zona_disegno', [
                'cantiere' => $d['cantiere'],
                'nome'     => mb_substr((string)$d['file_name'], 0, 100),
            ], 'high', $daChi);
        }
    }

    /**
     * Un disegno caricato per la prima volta: a chi lo vede, CSM compreso
     * (il committente carica le sue tavole). Chi l'ha caricato no.
     */
    public function disegnoNuovo(int $worksiteId, int $docId, int $daChi): void
    {
        $st = $this->conn->prepare('
            SELECT d.file_name, d.created_by, w.name AS cantiere
            FROM bb_worksite_documents d JOIN bb_worksites w ON w.id = d.worksite_id
            WHERE d.id = :d AND d.worksite_id = :w
        ');
        $st->execute([':d' => $docId, ':w' => $worksiteId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return;
        }
        $cond = new Condivisione($this->conn, new Accesso($this->conn));
        foreach ($this->persone($worksiteId, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE], null) as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            if (!$cond->vede($this->utente((int)$p['user_id']), $worksiteId, 'disegno', $docId, (int)($d['created_by'] ?? 0))) continue;
            $p['worksite_id'] = $worksiteId;
            $p['dove'] = 'disegno-' . $docId;
            $this->manda($p, 'zona_disegno_nuovo_t', 'zona_disegno_nuovo', [
                'cantiere' => $d['cantiere'],
                'nome'     => mb_substr((string)$d['file_name'], 0, 100),
            ], 'normal', $daChi);
        }
    }

    /**
     * Un file caricato nella Zone: a chi lo vede — condiviso con lui o con
     * la sua parte, o dentro una cartella che vede. Chi l'ha caricato no.
     */
    public function file(int $worksiteId, int $fileId, int $daChi): void
    {
        $st = $this->conn->prepare('
            SELECT f.file_name, f.folder_id, f.uploaded_by, w.name AS cantiere
            FROM bb_zone_files f JOIN bb_worksites w ON w.id = f.worksite_id
            WHERE f.id = :f AND f.worksite_id = :w
        ');
        $st->execute([':f' => $fileId, ':w' => $worksiteId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return;
        }
        $cartella = null;
        if (!empty($f['folder_id'])) {
            $c = $this->conn->prepare('SELECT id, created_by FROM bb_zone_folders WHERE id = :c');
            $c->execute([':c' => (int)$f['folder_id']]);
            $cartella = $c->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $acc  = new Accesso($this->conn);
        $cond = new Condivisione($this->conn, $acc);
        foreach ($this->persone($worksiteId, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE], null) as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            $u = $this->utente((int)$p['user_id']);
            $vede = $cond->vede($u, $worksiteId, 'file', $fileId, (int)($f['uploaded_by'] ?? 0))
                || ($cartella && $cond->vede($u, $worksiteId, 'cartella', (int)$cartella['id'], (int)($cartella['created_by'] ?? 0)));
            if (!$vede) continue;
            $p['worksite_id'] = $worksiteId;
            $p['dove'] = 'file-' . $fileId;
            $this->manda($p, 'zona_file_t', 'zona_file', [
                'cantiere' => $f['cantiere'],
                'nome'     => mb_substr((string)$f['file_name'], 0, 100),
            ], 'normal', $daChi);
        }
    }

    /**
     * Chi del cantiere, con uno di questi ruoli, vede una cartella (e tutte
     * quelle che la contengono).
     *
     * @param string[] $ruoli
     * @return array<int, array<string, mixed>> per user_id
     */
    private function vedonoCartella(int $worksiteId, ?int $folderId, array $ruoli): array
    {
        // in cima ai Documenti: CSM e il committente
        if ($folderId === null) {
            $chi = [];
            foreach ($this->persone($worksiteId, array_values(array_intersect($ruoli, [Accesso::UFFICIO, Accesso::CLIENTE])), null) as $p) {
                $chi[(int)$p['user_id']] = $p;
            }
            return $chi;
        }
        $catena = [];
        $st = $this->conn->prepare('SELECT id, parent_id, created_by FROM bb_zone_folders WHERE id = :c AND worksite_id = :w');
        $id = $folderId;
        for ($i = 0; $id && $i < 20; $i++) {
            $st->execute([':c' => $id, ':w' => $worksiteId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) break;
            $catena[] = $c;
            $id = $c['parent_id'] ? (int)$c['parent_id'] : 0;
        }
        if (!$catena) {
            return [];
        }
        $cond = new Condivisione($this->conn, new Accesso($this->conn));
        $chi = [];
        foreach ($this->persone($worksiteId, $ruoli, null) as $p) {
            $u = $this->utente((int)$p['user_id']);
            foreach ($catena as $c) {
                if (!$cond->vede($u, $worksiteId, 'cartella', (int)$c['id'], (int)($c['created_by'] ?? 0))) continue 2;
            }
            $chi[(int)$p['user_id']] = $p;
        }
        return $chi;
    }

    /** Chi vede una richiesta: chi vede la sua cartella (o, in cima, CSM e committente) e chi l'ha aperta. */
    private function vedonoRichiesta(array $r, array $ruoli): array
    {
        $w = (int)$r['worksite_id'];
        $chi = $this->vedonoCartella($w, $r['folder_id'] !== null ? (int)$r['folder_id'] : null, $ruoli);
        if ($r['folder_id'] === null && !empty($r['created_by'])) {
            foreach ($this->persone($w, [], (int)$r['created_by']) as $p) {
                if (in_array($p['ruolo'], $ruoli, true)) $chi[(int)$p['user_id']] = $p;
            }
        }
        return $chi;
    }

    /** I dati di una richiesta per gli avvisi. */
    private function richiesta(int $id): ?array
    {
        $st = $this->conn->prepare('
            SELECT r.*, w.name AS cantiere FROM bb_zone_richieste r JOIN bb_worksites w ON w.id = r.worksite_id WHERE r.id = :id
        ');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Una richiesta nuova: all'altra parte. Se la apre CSM, al committente e
     * alla squadra che vedono la cartella; se la apre il committente o la
     * squadra, a CSM.
     */
    public function richiestaNuova(int $id, int $daChi): void
    {
        $r = $this->richiesta($id);
        if (!$r) return;
        $w = (int)$r['worksite_id'];
        $ruoli = $this->ruoloDi($w, $daChi) === Accesso::UFFICIO ? [Accesso::CLIENTE, Accesso::CANTIERE] : [Accesso::UFFICIO];
        $chi = $this->nome($daChi);
        foreach ($this->vedonoRichiesta($r, $ruoli) as $uid => $p) {
            if ($uid === $daChi) continue;
            $p['worksite_id'] = $w;
            $p['dove'] = 'richiesta-' . $id;
            $this->manda($p, 'zona_richiesta_t', 'zona_richiesta', [
                'cantiere' => $r['cantiere'], 'chi' => $chi, 'titolo' => mb_substr((string)$r['titolo'], 0, 100),
            ], 'high', $daChi);
        }
    }

    /**
     * Una risposta: a chi partecipa alla discussione (chi l'ha aperta e chi
     * ha scritto) e a CSM se scrive qualcun altro. Solo a chi vede ancora la
     * cartella.
     */
    public function richiestaRisposta(int $id, int $daChi, string $testo): void
    {
        $r = $this->richiesta($id);
        if (!$r) return;
        $w = (int)$r['worksite_id'];
        $vedono = $this->vedonoRichiesta($r, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE]);
        $a = array_fill_keys((new Richieste($this->conn))->partecipanti($id), true);
        if ($this->ruoloDi($w, $daChi) !== Accesso::UFFICIO) {
            foreach ($vedono as $uid => $p) {
                if ($p['ruolo'] === Accesso::UFFICIO) $a[$uid] = true;
            }
        }
        $chi = $this->nome($daChi);
        foreach (array_keys($a) as $uid) {
            if ($uid === $daChi || !isset($vedono[$uid])) continue;
            $p = $vedono[$uid];
            $p['worksite_id'] = $w;
            $p['dove'] = 'richiesta-' . $id;
            $this->manda($p, 'zona_richiesta_msg_t', 'zona_richiesta_msg', [
                'cantiere' => $r['cantiere'], 'chi' => $chi, 'titolo' => mb_substr((string)$r['titolo'], 0, 80),
                'testo' => mb_substr($testo, 0, 140),
            ], 'normal', $daChi);
        }
    }

    /** Richiesta completata: a chi l'aveva aperta. */
    public function richiestaCompletata(int $id, int $daChi): void
    {
        $r = $this->richiesta($id);
        if (!$r || empty($r['created_by']) || (int)$r['created_by'] === $daChi) return;
        $w = (int)$r['worksite_id'];
        $vedono = $this->vedonoRichiesta($r, [Accesso::UFFICIO, Accesso::CANTIERE, Accesso::CLIENTE]);
        $p = $vedono[(int)$r['created_by']] ?? null;
        if (!$p) return;
        $p['worksite_id'] = $w;
        $p['dove'] = 'richiesta-' . $id;
        $this->manda($p, 'zona_richiesta_ok_t', 'zona_richiesta_ok', [
            'cantiere' => $r['cantiere'], 'chi' => $this->nome($daChi), 'titolo' => mb_substr((string)$r['titolo'], 0, 100),
        ], 'high', $daChi);
    }

    /**
     * Un'attivita' eliminata (da chiamare PRIMA di cancellarla): avvisa chi
     * ci lavorava (la persona a cui era assegnata, chi l'aveva creata) e, se
     * era nata da segnalazioni, chi le aveva aperte (le segnalazioni tornano
     * aperte). CSM e' avvisato quando a eliminarla e' qualcun altro.
     */
    public function attivitaEliminata(int $taskId, int $daChi): void
    {
        $st = $this->conn->prepare('
            SELECT t.*, w.name AS cantiere FROM bb_zone_tasks t JOIN bb_worksites w ON w.id = t.worksite_id WHERE t.id = :id
        ');
        $st->execute([':id' => $taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }
        $w = (int)$t['worksite_id'];
        $sg = $this->conn->prepare("SELECT id, testo, created_by, stato FROM bb_zone_segnalazioni WHERE task_id = :t");
        $sg->execute([':t' => $taskId]);
        $segnalazioni = $sg->fetchAll(PDO::FETCH_ASSOC);

        $chi = [];
        foreach ([(int)($t['assignee_user_id'] ?? 0), (int)($t['created_by'] ?? 0)] as $uid) {
            if ($uid > 0) $chi[$uid] = true;
        }
        foreach ($segnalazioni as $x) {
            $chi[(int)$x['created_by']] = true;
        }
        if ($this->ruoloDi($w, $daChi) !== Accesso::UFFICIO) {
            foreach ($this->persone($w, [Accesso::UFFICIO], null) as $p) $chi[(int)$p['user_id']] = true;
        }
        unset($chi[$daChi]);
        $nomeChi = $this->nome($daChi);
        $aperta  = array_values(array_filter($segnalazioni, fn($x) => $x['stato'] !== 'risolta'));
        foreach (array_keys($chi) as $uid) {
            foreach ($this->persone($w, [], $uid) as $p) {
                $p['worksite_id'] = $w;
                $p['dove'] = $aperta ? 'segnalazione-' . (int)$aperta[0]['id'] : '';
                $this->manda($p, 'zona_attivita_eliminata_t', $aperta ? 'zona_attivita_eliminata_segn' : 'zona_attivita_eliminata', [
                    'cantiere' => $t['cantiere'],
                    'chi'      => $nomeChi,
                    'nome'     => mb_substr((string)$t['name'], 0, 80),
                    'testo'    => $aperta ? mb_substr((string)$aperta[0]['testo'], 0, 80) : '',
                ], 'high', $daChi);
            }
        }
    }

    /** Un modulo assegnato: a chi lo deve compilare. */
    public function modulo(int $assegnazioneId, int $daChi): void
    {
        // moduli sospesi per ora: nessun avviso per qualcosa che non si vede
        if (!\App\Service\Zone\ScadenzeModuli::ATTIVI) {
            return;
        }
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
            $a['a_ruolo'] ? [(string)$a['a_ruolo']] : [],
            (int)($a['a_user_id'] ?? 0) ?: null,
        );
        foreach ($persone as $p) {
            if ((int)$p['user_id'] === $daChi) continue;
            if ($p['ruolo'] === Accesso::CLIENTE && empty($a['zone_cliente'])) continue;
            $p['worksite_id'] = (int)$a['worksite_id'];
            $p['dove'] = 'moduli';
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
              AND " . Accesso::SQL_CLIENTE_ENTRA . "
        ");
        $st->execute([':w' => $worksiteId, ':u' => $userId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        // al cliente lo si dice quando l'ufficio accende la condivisione,
        // non prima: un cantiere che non apre niente fa pensare a un guasto
        if (!$p || $userId === $daChi || ($p['ruolo'] === Accesso::CLIENTE && empty($p['zone_cliente']))) {
            return;
        }
        $p['worksite_id'] = $worksiteId;
        $p['dove'] = '';
        $this->manda($p, 'zona_accesso_t', 'zona_accesso', ['cantiere' => $p['cantiere']], 'normal', $daChi);
    }

    /**
     * Con chi e' condivisa una cosa, per id, da cercare con isset().
     *
     * @return array<int, true>
     */
    private function condivisaCon(string $tipo, int $oggettoId): array
    {
        $st = $this->conn->prepare(
            'SELECT user_id FROM bb_zone_condivisioni WHERE tipo = :t AND oggetto_id = :o'
        );
        $st->execute([':t' => $tipo, ':o' => $oggettoId]);
        return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
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
            JOIN bb_worksites w ON w.id = a.worksite_id
            WHERE a.worksite_id = :w AND u.active = 'Y' AND u.removed = 'N'
              AND " . Accesso::SQL_CLIENTE_ENTRA . "
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
            // Tutti dritti al cantiere, anche la squadra: il link dice anche
            // a quale cantiere e a quale sezione appartiene l'avviso, ed e'
            // cosi' che la Zone mostra il campanello e i numeri sul menu.
            // "/io" resta per un avviso senza cantiere.
            empty($p['worksite_id'])
                ? self::LINK
                : Segnalazioni::link((int)$p['worksite_id'], (string)($p['dove'] ?? '')),
            'zone',
            $priorita,
            $daChi ?: null,
        );
    }
}
