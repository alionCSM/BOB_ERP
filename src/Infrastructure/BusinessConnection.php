<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Exception;
use PDO;
use PDOException;

/**
 * Connessione al gestionale Business (TeamSystem, SQL Server).
 *
 * SOLA LETTURA: BOB non scrive nulla su Business. Le pagine che la usano
 * fanno solo SELECT aggregate; l'utente configurato nel .env dovrebbe avere
 * i soli permessi di lettura, cosi' il vincolo e' garantito anche lato DB.
 *
 * Sorgente distinta da Yard (SqlServerConnection): stesso driver, credenziali
 * e database diversi.
 */
class BusinessConnection
{
    private ?PDO $conn = null;

    public function __construct(private readonly Config $config) {}

    /** True se le variabili d'ambiente sono presenti (pagina attivabile). */
    public function isConfigured(): bool
    {
        return $this->config->businessConfigured();
    }

    public function connect(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        try {
            $dsn = sprintf(
                'sqlsrv:Server=%s,%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
                $this->config->businessHost(),
                $this->config->businessPort(),
                $this->config->businessDb(),
                $this->config->businessEncrypt()   ? 'yes' : 'no',
                $this->config->businessTrustCert() ? 'yes' : 'no'
            );

            $this->conn = new PDO(
                $dsn,
                $this->config->businessUser(),
                $this->config->businessPass(),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            // All'utente un messaggio generico, ma nel log (privato) la causa
            // vera: senza, diagnosticare un problema di rete o credenziali
            // diventa una caccia al buio.
            LoggerFactory::database()->error('Business connection error: ' . $e->getMessage());
            throw new Exception('Errore di connessione al gestionale Business');
        }

        return $this->conn;
    }
}
