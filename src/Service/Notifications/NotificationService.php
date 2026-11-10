<?php

declare(strict_types=1);

namespace App\Service\Notifications;

use App\Infrastructure\Config;
use App\Service\Push\FcmService;
use PDO;

/**
 * NotificationService — punto UNICO di creazione notifiche BOB.
 *
 * Prima di questa classe le notifiche venivano inserite con SQL sparsi in
 * piu' controller e cron. Ogni nuovo canale push (FCM per Android, web push
 * in futuro) avrebbe dovuto essere agganciato a ogni singolo INSERT.
 *
 * Da ora chi crea una notifica chiama NotificationService::create(): la riga
 * entra in bb_notifications (come prima) e il push ai dispositivi registrati
 * parte qui, una sola volta, per chiunque — controller, cron, webhook.
 *
 * Il push e' best-effort: un fallimento FCM non deve mai rompere la
 * notifica in-app, per questo l'invio e' dentro try/catch e viene loggato.
 */
final class NotificationService
{
    /** @var bool|null Presenza della colonna group_company_id (cache per richiesta) */
    private ?bool $hasGroupCompany = null;

    /** @var FcmService|null Lazy: non viene costruito se FCM non e' configurato */
    private ?FcmService $fcm = null;

    public function __construct(
        private PDO    $conn,
        private Config $config,
    ) {}

    /**
     * Crea una notifica e la spinge sui dispositivi mobili dell'utente.
     *
     * @param int         $userId          Destinatario (bb_users.id)
     * @param string      $title           Titolo (breve, compare nel banner)
     * @param string      $message         Corpo del messaggio
     * @param string      $link            Link interno da aprire (es. /worksites/12), '' se nessuno
     * @param string      $category        info | worksite | documenti | ...
     * @param string      $priority        normal | high
     * @param int|null    $createdBy       Chi ha generato la notifica (null = sistema)
     * @param int|null    $groupCompanyId  Societa' del gruppo di riferimento (null = tutte)
     *
     * @return int id della notifica creata
     */
    public function create(
        int         $userId,
        string      $title,
        string      $message,
        string      $link          = '',
        string      $category      = 'info',
        string      $priority      = 'normal',
        ?int        $createdBy     = null,
        ?int        $groupCompanyId = null,
    ): int
    {
        $columns = 'user_id, title, message, link, category, priority, created_by, is_read, created_at';
        $values  = ':user_id, :title, :message, :link, :category, :priority, :created_by, 0, NOW()';
        $params  = [
            ':user_id'    => $userId,
            ':title'      => $title,
            ':message'    => $message,
            ':link'       => $link,
            ':category'   => $category,
            ':priority'   => $priority,
            ':created_by' => $createdBy,
        ];

        if ($groupCompanyId !== null && $this->hasGroupCompanyColumn()) {
            $columns .= ', group_company_id';
            $values  .= ', :group_company_id';
            $params[':group_company_id'] = $groupCompanyId;
        }

        $stmt = $this->conn->prepare("INSERT INTO bb_notifications ({$columns}) VALUES ({$values})");
        $stmt->execute($params);

        $id = (int)$this->conn->lastInsertId();

        $this->pushToDevices($userId, $id, $title, $message, $link, $category, $priority);
        $this->emailAlCliente($userId, $id, $title, $message, $link);

        return $id;
    }

    // ── Email ai clienti ──────────────────────────────────────────────────────

