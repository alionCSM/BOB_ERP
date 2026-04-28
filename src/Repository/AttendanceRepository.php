<?php
declare(strict_types=1);

namespace App\Repository;
use Exception;
use PDO;
use App\Repository\Contracts\AttendanceRepositoryInterface;

class AttendanceRepository implements AttendanceRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    /* ============================================================
       INTERNAL (bb_presenze)
       ============================================================ */

    public function getExistingPresencesByWorkerAndDate(int $workerId, string $day): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, worksite_id, turno
            FROM bb_presenze
            WHERE worker_id = :wid
              AND data      = :day
        ");
        $stmt->execute([':wid' => $workerId, ':day' => $day]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getWorksiteLabel(int $worksiteId): string
    {
        $stmt = $this->conn->prepare("
            SELECT worksite_code, name
            FROM bb_worksites
            WHERE id = :wsid
            LIMIT 1
        ");
        $stmt->execute([':wsid' => $worksiteId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);

        $code = $c['worksite_code'] ?? '—';
        $name = $c['name'] ?? ('Cantiere ' . $worksiteId);

        return $code . ' – ' . $name;
    }

    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) return;

        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);

        // Nota: qui uso IN(...) perché gli ID arrivano dal DB/ UI, e li casto a int
        $this->conn->exec("DELETE FROM bb_presenze WHERE id IN ($in)");
    }

    public function getInternalByWorksiteAndDate(int $worksiteId, string $date): array
    {
        $stmt = $this->conn->prepare("
        SELECT
            p.id,
            p.worker_id,
            CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
            p.turno,          
            p.pranzo,
            p.pranzo_prezzo,
            p.cena,
            p.cena_prezzo,
            p.hotel,
            p.targa_auto,
            p.note            AS note,
            p.data
        FROM bb_presenze p
        INNER JOIN bb_workers w ON p.worker_id = w.id
        WHERE p.worksite_id = :wsid
          AND p.data = :day
        ORDER BY w.last_name, w.first_name
    ");

        $stmt->execute([
            ':wsid' => $worksiteId,
            ':day'  => $date
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getConsorziateByWorksiteAndDate(int $worksiteId, string $date): array
    {
        $stmt = $this->conn->prepare("
        SELECT c.*,
               co.name AS company_name
        FROM bb_presenze_consorziate c
        LEFT JOIN bb_companies co ON c.azienda_id = co.id
        WHERE c.worksite_id = :wsid
          AND c.data_presenza = :day
        ORDER BY co.name
    ");

        $stmt->execute([
            ':wsid' => $worksiteId,
            ':day'  => $date
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteByWorkerAndDate(int $workerId, string $day): void
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_presenze
            WHERE worker_id = :wid
              AND data      = :day
        ");
        $stmt->execute([':wid' => $workerId, ':day' => $day]);
    }

    public function updatePresenza(int $id, array $params): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_presenze
            SET turno = :turno,
                pranzo = :pranzo,
                pranzo_prezzo = :pranzo_prezzo,
                cena = :cena,
                cena_prezzo = :cena_prezzo,
                hotel = :hotel,
                targa_auto = :auto,
                note = :note,
                updated_by = :uid
            WHERE id = :id
        ");

        $params[':id'] = $id;
        $stmt->execute($params);
    }

    public function insertPresenza(array $params): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_presenze
                (worker_id, worksite_id, azienda, data, turno,
                 pranzo, pranzo_prezzo, cena, cena_prezzo,
                 hotel, targa_auto, trasferta, note, created_by)
            VALUES
                (:wid, :wsid, :azienda, :day, :turno,
                 :pranzo, :pranzo_prezzo, :cena, :cena_prezzo,
                 :hotel, :auto, 0, :note, :uid)
        ");
        $stmt->execute($params);
    }

    /* ============================================================
       WORKERS / COMPANY RESOLUTION
       ============================================================ */

    public function getWorkerInfo(int $workerId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT fiscal_code, company, active_from
            FROM bb_workers
            WHERE id = :wid
            LIMIT 1
        ");
        $stmt->execute([':wid' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getWorkerCompanyFromHistory(string $fiscalCode, string $day): ?string
    {
        $stmt = $this->conn->prepare("
            SELECT company
            FROM bb_worker_company_history
            WHERE fiscal_code = :fiscal
              AND start_date <= :day
              AND (end_date IS NULL OR end_date >= :day)
            ORDER BY start_date DESC
            LIMIT 1
        ");
        $stmt->execute([':fiscal' => $fiscalCode, ':day' => $day]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['company'] ?? null;
    }

    public function getWorkerFullName(int $workerId): string
    {
        $stmt = $this->conn->prepare("
            SELECT CONCAT(first_name, ' ', last_name) AS full_name
            FROM bb_workers
            WHERE id = :wid
            LIMIT 1
        ");
        $stmt->execute([':wid' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['full_name'] ?? ("ID {$workerId}");
    }

    /* ============================================================
       CONSORZIATE (bb_presenze_consorziate)
       ============================================================ */

    public function deleteConsorziateByIdsForWorksite(array $ids, int $worksiteId): void
    {
        if (empty($ids)) return;

        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);

        $this->conn->exec("
            DELETE FROM bb_presenze_consorziate
            WHERE id IN ($in)
              AND worksite_id = " . (int)$worksiteId
        );
    }

    public function deleteConsorziateByWorksiteAndDay(int $worksiteId, string $day): void
    {
        $stmt = $this->conn->prepare("
            DELETE FROM bb_presenze_consorziate
            WHERE worksite_id = :wsid
              AND data_presenza = :day
        ");
        $stmt->execute([':wsid' => $worksiteId, ':day' => $day]);
    }

    public function updateConsorziata(int $id, int $worksiteId, string $day, array $params): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_presenze_consorziate
            SET quantita       = :quantita,
                costo_unitario = :costo,
                pasti          = :pasti,
                auto           = :auto,
                hotel          = :hotel,
                note           = :note,
                updated_by     = :updated_by
            WHERE id = :id
              AND worksite_id = :wsid
              AND data_presenza = :day
        ");

        $stmt->execute([
            ':quantita'   => (float)($params['quantita'] ?? 0),
            ':costo'      => (float)($params['costo'] ?? 0),
            ':pasti'      => (int)($params['pasti'] ?? 0),
            ':auto'       => (string)($params['auto'] ?? ''),
            ':hotel'      => (string)($params['hotel'] ?? ''),
            ':note'       => (string)($params['note'] ?? ''),
            ':updated_by' => (int)($params['updated_by'] ?? 0),
            ':id'         => $id,
            ':wsid'       => $worksiteId,
            ':day'        => $day,
        ]);
    }

    public function insertConsorziata(int $worksiteId, string $day, array $params): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_presenze_consorziate (
                data_presenza,
                worksite_id,
                azienda_id,
                quantita,
                costo_unitario,
                pasti,
                auto,
                hotel,
                note,
                created_by,
                updated_by
            ) VALUES (
                :day,
                :wsid,
                :azienda_id,
                :quantita,
                :costo,
                :pasti,
                :auto,
                :hotel,
                :note,
                :created_by,
                :updated_by
            )
        ");

        $stmt->execute([
            ':day'        => $day,
            ':wsid'       => $worksiteId,
            ':azienda_id' => (int)($params['azienda_id'] ?? 0),
            ':quantita'   => (float)($params['quantita'] ?? 0),
            ':costo'      => (float)($params['costo'] ?? 0),
            ':pasti'      => (int)($params['pasti'] ?? 0),
            ':auto'       => (string)($params['auto'] ?? ''),
            ':hotel'      => (string)($params['hotel'] ?? ''),
            ':note'       => (string)($params['note'] ?? ''),
            ':created_by' => (int)($params['created_by'] ?? 0),
            ':updated_by' => (int)($params['updated_by'] ?? 0),
        ]);
    }

    public function resolveCompanyId(string $nomeOrId): int
    {
        $nomeOrId = trim($nomeOrId);
        if ($nomeOrId === '') {
            throw new Exception("Consorziata non valida.");
        }

        if (ctype_digit($nomeOrId)) {
            return (int)$nomeOrId;
        }

        $stmt = $this->conn->prepare("
            SELECT id
            FROM bb_companies
            WHERE name COLLATE utf8mb4_general_ci = :nome
            LIMIT 1
        ");
        $stmt->execute([':nome' => $nomeOrId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Consorziata \"{$nomeOrId}\" non trovata.");
        }

        return (int)$row['id'];
    }

    public function getFiltered(
        ?string $startDate,
        ?string $endDate,
        ?int $cantiereId,
        ?int $workerId,
        int $limit = 200
    ): array {

        $sql = "
        SELECT p.*,
               CONCAT(w.first_name, ' ', w.last_name) AS lavoratore,
               CONCAT(c.worksite_code, ' - ', c.name) AS cantiere,
               c.id AS cantiere_id
        FROM bb_presenze p
        JOIN bb_workers w ON p.worker_id = w.id
        JOIN bb_worksites c ON p.worksite_id = c.id
        WHERE 1
    ";

        $params = [];

        if ($startDate) {
            $sql .= " AND p.data >= :startDate";
            $params[':startDate'] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND p.data <= :endDate";
            $params[':endDate'] = $endDate;
        }

        if ($cantiereId) {
            $sql .= " AND p.worksite_id = :cantiereId";
            $params[':cantiereId'] = $cantiereId;
        }

        if ($workerId) {
            $sql .= " AND p.worker_id = :workerId";
            $params[':workerId'] = $workerId;
        }

        $sql .= " ORDER BY p.data DESC LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getConsorziateFiltered(
        ?string $startDate,
        ?string $endDate,
        ?int $cantiereId,
        ?string $consName,
        int $limit = 200
    ): array {

        $sql = "
        SELECT
            p.id,
            p.data_presenza,
            p.worksite_id AS cantiere_id,
            CONCAT(w.worksite_code, ' - ', w.name) AS cantiere,
            p.quantita,
            p.costo_unitario,
            p.pasti,
            p.auto,
            p.hotel,
            p.note,
            c.name AS consorziata_name
        FROM bb_presenze_consorziate p
        LEFT JOIN bb_worksites w ON p.worksite_id = w.id
        LEFT JOIN bb_companies c ON p.azienda_id = c.id
        WHERE 1
    ";

        $params = [];

        if ($startDate) {
            $sql .= " AND p.data_presenza >= :startDate";
            $params[':startDate'] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND p.data_presenza <= :endDate";
            $params[':endDate'] = $endDate;
        }

        if ($cantiereId) {
            $sql .= " AND p.worksite_id = :cantiereId";
            $params[':cantiereId'] = $cantiereId;
        }

        if ($consName) {
            $sql .= " AND c.name LIKE :consName";
            $params[':consName'] = '%' . $consName . '%';
        }

        $sql .= " ORDER BY p.data_presenza DESC LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteInternalById(int $id): bool
    {
        $stmt = $this->conn->prepare("
        DELETE FROM bb_presenze
        WHERE id = :id
        LIMIT 1
    ");
        return $stmt->execute([':id' => $id]);
    }

    public function deleteConsorziataById(int $id): bool
    {
        $stmt = $this->conn->prepare("
        DELETE FROM bb_presenze_consorziate
        WHERE id = :id
        LIMIT 1
    ");
        return $stmt->execute([':id' => $id]);
    }

    // ── Worksite-scoped queries (used in WorksitesController::show()) ─────────

    /**
     * All internal presenze for a worksite, with worker name.
     */
    public function getByWorksiteId(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT p.*, CONCAT(w.first_name, ' ', w.last_name) AS lavoratore
            FROM bb_presenze p
            JOIN bb_workers w ON p.worker_id = w.id
            WHERE p.worksite_id = :id
            ORDER BY p.data DESC
        ");
        $stmt->execute([':id' => $worksiteId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Internal presenze for a worksite filtered by a single date.
     */
    public function getByWorksiteIdAndDate(int $worksiteId, string $date): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                p.id, p.worker_id,
                CONCAT(w.first_name, ' ', w.last_name) AS lavoratore,
                p.turno           AS tipo_turno,
                p.pranzo, p.pranzo_prezzo,
                p.cena,   p.cena_prezzo,
                p.hotel, p.targa_auto AS auto, p.note, p.data
            FROM bb_presenze p
            JOIN bb_workers w ON p.worker_id = w.id
            WHERE p.worksite_id = :id AND p.data = :date
            ORDER BY p.data DESC
        ");
        $stmt->execute([':id' => $worksiteId, ':date' => $date]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Distinct internal-presence dates for a worksite (descending).
     */
    public function getDatesByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT data FROM bb_presenze WHERE worksite_id = :id ORDER BY data DESC"
        );
        $stmt->execute([':id' => $worksiteId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Distinct consorziata-presence dates for a worksite (descending).
     */
    public function getDatesConsByWorksite(int $worksiteId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT data_presenza FROM bb_presenze_consorziate WHERE worksite_id = :id ORDER BY data_presenza DESC"
        );
        $stmt->execute([':id' => $worksiteId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}