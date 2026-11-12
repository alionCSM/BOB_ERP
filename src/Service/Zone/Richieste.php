<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Le richieste nei Documenti della Zone.
 *
 * Una richiesta sta in una cartella ed e' una piccola discussione: un
 * oggetto e dei messaggi, ognuno con il suo testo e, se serve, dei
 * documenti allegati. "Ci serve il DURC aggiornato"; "Vi mandiamo il modulo
 * di collaudo da compilare". L'altra parte risponde allo stesso modo, con un
 * messaggio e il documento (o il PDF compilato in BOB), e la segna come
 * completata.
 *
 * Chi la vede e' chi vede la cartella: le regole sono quelle della cartella,
 * qui non ce ne sono altre. Gli allegati sono documenti della cartella
 * (bb_zone_files) e restano li' anche a richiesta chiusa.
 */
final class Richieste
{
    public const APERTA     = 'aperta';
    public const COMPLETATA = 'completata';

    public function __construct(private PDO $conn) {}

    /** @param int[] $fileIds */
    public function crea(int $w, int $folderId, string $titolo, int $daChi, string $testo, array $fileIds): int
    {
        $titolo = mb_substr(trim($titolo), 0, 200);
        if ($titolo === '') {
            throw new \RuntimeException('Inserire l\'oggetto della richiesta.');
        }
        $this->conn->prepare('
            INSERT INTO bb_zone_richieste (worksite_id, folder_id, titolo, stato, created_by)
            VALUES (:w, :f, :t, :s, :u)
        ')->execute([':w' => $w, ':f' => $folderId, ':t' => $titolo, ':s' => self::APERTA, ':u' => $daChi ?: null]);
        $id = (int)$this->conn->lastInsertId();
        $this->scrivi($id, $daChi, $testo, $fileIds);
        return $id;
    }

    public function trova(int $id): ?array
    {
        $st = $this->conn->prepare("
            SELECT r.*,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS creata_da_nome,
                   TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS completata_da_nome
            FROM bb_zone_richieste r
            LEFT JOIN bb_users u ON u.id = r.created_by
            LEFT JOIN bb_users c ON c.id = r.completata_da
            WHERE r.id = :id
        ");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Le richieste di una cartella: prima quelle aperte, poi le altre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function diCartella(int $w, int $folderId): array
    {
        $st = $this->conn->prepare("
            SELECT r.id, r.titolo, r.stato, r.created_by, r.created_at, r.updated_at, r.completata_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS creata_da_nome,
                   (SELECT COUNT(*) FROM bb_zone_richieste_messaggi m WHERE m.richiesta_id = r.id AND m.sistema IS NULL) AS n_messaggi
            FROM bb_zone_richieste r
            LEFT JOIN bb_users u ON u.id = r.created_by
            WHERE r.worksite_id = :w AND r.folder_id = :f
            ORDER BY r.stato = 'aperta' DESC, r.updated_at DESC
        ");
        $st->execute([':w' => $w, ':f' => $folderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, int> cartella => richieste aperte */
    public function apertePerCartella(int $w): array
    {
        $st = $this->conn->prepare("
            SELECT folder_id, COUNT(*) AS n FROM bb_zone_richieste
            WHERE worksite_id = :w AND stato = 'aperta' GROUP BY folder_id
        ");
        $st->execute([':w' => $w]);
        $fuori = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[(int)$r['folder_id']] = (int)$r['n'];
        }
        return $fuori;
    }

    /**
     * I messaggi di una richiesta, con gli allegati.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messaggi(int $id): array
    {
        $st = $this->conn->prepare("
            SELECT m.*, u.type AS autore_tipo,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS autore
            FROM bb_zone_richieste_messaggi m
            LEFT JOIN bb_users u ON u.id = m.user_id
            WHERE m.richiesta_id = :r
            ORDER BY m.id ASC
        ");
        $st->execute([':r' => $id]);
        $righe = $st->fetchAll(PDO::FETCH_ASSOC);

        $tutti = [];
        foreach ($righe as $m) {
            foreach (self::ids($m['file_ids'] ?? '') as $f) {
                $tutti[$f] = true;
            }
        }
        $file = [];
        if ($tutti) {
            $in = implode(',', array_keys($tutti));
            foreach ($this->conn->query("SELECT id, file_name, file_type, size_bytes, worksite_id FROM bb_zone_files WHERE id IN ($in)")
                         ->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $file[(int)$f['id']] = $f;
            }
        }
        foreach ($righe as &$m) {
            $m['allegati'] = [];
            foreach (self::ids($m['file_ids'] ?? '') as $f) {
                // un allegato eliminato dalla cartella resta citato, senza link
                $m['allegati'][] = isset($file[$f])
                    ? ['id' => $f, 'file_name' => $file[$f]['file_name'], 'file_type' => strtolower((string)$file[$f]['file_type']),
                       'size_bytes' => (int)$file[$f]['size_bytes'], 'esiste' => true]
                    : ['id' => $f, 'file_name' => null, 'esiste' => false];
            }
            unset($m['file_ids']);
        }
        return $righe;
    }

    /** @param int[] $fileIds */
    public function scrivi(int $id, int $daChi, string $testo, array $fileIds, ?string $sistema = null): int
    {
        $testo = trim($testo);
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
        if ($sistema === null && $testo === '' && !$fileIds) {
            throw new \RuntimeException('Scrivere un messaggio o allegare un documento.');
        }
        $this->conn->prepare('
            INSERT INTO bb_zone_richieste_messaggi (richiesta_id, user_id, testo, file_ids, sistema)
            VALUES (:r, :u, :t, :f, :s)
        ')->execute([
            ':r' => $id, ':u' => $daChi ?: null, ':t' => $testo !== '' ? mb_substr($testo, 0, 5000) : null,
            ':f' => $fileIds ? implode(',', $fileIds) : null, ':s' => $sistema,
        ]);
        $mid = (int)$this->conn->lastInsertId();
        $this->conn->prepare('UPDATE bb_zone_richieste SET updated_at = NOW() WHERE id = :id')->execute([':id' => $id]);
        return $mid;
    }

    /** Completata o riaperta, con la riga nella discussione. */
    public function cambiaStato(int $id, string $stato, int $daChi): void
    {
        if (!in_array($stato, [self::APERTA, self::COMPLETATA], true)) {
            throw new \RuntimeException('Stato non valido.');
        }
        $this->conn->prepare('
            UPDATE bb_zone_richieste
            SET stato = :s, completata_da = :u, completata_at = :q
            WHERE id = :id
        ')->execute([
            ':s' => $stato,
            ':u' => $stato === self::COMPLETATA ? ($daChi ?: null) : null,
            ':q' => $stato === self::COMPLETATA ? date('Y-m-d H:i:s') : null,
            ':id' => $id,
        ]);
        $this->scrivi($id, $daChi, '', [], $stato === self::COMPLETATA ? 'completata' : 'riaperta');
    }

    /** Elimina la richiesta e i suoi messaggi. I documenti restano nella cartella. */
    public function elimina(int $id): void
    {
        $this->conn->prepare('DELETE FROM bb_zone_richieste_messaggi WHERE richiesta_id = :r')->execute([':r' => $id]);
        $this->conn->prepare('DELETE FROM bb_zone_richieste WHERE id = :r')->execute([':r' => $id]);
    }

    /** Chi ha scritto nella discussione, piu' chi l'ha aperta. @return int[] */
    public function partecipanti(int $id): array
    {
        $st = $this->conn->prepare('
            SELECT created_by AS u FROM bb_zone_richieste WHERE id = :r
            UNION SELECT user_id FROM bb_zone_richieste_messaggi WHERE richiesta_id = :r2 AND user_id IS NOT NULL
        ');
        $st->execute([':r' => $id, ':r2' => $id]);
        return array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** @return int[] */
    private static function ids(?string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$csv))));
    }
}
