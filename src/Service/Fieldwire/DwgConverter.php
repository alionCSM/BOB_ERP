<?php
declare(strict_types=1);

namespace App\Service\Fieldwire;

use PDO;

/**
 * Converte un disegno DWG in SVG vettoriale (via scripts/dwg/dwg_to_svg.py)
 * e salva i metadati in bb_zone_dwg_render.
 *
 * L'SVG viene scritto accanto al DWG sull'NFS (stessa cartella Disegni) cosi'
 * resta dentro lo storage BOB. Le estensioni + meters_per_unit permettono al
 * viewer di fare misure esatte senza calibrazione manuale.
 */
final class DwgConverter
{
    public function __construct(private PDO $conn) {}

    /**
     * Converte il documento DWG indicato. Idempotente: ri-converte se richiamato.
     * @return array stato finale della riga bb_zone_dwg_render
     */
    public function convert(int $documentId): array
    {
        $doc = $this->loadDoc($documentId);
        if (!$doc) {
            return $this->store($documentId, 'error', null, null, 'Documento non trovato');
        }

        $root    = \CloudPath::getRoot();
        $dwgAbs  = $root . DIRECTORY_SEPARATOR . $doc['file_path'];
        if (!is_file($dwgAbs)) {
            return $this->store($documentId, 'error', null, null, 'File DWG non trovato su disco');
        }

        $outDir  = dirname($dwgAbs);
        $script  = APP_ROOT . '/scripts/dwg/dwg_to_svg.py';
        $python  = getenv('BOB_PYTHON') ?: 'python3';

        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
             . escapeshellarg($dwgAbs) . ' ' . escapeshellarg($outDir) . ' 2>&1';

        $raw = shell_exec($cmd);
        $json = $this->extractJson($raw ?? '');

        if (!$json || empty($json['ok'])) {
            $err = $json['error'] ?? ('Output non valido: ' . substr((string)$raw, 0, 300));
            return $this->store($documentId, 'error', null, null, $err);
        }

        $svgRel = \CloudPath::relativeToRoot($json['svg_path']);

        return $this->store($documentId, 'ok', $svgRel, $json, null);
    }

    public function status(int $documentId): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_zone_dwg_render WHERE document_id = :id");
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── interni ────────────────────────────────────────────────────────────────

    private function loadDoc(int $documentId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, file_path, file_type FROM bb_worksite_documents
            WHERE id = :id AND is_deleted = 0 LIMIT 1
        ");
        $stmt->execute([':id' => $documentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function store(int $documentId, string $status, ?string $svgRel, ?array $meta, ?string $error): array
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_zone_dwg_render
                (document_id, svg_path, minx, miny, maxx, maxy, insunits, meters_per_unit, status, error)
            VALUES
                (:doc, :svg, :minx, :miny, :maxx, :maxy, :insu, :mpu, :status, :err)
            ON DUPLICATE KEY UPDATE
                svg_path = VALUES(svg_path), minx = VALUES(minx), miny = VALUES(miny),
                maxx = VALUES(maxx), maxy = VALUES(maxy), insunits = VALUES(insunits),
                meters_per_unit = VALUES(meters_per_unit), status = VALUES(status), error = VALUES(error)
        ");
        $stmt->execute([
            ':doc'    => $documentId,
            ':svg'    => $svgRel,
            ':minx'   => $meta['minx'] ?? null,
            ':miny'   => $meta['miny'] ?? null,
            ':maxx'   => $meta['maxx'] ?? null,
            ':maxy'   => $meta['maxy'] ?? null,
            ':insu'   => $meta['insunits'] ?? null,
            ':mpu'    => $meta['meters_per_unit'] ?? null,
            ':status' => $status,
            ':err'    => $error,
        ]);
        return $this->status($documentId) ?? [];
    }

    /** Estrae l'ultima riga JSON valida dall'output (lo script stampa JSON su stdout). */
    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // prova l'intero output, poi l'ultima riga (in caso di warning sopra)
        $try = [$raw];
        $lines = preg_split('/\r?\n/', $raw);
        if ($lines) $try[] = trim((string)end($lines));
        foreach ($try as $candidate) {
            $j = json_decode($candidate, true);
            if (is_array($j)) return $j;
        }
        return null;
    }
}
