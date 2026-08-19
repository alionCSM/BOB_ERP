<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/**
 * Typed, central access to all environment variables.
 *
 * Usage:
 *   $cfg = new Config();          // reads $_ENV
 *   $cfg->dbHost();               // 'localhost'
 *   Config::validate();           // call once in bootstrap — throws if required vars missing
 */
final class Config
{
    private array $env;

    public function __construct(?array $env = null)
    {
        $this->env = $env ?? $_ENV;
    }

    // ── Boot-time validation ──────────────────────────────────────────────────

    /**
     * Assert all required env vars are present.
     * Call once from bootstrap.php — throws RuntimeException on first missing var.
     */
    public static function validate(?array $env = null): void
    {
        $env ??= $_ENV;

        $required = [
            'APP_ENV',
            'APP_URL',
            'DB_HOST',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'MAIL_HOST',
            'MAIL_USER',
            'MAIL_PASS',
        ];

        foreach ($required as $key) {
            if (empty($env[$key])) {
                throw new RuntimeException("Required environment variable \"{$key}\" is not set.");
            }
        }
    }

    // ── App ───────────────────────────────────────────────────────────────────

    public function appEnv(): string
    {
        return $this->get('APP_ENV', 'production');
    }

    public function isProduction(): bool
    {
        return $this->appEnv() === 'production';
    }

    public function appUrl(): string
    {
        return rtrim($this->require('APP_URL'), '/');
    }

    /**
     * Maximum upload size in bytes for documents, photos, attachments.
     * Configurable via UPLOAD_MAX_MB env var; defaults to 50 MB.
     *
     * NOTE: PHP's own upload_max_filesize / post_max_size in php.ini must
     * be at least this value, otherwise PHP rejects the upload before
     * application code runs.
     */
    public function maxUploadBytes(): int
    {
        $mb = (int)($this->env['UPLOAD_MAX_MB'] ?? 50);
        if ($mb <= 0) {
            $mb = 50;
        }
        return $mb * 1024 * 1024;
    }

    // ── MySQL ─────────────────────────────────────────────────────────────────

    public function dbHost(): string { return $this->require('DB_HOST'); }
    public function dbPort(): int    { return (int) $this->get('DB_PORT', '3306'); }
    public function dbName(): string { return $this->require('DB_NAME'); }
    public function dbUser(): string { return $this->require('DB_USER'); }
    public function dbPass(): string { return $this->require('DB_PASS'); }

    // ── SQL Server ────────────────────────────────────────────────────────────

    public function sqlSrvHost(): string    { return $this->require('SQLSRV_HOST'); }
    public function sqlSrvPort(): int       { return (int) $this->get('SQLSRV_PORT', '1433'); }
    public function sqlSrvDb(): string      { return $this->require('SQLSRV_DB'); }
    public function sqlSrvUser(): string    { return $this->require('SQLSRV_USER'); }
    public function sqlSrvPass(): string    { return $this->require('SQLSRV_PASS'); }
    public function sqlSrvEncrypt(): bool   { return $this->get('SQLSRV_ENCRYPT', 'true') === 'true'; }
    public function sqlSrvTrustCert(): bool { return $this->get('SQLSRV_TRUST_CERT', 'true') === 'true'; }

    // ── SQL Server — Business (TeamSystem), sola lettura ──────────────────────
    // Sorgente separata da Yard: se le variabili BUSINESS_* non sono presenti
    // si ricade su quelle di Yard (utile se i due database stanno sulla stessa
    // istanza), tranne il nome del database che va sempre indicato.

