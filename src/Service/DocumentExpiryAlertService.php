<?php

declare(strict_types=1);

namespace App\Service;
use DateTime;
use Exception;
use PDO;
use App\Repository\Documents\WorkerDocumentRepository;
use App\Service\Mailer;

/**
 * Document Expiry Alert Service
 *
 * Sends email alerts for expired and about-to-expire documents.
 * Designed to run as a daily cron job.
 *
 * Alert schedule:
 * - 30 days before expiry (sent once)
 * - 7 days before expiry (sent once)
 * - On expiry day (sent once)
 *
 * Recipients:
 * - Internal users with 'document_alerts' permission → all companies
 * - Company_viewer users → only their allowed companies
 *
 * Emails are one per company per recipient (digest format).
 */
class DocumentExpiryAlertService
{
    private PDO $conn;
    private WorkerDocumentRepository $repository;
    private string $appUrl;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->repository = new WorkerDocumentRepository($conn);
        $this->appUrl = rtrim($_ENV['APP_URL'] ?? 'https://bob.csmontaggi.it', '/');
    }

    public function run(): void
    {
        $today = date('Y-m-d');
        $in7days = date('Y-m-d', strtotime('+7 days'));
        $in30days = date('Y-m-d', strtotime('+30 days'));

        echo "[" . date('Y-m-d H:i:s') . "] Document expiry alert cron started\n";

        // ── 1. Ensure alert log table exists ──
        $this->ensureTableExists();

        // ── 2. Collect all documents needing alerts ──
        // Documents expiring in exactly 30 days
        $docs30d = $this->collectDocuments($in30days, '30d');
        // Documents expiring in exactly 7 days
        $docs7d = $this->collectDocuments($in7days, '7d');
        // Documents already expired
        $docsExpired = $this->collectExpiredDocuments($today);

        // ── 3. Merge all docs by company ──
        $allDocsByCompany = $this->groupByCompany($docs30d, $docs7d, $docsExpired);

        if (empty($allDocsByCompany)) {
            echo "  No documents needing alerts today.\n";
            return;
        }

        echo "  Found documents in " . count($allDocsByCompany) . " companies\n";

        // ── 4. Get recipients ──
        $internalRecipients = $this->getInternalRecipients();
        $companyViewerRecipients = $this->getCompanyViewerRecipients();

        echo "  Internal recipients: " . count($internalRecipients) . "\n";
        echo "  Company viewer recipients: " . count($companyViewerRecipients) . "\n";

        $totalSent = 0;

        // ── 5. Send alerts to internal recipients (all companies) ──
        foreach ($internalRecipients as $recipient) {
            foreach ($allDocsByCompany as $companyName => $companyDocs) {
                $sent = $this->sendAlertForCompany($recipient, $companyName, $companyDocs, false);
                if ($sent) $totalSent++;
            }
        }

        // ── 6. Send alerts to company_viewer recipients (only their companies) ──
        foreach ($companyViewerRecipients as $recipient) {
            $allowedCompanies = $this->getAllowedCompanyNames((int)$recipient['id']);
            foreach ($allDocsByCompany as $companyName => $companyDocs) {
                // Check if this company is in the viewer's allowed list
                $isAllowed = false;
                foreach ($allowedCompanies as $allowed) {
                    if (mb_strtolower(trim($allowed)) === mb_strtolower(trim($companyName))) {
                        $isAllowed = true;
                        break;
                    }
                }
                if (!$isAllowed) continue;

                $sent = $this->sendAlertForCompany($recipient, $companyName, $companyDocs, true);
                if ($sent) $totalSent++;
            }
        }

        echo "  Total emails sent: {$totalSent}\n";
        echo "[" . date('Y-m-d H:i:s') . "] Document expiry alert cron completed\n";
    }

    /**
     * Collect worker + company documents expiring on exact date.
     */
    private function collectDocuments(string $date, string $alertType): array
    {
        $workerDocs = $this->repository->getWorkerDocumentsExpiringOnDate($date);
        $companyDocs = $this->repository->getCompanyDocumentsExpiringOnDate($date);

        $results = [];
        foreach ($workerDocs as $doc) {
            $results[] = array_merge($doc, [
                '_source' => 'worker',
                '_alert_type' => $alertType,
                '_entity_name' => $doc['worker_name'],
            ]);
        }
        foreach ($companyDocs as $doc) {
            $results[] = array_merge($doc, [
                '_source' => 'company',
                '_alert_type' => $alertType,
                '_entity_name' => $doc['company_name'] . ' (Aziendale)',
            ]);
        }

        return $results;
    }

    /**
     * Collect all currently expired documents.
     */
    private function collectExpiredDocuments(string $today): array
    {
        // Use existing repository methods (no company scope — we scope per recipient later)
        $workerDocs = $this->repository->getExpiredWorkerDocuments($today, false, []);
        $companyDocs = $this->repository->getExpiredCompanyDocuments($today, false, []);

        $results = [];
        foreach ($workerDocs as $doc) {
            $results[] = array_merge($doc, [
                '_source' => 'worker',
                '_alert_type' => 'expired',
                '_entity_name' => $doc['worker_name'],
            ]);
        }
        foreach ($companyDocs as $doc) {
            $results[] = array_merge($doc, [
                '_source' => 'company',
                '_alert_type' => 'expired',
                '_entity_name' => $doc['company_name'] . ' (Aziendale)',
            ]);
        }

        return $results;
    }

    /**
     * Group documents by company name → { '30d': [...], '7d': [...], 'expired': [...] }
     */
    private function groupByCompany(array $docs30d, array $docs7d, array $docsExpired): array
    {
        $grouped = [];

        foreach ($docs30d as $doc) {
            $company = $doc['company_name'] ?? 'Sconosciuta';
            $grouped[$company]['30d'][] = $doc;
        }
        foreach ($docs7d as $doc) {
            $company = $doc['company_name'] ?? 'Sconosciuta';
            $grouped[$company]['7d'][] = $doc;
        }
        foreach ($docsExpired as $doc) {
            $company = $doc['company_name'] ?? 'Sconosciuta';
            $grouped[$company]['expired'][] = $doc;
        }

        return $grouped;
    }

    /**
     * Get internal users with 'document_alerts' permission.
     */
    private function getInternalRecipients(): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id, u.email, u.first_name, u.last_name
            FROM bb_users u
            INNER JOIN bb_user_permissions p ON p.user_id = u.id
            WHERE p.module = 'document_alerts'
              AND p.allowed = 1
              AND u.active = 'Y'
              AND u.removed = 'N'
              AND u.email IS NOT NULL
              AND u.email != ''
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get company_viewer users with valid email.
     */
    private function getCompanyViewerRecipients(): array
    {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT u.id, u.email, u.first_name, u.last_name
            FROM bb_users u
            WHERE (u.role = 'company_viewer' OR u.id IN (
                SELECT DISTINCT user_id FROM bb_user_company_access
            ))
              AND u.active = 'Y'
              AND u.removed = 'N'
              AND u.email IS NOT NULL
              AND u.email != ''
              AND u.type != 'worker'
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get allowed company names for a user (from bb_user_company_access).
     */
    private function getAllowedCompanyNames(int $userId): array
    {
        $stmt = $this->conn->prepare("
            SELECT c.name
            FROM bb_user_company_access uca
            INNER JOIN bb_companies c ON c.id = uca.company_id
            WHERE uca.user_id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Send alert email for a specific company to a specific recipient.
     * Returns true if an email was actually sent, false if all alerts already sent.
     */
    private function sendAlertForCompany(array $recipient, string $companyName, array $companyDocs, bool $isCompanyViewer = false): bool
    {
        $userId = (int)$recipient['id'];
        $email = $recipient['email'];

        // Filter out already-sent alerts
        $unsent30d = $this->filterUnsent($companyDocs['30d'] ?? [], $userId);
        $unsent7d = $this->filterUnsent($companyDocs['7d'] ?? [], $userId);
        $unsentExpired = $this->filterUnsent($companyDocs['expired'] ?? [], $userId);

        // Nothing to send
        if (empty($unsent30d) && empty($unsent7d) && empty($unsentExpired)) {
            return false;
        }

        // Build and send email
        $subject = "Riepilogo Documenti - " . $companyName;
        $body = $this->buildEmailBody($companyName, $unsentExpired, $unsent7d, $unsent30d, $isCompanyViewer);

        try {
            $mailer = new Mailer();
            $mailer->setSender('alerts');
            $mail = $mailer->getMailer();
            $mail->addAddress($email);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();

            // Log all sent alerts
            $this->logSentAlerts($unsent30d, $userId);
            $this->logSentAlerts($unsent7d, $userId);
            $this->logSentAlerts($unsentExpired, $userId);

            echo "    Sent to {$email} for '{$companyName}' (" .
                count($unsentExpired) . " expired, " .
                count($unsent7d) . " 7d, " .
                count($unsent30d) . " 30d)\n";

            return true;
        } catch (\Exception $e) {
            echo "    ERROR sending to {$email}: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Filter out documents whose alerts have already been sent to this recipient.
     */
    private function filterUnsent(array $docs, int $userId): array
    {
        if (empty($docs)) return [];

        $unsent = [];
        foreach ($docs as $doc) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM bb_document_alert_log
                WHERE document_id = :doc_id
                  AND document_source = :source
                  AND alert_type = :alert_type
                  AND recipient_user_id = :user_id
            ");
            $stmt->execute([
                ':doc_id' => (int)$doc['id'],
                ':source' => $doc['_source'],
                ':alert_type' => $doc['_alert_type'],
                ':user_id' => $userId,
            ]);

            if ((int)$stmt->fetchColumn() === 0) {
                $unsent[] = $doc;
            }
        }

        return $unsent;
    }

    /**
     * Log sent alerts to prevent duplicates.
     */
    private function logSentAlerts(array $docs, int $userId): void
    {
        if (empty($docs)) return;

        $stmt = $this->conn->prepare("
            INSERT IGNORE INTO bb_document_alert_log
            (document_id, document_source, alert_type, recipient_user_id)
            VALUES (:doc_id, :source, :alert_type, :user_id)
        ");

        foreach ($docs as $doc) {
            $stmt->execute([
                ':doc_id' => (int)$doc['id'],
                ':source' => $doc['_source'],
                ':alert_type' => $doc['_alert_type'],
                ':user_id' => $userId,
            ]);
        }
    }

    /**
     * Build the HTML email body.
     */
    private function buildEmailBody(string $companyName, array $expired, array $sevenDay, array $thirtyDay, bool $isCompanyViewer = false): string
    {
        $templateFile = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/includes/templates/email/document_expiry_alert.php';

        $today = new \DateTime();
        $ctaLink = $isCompanyViewer ? '/documents/expired-cv' : '/documents/expired';

        ob_start();
        include $templateFile;
        return ob_get_clean();
    }

    /**
     * Ensure the alert log table exists (auto-create on first run).
     */
    private function ensureTableExists(): void
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS bb_document_alert_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                document_source ENUM('worker','company') NOT NULL,
                alert_type ENUM('30d','7d','expired') NOT NULL,
                recipient_user_id INT NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_alert (document_id, document_source, alert_type, recipient_user_id),
                INDEX idx_recipient (recipient_user_id),
                INDEX idx_sent_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
