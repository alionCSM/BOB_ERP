<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Exception;
use PDO;
use PDOException;

/**
 * SQL Server connection (Yard integration).
 * Inject this class — do not reference $_ENV directly.
 */
class SqlServerConnection
{
    private ?PDO $conn = null;

    public function __construct(private readonly Config $config) {}

    public function connect(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        try {
            $server = sprintf(
                '%s,%s',
                $this->config->sqlSrvHost(),
                $this->config->sqlSrvPort()
            );

            $encrypt   = $this->config->sqlSrvEncrypt()   ? 'yes' : 'no';
            $trustCert = $this->config->sqlSrvTrustCert() ? 'yes' : 'no';

            $dsn = sprintf(
                'sqlsrv:Server=%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
                $server,
                $this->config->sqlSrvDb(),
                $encrypt,
                $trustCert
            );

            $this->conn = new PDO(
                $dsn,
                $this->config->sqlSrvUser(),
                $this->config->sqlSrvPass(),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

        } catch (PDOException $e) {
            if ($this->config->isProduction()) {
                \App\Infrastructure\LoggerFactory::database()->error('SQL Server connection error');
                throw new Exception('Errore di connessione al sistema Yard');
            }
            throw new Exception('SQL Server error: ' . $e->getMessage());
        }

        return $this->conn;
    }
}
