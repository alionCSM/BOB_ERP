<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Service\AiSqlService;
use App\Service\RateLimiter;

class AiSqlController
{
    public const USERS_WITH_PRICE_ACCESS = ['alion', 'laura', 'osman', 'elena', 'ermal'];

    // Only these users (plus user id 1) may use the AI module. The feature
    // executes LLM-generated SQL; access must stay narrow until that surface
    // is properly hardened.
    private const USERS_WITH_AI_ACCESS = ['alion', 'laura', 'osman', 'elena', 'ermal'];

    private \PDO $conn;
    private \App\Service\OllamaClient $client;

    public function __construct(\PDO $conn, \App\Service\OllamaClient $client)
    {
        $this->conn   = $conn;
        $this->client = $client;
    }

    private function assertAiAccess(Request $request): void
    {
        $user     = $request->user();
        $userId   = (int)($user->id ?? 0);
        $username = (string)($user->username ?? '');

        if ($userId === 1) {
            return;
        }
        if ($username !== '' && in_array($username, self::USERS_WITH_AI_ACCESS, true)) {
            return;
        }

        Response::error('Accesso negato.', 403);
    }

    public function chatPage(Request $request): never
    {
        $this->assertAiAccess($request);
        Response::view('ai/chat.html.twig', $request);
    }

    public function exportTable(Request $request): never
    {
        $this->assertAiAccess($request);
        $rawHeaders = $_POST['headers'] ?? '[]';
        $rawRows    = $_POST['rows']    ?? '[]';
        $filename   = $_POST['filename'] ?? ('bob_ai_' . date('Y-m-d') . '.xlsx');

        $headers = json_decode($rawHeaders, true);
        $rows    = json_decode($rawRows,    true);

        if (!is_array($headers) || empty($headers)) {
            http_response_code(400);
            exit('Dati mancanti');
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        // Sanitise filename
        $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);
        if (!str_ends_with($filename, '.xlsx')) {
            $filename .= '.xlsx';
        }

        require_once APP_ROOT . '/views/ai/export_table_excel.php';
        exit;
    }

    public function chat(Request $request): never
    {
        $this->assertAiAccess($request);
        header('Content-Type: application/json');

        $rateLimiter = new RateLimiter($this->conn, 30, 5);
        $userId = (int)($request->user()->id);
        $allow = $rateLimiter->allow($userId);
        if (!$allow['ok']) {
            Response::json(['ok' => false, 'error' => 'Troppe richieste. Riprova tra qualche minuto.'], 429);
        }

        $rawMessages = $_POST['messages'] ?? '';
        $messages = json_decode($rawMessages, true);

        if (!is_array($messages) || empty($messages)) {
            Response::json(['ok' => false, 'error' => 'Messaggio mancante'], 400);
        }
        $lastMessage = end($messages);
        $question = $lastMessage['content'] ?? '';

        if (mb_strlen($question) < 2) {
            Response::json(['ok' => false, 'error' => 'Messaggio troppo corto'], 400);
        }

        if (mb_strlen($question) > 500) {
            Response::json(['ok' => false, 'error' => 'Messaggio troppo lungo (max 500 caratteri)'], 400);
        }

        $history = [];
        $maxTurns = 10;
        for ($i = 0; $i < max(0, count($messages) - 1) && count($history) < $maxTurns; $i++) {
            if ($messages[$i]['role'] !== 'system') {
                $history[] = $messages[$i];
            }
        }

        // ── Build user context ────────────────────────────────────────────────
        $user     = $request->user();
        $username = $user->username ?? '';
        $isSuperAdmin = ((int)$userId === 1);

        // Full name
        $nameStmt = $this->conn->prepare(
            "SELECT first_name, last_name FROM bb_users WHERE id = :id LIMIT 1"
        );
        $nameStmt->execute([':id' => $userId]);
        $nameRow  = $nameStmt->fetch(\PDO::FETCH_ASSOC);
        $fullName = trim(($nameRow['first_name'] ?? '') . ' ' . ($nameRow['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $username;
        }

        // Allowed modules (superadmin = all)
        $allowedModules = [];
        if ($isSuperAdmin) {
            $allowedModules = null; // null = unrestricted
        } else {
            $permStmt = $this->conn->prepare(
                "SELECT module FROM bb_user_permissions WHERE user_id = :uid AND allowed = 1"
            );
            $permStmt->execute([':uid' => $userId]);
            $allowedModules = $permStmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        $userContext = [
            'user_id'          => $userId,
            'username'         => $username,
            'full_name'        => $fullName,
            'is_superadmin'    => $isSuperAdmin,
            'allowed_modules'  => $allowedModules, // null = all, array = specific list
            'can_see_prices'   => $isSuperAdmin
                || in_array($username, self::USERS_WITH_PRICE_ACCESS, true),
            'price_access_list'=> self::USERS_WITH_PRICE_ACCESS,
        ];

        // ── Ask AI ────────────────────────────────────────────────────────────
        $service = new AiSqlService($this->conn, $this->client);

        $startTime = microtime(true);
        try {
            $result = $service->ask($question, $history, $userContext);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Log to DB table
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO bb_ai_sql_logs (user_id, username, message, sql_query, db_source, rows_returned, duration_ms, error_msg, created_at)
                 VALUES (:uid, :user, :msg, :sql, :db, :rows, :dur, :err, NOW())"
            );
            $stmt->execute([
                ':uid'      => $userId,
                ':user'     => $username,
                ':msg'      => $question,
                ':sql'      => $result['query'] ?? null,
                ':db'       => $result['db'] ?? null,
                ':rows'     => $result['rows_returned'] ?? 0,
                ':dur'      => (int)round((microtime(true) - $startTime) * 1000),
                ':err'      => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Non-critical: don't fail the request if logging fails
        }

        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => $result['error']], 400);
        }

        Response::json([
            'ok'          => true,
            'answer'      => $result['answer'],
            'query'       => $result['query'] ?? null,
            'db'          => $result['db'] ?? null,
        ]);
    }
}
