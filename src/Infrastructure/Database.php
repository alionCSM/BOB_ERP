<?php

namespace App\Infrastructure;
use Exception;
use PDO;
use PDOException;

class Database
{
    private ?PDO $conn = null;

    /**
     * Connessione al database MySQL BOB
     */
    public function connect(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        try {
            // DSN costruito da variabili d'ambiente
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $_ENV['DB_HOST'],
                $_ENV['DB_PORT'] ?? 3306,
                $_ENV['DB_NAME']
            );

            $this->conn = new PDO(
                $dsn,
                $_ENV['DB_USER'],
                $_ENV['DB_PASS'],
                [
                    // errori chiari (fondamentale in collaudo)
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                    // fetch coerente ovunque
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                    // evita problemi con prepared statements
                    PDO::ATTR_EMULATE_PREPARES => false,

                    // rowCount() restituisce righe "trovate" (non solo "modificate")
                    // necessario per UPDATE che rilevano 0 righe cambiate ma record esistente
                    PDO::MYSQL_ATTR_FOUND_ROWS => true,
                ]
            );

        } catch (PDOException $e) {

            // In produzione NON mostriamo dettagli sensibili
            if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
                \App\Infrastructure\LoggerFactory::database()->error('DB connection error');
                throw new Exception('Errore di connessione al database');
            }

            // In collaudo vogliamo sapere ESATTAMENTE cosa non va
            throw new Exception('DB error: ' . $e->getMessage());
        }

        return $this->conn;
    }
}
