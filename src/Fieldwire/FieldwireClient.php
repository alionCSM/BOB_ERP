<?php
declare(strict_types=1);

namespace App\Fieldwire;

use RuntimeException;

class FieldwireClient
{
    private const TOKEN_ENDPOINT = 'https://client-api.super.fieldwire.com/api_keys/jwt';
    private const VERSION_HEADER = 'Fieldwire-Version: 2023-11-30';

    private string $refreshToken;
    private string $baseUrl;

    private ?string $accessToken  = null;
    private int     $accessExpiry = 0; // unix timestamp

    public function __construct(string $refreshToken, string $region = 'eu')
    {
        if (empty($refreshToken)) {
            throw new RuntimeException('FIELDWIRE_API_TOKEN not configured in .env');
        }
        $this->refreshToken = $refreshToken;
        $this->baseUrl = $region === 'eu'
            ? 'https://client-api.eu.fieldwire.com/api/v3'
            : 'https://client-api.us.fieldwire.com/api/v3';
    }

    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $this->baseUrl . $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->baseUrl . $path);
    }

    // ── Token management ───────────────────────────────────────────────────────

    private function accessToken(): string
    {
        // Refresh a minute early to avoid edge-case expiry mid-request
        if ($this->accessToken !== null && time() < $this->accessExpiry - 60) {
            return $this->accessToken;
        }

        $this->accessToken  = null;
        $this->accessExpiry = 0;

        $ch = curl_init(self::TOKEN_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['api_token' => $this->refreshToken]),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Fieldwire token request failed: $err");
        }
        if ($http !== 201 && $http !== 200) {
            throw new RuntimeException("Fieldwire token request returned HTTP $http");
        }

        $data = json_decode($raw, true) ?? [];

        if (empty($data['token'])) {
            throw new RuntimeException('Fieldwire did not return an access token');
        }

        $this->accessToken  = $data['token'];
        // expires_in may be provided in seconds; fall back to 30 minutes
        $ttl = (int) ($data['expires_in'] ?? 1800);
        $this->accessExpiry = time() + $ttl;

        return $this->accessToken;
    }

    // ── HTTP ───────────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body = null, bool $isRetry = false): array
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json',
            'Accept: application/json',
            self::VERSION_HEADER,
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Fieldwire cURL error: $err");
        }

        // Access token expired — regenerate once and retry
        if ($http === 401 && !$isRetry) {
            $this->accessToken  = null;
            $this->accessExpiry = 0;
            return $this->request($method, $url, $body, true);
        }

        $data = json_decode($raw, true) ?? [];

        if ($http < 200 || $http >= 300) {
            $message = $data['message'] ?? $data['error'] ?? "HTTP $http";
            throw new RuntimeException("Fieldwire API error ($http): $message");
        }

        return $data;
    }
}