    public function businessConfigured(): bool { return $this->get('BUSINESS_DB', '') !== ''; }
    public function businessHost(): string     { return $this->get('BUSINESS_HOST', '')  ?: $this->sqlSrvHost(); }
    public function businessPort(): int        { return (int) ($this->get('BUSINESS_PORT', '') ?: (string)$this->sqlSrvPort()); }
    public function businessDb(): string       { return $this->require('BUSINESS_DB'); }
    public function businessUser(): string     { return $this->get('BUSINESS_USER', '')  ?: $this->sqlSrvUser(); }
    public function businessPass(): string     { return $this->get('BUSINESS_PASS', '')  ?: $this->sqlSrvPass(); }
    public function businessEncrypt(): bool    { return ($this->get('BUSINESS_ENCRYPT', '')    ?: ($this->sqlSrvEncrypt()   ? 'true' : 'false')) === 'true'; }
    public function businessTrustCert(): bool  { return ($this->get('BUSINESS_TRUST_CERT', '') ?: ($this->sqlSrvTrustCert() ? 'true' : 'false')) === 'true'; }
    /** Codice ditta usato nelle tabelle Business (colonna codditt). */
    public function businessCodDitta(): string { return $this->get('BUSINESS_CODDITTA', 'CSM'); }

    // ── Mail ──────────────────────────────────────────────────────────────────

    public function mailHost(): string       { return $this->require('MAIL_HOST'); }
    public function mailUser(): string       { return $this->require('MAIL_USER'); }
    public function mailPass(): string       { return $this->require('MAIL_PASS'); }
    public function mailPort(): int          { return (int) $this->get('MAIL_PORT', '587'); }
    public function mailEncryption(): string { return $this->get('MAIL_ENCRYPTION', 'tls'); }

    /** @param string $channel system|alerts|hr|billing|security */
    public function mailFrom(string $channel): string
    {
        return $this->require('MAIL_' . strtoupper($channel) . '_FROM');
    }

    /** @param string $channel system|alerts|hr|billing|security */
    public function mailName(string $channel): string
    {
        return $this->require('MAIL_' . strtoupper($channel) . '_NAME');
    }

    // ── OnlyOffice ────────────────────────────────────────────────────────────

    public function onlyOfficeSecret(): string { return $this->get('ONLYOFFICE_JWT_SECRET', ''); }
    public function onlyOfficeUrl(): string    { return rtrim($this->get('ONLYOFFICE_SERVER_URL', ''), '/'); }

    // ── Cloud ─────────────────────────────────────────────────────────────────

    public function cloudRoot(): string { return $this->env['CLOUD_ROOT'] ?? getenv('CLOUD_ROOT') ?: ''; }

    // ── Push notifications ────────────────────────────────────────────────────

    public function vapidPublicKey(): string { return $this->get('VAPID_PUBLIC_KEY', ''); }

    /**
     * FCM (app Android). Il service account non va nel .env: si indica un
     * FILE JSON sul server (FCM_SERVICE_ACCOUNT_FILE) o, in alternativa, la
     * stringa JSON stessa (FCM_SERVICE_ACCOUNT_JSON). Senza nessuna delle due
     * la push e' disattivata e BOB funziona normalmente.
     */
    public function fcmServiceAccountFile(): string  { return $this->get('FCM_SERVICE_ACCOUNT_FILE', ''); }
    public function fcmServiceAccountJson(): string  { return $this->get('FCM_SERVICE_ACCOUNT_JSON', ''); }

    // ── AI / Ollama ───────────────────────────────────────────────────────────

    public function ollamaUrl(): string   { return $this->get('OLLAMA_URL', ''); }
    public function ollamaModel(): string { return $this->get('MODEL', ''); }

    // ── Fieldwire ─────────────────────────────────────────────────────────────

    public function fieldwireToken(): string  { return $this->get('FIELDWIRE_API_TOKEN', ''); }
    public function fieldwireRegion(): string { return $this->get('FIELDWIRE_REGION', 'eu'); }
    public function fieldwireEnabled(): bool  { return !empty($this->get('FIELDWIRE_API_TOKEN', '')); }

    public function attestatoUrl(): string { return rtrim($this->get('ATTESTATO_URL', 'https://docs.csmontaggi.it'), '/'); }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function require(string $key): string
    {
        if (empty($this->env[$key])) {
            throw new RuntimeException("Required environment variable \"{$key}\" is not set.");
        }
        return (string) $this->env[$key];
    }

    private function get(string $key, string $default = ''): string
    {
        return isset($this->env[$key]) && $this->env[$key] !== ''
            ? (string) $this->env[$key]
            : $default;
    }
}
