<?php
namespace App\Domain;
use Exception;
use PDO;
use App\Domain\YardWorksiteBilling;

class Billing {
    private $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    /**
     * Recupera tutte le fatture di un cantiere
     */
    public function getByWorksiteId(int $worksite_id): array
    {
        $sql = "
        SELECT
            b.*,
            a.codice   AS articolo_codice,
            a.descrizione AS articolo_descrizione
        FROM bb_billing b
        LEFT JOIN bb_billing_articles a
            ON b.articolo_id = a.id
        WHERE b.worksite_id = :wid
        ORDER BY b.data ASC
    ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':wid' => $worksite_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recupera una fattura per ID
     */
    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM bb_billing WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Crea una nuova fattura
     */
    public function create($data) {
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
            return $this->conn->lastInsertId();
        } catch (Exception $ex) {
            $logger = \App\Infrastructure\LoggerFactory::database();
            $logger->error('MYSQL INSERT ERROR: ' . $ex->getMessage(), ['data' => $data]);
            throw $ex;
        }
    }

    /**
     * Aggiorna una fattura esistente
     */
    public function update($id, $data) {
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

        $stmt->bindParam(':data',          $data['data']);
        $stmt->bindParam(':descrizione',   $data['descrizione']);
        $stmt->bindParam(':totale_imponibile', $data['totale_imponibile']);
        $stmt->bindParam(':totale',        $data['totale']);
        $stmt->bindParam(':aliquota_iva',  $data['aliquota_iva']);
        $stmt->bindParam(':articolo_id',   $data['articolo_id']);
        $stmt->bindParam(':iva_id',        $data['iva_id']);
        $stmt->bindParam(':attivita_id',   $data['attivita_id']);
        $stmt->bindParam(':id',            $id);

        return $stmt->execute();
    }


    /**
     * Elimina una fattura
     */
    public function delete($id) {
        $stmt = $this->conn->prepare("DELETE FROM bb_billing WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Restituisce tutti gli articoli per il select
     */
    public function getAllArticles() {
        $stmt = $this->conn->query("
            SELECT id, codice, descrizione
            FROM bb_billing_articles
            ORDER BY codice
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Restituisce tutti i codici IVA disponibili con la loro descrizione
     *
     * @return array[id => descrizione]
     */
    public function getVatCodes(): array {
        $sql = "
      SELECT id, descrizione
      FROM bb_billing_vat_codes
      ORDER BY descrizione
    ";
        $stmt = $this->conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mappo in [id => descrizione] per comodità
        $vatCodes = [];
        foreach ($rows as $r) {
            $vatCodes[$r['id']] = $r['descrizione'];
        }
        return $vatCodes;
    }

    public function getVatPercentageById(int $vatId): ?float {
        $stmt = $this->conn->prepare(
            "SELECT aliquota FROM bb_billing_vat_codes WHERE id = :vid"
        );
        $stmt->execute([':vid' => $vatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['aliquota'] : null;
    }


    /**
     * Restituisce la descrizione di un codice IVA dato il suo ID
     *
     * @param int $vatId
     * @return string|null
     */
    public function getVatDescription(int $vatId): ?string {
        $stmt = $this->conn->prepare("
      SELECT descrizione
      FROM bb_billing_vat_codes
      WHERE id = :vid
      LIMIT 1
    ");
        $stmt->execute([':vid' => $vatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['descrizione'] : null;
    }


    public function setYardId(int $billingId, int $yardId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_billing
               SET yard_id = :yard_id
             WHERE id      = :id
        ");
        return $stmt->execute([
            ':yard_id' => $yardId,
            ':id'      => $billingId,
        ]);
    }

    /**
     * Restituisce i cantieri movimentati nel mese selezionato con dati economici:
     * totale offerta + extra - fatture emesse.
     * Include anche le presenze consorziate.
     *
     * @param int|null $companyId
     * @param int $year
     * @param int $month
     * @return array
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
                SELECT SUM(e.totale)
                FROM bb_extra e
                WHERE e.worksite_id = w.id
            ), 0) AS totale_extras,
            COALESCE((
                SELECT SUM(b.totale_imponibile)
                FROM bb_billing b
                WHERE b.worksite_id = w.id
                  AND b.emessa = 1
            ), 0) AS totale_fatturato,
            (
                (w.total_offer +
                COALESCE((SELECT SUM(e.totale)
                          FROM bb_extra e
                          WHERE e.worksite_id = w.id), 0)) -
                COALESCE((SELECT SUM(b.totale_imponibile)
                          FROM bb_billing b
                          WHERE b.worksite_id = w.id
                            AND b.emessa = 1), 0)
            ) AS residuo
        FROM bb_worksites w
        JOIN bb_clients c ON c.id = w.client_id
        WHERE w.id IN (
            SELECT DISTINCT worksite_id
            FROM (
                SELECT p.worksite_id
                FROM bb_presenze p
                WHERE YEAR(p.data) = ?
                  AND MONTH(p.data) = ?
                UNION
                SELECT pc.worksite_id
                FROM bb_presenze_consorziate pc
                WHERE YEAR(pc.data_presenza) = ?
                  AND MONTH(pc.data_presenza) = ?
            ) AS combined
        )
    ";

        $params = [
            $year,
            $month,
            $year,
            $month,
        ];

        if ($companyId !== 1 && $companyId !== null) {
            $sql .= " AND w.company_id = ?";
            $params[] = $companyId;
        }

        $sql .= " ORDER BY c.name, w.name";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Per-client billing views ───────────────────────────────────────────────

    /**
     * All clients that have at least one billing row.
     * Per-year counters (for KPI cards) are scoped to $year;
     * the all-time totals are still included for the table rows.
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
                -- year-scoped KPI columns
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
            WHERE w.client_id = :cid
              AND b.emessa = 0
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
            WHERE w.client_id = :cid
              AND b.emessa = 1
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
            FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
            WHERE w.client_id = :cid AND b.emessa = 1
        ");
        $stmt->execute([':cid' => $clientId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Sync emessa flag from Yard for all billing rows of a client that have a yard_id.
     */
    public function syncEmessaForClient(int $clientId, YardWorksiteBilling $yardBilling): void
    {
        $stmt = $this->conn->prepare("
            SELECT b.id, b.yard_id
            FROM bb_billing b
            JOIN bb_worksites w ON w.id = b.worksite_id
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
    ): void
    {
        $sql = "
        SELECT DISTINCT b.id, b.yard_id
        FROM bb_billing b
        JOIN bb_worksites w ON w.id = b.worksite_id
        WHERE b.yard_id IS NOT NULL
          AND w.id IN (
              SELECT DISTINCT worksite_id
              FROM (
                  SELECT p.worksite_id
                  FROM bb_presenze p
                  WHERE YEAR(p.data) = ?
                    AND MONTH(p.data) = ?
                  UNION
                  SELECT pc.worksite_id
                  FROM bb_presenze_consorziate pc
                  WHERE YEAR(pc.data_presenza) = ?
                    AND MONTH(pc.data_presenza) = ?
              ) t
          )
    ";

        $params = [$year, $month, $year, $month];

        if ($companyId !== 1 && $companyId !== null) {
            $sql .= " AND w.company_id = ?";
            $params[] = $companyId;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $upd = $this->conn->prepare("
        UPDATE bb_billing
           SET emessa = :emessa
         WHERE id = :id
    ");

        foreach ($rows as $r) {
            $isEmessa = $yardBilling->isEmessa((int)$r['yard_id']);
            $upd->execute([
                ':emessa' => $isEmessa ? 1 : 0,
                ':id'     => $r['id'],
            ]);
        }
    }



}
