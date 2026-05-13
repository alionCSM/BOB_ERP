<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\WorkerDocumentTypes;
use PDO;
use Throwable;

/**
 * Notturno: verifica che i documenti dei tipi monitorati (UNILAV, Visita
 * medica) corrispondano davvero a quanto dichiarato dall'utente che li
 * ha caricati. Usa BOB AI (Ollama locale) per leggere il testo del PDF
 * e confrontarlo coi metadati.
 *
 * Senza tabella di stato: ogni notte rigira tutti i documenti monitorati
 * — se l'utente corregge il file/metadati nel frattempo, il giorno dopo
 * BOB non lo segnala più.
 *
 * Output: una singola email con i sospetti. Niente sospetti -> niente email.
 */
final class DocumentVerifierService
{
    /**
     * Tipi di documento monitorati: tutti quelli presenti nella tendina del
     * modal di upload (WorkerDocumentTypes::all).
     */
    private function monitoredWorkerTypes(): array
    {
        return WorkerDocumentTypes::all();
    }

    /** Soglia minima di confidenza dell'LLM per considerare un mismatch reale. */
    private const MISMATCH_CONFIDENCE_THRESHOLD = 70;

    /** Email destinatario (hardcoded per fase di rodaggio). */
    private const REPORT_RECIPIENT = 'alion@csmontaggi.it';

    /** Numero massimo di pagine PDF da analizzare per documento. */
    private const MAX_PAGES = 2;

    /** Numero massimo di caratteri di testo da inviare a Ollama (verifica notturna). */
    private const MAX_TEXT_CHARS = 6000;

    /** Caratteri per il suggerimento al volo (più stretto -> più veloce). */
    private const MAX_TEXT_CHARS_SUGGEST = 2500;

    private PDO $conn;
    private OllamaClient $ai;
    private Mailer $mailer;
    private string $cloudRoot;

    /** @var array<int, array<string,mixed>> */
    private array $findings = [];

    public function __construct(PDO $conn, OllamaClient $ai, Mailer $mailer)
    {
        $this->conn   = $conn;
        $this->ai     = $ai;
        $this->mailer = $mailer;

        // Cloud root come negli altri service che servono file
        $envRoot = $_ENV['CLOUD_ROOT'] ?? getenv('CLOUD_ROOT');
        if ($envRoot) {
            $this->cloudRoot = rtrim($envRoot, '/\\');
        } else {
            $fallback = realpath(dirname(APP_ROOT) . '/cloud');
            $this->cloudRoot = $fallback ?: (dirname(APP_ROOT) . '/cloud');
        }
    }

    public function run(): void
    {
        echo "=== BOB Document Verifier — " . date('Y-m-d H:i:s') . " ===\n\n";

        $candidates = $this->findWorkerDocumentsToVerify();
        echo "Documenti da verificare: " . count($candidates) . "\n\n";

        $t0 = microtime(true);
        $i  = 0;
        foreach ($candidates as $doc) {
            $i++;
            echo "[{$i}/" . count($candidates) . "] ID={$doc['id']} tipo='{$doc['tipo_documento']}' operaio='{$doc['worker_name']}' ... ";
            try {
                $this->verifyWorkerDocument($doc);
                echo "ok\n";
            } catch (Throwable $e) {
                echo "ERRORE: {$e->getMessage()}\n";
            }
        }
        $elapsed = round(microtime(true) - $t0, 1);
        echo "\nVerifica completata in {$elapsed}s. Trovati " . count($this->findings) . " sospetti.\n";

        if (!empty($this->findings)) {
            $this->sendReport();
        } else {
            echo "Niente da segnalare. Nessuna email inviata.\n";
        }
    }

