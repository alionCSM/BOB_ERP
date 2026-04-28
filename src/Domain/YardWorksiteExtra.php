<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infrastructure\SqlServerConnection;

/**
 * YardWorksiteExtra domain service.
 *
 * Handles CRUD operations for yard extra items in the YardManager SQL Server database.
 * Uses the existing SqlServerConnection infrastructure class.
 */
class YardWorksiteExtra
{
    private SqlServerConnection $sqlServer;

    public function __construct(SqlServerConnection $sqlServer)
    {
        $this->sqlServer = $sqlServer;
    }

    /**
     * Insert a new extra item into the yard database.
     *
     * @param array<string, mixed> $data
     * @return string Returns the inserted GUID
     */
    public function insertToYard(array $data): string
    {
        $conn = $this->sqlServer->connect();

        $stmt = $conn->prepare("
            INSERT INTO dbo.CNT_cantieri_attivita (
                descrizione, totale_preventivato, data_creazione, data_modifica,
                storico, obsoleto, guid, cantiere_id, data, numero_ordine
            )
            VALUES (
                :descrizione, :totale, GETDATE(), GETDATE(),
                0, 0, NEWID(), :yard_worksite_id, :data, :numero_ordine
            )
        ");

        $stmt->execute([
            ':descrizione'        => $data['descrizione'],
            ':totale'             => $data['totale'],
            ':yard_worksite_id'   => $data['yard_worksite_id'],
            ':data'               => $data['data'],
            ':numero_ordine'      => $data['ordine'],
        ]);

        return (string) $conn->lastInsertId();
    }

    /**
     * Update an existing extra item in the yard database.
     *
     * @param string $yardId
     * @param array<string, mixed> $data
     */
    public function updateInYard(int|string $yardId, array $data): void
    {
        $conn = $this->sqlServer->connect();

        $stmt = $conn->prepare("
            UPDATE dbo.CNT_cantieri_attivita
            SET descrizione = :descrizione,
                totale_preventivato = :totale,
                data_modifica = GETDATE(),
                numero_ordine = :numero_ordine
            WHERE id = :yard_id
        ");

        $stmt->execute([
            ':yard_id'      => $yardId,
            ':descrizione'  => $data['descrizione'],
            ':totale'       => $data['totale'],
            ':numero_ordine'=> $data['ordine'],
        ]);
    }

    /**
     * Soft delete an extra item in the yard database.
     *
     * @param string $yardId
     */
    public function softDeleteInYard(int|string $yardId): void
    {
        $conn = $this->sqlServer->connect();

        $stmt = $conn->prepare("
            UPDATE dbo.CNT_cantieri_attivita
            SET obsoleto = 1, data_modifica = GETDATE()
            WHERE id = :yard_id
        ");

        $stmt->execute([':yard_id' => $yardId]);
    }
}
