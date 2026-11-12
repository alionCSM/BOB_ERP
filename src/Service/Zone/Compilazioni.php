<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * PDF da compilare nei Documenti della Zone.
 *
 * Il committente carica il suo modulo in PDF e chiede a CSM di compilarlo
 * (o CSM chiede al committente o alla squadra). Chi deve compilarlo lo apre
 * nel browser, scrive, salva: la copia compilata diventa una versione del
 * documento e l'originale resta com'era. Chi aveva chiesto riceve l'avviso.
 *
 * Chi chiede a chi:
 *  - committente e squadra chiedono a CSM;
 *  - CSM chiede al committente o alla squadra.
 * Compila chi ha il ruolo a cui e' stata chiesta, e CSM sempre.
 */
final class Compilazioni
{
    public const RUOLI = [Accesso::UFFICIO, Accesso::CLIENTE, Accesso::CANTIERE];

    public function __construct(private PDO $conn) {}

    /** Un file si puo' compilare nel browser solo se e' un PDF. */
    public static function compilabile(array $file): bool
    {
        return strtolower((string)($file['file_type'] ?? '')) === 'pdf';
    }

    /** A chi puo' chiedere chi ha questo ruolo. */
    public static function destinatari(string $ruolo): array
    {
        return $ruolo === Accesso::UFFICIO ? [Accesso::CLIENTE, Accesso::CANTIERE] : [Accesso::UFFICIO];
    }

    public static function puoCompilare(?string $ruolo, ?array $richiesta): bool
    {
        if ($ruolo === Accesso::UFFICIO) {
            return true;
        }
        return $richiesta !== null && ($richiesta['stato'] ?? '') === 'da_compilare'
            && ($richiesta['per_ruolo'] ?? '') === $ruolo;
    }

