<?php
declare(strict_types=1);

namespace App\Fieldwire\Sync;

use App\Fieldwire\Api\FloorplansApi;
use RuntimeException;

/**
 * Push di un disegno BOB → Fieldwire come sheet (flusso a 3 step S3).
 *
 *   1. POST /sheet_uploads        → Fieldwire risponde con i parametri S3
 *                                    (presigned URL + eventuali campi form)
 *   2. Upload diretto del file su S3 con quei parametri
 *   3. PATCH /sheet_uploads/{id}  → status=uploaded; Fieldwire processa il
 *      file in un floorplan/sheet in modo asincrono (poi webhook
 *      floorplan.created lo risincronizza in BOB)
 *
 * Best-effort: ogni fallimento e' loggato e rilanciato come RuntimeException
 * con messaggio leggibile (il chiamante decide se mostrarlo all'utente).
 * Il disegno BOB resta comunque salvato a prescindere dall'esito.
 */
final class FloorplanSync
{
    public function __construct(private FloorplansApi $api) {}

    /**
     * @param string $absolutePath  path sul filesystem del file da caricare
     * @param string $filename      nome file da mostrare su Fieldwire
     * @return string  id del sheet_upload creato su Fieldwire
     */
    public function pushFile(string $projectId, string $absolutePath, string $filename): string
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException("File non trovato: {$absolutePath}");
        }
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'        => 'application/pdf',
            'png'        => 'image/png',
            'jpg','jpeg' => 'image/jpeg',
            default      => 'application/octet-stream',
        };

        // 1) chiedi i parametri di upload a Fieldwire
        $up = $this->api->createUpload($projectId, $filename, $mime);

        $sheetUploadId = (string)($up['id'] ?? '');
        if ($sheetUploadId === '') {
            throw new RuntimeException('Fieldwire non ha restituito un sheet_upload id');
        }

        // 2) carica il file binario su S3
        $this->uploadToS3($up, $absolutePath, $mime);

        // 3) conferma → Fieldwire processa il floorplan async
        $this->api->confirmUpload($projectId, $sheetUploadId);

        return $sheetUploadId;
    }

    /**
     * Esegue l'upload su S3. Gestisce due formati di risposta Fieldwire:
     *   A) presigned PUT  → campo url/upload_url, si fa una PUT col body grezzo
     *   B) presigned POST → campo url + fields[], si fa multipart form-data
     */
    private function uploadToS3(array $up, string $absolutePath, string $mime): void
    {
        $url    = $up['upload_url'] ?? $up['url'] ?? $up['presigned_url'] ?? null;
        $fields = $up['fields'] ?? $up['form_fields'] ?? null;

        if (!$url) {
            throw new RuntimeException('Risposta sheet_upload senza URL S3: ' . json_encode(array_keys($up)));
        }

        $ch = curl_init($url);

        if (is_array($fields) && !empty($fields)) {
            // ── Presigned POST (multipart) ──
            $post = $fields;
            $post['file'] = new \CURLFile($absolutePath, $mime, basename($absolutePath));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_TIMEOUT        => 60,
            ]);
        } else {
            // ── Presigned PUT (body grezzo) ──
            $fp = fopen($absolutePath, 'rb');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PUT            => true,
                CURLOPT_INFILE         => $fp,
                CURLOPT_INFILESIZE     => filesize($absolutePath),
                CURLOPT_HTTPHEADER     => ['Content-Type: ' . $mime],
                CURLOPT_TIMEOUT        => 60,
            ]);
        }

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if (isset($fp) && is_resource($fp)) fclose($fp);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("Upload S3 fallito (cURL): {$err}");
        }
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException("Upload S3 HTTP {$http}: " . substr((string)$resp, 0, 300));
        }
    }
}