    // ── Selezione documenti ──────────────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    private function findWorkerDocumentsToVerify(): array
    {
        $types = $this->monitoredWorkerTypes();
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $sql = "
            SELECT
                wd.id, wd.worker_id, wd.tipo_documento, wd.scadenza, wd.path,
                CONCAT(TRIM(wr.last_name), ' ', TRIM(wr.first_name)) AS worker_name,
                wr.fiscal_code AS worker_cf
            FROM bb_worker_documents wd
            JOIN bb_workers wr ON wr.id = wd.worker_id
            WHERE wr.active = 'Y'
              AND wr.removed = 'N'
              AND COALESCE(wd.nascondere, 'N') != 'Y'
              AND wd.tipo_documento IN ({$placeholders})
              AND wd.path IS NOT NULL
              AND wd.path != ''
            ORDER BY wd.id ASC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($types);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Suggerimento al volo per modal di upload ─────────────────────────────

    /**
     * Analizza un file PDF appena caricato e ritorna i metadati suggeriti:
     * tipo documento, data emissione, scadenza.
     *
     * Pensato per essere chiamato da un endpoint AJAX nel form di upload.
     *
     * @return array{type:?string, emission:?string, expiry:?string, confidence:int, note:string}
     */
    public function suggestForUpload(string $pdfPath): array
    {
        $blank = ['type' => null, 'emission' => null, 'expiry' => null, 'confidence' => 0, 'note' => ''];

        $text = $this->extractText($pdfPath);
        $textLen = mb_strlen(trim($text));
        error_log("[DocumentVerifier] suggestForUpload: extracted_text_len={$textLen}");
        if ($textLen < 30) {
            return $blank + ['note' => "Testo illeggibile (estratti {$textLen} char). Verifica che poppler-utils e tesseract-ocr-ita siano installati sul server."];
        }

        $types = $this->monitoredWorkerTypes();
        $typesList = implode(' | ', $types);

        // Prompt ultra-snello: poche istruzioni, output JSON breve.
        // Meno token = risposta più rapida (target ~3-5s anziché 10-15).
        $systemPrompt = <<<PROMPT
Classifica il documento. Risposta SOLO JSON, niente altro.

Tipi ammessi: {$typesList} | Altro

Schema:
{"tipo_documento":"...","data_emissione":"YYYY-MM-DD","data_scadenza":"YYYY-MM-DD","confidenza":0-100}

Note:
- UNILAV a tempo indeterminato: data_scadenza="INDETERMINATO"
- Se non riconosci: "Altro" e confidenza bassa
- Niente invenzioni
PROMPT;

        $userPrompt = mb_substr($text, 0, self::MAX_TEXT_CHARS_SUGGEST);

        // max_tokens basso (la JSON è di ~120 char) + temperatura più stretta
        $result = $this->ai->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            ['temperature' => 0.1, 'max_tokens' => 200]
        );
        if (!($result['ok'] ?? false)) {
            error_log("[DocumentVerifier] LLM call failed: " . ($result['error'] ?? 'unknown'));
            return $blank + ['note' => 'BOB AI non disponibile (' . ($result['error'] ?? 'errore') . ').'];
        }

        $raw = trim((string)($result['response'] ?? ''));
        error_log("[DocumentVerifier] LLM raw response (first 300 chars): " . mb_substr($raw, 0, 300));
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            error_log("[DocumentVerifier] JSON parse failed. Raw: " . $raw);
            return $blank + ['note' => 'Risposta AI non interpretabile (vedi log server).'];
        }
        error_log("[DocumentVerifier] LLM parsed: tipo='" . ($json['tipo_documento'] ?? '') . "' confidenza=" . ($json['confidenza'] ?? '-'));

