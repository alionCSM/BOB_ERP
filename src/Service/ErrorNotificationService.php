<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Config;

/**
 * Error notification service
 *
 * Sends email notifications to superadmin on 500 errors
 */
final class ErrorNotificationService
{
    private \PDO $conn;
    private Config $config;

    public function __construct(\PDO $conn, Config $config)
    {
        $this->conn = $conn;
        $this->config = $config;
    }

    /**
     * Get superadmin email (user with id=1)
     *
     * @return string|null
     */
    private function getSuperadminEmail(): ?string
    {
        try {
            $stmt = $this->conn->prepare("SELECT email FROM bb_users WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row['email'] ?? null;
        } catch (\Exception $e) {
            \App\Infrastructure\LoggerFactory::app()->error('[ErrorNotificationService] Failed to fetch superadmin email: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send error notification email
     *
     * @param \Throwable $exception The exception that occurred
     * @param array $context Additional context (request, user, etc.)
     * @return bool True if email sent successfully
     */
    public function notify(\Throwable $exception, array $context = []): bool
    {
        $adminEmail = $this->getSuperadminEmail();

        if (empty($adminEmail)) {
            \App\Infrastructure\LoggerFactory::app()->error('[ErrorNotificationService] No superadmin email found in bb_users (id=1)');
            return false;
        }

        try {
            // Get error ID from context
            $errorId = $context['error_id'] ?? null;

            $subject = sprintf(
                '[BOB ERROR] %s - %s',
                $this->config->appEnv(),
                $exception::class
            );

            // Add error ID to subject if available
            if ($errorId) {
                $subject .= " - {$errorId}";
            }

            $body = $this->buildEmailBody($exception, $context);

            // Send email
            $mailer = new Mailer();
            $mailer->setSender('alerts');
            $mail = $mailer->getMailer();

            $mail->addAddress($adminEmail);
            $mail->Subject = $subject;
            $mail->Body = $body;

            // Set priority for production emails
            if ($this->config->isProduction()) {
                $mail->set('X-Priority', '1');  // Highest priority
                $mail->set('X-MSMail-Priority', 'High');
                $mail->set('Importance', 'High');
            }

            $mail->send();

            return true;
        } catch (\Exception $e) {
            // Don't throw - logging failure shouldn't crash the app
            \App\Infrastructure\LoggerFactory::app()->error('[ErrorNotificationService] Failed to send notification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build HTML email body
     *
     * @param \Throwable $exception
     * @param array $context
     * @return string
     */
    private function buildEmailBody(\Throwable $exception, array $context): string
    {
        $appUrl = $this->config->appUrl();
        $environment = $this->config->appEnv();
        $envColor = $environment === 'production' ? '#dc2626' : '#059669';

        $userEmail = $context['user_email'] ?? 'N/A';
        $userUsername = $context['user_username'] ?? 'N/A';
        $requestUri = $context['request_uri'] ?? $_SERVER['REQUEST_URI'] ?? 'N/A';
        $remoteAddr = $context['remote_addr'] ?? $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $requestMethod = $context['request_method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'N/A';

        $exceptionClass = htmlspecialchars($exception::class);
        $exceptionMessage = htmlspecialchars($exception->getMessage());
        $exceptionFile = htmlspecialchars($exception->getFile());
        $exceptionLine = $exception->getLine();
        $exceptionTrace = htmlspecialchars($exception->getTraceAsString());
        $requestUriEsc = htmlspecialchars($requestUri);
        $requestMethodEsc = htmlspecialchars($requestMethod);
        $userUsernameEsc = htmlspecialchars($userUsername);
        $userEmailEsc = htmlspecialchars($userEmail);
        $remoteAddrEsc = htmlspecialchars($remoteAddr);
        $environmentUpper = strtoupper($environment);
        $currentDate = date('d/m/Y H:i:s');
        $errorId = $context['error_id'] ?? null;
        $errorIdDisplay = $errorId ? htmlspecialchars($errorId) : 'N/A';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        .code-block { background: #1f2937; color: #f3f4f6; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 11px; font-family: 'Courier New', monospace; }
    </style>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center" style="padding:30px 10px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:700px;background:#ffffff;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.08);">

                <!-- Header -->
                <tr>
                    <td style="padding:20px 30px;text-align:center;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="font-size:24px;font-weight:bold;color:#1f2937;">⚠️ Errore BOB</td>
                                <td align="right">
                                    <span style="background:{$envColor};color:#ffffff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:bold;">
                                        {$environmentUpper}
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Error Summary -->
                <tr>
                    <td style="padding:0 30px 20px 30px;">
                        <div style="background:#fef2f2;border-left:4px solid #dc2626;padding:15px;margin-bottom:20px;">
                            <strong style="color:#991b1b;font-size:14px;">Tipo Errore:</strong>
                            <div style="color:#dc2626;margin-top:5px;font-weight:bold;">
                                {$exceptionClass}
                            </div>
                        </div>

                        <div style="background:#fef2f2;border-left:4px solid #dc2626;padding:15px;margin-bottom:20px;">
                            <strong style="color:#991b1b;font-size:14px;">Messaggio:</strong>
                            <div style="color:#dc2626;margin-top:5px;">
                                {$exceptionMessage}
                            </div>
                        </div>

                        <div style="background:#fff7ed;border-left:4px solid #f97316;padding:15px;margin-bottom:20px;">
                            <strong style="color:#c2410c;font-size:14px;">File & Linea:</strong>
                            <div style="color:#ea580c;margin-top:5px;">
                                {$exceptionFile}:{$exceptionLine}
                            </div>
                        </div>

                        <div style="background:#f0f9ff;border-left:4px solid #0284c7;padding:15px;margin-bottom:20px;">
                            <strong style="color:#0369a1;font-size:14px;">Codice Errore:</strong>
                            <div style="color:#0284c7;margin-top:5px;font-family:'Courier New',monospace;font-size:16px;font-weight:bold;">
                                {$errorIdDisplay}
                            </div>
                        </div>
                    </td>
                </tr>

                <!-- Request Context -->
                <tr>
                    <td style="padding:0 30px 20px 30px;">
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:15px;border-radius:6px;margin-bottom:20px;">
                            <strong style="color:#475569;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;">Contesto Richiesta</strong>
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;">
                                <tr><td style="padding:4px 0;color:#64748b;font-size:13px;"><strong>URL:</strong></td><td style="padding:4px 0;color:#1e293b;font-size:13px;">{$requestUriEsc}</td></tr>
                                <tr><td style="padding:4px 0;color:#64748b;font-size:13px;"><strong>Metodo:</strong></td><td style="padding:4px 0;color:#1e293b;font-size:13px;">{$requestMethodEsc}</td></tr>
                                <tr><td style="padding:4px 0;color:#64748b;font-size:13px;"><strong>Utente:</strong></td><td style="padding:4px 0;color:#1e293b;font-size:13px;">{$userUsernameEsc} ({$userEmailEsc})</td></tr>
                                <tr><td style="padding:4px 0;color:#64748b;font-size:13px;"><strong>IP:</strong></td><td style="padding:4px 0;color:#1e293b;font-size:13px;">{$remoteAddrEsc}</td></tr>
                                <tr><td style="padding:4px 0;color:#64748b;font-size:13px;"><strong>Data/Ora:</strong></td><td style="padding:4px 0;color:#1e293b;font-size:13px;">{$currentDate}</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>

                <!-- Stack Trace -->
                <tr>
                    <td style="padding:0 30px 20px 30px;">
                        <div style="margin-bottom:15px;">
                            <strong style="color:#475569;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;">Stack Trace</strong>
                        </div>
                        <div class="code-block">
{$exceptionTrace}
                        </div>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding:20px 30px;text-align:center;border-top:1px solid #e5e7eb;">
                        <p style="margin:0;color:#9ca3af;font-size:12px;">
                            Generato da BOB Error Notification System<br>
                            {$appUrl}
                        </p>
                    </td>
                </tr>

            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;
    }
}
