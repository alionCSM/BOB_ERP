<?php

declare(strict_types=1);

namespace App\Service\Poti;

use PDO;
use RuntimeException;

/**
 * Foto di uscita e rientro dei mezzi.
 *
 * Uno solo per tutti e due i moduli: la foto di un'autocarrata che esce e
 * quella di un telescopico che rientra sono la stessa cosa, e due copie di
 * questo codice vorrebbero dire correggere ogni bug due volte.
 *
 * Servono a una cosa sola ma importante: quando il cliente riporta il mezzo
 * ammaccato, la discussione su chi l'ha rotto la chiude una fotografia
 * fatta il giorno della consegna. Per questo si salvano con la data e con
 * chi le ha scattate, e non si sovrascrivono mai.
 *
 * I file stanno fuori dalla cartella pubblica: a database c'e' il percorso,
 * e per vederle si passa da una rotta che controlla i permessi. Se stessero
 * sotto public, chiunque indovinasse l'indirizzo vedrebbe i cantieri dei
 * clienti senza passare da BOB.
 */
final class Foto
{
    public const ENTITA   = ['prenotazione', 'riga'];
    public const MOMENTI  = ['uscita', 'rientro'];

    /** Oltre questa dimensione si rifiuta: e' gia' molto per una foto di telefono. */
    private const MAX_BYTE = 15 * 1024 * 1024;

    private const ESTENSIONI = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

    public function __construct(private PDO $conn) {}

    /**
     * Salva un file caricato e ne registra la riga.
     *
     * @param array<string, mixed> $file una voce di $_FILES
     * @return array<string, mixed> la foto appena creata, pronta da mostrare
     */
    public function salva(
        int $companyId,
        string $entita,
        int $entitaId,
        string $momento,
        array $file,
        ?int $userId
    ): array {
        if (!in_array($entita, self::ENTITA, true) || !in_array($momento, self::MOMENTI, true)) {
            throw new RuntimeException('Foto non collocabile');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Nessuna foto ricevuta');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTE) {
            throw new RuntimeException('Foto troppo grande (massimo 15 MB)');
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::ESTENSIONI, true)) {
            throw new RuntimeException('Formato non consentito');
        }

        // il nome del file lo sceglie chi carica: non ci si fida, si guarda
        // dentro. Un .jpg puo' contenere qualsiasi cosa.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
        if (!str_starts_with($mime, 'image/') && $mime !== 'application/octet-stream') {
            throw new RuntimeException("Il file non e' un'immagine");
        }

