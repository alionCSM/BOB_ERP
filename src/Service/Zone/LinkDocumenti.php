<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Un link di "Doc condivisi" collegato al cantiere, dentro i Documenti.
 *
 * CSM collega al cantiere un link gia' preparato in Doc condivisi. Nei
 * Documenti compaiono due cartelle:
 *   - "Documenti azienda", con una cartella per ogni azienda del link;
 *   - "Documenti operai", con una cartella per ogni operaio ("Nome
 *     Cognome · Azienda").
 * I documenti si leggono dal link ogni volta (sono quelli di BOB, sempre
 * aggiornati) e non si modificano dalla Zone. Le cartelle invece sono
 * cartelle vere: chi le vede lo decide CSM come per le altre, e dentro si
 * possono fare richieste ("il DURC e' scaduto, ci mandate il nuovo?").
 *
 * Di base le vedono CSM e il committente: i documenti degli operai sono
 * dati personali, la squadra non vede quelli dei colleghi.
 */
final class LinkDocumenti
{
    public const AZIENDE = 'link_aziende';
    public const AZIENDA = 'link_azienda';
    public const OPERAI  = 'link_operai';
    public const OPERAIO = 'link_operaio';

    public function __construct(private PDO $conn, private Condivisione $condivisione) {}

    public static function daLink(?array $cartella): bool
    {
        return !empty($cartella['sorgente']);
    }

    /** Il link collegato al cantiere, se c'e'. */
    public function collegato(int $w): ?array
    {
        $st = $this->conn->prepare('
            SELECT l.id, l.title, l.expires_at, l.is_active
            FROM bb_worksites w JOIN bb_shared_links l ON l.id = w.zone_share_link_id
            WHERE w.id = :w
        ');
        $st->execute([':w' => $w]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** I link fra cui CSM sceglie (attivi). */
    public function disponibili(): array
    {
        return $this->conn->query('
            SELECT id, title, expires_at FROM bb_shared_links
            WHERE is_active = 1 ORDER BY created_at DESC, id DESC LIMIT 200
        ')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Collega (o scollega, con null) un link al cantiere e allinea le cartelle. */
    public function collega(int $w, ?int $linkId, int $daChi): void
    {
        $this->conn->prepare('UPDATE bb_worksites SET zone_share_link_id = :l WHERE id = :w')
            ->execute([':l' => $linkId, ':w' => $w]);
        $this->sincronizza($w, $daChi);
    }

    /**
     * Allinea le cartelle al link: crea quelle che mancano, toglie quelle di
     * aziende e operai non piu' nel link (se sono vuote: una cartella con
     * dentro una richiesta o un documento resta).
     */
    public function sincronizza(int $w, int $daChi = 0): void
    {
        $link = $this->collegato($w);
        $esistenti = [];
        $st = $this->conn->prepare('SELECT * FROM bb_zone_folders WHERE worksite_id = :w AND sorgente IS NOT NULL');
        $st->execute([':w' => $w]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $esistenti[$c['sorgente'] . ':' . (int)$c['sorgente_id']] = $c;
        }

        $vogliamo = [];
        if ($link) {
            $vogliamo[self::AZIENDE . ':0'] = ['nome' => 'Documenti azienda', 'su' => null];
            $vogliamo[self::OPERAI . ':0']  = ['nome' => 'Documenti operai', 'su' => null];
            $az = $this->conn->prepare('
                SELECT c.id, c.name FROM bb_shared_link_companies lc JOIN bb_companies c ON c.id = lc.company_id
                WHERE lc.shared_link_id = :l ORDER BY c.name
            ');
            $az->execute([':l' => (int)$link['id']]);
            foreach ($az->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $vogliamo[self::AZIENDA . ':' . (int)$a['id']] = ['nome' => (string)$a['name'], 'su' => self::AZIENDE . ':0'];
            }
            $op = $this->conn->prepare("
                SELECT w.id, TRIM(CONCAT(COALESCE(w.first_name,''),' ',COALESCE(w.last_name,''))) AS nome, w.company
                FROM bb_shared_link_workers lw JOIN bb_workers w ON w.id = lw.worker_id
                WHERE lw.shared_link_id = :l ORDER BY w.last_name, w.first_name
            ");
            $op->execute([':l' => (int)$link['id']]);
            foreach ($op->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $nome = $o['nome'] . (trim((string)$o['company']) !== '' ? ' · ' . trim((string)$o['company']) : '');
                $vogliamo[self::OPERAIO . ':' . (int)$o['id']] = ['nome' => $nome, 'su' => self::OPERAI . ':0'];
            }
        }

        // prima le due cartelle in cima, poi quelle dentro
        uasort($vogliamo, fn($a, $b) => ($a['su'] === null ? 0 : 1) <=> ($b['su'] === null ? 0 : 1));
        foreach ($vogliamo as $chiave => $v) {
            [$sorgente, $rif] = explode(':', $chiave);
            $padre = $v['su'] !== null ? ($esistenti[$v['su']]['id'] ?? null) : null;
            if (isset($esistenti[$chiave])) {
                $c = $esistenti[$chiave];
                if ($c['name'] !== $v['nome'] || (int)($c['parent_id'] ?? 0) !== (int)($padre ?? 0)) {
                    $this->conn->prepare('UPDATE bb_zone_folders SET name = :n, parent_id = :p WHERE id = :id')
                        ->execute([':n' => mb_substr($v['nome'], 0, 255), ':p' => $padre, ':id' => (int)$c['id']]);
                }
                continue;
            }
            $this->conn->prepare('
                INSERT INTO bb_zone_folders (worksite_id, parent_id, name, created_by, sorgente, sorgente_id)
                VALUES (:w, :p, :n, :u, :s, :r)
            ')->execute([':w' => $w, ':p' => $padre, ':n' => mb_substr($v['nome'], 0, 255), ':u' => $daChi ?: null,
                         ':s' => $sorgente, ':r' => (int)$rif ?: null]);
            $id = (int)$this->conn->lastInsertId();
            $esistenti[$chiave] = ['id' => $id, 'name' => $v['nome'], 'parent_id' => $padre];
            // chi la vede: le due in cima CSM e il committente; quelle dentro
            // come la cartella che le contiene
            if ($padre === null) {
                $this->condivisione->salvaParti($w, 'cartella', $id, [Accesso::CLIENTE]);
            } else {
                $this->condivisione->salvaParti($w, 'cartella', $id, $this->condivisione->partiDi('cartella', [$padre])[$padre] ?? []);
                $persone = $this->condivisione->conChi('cartella', [$padre])[$padre] ?? [];
                if ($persone) {
                    $this->condivisione->salva($w, 'cartella', $id, $persone, $daChi);
                }
            }
        }

        // quelle che non servono piu': via se vuote
        foreach ($esistenti as $chiave => $c) {
            if (isset($vogliamo[$chiave]) || empty($c['id'])) continue;
            if ($this->vuota((int)$c['id'])) {
                $this->conn->prepare('DELETE FROM bb_zone_folders WHERE id = :id')->execute([':id' => (int)$c['id']]);
                $this->conn->prepare("DELETE FROM bb_zone_condivisioni_ruoli WHERE tipo = 'cartella' AND oggetto_id = :id")->execute([':id' => (int)$c['id']]);
                $this->conn->prepare("DELETE FROM bb_zone_condivisioni WHERE tipo = 'cartella' AND oggetto_id = :id")->execute([':id' => (int)$c['id']]);
            }
        }
    }

    private function vuota(int $folderId): bool
    {
        foreach (['SELECT 1 FROM bb_zone_files WHERE folder_id = :f LIMIT 1',
                  'SELECT 1 FROM bb_zone_richieste WHERE folder_id = :f LIMIT 1',
                  'SELECT 1 FROM bb_zone_folders WHERE parent_id = :f LIMIT 1'] as $q) {
            try {
                $st = $this->conn->prepare($q);
                $st->execute([':f' => $folderId]);
                if ($st->fetchColumn()) return false;
            } catch (\PDOException $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * I documenti di una cartella del link (azienda o operaio), dal link.
     *
     * @return array<int, array{tipo:string, id:int, nome:string, scadenza:?string, stato:string}>
     */
    public function documenti(int $w, array $cartella): array
    {
        $link = $this->collegato($w);
        if (!$link || !in_array($cartella['sorgente'] ?? '', [self::AZIENDA, self::OPERAIO], true)) {
            return [];
        }
        $rif = (int)$cartella['sorgente_id'];
        if ($cartella['sorgente'] === self::AZIENDA) {
            $st = $this->conn->prepare('
                SELECT d.id, d.tipo_documento AS nome, d.scadenza
                FROM bb_shared_link_companies lc JOIN bb_company_documents d ON d.company_id = lc.company_id
                WHERE lc.shared_link_id = :l AND lc.company_id = :r ORDER BY d.tipo_documento
            ');
            $tipo = 'azienda';
        } else {
            $st = $this->conn->prepare('
                SELECT d.id, d.tipo_documento AS nome, d.scadenza
                FROM bb_shared_link_workers lw JOIN bb_worker_documents d ON d.worker_id = lw.worker_id
                WHERE lw.shared_link_id = :l AND lw.worker_id = :r ORDER BY d.tipo_documento
            ');
            $tipo = 'operaio';
        }
        $st->execute([':l' => (int)$link['id'], ':r' => $rif]);
        $oggi = date('Y-m-d');
        $tra30 = date('Y-m-d', strtotime('+30 days'));
        return array_map(fn($d) => [
            'tipo'     => $tipo,
            'id'       => (int)$d['id'],
            'nome'     => (string)$d['nome'],
            'scadenza' => $d['scadenza'] ?: null,
            'stato'    => !$d['scadenza'] ? 'valido' : ($d['scadenza'] < $oggi ? 'scaduto' : ($d['scadenza'] <= $tra30 ? 'in_scadenza' : 'valido')),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Il percorso (relativo alla cartella cloud di BOB) di un documento del
     * link, e la cartella della Zone in cui compare. Null se non e' del link.
     *
     * @return array{percorso:string, nome:string, sorgente:string, sorgente_id:int}|null
     */
    public function documento(int $w, string $tipo, int $docId): ?array
    {
        $link = $this->collegato($w);
        if (!$link) {
            return null;
        }
        if ($tipo === 'azienda') {
            $st = $this->conn->prepare('
                SELECT d.file_path AS percorso, d.tipo_documento AS nome, d.company_id AS rif
                FROM bb_company_documents d JOIN bb_shared_link_companies lc ON lc.company_id = d.company_id AND lc.shared_link_id = :l
                WHERE d.id = :d
            ');
            $sorgente = self::AZIENDA;
        } elseif ($tipo === 'operaio') {
            $st = $this->conn->prepare('
                SELECT d.path AS percorso, d.tipo_documento AS nome, d.worker_id AS rif
                FROM bb_worker_documents d JOIN bb_shared_link_workers lw ON lw.worker_id = d.worker_id AND lw.shared_link_id = :l
                WHERE d.id = :d
            ');
            $sorgente = self::OPERAIO;
        } else {
            return null;
        }
        $st->execute([':l' => (int)$link['id'], ':d' => $docId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['percorso' => (string)$r['percorso'], 'nome' => (string)$r['nome'], 'sorgente' => $sorgente, 'sorgente_id' => (int)$r['rif']] : null;
    }
}
