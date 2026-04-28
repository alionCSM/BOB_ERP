<?php

declare(strict_types=1);

namespace App\Domain;

use PDO;
use PDOException;

/**
 * Handles CRUD operations for worksite records in the Yard SQL Server database.
 * Receives an already-established PDO connection via constructor injection.
 */
class YardWorksite
{
    public function __construct(private readonly PDO $conn) {}

    // Method to save worksite data to SQL Server (dbo.CNT_cantieri)
    public function createYardWorksite(array $data): int|false
    {
        $query = "INSERT INTO dbo.CNT_cantieri (
            nome_cantiere,
            descrizione,
            numero_ordine,
            data_ordine,
            data_inizio,
            totale_contratto,
            totale_fatturato,
            avanzamento,
            totale_presenze,
            pasti_anticipati,
            pasti_consorzio,
            operatori,
            totale_anticipi,
            totale_rimborsi,
            totale_pasti_anticipati,
            totale_pasti_consorzio,
            totale_hotel,
            totale_manodopera,
            data_creazione,
            data_modifica,
            storico,
            obsoleto,
            guid,
            cliente_id,
            ubicazione
        ) VALUES (
            :nome_cantiere,
            :descrizione,
            :numero_ordine,
            :data_ordine,
            :data_inizio,
            :totale_contratto,
            0,  -- totale_fatturato
            0,  -- avanzamento
            0,  -- totale_presenze
            0,  -- pasti_anticipati
            0,  -- pasti_consorzio
            0,  -- operatori
            0,  -- totale_anticipi
            0,  -- totale_rimborsi
            0,  -- totale_pasti_anticipati
            0,  -- totale_pasti_consorzio
            0,  -- totale_hotel
            0,  -- totale_manodopera
            GETDATE(),  -- data_creazione
            GETDATE(),  -- data_modifica
            0,  -- storico
            0,  -- obsoleto
            NEWID(),  -- guid
            :cliente_id,
            :ubicazione
        )";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':nome_cantiere', $data['name']);
            $stmt->bindParam(':descrizione', $data['descrizione']);

            // Composes "order number + commessa" as required by Business
            $numeroOrdine = trim(
                ($data['order_number'] ?? '') . ' ' . ($data['commessa'] ?? '')
            );
            $stmt->bindValue(
                ':numero_ordine',
                $numeroOrdine !== '' ? $numeroOrdine : null,
                $numeroOrdine !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(
                ':data_ordine',
                $data['order_date'],
                $data['order_date'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $stmt->bindParam(':data_inizio', $data['start_date']);
            $stmt->bindParam(':totale_contratto', $data['total_offer']);
            $stmt->bindParam(':cliente_id', $data['yard_client_id']);
            $stmt->bindParam(':ubicazione', $data['location']);

            $stmt->execute();

            return (int) $this->conn->lastInsertId();

        } catch (PDOException $e) {
            \App\Infrastructure\LoggerFactory::database()->error(
                'Errore SQL Server createYardWorksite: ' . $e->getMessage(),
                ['data' => $data]
            );
            return false;
        }
    }

    /**
     * Soft-delete a worksite in Yard (set obsoleto = 1).
     */
    public function softDeleteWorksite(int $yardWorksiteId): bool
    {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE dbo.CNT_cantieri SET obsoleto = 1, data_modifica = GETDATE() WHERE id = :id"
            );
            $stmt->bindParam(':id', $yardWorksiteId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            \App\Infrastructure\LoggerFactory::database()->error(
                'Errore SQL Server softDeleteWorksite: ' . $e->getMessage(),
                ['yardWorksiteId' => $yardWorksiteId]
            );
            return false;
        }
    }

    public function updateYardWorksite(int|string $yardWorksiteId, array $data): bool
    {
        $query = "UPDATE dbo.CNT_cantieri SET
            nome_cantiere    = :nome_cantiere,
            descrizione      = :descrizione,
            numero_ordine    = :numero_ordine,
            data_ordine      = :data_ordine,
            data_inizio      = :data_inizio,
            totale_contratto = :totale_contratto,
            cliente_id       = :cliente_id,
            ubicazione       = :ubicazione
            WHERE id = :yard_worksite_id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':nome_cantiere', $data['name']);
            $stmt->bindParam(':descrizione', $data['descrizione']);

            $numeroOrdine = trim(
                ($data['order_number'] ?? '') . ' ' . ($data['commessa'] ?? '')
            );
            $stmt->bindValue(
                ':numero_ordine',
                $numeroOrdine !== '' ? $numeroOrdine : null,
                $numeroOrdine !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(
                ':data_ordine',
                $data['order_date'],
                $data['order_date'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $stmt->bindParam(':data_inizio', $data['start_date']);
            $stmt->bindParam(':totale_contratto', $data['total_offer']);
            $stmt->bindParam(':cliente_id', $data['yard_client_id']);
            $stmt->bindParam(':ubicazione', $data['location']);
            $stmt->bindParam(':yard_worksite_id', $yardWorksiteId);

            return $stmt->execute();
        } catch (PDOException $e) {
            \App\Infrastructure\LoggerFactory::database()->error(
                'Errore SQL Server updateYardWorksite: ' . $e->getMessage(),
                ['yardWorksiteId' => $yardWorksiteId, 'data' => $data]
            );
            return false;
        }
    }
}
