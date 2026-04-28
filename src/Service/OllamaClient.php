<?php
declare(strict_types=1);

namespace App\Service;
use Exception;

class OllamaClient
{
    private string $url;
    private string $model;

    public function __construct(string $url, string $model)
    {
        $this->url   = $url;
        $this->model = $model;
    }

    /**
     * Send a single prompt (legacy, wraps chat()).
     */
    public function generate(string $prompt): array {
        return $this->chat([
            ['role' => 'user', 'content' => $prompt]
        ]);
    }

    /**
     * Send a multi-turn conversation as an array of messages.
     * Each message: ['role' => 'system'|'user'|'assistant', 'content' => '...']
     */
    public function chat(array $messages): array {
        try {
            $payload = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.2,
                'stream' => false
            ];
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT        => 60,
            ]);
            $t0 = microtime(true);
            $raw = curl_exec($ch);
            $latencyMs = (int)round((microtime(true) - $t0) * 1000);
            if ($raw === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return ['ok' => false, 'error' => "Errore cURL: $err", 'latency_ms' => $latencyMs];
            }
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $json = json_decode($raw, true);
            if ($http < 200 || $http >= 300) {
                return ['ok' => false, 'error' => 'Risposta HTTP non valida', 'latency_ms' => $latencyMs];
            }
            if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
                return ['ok' => false, 'error' => 'Formato risposta Ollama non valido', 'latency_ms' => $latencyMs];
            }
            return ['ok' => true, 'response' => (string)$json['choices'][0]['message']['content'], 'latency_ms' => $latencyMs];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Eccezione: ' . $e->getMessage()];
        }
    }
}