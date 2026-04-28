<?php

declare(strict_types=1);

namespace App\Repository\Billing;

use PDO;
use Exception;
use App\Domain\YardWorksiteBilling;

/**
 * All billing SQL in one place.
 * Replaces the mixed DB + domain logic from Billing.php (App\Domain\Billing).
 */
final class BillingRepository
{
    public function __construct(private PDO $conn) {}

    // ── Billing rows ─────────────────────────────────────────────────────────

    /**
     * All billing rows for a worksite, joined to article info.
     */
    public function getByWorksiteId(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                b.*,
                a.codice      AS articolo_codice,
                a.descrizione AS articolo_descrizione
            FROM bb_billing b
            LEFT JOIN bb_billing_articles a ON b.articolo_id = a.id
            WHERE b.worksite_id = :wid
            ORDER BY b.data ASC
        ");
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Single billing row by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_billing WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Insert a new billing row; returns the new ID.
     */
    public function create(array $data): int
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_billing (
                    worksite_id, nome_cantiere, nome_cliente, data,
                    descrizione, totale_imponibile, aliquota_iva,
                    articolo_id, iva_id, attivita_id
                ) VALUES (
                    :worksite_id, :nome_cantiere, :nome_cliente, :data,
                    :descrizione, :totale_imponibile, :aliquota_iva,
                    :articolo_id, :iva_id, :attivita_id
                )
            ");
            $stmt->execute([
                ':worksite_id'       => $data['worksite_id'],
                ':nome_cantiere'     => $data['nome_cantiere'],
                ':nome_cliente'      => $data['nome_cliente'],
                ':data'              => $data['data'],
                ':descrizione'       => $data['descrizione'],
                ':totale_imponibile' => $data['totale_imponibile'],
                ':aliquota_iva'      => $data['aliquota_iva'],
                ':articolo_id'       => $data['articolo_id'],
                ':iva_id'            => $data['iva_id'],
                ':attivita_id'       => $data['attivita_id'],
            ]);
            return (int)$this->conn->lastInsertId();
        } catch (Exception $ex) {
            $logger = \App\Infrastructure\LoggerFactory::database();
            $logger->error('MYSQL INSERT ERROR: ' . $ex->getMessage(), ['data' => $data]);
            throw $ex;
        }
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_billing SET
                data               = :data,
                descrizione        = :descrizione,
                totale_imponibile  = :totale_imponibile,
                totale             = :totale,
                aliquota_iva       = :aliquota_iva,
                articolo_id        = :articolo_id,
                iva_id             = :iva_id,
                attivita_id        = :attivita_id
            WHERE id = :id
        ");
        return $stmt->execute([
            ':data'              => $data['data'],
            ':descrizione'       => $data['descrizione'],
            ':totale_imponibile' => $data['totale_imponibile'],
            ':totale'            => $data['totale'],
            ':aliquota_iva'      => $data['aliquota_iva'],
            ':articolo_id'       => $data['articolo_id'],
            ':iva_id'            => $data['iva_id'],
            ':attivita_id'       => $data['attivita_id'],
            ':id'                => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_billing WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function setYardId(int $billingId, int $yardId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_billing SET yard_id = :yard_id WHERE id = :id
        ");
        return $stmt->execute([':yard_id' => $yardId, ':id' => $billingId]);
    }

    // ── Articles & VAT ───────────────────────────────────────────────────────

    public function getAllArticles(): array
    {
        $stmt = $this->conn->query("
            SELECT id, codice, descrizione FROM bb_billing_articles ORDER BY codice
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns [id => descrizione] map.
     */
    public function getVatCodes(): array
    {
        $stmt = $this->conn->query("
            SELECT id, descrizione FROM bb_billing_vat_codes ORDER BY descrizione
        ");
        $vatCodes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vatCodes[$r['id']] = $r['descrizione'];
        }
        return $vatCodes;
    }

    public function getVatPercentageById(int $vatId): ?float
    {
        $stmt = $this->conn->prepare("SELECT aliquota FROM bb_billing_vat_codes WHERE id = :vid");
        $stmt->execute([':vid' => $vatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['aliquota'] : null;
    }

    public function getVatDescription(int $vatId): ?string
    {
        $stmt = $this->conn->prepare("SELECT descrizione FROM bb_billing_vat_codes WHERE id = :vid LIMIT 1");
        $stmt->execute([':vid' => $vatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['descrizione'] : null;
    }

    /**
     * Return the Yard's article ID that corresponds to a local bb_billing_articles row.
     */
    public function getArticleYardId(int $articleId): ?int
    {
        $stmt = $this->conn->prepare("SELECT yard_id FROM bb_billing_articles WHERE id = :aid");
        $stmt->execute([':aid' => $articleId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    // ── Overview / moved-worksites ────────────────────────────────────────────

    /**
     * Worksites that had attendance in the given month, with billing summary.
     */
    public function getMovedWorksitesWithBilling(?int $companyId, int $year, int $month): array
    {
        $sql = "
            SELECT
                w.id,
                w.name,
                w.order_number,
                c.name AS cliente,
                w.total_offer,
                COALESCE((
                    SELECT SUM(e.totale) FROM bb_extra e WHERE e.worksite_id = w.id
                ), 0) AS totale_extras,
                COALESCE((
                    SELECT SUM(b.totale_imponibile) FROM bb_billing b
                    WHERE b.worksite_id = w.id AND b.emessa = 1
                ), 0) AS totale_fatturato,
                (
                    (w.total_offer +
                     COALESCE((SELECT SUM(e.totale) FROM bb_extra e WHERE e.worksite_id = w.id), 0)) -
                    COALESCE((SELECT SUM(b.totale_imponibile) FROM bb_billing b
                               WHERE b.worksite_id = w.id AND b.emessa = 1), 0)
                ) AS residuo
            FROM bb_worksites w
            JOIN bb_clients c ON c.id = w.client_id
            WHERE w.id IN (
                SELECT DISTINCT worksite_id
                FROM (
                    SELECT p.worksite_id  FROM bb_presenze p
                    WHERE YEAR(p.data) = ? AND MONTH(p.data) = ?
                    UNION
                    SELECT pc.worksite_id FROM bb_presenze_consorziate pc
                    WHERE YEAR(pc.data_presenza) = ? AND MONTH(pc.data_presenza) = ?
                ) AS combined
            )
        ";
        $params = [$year, $month, $year, $month];

        if ($companyId !== 1 && $companyId !== null) {
            $sql    .= " AND w.company_id = ?";
            $params[] = $companyId;
        }

        $sql .= " ORDER BY c.name, w.name";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Per-client views ─────────────────────────────────────────────────────

    /**
     * All clients with at least one billing row; year-scoped KPI columns included.
     */
    public function getClientsWithBillingSummary(int $year): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                c.id,
                c.name,
                COUNT(b.id)                                                                       AS total_fatture,
                SUM(b.emessa = 0)                                                                 AS da_emettere_count,
                SUM(b.emessa = 1)                                                                 AS emesse_count,
                COALESCE(SUM(CASE WHEN b.emessa = 0 THEN b.totale_imponibile END), 0)             AS da_emettere_euro,
                COALESCE(SUM(CASE WHEN b.emessa = 1 THEN b.totale_imponibile END), 0)             AS emesse_euro,
                SUM(b.emessa = 0 AND YEAR(b.data) = :yr)                                          AS da_emettere_count_yr,
                SUM(b.emessa = 1 AND YEAR(b.data) = :yr2)                                         AS emesse_count_yr,
                COALESCE(SUM(CASE WHEN b.emessa = 0 AND YEAR(b.data) = :yr3 THEN b.totale_imponibile END), 0) AS da_emettere_euro_yr,
                COALESCE(SUM(CASE WHEN b.emessa = 1 AND YEAR(b.data) = :yr4 THEN b.totale_imponibile END), 0) AS emesse_euro_yr
            FROM bb_clients c
            JOIN bb_worksites w ON w.client_id = c.id
            JOIN bb_billing b   ON b.worksite_id = w.id
            GROUP BY c.id, c.name
            HAVING da_emettere_count > 0 OR emesse_count > 0
            ORDER BY c.name
        ");
        $stmt->execute([':yr' => $year, ':yr2' => $year, ':yr3' => $year, ':yr4' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All da-emettere rows for a client (emessa = 0).
     */
    public function getDaEmettereByClient(int $clientId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                b.id, b.data, b.descrizione, b.totale_imponibile, b.aliquota_iva,
                b.emessa, b.yard_id,
                w.id AS worksite_id, w.name AS cantiere, w.order_number, w.order_date,
                c.name AS ragione_sociale
            FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
            JOIN bb_clients   c ON c.id = w.client_id
            WHERE w.client_id = :cid AND b.emessa = 0
            ORDER BY b.data ASC, w.name ASC
        ");
        $stmt->execute([':cid' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Paginated emesse rows for a client (emessa = 1).
     */
    public function getEmesseByClient(int $clientId, int $limit, int $offset): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                b.id, b.data, b.descrizione, b.totale_imponibile, b.aliquota_iva,
                b.emessa, b.yard_id,
                w.id AS worksite_id, w.name AS cantiere, w.order_number
            FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid AND b.emessa = 1
            ORDER BY b.data DESC, w.name ASC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':cid', $clientId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,    PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total count of emesse rows for a client (for pagination).
     */
    public function countEmesseByClient(int $clientId): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(b.id)
            FROM bb_billing b JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid AND b.emessa = 1
        ");
        $stmt->execute([':cid' => $clientId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * All-time total emesse (imponibile) for a client.
     */
    public function getTotalEmesseEuroByClient(int $clientId): float
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(b.totale_imponibile), 0)
            FROM bb_billing b JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid AND b.emessa = 1
        ");
        $stmt->execute([':cid' => $clientId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Year-scoped KPI card totals for a client.
     *
     * @return array{da_emettere_count_yr:int, da_emettere_euro_yr:float, emesse_count_yr:int, emesse_euro_yr:float}
     */
    public function getYearStatsByClient(int $clientId, int $year): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                SUM(CASE WHEN b.emessa = 0 AND YEAR(b.data) = :yr  THEN 1 ELSE 0 END)                        AS da_emettere_count_yr,
                COALESCE(SUM(CASE WHEN b.emessa = 0 AND YEAR(b.data) = :yr2 THEN b.totale_imponibile END), 0) AS da_emettere_euro_yr,
                SUM(CASE WHEN b.emessa = 1 AND YEAR(b.data) = :yr3 THEN 1 ELSE 0 END)                        AS emesse_count_yr,
                COALESCE(SUM(CASE WHEN b.emessa = 1 AND YEAR(b.data) = :yr4 THEN b.totale_imponibile END), 0) AS emesse_euro_yr
            FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid
        ");
        $stmt->execute([':cid' => $clientId, ':yr' => $year, ':yr2' => $year, ':yr3' => $year, ':yr4' => $year]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Yard sync ────────────────────────────────────────────────────────────

    /**
     * Sync emessa flag from Yard for all billing rows of a client that have a yard_id.
     */
    public function syncEmessaForClient(int $clientId, YardWorksiteBilling $yardBilling): void
    {
        $stmt = $this->conn->prepare("
            SELECT b.id, b.yard_id
            FROM bb_billing b JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid AND b.yard_id IS NOT NULL
        ");
        $stmt->execute([':cid' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $upd = $this->conn->prepare("UPDATE bb_billing SET emessa = :emessa WHERE id = :id");
        foreach ($rows as $r) {
            $upd->execute([
                ':emessa' => $yardBilling->isEmessa((int)$r['yard_id']) ? 1 : 0,
                ':id'     => $r['id'],
            ]);
        }
    }

    public function syncEmessaFromYardForMovedWorksites(
        ?int $companyId,
        int $year,
        int $month,
        YardWorksiteBilling $yardBilling
    ): void {
        $sql = "
            SELECT DISTINCT b.id, b.yard_id
            FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE b.yard_id IS NOT NULL
              AND w.id IN (
                  SELECT DISTINCT worksite_id
                  FROM (
                      SELECT p.worksite_id  FROM bb_presenze p
                      WHERE YEAR(p.data) = ? AND MONTH(p.data) = ?
                      UNION
                      SELECT pc.worksite_id FROM bb_presenze_consorziate pc
                      WHERE YEAR(pc.data_presenza) = ? AND MONTH(pc.data_presenza) = ?
                  ) t
              )
        ";
        $params = [$year, $month, $year, $month];

        if ($companyId !== 1 && $companyId !== null) {
            $sql    .= " AND w.company_id = ?";
            $params[] = $companyId;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $upd = $this->conn->prepare("UPDATE bb_billing SET emessa = :emessa WHERE id = :id");
        foreach ($rows as $r) {
            $upd->execute([
                ':emessa' => $yardBilling->isEmessa((int)$r['yard_id']) ? 1 : 0,
                ':id'     => $r['id'],
            ]);
        }
    }
}