    /**
     * Al cliente la notifica arriva anche per email.
     *
     * Un committente usa BOB dal sito, quando se ne ricorda: senza l'app la
     * campanella non la vede nessuno, e una domanda dell'ufficio restava li'
     * finche' non gli capitava di entrare. L'email lo porta dentro.
     *
     * Una sola email finche' non ha letto: se ha gia' notifiche non lette
     * della mezz'ora prima, l'email l'ha gia' avuta e una chat vivace non
     * gli riempie la casella. Come il push, non deve mai rompere la
     * notifica: se l'email non parte, resta quella in BOB.
     */
    private function emailAlCliente(int $userId, int $notificationId, string $title, string $message, string $link): void
    {
        try {
            $st = $this->conn->prepare("
                SELECT email, first_name FROM bb_users
                WHERE  id = :u AND type = 'client' AND active = 'Y' AND removed = 'N'
            ");
            $st->execute([':u' => $userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || !filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $prima = $this->conn->prepare("
                SELECT 1 FROM bb_notifications
                WHERE  user_id = :u AND id <> :id AND is_read = 0
                  AND  created_at >= NOW() - INTERVAL 30 MINUTE
                LIMIT 1
            ");
            $prima->execute([':u' => $userId, ':id' => $notificationId]);
            if ($prima->fetchColumn()) {
                return;
            }

            $sito  = \App\Service\EmailBob::sito();
            $url   = $sito . ($link !== '' ? $link : '/worksites/my');
            $h     = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
            $nome  = trim((string)($u['first_name'] ?? ''));

            $mailer = new \App\Service\Mailer();
            $mailer->setSender('system');
            $mail = $mailer->getMailer();
            $mail->addAddress((string)$u['email']);
            $mail->Subject = 'BOB – ' . $title;
            $mail->Body    = \App\Service\EmailBob::pagina(
                $title,
                '<p style="margin:0 0 12px">' . ($nome !== '' ? 'Buongiorno ' . $h($nome) . ',' : 'Buongiorno,') . '</p>'
                . '<div style="padding:14px 16px;background:#F8FAFC;border-left:4px solid #1E40AF;border-radius:8px">'
                . nl2br($h($message)) . '</div>',
                ['Apri in BOB', $url],
                'Ricevi questa email perché hai accesso a un cantiere in BOB.<br>'
                . 'Altri avvisi arrivano solo dopo che hai letto questo.'
            );
            $mail->AltBody = $title . "\n\n" . $message . "\n\n" . $url;
            $mail->send();
        } catch (\Throwable $e) {
            error_log('[Email cliente] ' . $e->getMessage());
        }
    }

    // ── Push ──────────────────────────────────────────────────────────────────

    private function pushToDevices(
        int    $userId,
        int    $notificationId,
        string $title,
        string $message,
        string $link,
        string $category,
        string $priority,
    ): void
    {
        try {
            if (!$this->fcm()->configured()) {
                return;
            }

            $stmt = $this->conn->prepare("
                SELECT fcm_token
                FROM   bb_push_devices
                WHERE  user_id = :uid AND is_active = 1
            ");
            $stmt->execute([':uid' => $userId]);
            $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $data = [
                'notification_id' => (string)$notificationId,
                'link'            => $link,
                'category'        => $category,
                'priority'        => $priority,
            ];

            foreach ($tokens as $token) {
                $this->fcm()->sendToDevice((string)$token, $title, $message, $data);
            }
        } catch (\Throwable $e) {
            // Il push non deve mai rompere la notifica in-app
            try {
                \App\Infrastructure\LoggerFactory::app()->error('[Push] Invio notifica fallito: ' . $e->getMessage(), [
                    'notification_id' => $notificationId,
                    'user_id'         => $userId,
                ]);
            } catch (\Throwable $logEx) {
                error_log('[Push] ' . $e->getMessage());
            }
        }
    }

    private function fcm(): FcmService
    {
        if ($this->fcm === null) {
            $this->fcm = new FcmService($this->conn, $this->config);
        }
        return $this->fcm;
    }

    private function hasGroupCompanyColumn(): bool
    {
        if ($this->hasGroupCompany === null) {
            try {
                $stmt = $this->conn->query("SHOW COLUMNS FROM bb_notifications LIKE 'group_company_id'");
                $this->hasGroupCompany = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
            } catch (\Throwable $e) {
                $this->hasGroupCompany = false;
            }
        }
        return $this->hasGroupCompany;
    }
}