        return [
            'type'       => $this->normalizeType((string)($json['tipo_documento'] ?? '')),
            'emission'   => $this->normalizeDate((string)($json['data_emissione'] ?? '')),
            'expiry'     => $this->normalizeExpiry((string)($json['data_scadenza'] ?? '')),
            'confidence' => max(0, min(100, (int)($json['confidenza'] ?? 0))),
            'note'       => mb_substr((string)($json['note'] ?? ''), 0, 150),
        ];
    }

    private function normalizeType(string $type): ?string
    {
        $type = trim($type);
        if ($type === '' || strcasecmp($type, 'Altro') === 0) return null;
        // Caso-insensitive match con uno dei tipi conosciuti
        foreach ($this->monitoredWorkerTypes() as $known) {
            if (strcasecmp($type, $known) === 0) return $known;
        }
        return $type; // fallback: ritorna così com'è, datalist può comunque mostrarlo
    }

    private function normalizeDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $s);
            if ($dt && $dt->format($fmt) === $s) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }

    private function normalizeExpiry(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        if (strcasecmp($s, 'INDETERMINATO') === 0) return 'INDETERMINATO';
        return $this->normalizeDate($s);
    }

    // ── Verifica singolo documento ───────────────────────────────────────────

    private function verifyWorkerDocument(array $doc): void
    {
        $filePath = realpath($this->cloudRoot . '/' . $doc['path']);
        if (!$filePath || !is_file($filePath)) {
            // File mancante è già un'anomalia, ma non è scopo di QUESTO check.
            // Lascia che lo trovi checkDocumenti standard.
            return;
        }

        $text = $this->extractText($filePath);
        if (mb_strlen(trim($text)) < 30) {
            // Testo troppo corto per analizzare (PDF protetto / immagine vuota /
            // OCR fallito). Non posso dire niente — skip silenzioso.
            return;
        }

        $analysis = $this->askLlmAboutWorkerDoc(
            (string)$doc['tipo_documento'],
            (string)$doc['worker_name'],
            (string)($doc['scadenza'] ?? ''),
            $text
        );
        if ($analysis === null) {
            return;
        }

        $issues = $this->compareWorkerDoc($doc, $analysis);
        if (!empty($issues)) {
            $this->findings[] = [
                'doc'      => $doc,
                'analysis' => $analysis,
                'issues'   => $issues,
            ];
        }
    }

    /**
     * Confronta i metadati dichiarati con quelli letti dall'LLM.
     * Ritorna l'elenco dei problemi rilevati (vuoto = ok).
     *
     * @return string[]
     */
    private function compareWorkerDoc(array $doc, array $a): array
    {
        $issues = [];

        $confidence = (int)($a['confidenza'] ?? 0);
        if ($confidence < self::MISMATCH_CONFIDENCE_THRESHOLD) {
            // BOB non è abbastanza sicuro per dire qualcosa.
            return [];
        }

        // 1) Tipo documento
        $declaredType = trim((string)$doc['tipo_documento']);
        $detectedType = trim((string)($a['tipo_documento_rilevato'] ?? ''));
        if ($detectedType !== '' && !$this->typesLooseMatch($declaredType, $detectedType)) {
            $issues[] = "Tipo dichiarato: <strong>{$declaredType}</strong> &mdash; rilevato: <strong>{$detectedType}</strong>";
        }

        // 2) Nome operaio
        $declaredName = trim((string)$doc['worker_name']);
        $detectedName = trim((string)($a['nome_rilevato'] ?? ''));
        if ($detectedName !== '' && !$this->namesLooseMatch($declaredName, $detectedName)) {
            $issues[] = "Operaio dichiarato: <strong>{$declaredName}</strong> &mdash; rilevato: <strong>{$detectedName}</strong>";
        }

        // 3) Scadenza
        $declaredExpiry = trim((string)($doc['scadenza'] ?? ''));
        $detectedExpiry = trim((string)($a['scadenza_rilevata'] ?? ''));
        if ($declaredExpiry !== '' && $detectedExpiry !== ''
            && !$this->expiriesLooseMatch($declaredExpiry, $detectedExpiry)) {
            $issues[] = "Scadenza dichiarata: <strong>{$declaredExpiry}</strong> &mdash; rilevata: <strong>{$detectedExpiry}</strong>";
        }

        return $issues;
    }

    private function typesLooseMatch(string $a, string $b): bool
    {
        $norm = fn(string $s) => strtolower(preg_replace('/[^a-z0-9]+/i', '', $s) ?? '');
        return $norm($a) === $norm($b);
    }

    private function namesLooseMatch(string $a, string $b): bool
    {
        $tokenize = function (string $s): array {
            $s = strtolower(preg_replace('/[^a-zà-ÿ\s]/iu', '', $s) ?? '');
            $tokens = preg_split('/\s+/', trim($s)) ?: [];
            return array_filter($tokens, fn($t) => mb_strlen($t) >= 3);
        };
        $aTokens = $tokenize($a);
        $bTokens = $tokenize($b);
        if (empty($aTokens) || empty($bTokens)) return true; // niente da confrontare
        $shared = array_intersect($aTokens, $bTokens);
        return count($shared) >= 1;
    }

    private function expiriesLooseMatch(string $a, string $b): bool
    {
        // Valori speciali UNILAV
        $aU = strtoupper($a);
        $bU = strtoupper($b);
        $specials = ['INDETERMINATO', 'INDETERMINATA'];
        $aIsSpecial = in_array($aU, $specials, true);
        $bIsSpecial = in_array($bU, $specials, true);
        if ($aIsSpecial || $bIsSpecial) {
            return $aIsSpecial === $bIsSpecial;
        }

        $parse = function (string $s): ?string {
            $s = trim($s);
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y'] as $fmt) {
                $dt = \DateTime::createFromFormat($fmt, $s);
                if ($dt && $dt->format($fmt) === $s) {
                    return $dt->format('Y-m-d');
                }
            }
            return null;
        };
        $aN = $parse($a);
        $bN = $parse($b);
        if ($aN === null || $bN === null) {
            // Una delle due non è parsabile come data — confronto stringa case-insensitive
            return strtoupper(trim($a)) === strtoupper(trim($b));
        }
        return $aN === $bN;
    }

    // ── Chiamata LLM ─────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function askLlmAboutWorkerDoc(
        string $declaredType,
        string $declaredName,
        string $declaredExpiry,
        string $documentText
    ): ?array {
        $text = mb_substr($documentText, 0, self::MAX_TEXT_CHARS);

        $systemPrompt = <<<PROMPT
Sei BOB, l'assistente del gestionale BOB. Analizzi il testo grezzo (estratto da PDF, possibile OCR rumoroso) di un documento di un operaio e devi estrarre alcuni dati.

I tipi di documento riconosciuti sono:
- UNILAV (comunicazione obbligatoria di assunzione/proroga/cessazione lavoro)
- Visita medica (idoneità sanitaria mansione)
- DURC (regolarità contributiva)
- Visura camerale
- Patente (di guida)
- Carta d'identità
- Codice fiscale (tessera sanitaria)
- Formazione sicurezza
- Permesso di soggiorno
- Altro

Devi rispondere SOLO con un oggetto JSON valido, niente prefisso, niente commenti, niente markdown.

Schema:
{
  "tipo_documento_rilevato": "UNILAV"|"Visita medica"|...|"Altro",
  "nome_rilevato": "Nome Cognome o stringa vuota se non rilevato",
  "scadenza_rilevata": "YYYY-MM-DD" oppure "INDETERMINATO" oppure stringa vuota,
  "confidenza": 0-100,
  "note": "breve nota se serve, massimo 150 caratteri"
}

Regole:
- Per UNILAV la "scadenza" è la data di fine rapporto; se rapporto a tempo indeterminato scrivi "INDETERMINATO".
- Per Visita medica la "scadenza" è la data di prossima revisione/scadenza dell'idoneità.
- Se il testo è troppo confuso o non riconosci il tipo, restituisci "Altro" e confidenza bassa.
- Sii rigoroso: meglio bassa confidenza che invenzioni.
PROMPT;

        $userPrompt = "Documento dichiarato come:\n"
            . "- Tipo: {$declaredType}\n"
            . "- Operaio: {$declaredName}\n"
            . "- Scadenza dichiarata: {$declaredExpiry}\n\n"
            . "Testo del documento (potenzialmente OCR rumoroso):\n\n"
            . "---\n{$text}\n---\n";

        $result = $this->ai->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ]);
        if (!($result['ok'] ?? false)) {
            return null;
        }

        $raw = trim((string)($result['response'] ?? ''));
        if ($raw === '') return null;

        // Strip eventuali ```json``` wrappers o testo prima/dopo
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        return $json;
    }

    // ── Estrazione testo dal PDF ─────────────────────────────────────────────

    private function extractText(string $pdfPath): string
    {
        // 1) Tentativo veloce con pdftotext (poppler)
        $native = $this->runShell(sprintf(
            'pdftotext -layout -f 1 -l %d %s -',
            self::MAX_PAGES,
            escapeshellarg($pdfPath)
        ));
        if (mb_strlen(trim($native)) >= 100) {
            return $native;
        }

        // 2) Fallback OCR (PDF è scansione di immagini)
        return $this->extractTextViaOcr($pdfPath);
    }

    private function extractTextViaOcr(string $pdfPath): string
    {
        $tmpDir = sys_get_temp_dir() . '/bobocr_' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0700, true)) {
            return '';
        }

        try {
            // PDF -> PNG (prime N pagine, 200 dpi)
            $this->runShell(sprintf(
                'pdftoppm -r 200 -f 1 -l %d -png %s %s',
                self::MAX_PAGES,
                escapeshellarg($pdfPath),
                escapeshellarg($tmpDir . '/page')
            ));

            $images = glob($tmpDir . '/page-*.png') ?: [];
            sort($images);

            $allText = '';
            foreach ($images as $img) {
                $allText .= $this->runShell(sprintf(
                    'tesseract %s - -l ita 2>/dev/null',
                    escapeshellarg($img)
                )) . "\n";
            }
            return trim($allText);
        } finally {
            // Cleanup
            foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($tmpDir);
        }
    }

    private function runShell(string $cmd): string
    {
        $out = @shell_exec($cmd . ' 2>/dev/null');
        return $out === null ? '' : (string)$out;
    }

    // ── Reporting email ──────────────────────────────────────────────────────

    private function sendReport(): void
    {
        $count = count($this->findings);

        // Lista findings come righe di card
        $cards = '';
        foreach ($this->findings as $f) {
            $doc = $f['doc'];
            $note = trim((string)($f['analysis']['note'] ?? ''));
            $issuesHtml = '<ul style="margin: 8px 0; padding-left: 18px;">';
            foreach ($f['issues'] as $issue) {
                $issuesHtml .= '<li style="margin-bottom: 4px;">' . $issue . '</li>';
            }
            $issuesHtml .= '</ul>';

            $cards .= '<div style="background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:0 8px 8px 0; padding:14px 18px; margin-bottom:12px;">'
                . '<div style="font-weight:600; color:#92400e; margin-bottom:6px;">Documento #' . htmlspecialchars((string)$doc['id']) . ' — ' . htmlspecialchars((string)$doc['tipo_documento']) . '</div>'
                . '<div style="color:#374151; font-size:13px; margin-bottom:4px;">Operaio: <strong>' . htmlspecialchars((string)$doc['worker_name']) . '</strong></div>'
                . $issuesHtml
                . ($note !== '' ? '<div style="font-size:12px; color:#6b7280; font-style:italic;">' . htmlspecialchars($note) . '</div>' : '')
                . '</div>';
        }

        $word = $count === 1 ? 'cosa' : 'cose';
        $subject = "BOB · Documenti: {$count} {$word} da rivedere";

        $body = '<html><body style="margin:0; padding:20px; background:#f6f7f9; font-family:Arial,sans-serif;">'
            . '<div style="max-width:850px; margin:auto; background:#fff; padding:24px; border-radius:8px;">'
            . '<p style="font-size:16px; color:#1e293b; margin:0 0 12px;">Buongiorno,</p>'
            . '<p style="color:#334155; margin:0 0 18px;">stanotte ho dato un&rsquo;occhiata ai documenti caricati e ho trovato ' . $count . ' ' . $word . ' che non mi tornavano. Magari sono solo io che ho letto male, dai un&rsquo;occhiata quando hai un attimo.</p>'
            . $cards
            . '<p style="color:#64748b; margin-top:24px; font-size:13px;">Se sono falsi allarmi, ignora pure &mdash; il giorno dopo che il file/dichiarazione sono corretti, smetto di segnalarli.</p>'
            . '<p style="color:#94a3b8; font-size:12px; margin:4px 0 0;">&mdash; BOB</p>'
            . '</div></body></html>';

        try {
            $this->mailer->setSender('alerts');
            $mail = $this->mailer->getMailer();
            $mail->clearAddresses();
            $mail->addAddress(self::REPORT_RECIPIENT);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>', '</div>', '</li>'], "\n", $body));
            $mail->send();
            echo "Email inviata a " . self::REPORT_RECIPIENT . ".\n";
        } catch (Throwable $e) {
            echo "Errore invio email: {$e->getMessage()}\n";
        }
    }
}
