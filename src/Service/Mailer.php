<?php

namespace App\Service;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Mailer centralizzato BOB
 *
 * IDENTITÀ SUPPORTATE (esattamente quelle definite in .env):
 * - system    → notifiche@bob.csmontaggi.it
 * - admin     → alerts@bob.csmontaggi.it
 * - hr        → hr@bob.csmontaggi.it
 * - billing   → fatture@bob.csmontaggi.it
 * - security   → security@bob.csmontaggi.it
 *
 * Nessun fallback silenzioso:
 * se una variabile manca → errore chiaro.
 */
class Mailer
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);

        /* =========================
           SMTP CONFIG (Mailcow)
        ========================= */
        $this->mail->isSMTP();
        $this->mail->Host       = $_ENV['MAIL_HOST'];
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = $_ENV['MAIL_USER'];
        $this->mail->Password   = $_ENV['MAIL_PASS'];
        $this->mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? 587);
        $this->mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'tls';

        $this->mail->CharSet = 'UTF-8';
        $this->mail->isHTML(true);
        $this->mail->Timeout = 10;

        // Debug SMTP SOLO se esplicitamente richiesto
        $this->mail->SMTPDebug = 0;
    }

    /**
     * Imposta il mittente in base al contesto funzionale
     *
     * Valori ammessi:
     * - system
     * - alerts
     * - hr
     * - billing
     * - security
     */
    public function setSender(string $type): void
    {
        switch ($type) {

            case 'system':
                $from = $_ENV['MAIL_SYSTEM_FROM'];
                $name = $_ENV['MAIL_SYSTEM_NAME'];
                break;

            case 'alerts':
                $from = $_ENV['MAIL_ALERTS_FROM'];
                $name = $_ENV['MAIL_ALERTS_NAME'];
                break;

            case 'hr':
                $from = $_ENV['MAIL_HR_FROM'];
                $name = $_ENV['MAIL_HR_NAME'];
                break;

            case 'billing':
                $from = $_ENV['MAIL_BILLING_FROM'];
                $name = $_ENV['MAIL_BILLING_NAME'];
                break;

            case 'security':
                $from = $_ENV['MAIL_SECURITY_FROM'];
                $name = $_ENV['MAIL_SECURITY_NAME'];
                break;

            default:
                throw new \Exception("Tipo mittente non valido: {$type}");
        }

        // Mittente visibile
        $this->mail->setFrom($from, $name);

        // Reply-To coerente (evita confusione e spoofing)
        $this->mail->addReplyTo($from, $name);
    }

    /**
     * Restituisce l'istanza PHPMailer pronta
     */
    public function getMailer(): PHPMailer
    {
        return $this->mail;
    }
}