    /**
     * Nuova richiesta di compilazione. Una sola aperta per documento.
     *
     * @return int id della richiesta
     */
    public function richiedi(int $w, array $file, int $daChi, string $ruolo, ?string $perRuolo, string $nota): int
    {
        if (!self::compilabile($file)) {
            throw new \RuntimeException('Solo i documenti PDF possono essere compilati in BOB Zone.');
        }
        $ammessi = self::destinatari($ruolo);
        $perRuolo = $perRuolo && in_array($perRuolo, $ammessi, true) ? $perRuolo : $ammessi[0];
        if ($this->aperta((int)$file['id'])) {
            throw new \RuntimeException('Per questo documento è già presente una richiesta di compilazione aperta.');
        }
        $this->conn->prepare('
            INSERT INTO bb_zone_file_compilazioni (worksite_id, file_id, richiesto_da, per_ruolo, nota, stato)
            VALUES (:w, :f, :u, :r, :n, \'da_compilare\')
        ')->execute([
            ':w' => $w, ':f' => (int)$file['id'], ':u' => $daChi ?: null,
            ':r' => $perRuolo, ':n' => mb_substr(trim($nota), 0, 1000) ?: null,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    /** La richiesta aperta di un documento, se c'e'. */
    public function aperta(int $fileId): ?array
    {
        $st = $this->conn->prepare("
            SELECT * FROM bb_zone_file_compilazioni
            WHERE file_id = :f AND stato = 'da_compilare' ORDER BY id DESC LIMIT 1
        ");
        $st->execute([':f' => $fileId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function trova(int $id): ?array
    {
        $st = $this->conn->prepare('SELECT * FROM bb_zone_file_compilazioni WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function annulla(int $id): void
    {
        $this->conn->prepare("UPDATE bb_zone_file_compilazioni SET stato = 'annullata' WHERE id = :id AND stato = 'da_compilare'")
            ->execute([':id' => $id]);
    }

    /**
     * Salva la copia compilata come nuova versione del documento e chiude
     * la richiesta aperta (se c'e').
     *
     * @return array{versione_id:int, richiesta:?array}
     */
    public function salvaCompilato(int $w, array $file, int $daChi, string $percorso, int $dimensione): array
    {
        $richiesta = $this->aperta((int)$file['id']);
        $this->conn->prepare('
            INSERT INTO bb_zone_file_versioni (worksite_id, file_id, file_path, size_bytes, compilazione_id, creato_da)
            VALUES (:w, :f, :p, :s, :c, :u)
        ')->execute([
            ':w' => $w, ':f' => (int)$file['id'], ':p' => $percorso, ':s' => $dimensione,
            ':c' => $richiesta ? (int)$richiesta['id'] : null, ':u' => $daChi ?: null,
        ]);
        $versione = (int)$this->conn->lastInsertId();
        if ($richiesta) {
            $this->conn->prepare("
                UPDATE bb_zone_file_compilazioni
                SET stato = 'compilato', compilato_da = :u, compilato_at = NOW(), versione_id = :v
                WHERE id = :id
            ")->execute([':u' => $daChi ?: null, ':v' => $versione, ':id' => (int)$richiesta['id']]);
            $richiesta = $this->trova((int)$richiesta['id']);
        }
        return ['versione_id' => $versione, 'richiesta' => $richiesta];
    }

    public function versione(int $id): ?array
    {
        $st = $this->conn->prepare('SELECT * FROM bb_zone_file_versioni WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Per l'elenco dei documenti: l'ultima richiesta e le versioni compilate
     * di ciascun file, con i nomi delle persone.
     *
     * @param int[] $fileIds
     * @return array{richieste: array<int, array>, versioni: array<int, array[]>}
     */
    public function perFile(array $fileIds): array
    {
        $fileIds = array_values(array_unique(array_map('intval', $fileIds)));
        if (!$fileIds) {
            return ['richieste' => [], 'versioni' => []];
        }
        $in = implode(',', $fileIds);
        $nome = "TRIM(CONCAT(COALESCE(%s.first_name,''),' ',COALESCE(%s.last_name,'')))";

        $richieste = [];
        foreach ($this->conn->query("
            SELECT c.*, " . sprintf($nome, 'r', 'r') . " AS richiesto_da_nome, " . sprintf($nome, 'k', 'k') . " AS compilato_da_nome
            FROM bb_zone_file_compilazioni c
            LEFT JOIN bb_users r ON r.id = c.richiesto_da
            LEFT JOIN bb_users k ON k.id = c.compilato_da
            WHERE c.file_id IN ($in) AND c.stato <> 'annullata'
            ORDER BY c.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $richieste[(int)$r['file_id']] = $r;   // vince la piu' recente
        }
        $versioni = [];
        foreach ($this->conn->query("
            SELECT v.id, v.file_id, v.created_at, v.creato_da, " . sprintf($nome, 'u', 'u') . " AS creato_da_nome
            FROM bb_zone_file_versioni v LEFT JOIN bb_users u ON u.id = v.creato_da
            WHERE v.file_id IN ($in)
            ORDER BY v.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $versioni[(int)$v['file_id']][] = $v;
        }
        return ['richieste' => $richieste, 'versioni' => $versioni];
    }

    /**
     * Le richieste del cantiere (aperte, e chiuse negli ultimi 30 giorni),
     * con il nome del documento. Chi le vede lo filtra chi chiama.
     *
     * @return array<int, array<string, mixed>>
     */
    public function delCantiere(int $w): array
    {
        $st = $this->conn->prepare("
            SELECT c.*, f.file_name, f.folder_id, f.uploaded_by, f.file_type,
                   TRIM(CONCAT(COALESCE(r.first_name,''),' ',COALESCE(r.last_name,''))) AS richiesto_da_nome,
                   TRIM(CONCAT(COALESCE(k.first_name,''),' ',COALESCE(k.last_name,''))) AS compilato_da_nome
            FROM bb_zone_file_compilazioni c
            JOIN bb_zone_files f ON f.id = c.file_id
            LEFT JOIN bb_users r ON r.id = c.richiesto_da
            LEFT JOIN bb_users k ON k.id = c.compilato_da
            WHERE c.worksite_id = :w
              AND (c.stato = 'da_compilare' OR (c.stato = 'compilato' AND c.compilato_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)))
            ORDER BY c.stato = 'da_compilare' DESC, COALESCE(c.compilato_at, c.created_at) DESC
        ");
        $st->execute([':w' => $w]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
