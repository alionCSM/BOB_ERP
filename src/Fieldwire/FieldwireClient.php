<?php
declare(strict_types=1);

namespace App\Fieldwire;

use RuntimeException;

class FieldwireClient
{
    private const TOKEN_ENDPOINT = 'https://client-api.super.fieldwire.com/api_keys/jwt';
    private const VERSION_HEADER = 'Fieldwire-Version: 2023-11-30';
    private const PER_PAGE       = 1000; // max consentito (default API: 50)

    private string $refreshToken;
    private string $baseUrl;

    private ?string $accessToken  = null;
    private int     $accessExpiry = 0; // unix timestamp

    /** Response headers dell'ultima richiesta (lowercase name => value). */
    private array $lastHeaders = [];

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

    /**
     * GET con paginazione completa: Fieldwire pagina tramite gli header
     * X-Has-More / X-Last-Synced-At e il query param last_synced_at.
     * Senza questo loop una lista restituirebbe al massimo una pagina.
     */
    public function getAll(string $path, array $query = []): array
    {
        $all = [];
        $guard = 0;
        do {
            $page = $this->get($path, $query);
            if (!is_array($page)) break;
            $all = array_merge($all, $page);

            $hasMore = strtolower((string)($this->lastHeaders['x-has-more'] ?? 'false')) === 'true';
            $cursor  = $this->lastHeaders['x-last-synced-at'] ?? null;
            if ($hasMore && $cursor) {
                $query['last_synced_at'] = $cursor;
            } else {
                $hasMore = false;
            }
        } while ($hasMore && ++$guard < 100); // guardia anti-loop

        return $all;
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

    // ── Helpers per i payload v3 ────────────────────────────────────────────────
    // L'API v3 richiede che il client generi l'id (UUID) delle nuove entita'
    // e i timestamp device_created_at / device_updated_at.

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z');
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
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
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

        // La doc ufficiale documenta "access_token"; accettiamo anche "token"
        // per robustezza verso versioni precedenti dell'endpoint.
        $token = $data['access_token'] ?? $data['token'] ?? null;
        if (empty($token)) {
            throw new RuntimeException('Fieldwire did not return an access token');
        }

        $this->accessToken  = $token;
        // TTL non documentato ("da pochi minuti a poche ore"): teniamo 30 minuti
        // e in ogni caso il retry su 401 rigenera il token.
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
            'Fieldwire-Per-Page: ' . self::PER_PAGE,
        ];

        $this->lastHeaders = [];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $this->lastHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
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
            $message = $data['message'] ?? $data['error'] ?? (is_string($raw) ? substr($raw, 0, 300) : "HTTP $http");
            throw new RuntimeException("Fieldwire API error ($http): $message");
        }

        return $data;
    }
}
