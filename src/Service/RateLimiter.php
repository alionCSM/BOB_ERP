<?php
declare(strict_types=1);

namespace App\Service;
use DateTimeImmutable;
use PDO;

class RateLimiter
{
    private PDO $conn;
    private int $maxRequests;
    private int $windowMinutes;

    public function __construct(PDO $conn, int $maxRequests = 200, int $windowMinutes = 10)
    {
        $this->conn          = $conn;
        $this->maxRequests   = $maxRequests;
        $this->windowMinutes = $windowMinutes;
    }

    public function allow(int $userId): array
    {
        // Finestra arrotondata (es: blocchi da 10 minuti)
        $now = new DateTimeImmutable('now');
        $minute = (int)$now->format('i');
        $rounded = $minute - ($minute % $this->windowMinutes);
        $windowStart = $now->setTime((int)$now->format('H'), $rounded, 0)->format('Y-m-d H:i:s');

        $this->conn->beginTransaction();

        try {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_ai_rate_limits (user_id, window_start, requests)
                VALUES (:uid, :ws, 1)
                ON DUPLICATE KEY UPDATE requests = requests + 1
            ");
            $stmt->execute([':uid' => $userId, ':ws' => $windowStart]);

            $stmt = $this->conn->prepare("
                SELECT requests
                FROM bb_ai_rate_limits
                WHERE user_id = :uid AND window_start = :ws
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId, ':ws' => $windowStart]);
            $count = (int)$stmt->fetchColumn();

            $this->conn->commit();

            if ($count > $this->maxRequests) {
                return ['ok' => false, 'reason' => 'rate_limit'];
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            $this->conn->rollBack();
            // In produzione: meglio permettere oppure bloccare? Io blocco per sicurezza.
            return ['ok' => false, 'reason' => 'rate_limit_error'];
        }
    }
}