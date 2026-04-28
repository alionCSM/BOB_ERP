<?php

namespace App\Domain;
use PDO;

class UserAnalytics
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function countOnlineUsers(): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT user_id)
            FROM bb_page_analytics
            WHERE created_at >= NOW() - INTERVAL 2 MINUTE
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function getOnlineUsers(): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                u.first_name,
                u.last_name,
                pa.page,
                pa.active_seconds
            FROM bb_page_analytics pa
            JOIN bb_users u ON u.id = pa.user_id
            WHERE pa.updated_at >= NOW() - INTERVAL 2 MINUTE
            ORDER BY pa.updated_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTopUsers(string $range = 'today'): array
    {
        $where = match ($range) {
            '7days'  => "pa.created_at >= NOW() - INTERVAL 7 DAY",
            '30days' => "pa.created_at >= NOW() - INTERVAL 30 DAY",
            default  => "pa.created_at >= CURDATE()",
        };

        $stmt = $this->conn->prepare("
            SELECT
                u.first_name,
                u.last_name,
                SUM(pa.active_seconds) AS total_seconds
            FROM bb_page_analytics pa
            JOIN bb_users u ON u.id = pa.user_id
            WHERE $where
            GROUP BY u.id
            ORDER BY total_seconds DESC
            LIMIT 10
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecentActions(int $limit = 10): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                u.first_name,
                u.last_name,
                al.action,
                al.page
            FROM bb_user_activity_log al
            JOIN bb_users u ON u.id = al.user_id
            ORDER BY al.created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
