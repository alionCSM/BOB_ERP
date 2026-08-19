<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Infrastructure\Config;
use Firebase\JWT\JWT;
use PDO;

/**
 * FcmService — invio notifiche push Firebase Cloud Messaging (HTTP v1).
 *
 * Configurazione (Config):
 *   FCM_SERVICE_ACCOUNT_FILE  /percorso/service-account.json   (preferita)
 *   FCM_SERVICE_ACCOUNT_JSON  {"type":"service_account",...}   (in alternativa)
 *
 * Senza service account la push e' semplicemente disattivata: ogni invio
 * torna false e il resto di BOB non e' toccato.
 *
 * Il token OAuth2 per l'API FCM viene firmato (RS256) con la chiave del
 * service account e cacheato in memoria per la durata della richiesta; ogni
 * processo (web request, cron) lo riscrive, costo trascurabile.
 */
final class FcmService
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const OAUTH_URL    = 'https://oauth2.googleapis.com/token';
    private const SCOPE        = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array<string,mixed>|null Service account gia' letto */
    private ?array $account = null;
    /** true se il caricamento e' gia' fallito (evita retry e log ripetuti) */
    private bool $accountFailed = false;
    /** @var array{token:string,expires:int}|null OAuth token cache (richiesta) */
    private ?array $oauth = null;

    public function __construct(
        private PDO    $conn,
        private Config $config,
    ) {}

    public function configured(): bool
    {
        return $this->loadAccount() !== null;
    }

    /**
     * Invia una notifica push a UN dispositivo (fcm token).
     *
     * @param array<string,string> $data Chiusure extra (notification_id, link, ...)
     *
     * Ritorna true se FCM ha accettato la messaggistica. Se il token e'
     * scaduto/non valido FCM risponde 404: il dispositivo viene disattivato.
     */
    public function sendToDevice(
        string $fcmToken,
        string $title,
        string $body,
        array  $data = [],
    ): bool
    {
        $account = $this->loadAccount();
        if ($account === null) {
            return false;
        }

        $accessToken = $this->oauthToken($account);
        if ($accessToken === null) {
            $this->log('OAuth token FCM non ottenuto');
            return false;
        }

        $payload = [
            'message' => [
                'token'       => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'data'    => $data,
                'android' => [
                    'priority' => 'high',
                ],
            ],
        ];

        $endpoint = sprintf(self::FCM_ENDPOINT, rawurlencode((string)$account['project_id']));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response  = curl_exec($ch);
        $status    = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log("FCM curl error: {$curlError}");
            return false;
        }

        // 404/410: token non valido o dismesso — lo disattivo nel DB
        if ($status === 404 || $status === 410) {
            $this->deactivateDevice($fcmToken);
            $this->log("FCM token non valido (HTTP {$status}), dispositivo disattivato");
            return false;
        }

        if ($status < 200 || $status >= 300) {
            $this->log("FCM HTTP {$status}: " . substr((string)$response, 0, 500));
            return false;
        }

        return true;
    }

    // ── Interni ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function loadAccount(): ?array
    {
        if ($this->account !== null) {
            return $this->account;
        }
        if ($this->accountFailed) {
            return null;
        }

        $json = $this->config->fcmServiceAccountJson();
        if ($json === '') {
            $file = $this->config->fcmServiceAccountFile();
            if ($file !== '' && is_readable($file)) {
                $json = (string)file_get_contents($file);
            }
        }

        if ($json === '') {
            $this->accountFailed = true;
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['project_id']) || empty($data['private_key']) || empty($data['client_email'])) {
            $this->accountFailed = true;
            $this->log('FCM service account non valido (manca project_id/private_key/client_email)');
            return null;
        }

        $this->account = $data;
        return $data;
    }

    private function oauthToken(array $account): ?string
    {
        if ($this->oauth !== null && $this->oauth['expires'] > time() + 60) {
            return $this->oauth['token'];
        }

        $now = time();
        $jwt = JWT::encode([
            'iss'   => (string)$account['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::OAUTH_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], (string)$account['private_key'], 'RS256');

        $ch = curl_init(self::OAUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'     => $jwt,
            ]),
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log("FCM OAuth curl error: {$curlError}");
            return null;
        }

        $data = json_decode((string)$response, true);
        $token = is_array($data) ? (string)($data['access_token'] ?? '') : '';

        if ($token === '') {
            $this->log('FCM OAuth fallito: ' . substr((string)$response, 0, 300));
            return null;
        }

        $this->oauth = [
            'token'   => $token,
            'expires' => $now + ((int)($data['expires_in'] ?? 3600)),
        ];

        return $token;
    }

    private function deactivateDevice(string $fcmToken): void
    {
        try {
            $this->conn->prepare("UPDATE bb_push_devices SET is_active = 0 WHERE fcm_token = :t")
                ->execute([':t' => $fcmToken]);
        } catch (\Throwable $e) {
            $this->log('Impossibile disattivare dispositivo FCM: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        try {
            \App\Infrastructure\LoggerFactory::app()->warning('[FCM] ' . $message);
        } catch (\Throwable $e) {
            error_log('[FCM] ' . $message);
        }
    }
}
