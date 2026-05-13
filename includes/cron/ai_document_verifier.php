<?php
/**
 * BOB Document Verifier — Nightly Cron Job
 *
 * Rilegge ogni notte i documenti dei tipi monitorati (UNILAV, Visita medica),
 * estrae il testo via pdftotext/OCR, chiede a Ollama se i metadati dichiarati
 * corrispondono al contenuto reale, e manda UN'EMAIL con i sospetti.
 *
 * Run nightly via cron (3:00):
 *   0 3 * * * /usr/bin/php /var/www/bob.csmontaggi.it/public/includes/cron/ai_document_verifier.php
 *
 * Environment richiesto:
 *   OLLAMA_URL  — endpoint LLM
 *   MODEL       — nome modello (es. Qwen3-30B-A3B-Q4_K_M.gguf)
 *   MAIL_*      — config SMTP
 *
 * Pacchetti server richiesti:
 *   poppler-utils         (per pdftotext / pdftoppm)
 *   tesseract-ocr         (OCR fallback)
 *   tesseract-ocr-ita     (pacchetto lingua italiana)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/bootstrap.php';

use App\Infrastructure\Database;
use App\Service\OllamaClient;
use App\Service\Mailer;
use App\Service\DocumentVerifierService;
use App\Infrastructure\LoggerFactory;

$logger = LoggerFactory::app();

try {
    $db   = new Database();
    $conn = $db->connect();

    // Endpoint LLM dedicato alla lettura documenti (es. modello più piccolo/
    // veloce). Cade su OLLAMA_URL/MODEL se DOC_CHECK_URL/DOC_CHECK_MODEL
    // non sono settate.
    $ollamaUrl = $_ENV['DOC_CHECK_URL']   ?? ($_ENV['OLLAMA_URL'] ?? '');
    $model     = $_ENV['DOC_CHECK_MODEL'] ?? ($_ENV['MODEL']      ?? '');
    if (!$ollamaUrl || !$model) {
        echo "DOC_CHECK_URL/MODEL (o OLLAMA_URL/MODEL come fallback) mancanti in .env. Esco.\n";
        exit(2);
    }
    echo "Using LLM endpoint: {$model} @ {$ollamaUrl}\n";
    $ai = new OllamaClient($ollamaUrl, $model);

    if (empty($_ENV['MAIL_HOST'])) {
        echo "MAIL_HOST mancante in .env. Esco.\n";
        exit(2);
    }
    $mailer = new Mailer();

    $service = new DocumentVerifierService($conn, $ai, $mailer);
    $service->run();

    $logger->info('ai_document_verifier: completed');
    exit(0);
} catch (Throwable $e) {
    $logger->error('ai_document_verifier: fatal error', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