        $dir  = \CloudPath::ensurePotiFotoDir(date('Y-m-d'));
        $nome = sprintf(
            '%s%d_%s_%s_%s.%s',
            $entita === 'riga' ? 'r' : 'p',
            $entitaId,
            $momento,
            date('Ymd_His'),
            bin2hex(random_bytes(3)),   // due foto nello stesso secondo non si sovrascrivono
            $ext
        );
        $dest = $dir . DIRECTORY_SEPARATOR . $nome;

        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            throw new RuntimeException('Salvataggio foto non riuscito');
        }

        $relativo = \CloudPath::relativeToRoot($dest);

        $stmt = $this->conn->prepare("
            INSERT INTO pn_foto
                (group_company_id, entita, entita_id, momento, percorso, mime, dimensione, created_by)
            VALUES (:cid, :ent, :eid, :mom, :perc, :mime, :dim, :uid)
        ");
        $stmt->execute([
            ':cid'  => $companyId,
            ':ent'  => $entita,
            ':eid'  => $entitaId,
            ':mom'  => $momento,
            ':perc' => $relativo,
            ':mime' => $mime,
            ':dim'  => (int)($file['size'] ?? 0),
            ':uid'  => $userId,
        ]);

        $id = (int)$this->conn->lastInsertId();

        return [
            'id'      => $id,
            'momento' => $momento,
            'url'     => $this->url($id, $entita),
        ];
    }

    /**
     * Piu' foto in un colpo solo.
     *
     * PHP consegna i caricamenti multipli in una forma scomoda: invece di
     * un elenco di file da' un file solo con dentro elenchi paralleli
     * (tutti i nomi, tutti i temporanei, tutti gli errori). Qui si
     * raddrizza, cosi' il resto del codice non deve saperlo.
     *
     * Le foto buone si salvano anche se una fallisce: al tecnico che ne ha
     * scattate cinque non si buttano le altre quattro perche' la terza era
     * di un formato strano. Quelle rifiutate tornano in `errori`.
     *
     * @param array<string, mixed> $files la voce $_FILES['foto']
     * @return array{foto: array<int, array<string,mixed>>, errori: string[]}
     */
    public function salvaMolte(
        int $companyId,
        string $entita,
        int $entitaId,
        string $momento,
        array $files,
        ?int $userId
    ): array {
        $out    = [];
        $errori = [];

        foreach ($this->separa($files) as $file) {
            try {
                $out[] = $this->salva($companyId, $entita, $entitaId, $momento, $file, $userId);
            } catch (\Throwable $e) {
                $errori[] = ($file['name'] ?? 'foto') . ': ' . $e->getMessage();
            }
        }

        if (!$out && !$errori) {
            $errori[] = 'Nessuna foto ricevuta';
        }
        return ['foto' => $out, 'errori' => $errori];
    }

    /**
     * Da come le manda PHP a un elenco normale di file.
     *
     * Con un file solo $_FILES['foto']['name'] e' una stringa; con piu' file
     * e' un elenco, e lo stesso vale per tutte le altre chiavi. Trattare i
     * due casi in un posto solo evita di doverci pensare ogni volta.
     *
     * @param array<string, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function separa(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }

        // un file solo: e' gia' nella forma giusta
        if (!is_array($files['name'])) {
            return [$files];
        }

        $out = [];
        foreach (array_keys($files['name']) as $i) {
            $out[] = [
                'name'     => $files['name'][$i]     ?? '',
                'type'     => $files['type'][$i]     ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error'    => $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'][$i]     ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Foto di piu' righe in un colpo solo, raggruppate.
     *
     * A blocchi e non una query per riga: la giornata puo' avere trenta
     * schede, e trenta interrogazioni per mostrare qualche miniatura sono
     * trenta viaggi al database che si possono fare in uno.
     *
     * @param int[] $ids
     * @return array<int, array<string, array<int, array<string,mixed>>>>
     */
    public function perEntita(int $companyId, string $entita, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || !in_array($entita, self::ENTITA, true)) {
            return [];
        }

        $segnaposti = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare("
            SELECT id, entita_id, momento
            FROM   pn_foto
            WHERE  group_company_id = ? AND entita = ? AND entita_id IN ({$segnaposti})
            ORDER BY id ASC
        ");
        $stmt->execute(array_merge([$companyId, $entita], $ids));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['entita_id']][(string)$r['momento']][] = [
                'id'  => (int)$r['id'],
                'url' => $this->url((int)$r['id'], $entita),
            ];
        }
        return $out;
    }

    /** Una foto, se appartiene davvero a questa societa'. */
    public function trova(int $companyId, int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM pn_foto WHERE id = :id AND group_company_id = :cid'
        );
        $stmt->execute([':id' => $id, ':cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Toglie la riga e il file. */
    public function elimina(int $companyId, int $id): bool
    {
        $foto = $this->trova($companyId, $id);
        if (!$foto) {
            return false;
        }

        $stmt = $this->conn->prepare('DELETE FROM pn_foto WHERE id = :id AND group_company_id = :cid');
        $stmt->execute([':id' => $id, ':cid' => $companyId]);

        // il file dopo la riga: se sparisse il file e restasse la riga, la
        // giornata mostrerebbe una miniatura rotta per sempre
        $assoluto = \CloudPath::fotoAssoluta((string)$foto['percorso']);
        if ($assoluto) {
            @unlink($assoluto);
        }
        return true;
    }

    /**
     * L'indirizzo da cui si guarda una foto.
     *
     * Per id e non per percorso: mettendo il percorso nell'indirizzo si
     * darebbe a chiunque la mappa di come sono organizzati i file, e ogni
     * rotta dovrebbe difendersi da sola dai percorsi costruiti a mano.
     *
     * L'indirizzo cambia col modulo perche' cambia il permesso: la foto di
     * un'autocarrata la deve poter vedere chi lavora sulle autocarrate, non
     * chiunque abbia accesso ai mezzi di sollevamento. Sono due permessi
     * distinti e devono restare tali anche qui.
     */
    public function url(int $id, string $entita): string
    {
        return ($entita === 'prenotazione' ? '/autocarrate/foto/' : '/noleggi/foto/') . $id;
    }
}
