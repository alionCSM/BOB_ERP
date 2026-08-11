<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

final class NotificationsController
{
    public function __construct(private \PDO $conn) {}

    // ── GET /notifications/unread ─────────────────────────────────────────────

    /**
     * Filtro sulla societa' attiva per le query sulle notifiche.
     *
     * Se la colonna non c'e' ancora (migration non applicata) torna vuoto,
     * cosi' le notifiche restano visibili invece di sparire tutte.
     *
     * @return array{sql: string, args: array, presente: bool}
     */
    private function filtroSocieta(): array
    {
        static $presente = null;

        if ($presente === null) {
            try {
                $stmt     = $this->conn->query("SHOW COLUMNS FROM bb_notifications LIKE 'group_company_id'");
                $presente = (bool)($stmt && $stmt->fetch(\PDO::FETCH_ASSOC));
            } catch (\Throwable $e) {
                $presente = false;
            }
        }

        if (!$presente) {
            return ['sql' => '', 'args' => [], 'presente' => false];
        }

        $service = $GLOBALS['currentCompany'] ?? new \App\Service\CurrentCompany($this->conn);
        return [
            'sql'      => ' AND n.group_company_id = :cid',
            'args'     => [':cid' => $service->id()],
            'presente' => true,
        ];
    }

    public function unread(Request $request): never
    {
        try {
            // il pallino e l'elenco seguono la societa' attiva, come il resto
            $filtro = $this->filtroSocieta();

            $stmt = $this->conn->prepare('
                SELECT n.id, n.title, n.message, n.link, n.category, n.priority, n.created_at
                FROM bb_notifications n
                WHERE n.user_id = :uid AND n.is_read = 0' . $filtro['sql'] . '
                ORDER BY n.created_at DESC
                LIMIT 20
            ');
            $stmt->execute([':uid' => $request->user()->id] + $filtro['args']);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            Response::json(['success' => true, 'count' => count($rows), 'notifications' => $rows]);
        } catch (\Exception $e) {
            Response::json(['success' => false, 'message' => 'Errore nel caricamento notifiche'], 500);
        }
    }

    // ── GET /notifications/history ────────────────────────────────────────────

    public function history(Request $request): never
    {
        try {
            $hasReadAt = false;
            $colStmt = $this->conn->query("SHOW COLUMNS FROM bb_notifications LIKE 'read_at'");
            if ($colStmt && $colStmt->fetch(\PDO::FETCH_ASSOC)) {
                $hasReadAt = true;
            }

            $filtro     = $this->filtroSocieta();
            $hasSocieta = $filtro['presente'];

            $colSocieta  = $hasSocieta
                ? ', g.codice AS societa_codice, g.colore AS societa_colore'
                : ', NULL AS societa_codice, NULL AS societa_colore';
            $joinSocieta = $hasSocieta
                ? ' LEFT JOIN bb_group_companies g ON g.id = n.group_company_id'
                : '';
            // anche lo storico resta dentro la societa' in cui si sta lavorando
            $filtroSocieta = $filtro['sql'];
            $argSocieta    = $filtro['args'];

            $letta  = $hasReadAt ? 'n.read_at' : 'NULL AS read_at';
            $ordine = $hasReadAt ? 'COALESCE(n.read_at, n.created_at)' : 'n.created_at';

            $stmt = $this->conn->prepare('
                SELECT n.id, n.title, n.message, n.link, n.created_at, ' . $letta . ',
                       COALESCE(CONCAT(u.first_name, " ", u.last_name), u.username, "Sistema") AS created_by_name
                       ' . $colSocieta . '
                FROM bb_notifications n
                LEFT JOIN bb_users u ON n.created_by = u.id' . $joinSocieta . '
                WHERE n.user_id = :uid AND n.is_read = 1' . $filtroSocieta . '
                ORDER BY ' . $ordine . ' DESC
                LIMIT 50
            ');

            $stmt->execute([':uid' => $request->user()->id] + $argSocieta);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            Response::json(['success' => true, 'notifications' => $rows]);
        } catch (\Exception $e) {
            Response::json([
                'success' => false,
                'message' => 'Errore nel caricamento storico notifiche',
                'error'   => (($_ENV['APP_ENV'] ?? 'production') !== 'production') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ── POST /notifications/action ────────────────────────────────────────────

    public function action(Request $request): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Metodo non consentito'], 405);
        }

        $action         = (string)($_POST['action'] ?? '');
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        $userId         = $request->user()->id;

        if ($action === 'dismiss_priority') {
            try {
                $stmt = $this->conn->prepare("UPDATE bb_users SET last_modal_date = CURDATE() WHERE id = :uid");
                $stmt->execute([':uid' => $userId]);
                Response::json(['success' => true]);
            } catch (\Exception $e) {
                Response::json(['success' => false, 'message' => 'Errore aggiornamento'], 500);
            }
        }

        if ($action !== 'mark_read' || $notificationId <= 0) {
            Response::json(['success' => false, 'message' => 'Parametri non validi'], 422);
        }

        try {
            $hasReadAt = false;
            $colStmt = $this->conn->query("SHOW COLUMNS FROM bb_notifications LIKE 'read_at'");
            if ($colStmt && $colStmt->fetch(\PDO::FETCH_ASSOC)) {
                $hasReadAt = true;
            }

            if ($hasReadAt) {
                $stmt = $this->conn->prepare('UPDATE bb_notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :uid');
            } else {
                $stmt = $this->conn->prepare('UPDATE bb_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
            }

            $stmt->execute([':id' => $notificationId, ':uid' => $userId]);
            Response::json(['success' => true]);
        } catch (\Exception $e) {
            Response::json([
                'success' => false,
                'message' => 'Operazione fallita',
                'error'   => (($_ENV['APP_ENV'] ?? 'production') !== 'production') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ── POST /notifications/push-subscription ────────────────────────────────

    public function savePushSubscription(Request $request): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Metodo non consentito'], 405);
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Response::json(['success' => false, 'message' => 'Payload non valido'], 400);
        }

        $endpoint  = trim((string)($payload['endpoint']         ?? ''));
        $p256dh    = trim((string)($payload['keys']['p256dh']   ?? ''));
        $auth      = trim((string)($payload['keys']['auth']     ?? ''));
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            Response::json(['success' => false, 'message' => 'Dati subscription incompleti'], 422);
        }

        try {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_user_push_subscriptions
                    (user_id, endpoint, p256dh_key, auth_key, user_agent, is_active, last_seen_at, created_at, updated_at)
                VALUES
                    (:user_id, :endpoint, :p256dh_key, :auth_key, :user_agent, 1, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    p256dh_key = VALUES(p256dh_key),
                    auth_key = VALUES(auth_key),
                    user_agent = VALUES(user_agent),
                    is_active = 1,
                    last_seen_at = NOW(),
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':user_id'    => $request->user()->id,
                ':endpoint'   => $endpoint,
                ':p256dh_key' => $p256dh,
                ':auth_key'   => $auth,
                ':user_agent' => $userAgent,
            ]);

            Response::json(['success' => true]);
        } catch (\Exception $e) {
            Response::json([
                'success' => false,
                'message' => 'Salvataggio subscription fallito',
                'error'   => (($_ENV['APP_ENV'] ?? 'production') !== 'production') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
