<?php

namespace App\Service;
use PDO;

/**
 * AuditLogger — write security-relevant events to bb_audit_log.
 *
 * Usage (anywhere $connection / $user are available):
 *   AuditLogger::log($connection, $user, 'worker_delete', 'worker', $id, $label);
 *
 * For unauthenticated events (login_failure) pass null for $user:
 *   AuditLogger::log($connection, null, 'login_failure', null, null, $username);
 */
class AuditLogger
{
    /**
     * @param PDO         $conn
     * @param object|null $user        Authenticated user object (needs ->id, ->username); null for pre-auth events
     * @param string      $action      e.g. 'worker_delete', 'login_success'
     * @param string|null $entityType  e.g. 'worker', 'document', 'user'
     * @param int|null    $entityId    Primary key of the affected entity
     * @param string|null $entityLabel Human-readable name at time of event
     * @param array|null  $detail      Extra structured data (will be JSON-encoded)
     */
    public static function log(
        PDO    $conn,
        ?object $user,
        string $action,
        ?string $entityType  = null,
        ?int   $entityId     = null,
        ?string $entityLabel = null,
        ?array $detail       = null
    ): void {
        try {
            $stmt = $conn->prepare("
                INSERT INTO bb_audit_log
                    (user_id, username, action, entity_type, entity_id, entity_label, detail, ip_address, user_agent)
                VALUES
                    (:uid, :username, :action, :entity_type, :entity_id, :entity_label, :detail, :ip, :ua)
            ");

            $stmt->execute([
                ':uid'          => $user ? (int)$user->id : null,
                ':username'     => $user ? (string)($user->username ?? '') : '',
                ':action'       => $action,
                ':entity_type'  => $entityType,
                ':entity_id'    => $entityId,
                ':entity_label' => $entityLabel,
                ':detail'       => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ':ua'           => isset($_SERVER['HTTP_USER_AGENT'])
                                   ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
                                   : null,
            ]);
        } catch (Throwable) {
            // Never let audit failures break the main flow
        }
    }
}
