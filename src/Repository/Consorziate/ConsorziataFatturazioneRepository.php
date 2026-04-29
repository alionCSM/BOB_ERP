<?php

declare(strict_types=1);

namespace App\Repository\Consorziate;

use PDO;

/**
 * All DB queries for the consorziate fatturazione module.
 * Reads: bb_companies, bb_presenze_consorziate, bb_ordini, bb_worksites
 * Writes: bb_pagamenti_consorziate
 */
final class ConsorziataFatturazioneRepository
{
    public function __construct(private PDO $conn) {}

    // ── Consorziate list ──────────────────────────────────────────────────────

    /**
     * All companies with consorziata=1, with all-time aggregate stats.
     */
    public function listConsorziate(): array
    {
        $stmt = $this->conn->query("
            SELECT
                c.id,
                c.name,
                c.codice,
                COALESCE(SUM(p.quantita), 0)                                     AS totale_presenze,
                COALESCE(SUM(p.quantita * IFNULL(p.costo_unitario, 0)), 0)       AS totale_costo_presenze,
                COALESCE((
                    SELECT SUM(pg.importo)
                    FROM   bb_pagamenti_consorziate pg
                    WHERE  pg.azienda_id = c.id
                ), 0)                                                             AS totale_pagato
            FROM bb_companies c
            LEFT JOIN bb_presenze_consorziate p ON p.azienda_id = c.id
            WHERE c.consorziata = 1
            GROUP BY c.id, c.name, c.codice
            ORDER BY c.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Single consorziata row (id, name, codice).
     */
    public function findConsorziata(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, name, codice
            FROM   bb_companies
            WHERE  id = :id AND consorziata = 1
            LIMIT  1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Detail rows (per-cantiere in period) ─────────────────────────────────

    /**
     * One row per cantiere where this consorziata had presenze in [from, to].
     * Includes:
     *   - presenze_gg, costo_presenze   (from bb_presenze_consorziate, period-scoped)
     *   - valore_ordine                 (from bb_ordini, all-time per azienda+cantiere)
     *   - gia_pagato                    (from bb_pagamenti_consorziate, all-time per azienda+cantiere)
     */
    public function getDetailRows(int $aziendaId, string $from, string $to): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                w.id                                                              AS worksite_id,
                w.worksite_code,
                w.name                                                            AS worksite_name,
                SUM(p.quantita)                                                   AS presenze_gg,
                SUM(p.quantita * IFNULL(p.costo_unitario, 0))                    AS costo_presenze,
                COALESCE((
                    SELECT SUM(o.total)
                    FROM   bb_ordini o
                    WHERE  o.destinatario_id = :aid1
                      AND  o.worksite_id     = w.id
                      AND  o.order_date <= :to1
                ), 0)                                                             AS valore_ordine,
                (
                    SELECT MAX(o2.order_date)
                    FROM   bb_ordini o2
                    WHERE  o2.destinatario_id = :aid5
                      AND  o2.worksite_id     = w.id
                      AND  o2.order_date <= :to2
                )                                                                 AS data_ordine,
                COALESCE((
                    SELECT SUM(pg.importo)
                    FROM   bb_pagamenti_consorziate pg
                    WHERE  pg.azienda_id  = :aid2
                      AND  pg.worksite_id = w.id
                ), 0)                                                             AS gia_pagato,
                COALESCE((
                    SELECT SUM(
                        CASE WHEN bp.data_dal IS NOT NULL AND bp.data_al IS NOT NULL
                             THEN (DATEDIFF(bp.data_al, bp.data_dal) + 1)
                                  * COALESCE(bp.n_persone, 0)
                                  * bp.prezzo_persona
                             ELSE 0 END
                    )
                    FROM   bb_bookings bk
                    INNER JOIN bb_booking_periods bp ON bp.booking_id = bk.id
                    WHERE  bk.consorziata_id        = :aid4
                      AND  bk.worksite_id           = w.id
                      AND  bk.a_carico_consorziata  = 1
                ), 0)                                                             AS spese_consorziata
            FROM bb_presenze_consorziate p
            INNER JOIN bb_worksites w ON w.id = p.worksite_id
            WHERE p.azienda_id    = :aid3
              AND p.data_presenza BETWEEN :from AND :to
            GROUP BY w.id, w.worksite_code, w.name
            ORDER BY w.worksite_code ASC
        ");
        $stmt->execute([
            ':aid1' => $aziendaId,
            ':to1'  => $to,
            ':aid2' => $aziendaId,
            ':aid4' => $aziendaId,
            ':aid3' => $aziendaId,
            ':aid5' => $aziendaId,
            ':to2'  => $to,
            ':from' => $from,
            ':to'   => $to,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Storico pagamenti ─────────────────────────────────────────────────────

    /**
     * All payment rows for a consorziata, newest first, joined to worksite name.
     */
    public function getPayments(int $aziendaId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                pg.id,
                pg.importo,
                pg.data_pagamento,
                pg.note,
                pg.created_at,
                w.worksite_code,
                w.name AS worksite_name
            FROM bb_pagamenti_consorziate pg
            INNER JOIN bb_worksites w ON w.id = pg.worksite_id
            WHERE pg.azienda_id = :aid
            ORDER BY pg.data_pagamento DESC, pg.created_at DESC
        ");
        $stmt->execute([':aid' => $aziendaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public function insertPayment(
        int     $aziendaId,
        int     $worksiteId,
        float   $importo,
        string  $dataPagamento,
        ?string $note,
        int     $createdBy
    ): void {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_pagamenti_consorziate
                (azienda_id, worksite_id, importo, data_pagamento, note, created_by)
            VALUES
                (:aid, :wid, :importo, :data, :note, :uid)
        ");
        $stmt->execute([
            ':aid'    => $aziendaId,
            ':wid'    => $worksiteId,
            ':importo'=> $importo,
            ':data'   => $dataPagamento,
            ':note'   => $note ?: null,
            ':uid'    => $createdBy,
        ]);
    }

    public function deletePayment(int $paymentId, int $aziendaId): bool
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_pagamenti_consorziate
            WHERE id = :id AND azienda_id = :aid
            LIMIT 1
        ");
        $stmt->execute([':id' => $paymentId, ':aid' => $aziendaId]);
        return $stmt->rowCount() > 0;
    }
}
