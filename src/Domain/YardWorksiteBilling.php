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
     * Dati del documento (stato + numero + data) per un insieme di righe Yard,
     * in una sola query. Usata dalla scheda cantiere per mostrare numero e data
     * fattura accanto allo stato, senza interrogare Yard riga per riga.
     *
     * @param  int[] $yardIds
     * @return array<int, array{emessa:bool, tm_anno:int, tm_numdoc:int, tm_datdoc:?string, numero_label:?string}>
     */
    public function getDocumentInfoMap(array $yardIds): array
    {
        $map = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $yardIds))));
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->conn->prepare("
                SELECT id, emessa, tm_anno, tm_numdoc, tm_datdoc
                FROM dbo.CNT_cantieri_brogliacci
                WHERE id IN ({$ph})
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $anno = (int)($r['tm_anno']   ?? 0);
                $num  = (int)($r['tm_numdoc'] ?? 0);
                $map[(int)$r['id']] = [
                    'emessa'       => ((int)$r['emessa'] === 1),
                    'tm_anno'      => $anno,
                    'tm_numdoc'    => $num,
                    'tm_datdoc'    => $r['tm_datdoc'] ?? null,
                    'numero_label' => ($anno > 0 && $num > 0) ? ($num . '/' . $anno) : null,
                ];
            }
        }
        return $map;
    }

    /**
     * Stato emessa per un insieme di righe Yard in un colpo solo
     * (per il sync massivo del cron: evita una query per riga).
     *
     * @param  int[] $yardIds
     * @return array<int,bool> yard_id => emessa
     */
    public function getEmessaMap(array $yardIds): array
    {
        $map = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $yardIds))), 500) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->conn->prepare("
                SELECT id, emessa
                FROM dbo.CNT_cantieri_brogliacci
                WHERE id IN ({$ph})
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(int)$r['id']] = ((int)$r['emessa'] === 1);
            }
        }
        return $map;
    }

    /**
     * Righe "emesse reale" (Yard) per un mese.
     * Filtra su emessa=1 e obsoleto=0, ordinate per data DESC.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getEmesseRowsForMonth(int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $nextYear  = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1         : $month + 1;
        $endExcl   = sprintf('%04d-%02d-01', $nextYear, $nextMonth);

        // La data di riferimento e' quella del DOCUMENTO (tm_datdoc), non la
        // data della riga di brogliaccio (data): e' tm_datdoc a stabilire in
        // che mese la fattura e' stata emessa.
        // COALESCE come rete di sicurezza: una riga emessa senza tm_datdoc
        // resterebbe altrimenti invisibile in qualsiasi mese.
        $stmt = $this->conn->prepare("
            SELECT
                id,
                COALESCE(tm_datdoc, data) AS data,
                data                      AS data_riga,
                tm_datdoc,
                nome_cliente,
                nome_cantiere,
                descrizione,
                totale_imponibile,
                tm_anno,
                tm_numdoc
            FROM dbo.CNT_cantieri_brogliacci
            WHERE emessa  = 1
              AND obsoleto = 0
              AND COALESCE(tm_datdoc, data) >= :start
              AND COALESCE(tm_datdoc, data) <  :endExcl
            ORDER BY COALESCE(tm_datdoc, data) DESC, id DESC
        ");
        $stmt->execute([':start' => $start, ':endExcl' => $endExcl]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Totali "emesse reale" (Yard) per un mese.
     * Filtra su emessa=1 e obsoleto=0, sulla colonna data del brogliaccio.
     *
     * @return array{count:int, imponibile:float}
     */
    public function getEmesseTotalsForMonth(int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        // First day of next month, exclusive upper bound — works at year boundary
        $nextYear  = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1         : $month + 1;
        $endExcl   = sprintf('%04d-%02d-01', $nextYear, $nextMonth);

        $stmt = $this->conn->prepare("
            SELECT
                COUNT(*)                          AS cnt,
                COALESCE(SUM(totale_imponibile),0) AS tot
            FROM dbo.CNT_cantieri_brogliacci
            WHERE emessa = 1
              AND obsoleto = 0
              AND COALESCE(tm_datdoc, data) >= :start
              AND COALESCE(tm_datdoc, data) <  :endExcl
        ");
        $stmt->execute([':start' => $start, ':endExcl' => $endExcl]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'tot' => 0];

        return [
            'count'      => (int)   $row['cnt'],
            'imponibile' => (float) $row['tot'],
        ];
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
