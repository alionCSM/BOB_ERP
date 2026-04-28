<?php

namespace App\Domain;
use PDO;

class UserActivity
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function log(
        int $userId,
        string $action,
        ?string $page = null
    ): void {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_user_activity_log
            (user_id, action, page, method, ip_address, user_agent)
            VALUES
            (:uid, :action, :page, :method, :ip, :ua)
        ");

        $stmt->execute([
            ':uid'    => $userId,
            ':action' => $action,
            ':page'   => $page,
            ':method' => $_SERVER['REQUEST_METHOD'] ?? null,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
}
