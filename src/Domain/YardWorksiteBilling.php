<?php

declare(strict_types=1);

namespace App\Domain;

use PDO;
use App\Infrastructure\SqlServerConnection;

/**
 * Handles billing record CRUD in the Yard SQL Server database (CNT_cantieri_brogliacci).
 * Injects SqlServerConnection — does NOT extend it.
 */
class YardWorksiteBilling
{
    private readonly PDO $conn;

    public function __construct(SqlServerConnection $sqlServer)
    {
        $this->conn = $sqlServer->connect();
    }

    /**
     * Inserisce una nuova riga in CNT_cantieri_brogliacci.
     * Imposta attivita_id a NULL (gestito dal catalogo Yard).
     */
    public function insertToBrogliaccio(array $data): int
    {
        $sql = "
            INSERT INTO CNT_cantieri_brogliacci (
                nome_cantiere, nome_cliente, conto, anno, data, numero, descrizione,
                quantita, aliquota_iva, sconto1, sconto2, spese_accessorie, totale_imponibile,
                totale_imposta, totale_documento, totale_pagato, data_creazione, data_modifica,
                storico, obsoleto, guid, articolo_id, cantiere_id, iva_id, attivita_id,
                emessa, tm_anno, tm_numdoc
            )
            VALUES (
                :nome_cantiere, :nome_cliente, 0, 0, :data, 0, :descrizione,
                1, :aliquota_iva, 0, 0, 0, :totale_imponibile,
                0, 0, 0, GETDATE(), GETDATE(),
                0, 0, NEWID(), :articolo_id, :cantiere_id, :iva_id, NULL,
                0, 0, 0
            );
            SELECT CAST(SCOPE_IDENTITY() AS INT) AS newId;
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':nome_cantiere'     => $data['nome_cantiere'],
            ':nome_cliente'      => $data['nome_cliente'],
            ':data'              => $data['data'],
            ':descrizione'       => $data['descrizione'],
            ':aliquota_iva'      => $data['aliquota_iva'],
            ':totale_imponibile' => $data['totale_imponibile'],
            ':articolo_id'       => $data['articolo_id'],
            ':cantiere_id'       => $data['cantiere_id'],
            ':iva_id'            => $data['iva_id'],
        ]);

        $stmt->nextRowset();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Aggiorna una riga esistente in CNT_cantieri_brogliacci.
     */
    public function updateBrogliaccio(int $id, array $data): void
    {
        $sql = "
            UPDATE dbo.CNT_cantieri_brogliacci
            SET
                data              = :data,
                nome_cantiere     = :nome_cantiere,
                nome_cliente      = :nome_cliente,
                descrizione       = :descrizione,
                aliquota_iva      = :aliquota_iva,
                totale_imponibile = :totale_imponibile,
                articolo_id       = :articolo_id,
                cantiere_id       = :cantiere_id,
                iva_id            = :iva_id,
                data_modifica     = GETDATE()
            WHERE id = :id
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':id'                => $id,
            ':data'              => $data['data'],
            ':nome_cantiere'     => $data['nome_cantiere'],
            ':nome_cliente'      => $data['nome_cliente'],
            ':descrizione'       => $data['descrizione'],
            ':aliquota_iva'      => $data['aliquota_iva'],
            ':totale_imponibile' => $data['totale_imponibile'],
            ':articolo_id'       => $data['articolo_id'],
            ':cantiere_id'       => $data['cantiere_id'],
            ':iva_id'            => $data['iva_id'],
        ]);
    }

    public function isEmessa(int $yardId): bool
    {
        $stmt = $this->conn->prepare("
            SELECT emessa
            FROM dbo.CNT_cantieri_brogliacci
            WHERE id = :id
        ");
        $stmt->execute([':id' => $yardId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Segna come obsoleto un record di CNT_cantieri_brogliacci.
     */
    public function softDeleteBrogliaccio(int $id): void
    {
        $stmt = $this->conn->prepare("
            UPDATE dbo.CNT_cantieri_brogliacci
            SET obsoleto = 1, data_modifica = GETDATE()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }
}
