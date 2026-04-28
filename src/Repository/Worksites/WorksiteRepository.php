<?php

declare(strict_types=1);

namespace App\Repository\Worksites;

use PDO;

/**
 * All worksite-related SQL in one place.
 * Replaces the mixed DB + domain logic that was spread across Worksite.php.
 */
final class WorksiteRepository
{
    public function __construct(private PDO $conn) {}

    // ── Lookups ───────────────────────────────────────────────────────────────

    /**
     * Fetch a single worksite row (with client_name via JOIN).
     * Returns null if not found or if it is still a draft.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT w.*, c.name AS client_name
            FROM bb_worksites w
            LEFT JOIN bb_clients c ON c.id = w.client_id
            WHERE w.id = :id AND w.is_draft = 0
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** id list of all live (non-draft) worksites. */
    public function getAllIds(): array
    {
        return $this->conn
            ->query("SELECT id FROM bb_worksites WHERE is_draft = 0")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Minimal list for dropdowns/autocomplete. */
    public function getAllMinimal(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, name FROM bb_worksites WHERE is_draft = 0 ORDER BY name ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Full filterable list used by the worksites index page. */
    public function getAllByCompany(
        ?int   $companyId,
        string $status   = '',
        string $year     = '',
        string $clientId = '',
        string $search   = '',
        int    $limit    = 200
    ): array {
        $query = "
            SELECT
                w.id,
                w.worksite_code,
                w.name AS worksite_name,
                w.order_number,
                w.order_date,
                w.location,
                w.start_date,
                w.end_date,
                w.created_at,
                w.total_offer,
                w.ext_total_offer,
                w.is_consuntivo,
                w.prezzo_persona,
                w.status,
                c.name AS client_name,
                fs.margin
            FROM bb_worksites w
            LEFT JOIN bb_clients c ON w.client_id = c.id
            LEFT JOIN bb_worksite_financial_status fs ON fs.worksite_id = w.id
        ";

        $conditions = ['w.is_draft = 0'];
        $params     = [];

        if ($companyId != 1 && $companyId !== null) {
            $conditions[] = 'w.company_id = :company_id';
            $params[':company_id'] = $companyId;
        }

        if (!empty($status)) {
            if ($status === 'A rischio') {
                $contractField = ($companyId == 1) ? 'w.total_offer' : 'w.ext_total_offer';
                $conditions[] = "(fs.margin < 0 OR ((fs.margin / NULLIF({$contractField}, 0)) * 100 <= 30))";
            } else {
                $conditions[] = 'w.status = :status';
                $params[':status'] = $status;
            }
        }

        if (!empty($year)) {
            $conditions[] = 'YEAR(w.start_date) = :year';
            $params[':year'] = (int)$year;
        }

        if ($companyId == 1 && !empty($clientId)) {
            $conditions[] = 'w.client_id = :client_id';
            $params[':client_id'] = (int)$clientId;
        }

        if (!empty($search)) {
            $conditions[] = "(
                w.name LIKE :search_name
                OR w.worksite_code LIKE :search_code
                OR w.location LIKE :search_location
                OR c.name LIKE :search_client
                OR w.order_number LIKE :search_order
            )";
            $like = '%' . $search . '%';
            $params[':search_name']     = $like;
            $params[':search_code']     = $like;
            $params[':search_location'] = $like;
            $params[':search_client']   = $like;
            $params[':search_order']    = $like;
        }

        $query .= ' WHERE ' . implode(' AND ', $conditions);
        $query .= "
            ORDER BY
                CAST(SUBSTRING(w.worksite_code, 2, 2) AS UNSIGNED) DESC,
                CAST(SUBSTRING_INDEX(w.worksite_code, '-', -1) AS UNSIGNED) DESC
            LIMIT :limit
        ";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Drafts ────────────────────────────────────────────────────────────────

    public function getDrafts(): array
    {
        return $this->conn
            ->query("SELECT * FROM bb_worksites WHERE is_draft = 1 ORDER BY created_at DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activateDraft(int $id, int $yardWorksiteId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_worksites
            SET is_draft = 0,
                draft_reason = NULL,
                yard_worksite_id = :yard_id
            WHERE id = :id AND is_draft = 1
        ");
        return $stmt->execute([':id' => $id, ':yard_id' => $yardWorksiteId]);
    }

    // ── Write operations ─────────────────────────────────────────────────────

    /**
     * Insert a new worksite; generates and assigns the worksite_code.
     * Returns the new worksite's auto-increment ID.
     */
    public function create(array $data): int
    {
        $yearSuffix   = date('y');
        $prefix       = "C{$yearSuffix}-";

        $codeStmt = $this->conn->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(worksite_code, '-', -1) AS UNSIGNED))
             FROM bb_worksites WHERE worksite_code LIKE :prefix"
        );
        $codeStmt->execute([':prefix' => $prefix . '%']);
        $lastNumber   = (int)$codeStmt->fetchColumn();
        $worksiteCode = $prefix . str_pad((string)($lastNumber + 1), 3, '0', STR_PAD_LEFT);

        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksites (
                name, client_id, location, descrizione, ext_descrizione, start_date,
                offer_number, yard_client_id, yard_worksite_id, company_id, is_placeholder_client,
                total_offer, is_consuntivo, prezzo_persona, order_number, commessa, order_date,
                ext_total_offer, ext_order_number, ext_order_date, worksite_code, is_draft, draft_reason
            ) VALUES (
                :name, :client_id, :location, :descrizione, :ext_descrizione, :start_date,
                :offer_number, :yard_client_id, :yard_worksite_id, :company_id, :is_placeholder_client,
                :total_offer, :is_consuntivo, :prezzo_persona, :order_number, :commessa, :order_date,
                :ext_total_offer, :ext_order_number, :ext_order_date, :worksite_code, :is_draft, :draft_reason
            )
        ");

        $stmt->execute([
            ':name'                  => $data['name'],
            ':client_id'             => $data['client_id'],
            ':location'              => $data['location'],
            ':descrizione'           => $data['descrizione'],
            ':ext_descrizione'       => $data['ext_descrizione'] ?? null,
            ':start_date'            => $data['start_date'],
            ':offer_number'          => $data['offer_number'],
            ':yard_client_id'        => $data['yard_client_id'],
            ':yard_worksite_id'      => $data['yard_worksite_id'],
            ':company_id'            => $data['company_id'],
            ':is_placeholder_client' => $data['is_placeholder_client'],
            ':total_offer'           => $data['total_offer']    ?? null,
            ':is_consuntivo'         => $data['is_consuntivo']  ?? 0,
            ':prezzo_persona'        => $data['prezzo_persona'] ?? null,
            ':order_number'          => $data['order_number']   ?? null,
            ':commessa'              => $data['commessa']        ?? null,
            ':order_date'            => empty($data['order_date'])     ? null : $data['order_date'],
            ':ext_total_offer'       => $data['ext_total_offer']       ?? null,
            ':ext_order_number'      => $data['ext_order_number']      ?? null,
            ':ext_order_date'        => $data['ext_order_date']        ?? null,
            ':worksite_code'         => $worksiteCode,
            ':is_draft'              => $data['is_draft']              ?? 0,
            ':draft_reason'          => $data['draft_reason']          ?? null,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    /**
     * Full update of an existing worksite.
     * Company-1 and sub-company fields are written selectively (same logic as Worksite::updateAdvanced).
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE bb_worksites SET
            name             = :name,
            client_id        = :client_id,
            location         = :location,
            descrizione      = :descrizione,
            ext_descrizione  = :ext_descrizione,
            start_date       = :start_date,
            offer_number     = :offer_number,
            status           = :status,
            end_date         = :end_date,
            is_consuntivo    = :is_consuntivo,
            prezzo_persona   = :prezzo_persona";

        if (($data['company_id'] ?? 0) == 1) {
            $sql .= ",
                total_offer  = :total_offer,
                order_number = :order_number,
                commessa     = :commessa,
                order_date   = :order_date";
        } else {
            $sql .= ",
                ext_total_offer  = :ext_total_offer,
                ext_order_number = :ext_order_number,
                ext_order_date   = :ext_order_date";
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':name',           $data['name']);
        $stmt->bindValue(':client_id',      $data['client_id']);
        $stmt->bindValue(':location',       $data['location']);
        $stmt->bindValue(':descrizione',    $data['descrizione']);
        $stmt->bindValue(':ext_descrizione',$data['ext_descrizione']);
        $stmt->bindValue(':start_date',     $data['start_date']);
        $stmt->bindValue(':offer_number',   $data['offer_number']);
        $stmt->bindValue(':status',         $data['status'] ?? 'In corso');
        $stmt->bindValue(':end_date',       $data['end_date']);
        $stmt->bindValue(':id',             $id);
        $stmt->bindValue(':is_consuntivo',  (int)($data['is_consuntivo'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':prezzo_persona', !empty($data['prezzo_persona']) ? (float)$data['prezzo_persona'] : null);

        if (($data['company_id'] ?? 0) == 1) {
            $stmt->bindValue(':total_offer',  $data['total_offer']);
            $stmt->bindValue(':order_number', $data['order_number']);
            $stmt->bindValue(':commessa',     $data['commessa']);
            $stmt->bindValue(':order_date',   $data['order_date'],
                $data['order_date'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':ext_total_offer',  $data['ext_total_offer']);
            $stmt->bindValue(':ext_order_number', $data['ext_order_number']);
            $stmt->bindValue(':ext_order_date',   $data['ext_order_date']);
        }

        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_worksites WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    public function getTasks(int $worksiteId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM bb_worksite_tasks WHERE worksite_id = :wid ORDER BY created_at DESC"
        );
        $stmt->execute([':wid' => $worksiteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addTask(
        int     $worksiteId,
        string  $title,
        string  $description,
        string  $assignedTo,
        string  $status,
        ?string $dueDate
    ): bool {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_tasks (worksite_id, title, description, assigned_to, status, due_date)
            VALUES (:wid, :title, :desc, :assigned_to, :status, :due_date)
        ");
        return $stmt->execute([
            ':wid'         => $worksiteId,
            ':title'       => $title,
            ':desc'        => $description,
            ':assigned_to' => $assignedTo,
            ':status'      => $status,
            ':due_date'    => $dueDate,
        ]);
    }

    public function updateTask(int $worksiteId, int $taskId, string $status): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE bb_worksite_tasks SET status = :status WHERE id = :task_id AND worksite_id = :wid"
        );
        return $stmt->execute([':status' => $status, ':task_id' => $taskId, ':wid' => $worksiteId]);
    }

    public function deleteTask(int $worksiteId, int $taskId): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM bb_worksite_tasks WHERE id = :task_id AND worksite_id = :wid"
        );
        return $stmt->execute([':task_id' => $taskId, ':wid' => $worksiteId]);
    }

    public function getTaskComments(int $taskId): array
    {
        $stmt = $this->conn->prepare("
            SELECT c.comment, c.created_at, u.username
            FROM bb_task_comments c
            JOIN bb_users u ON c.user_id = u.id
            WHERE c.task_id = :task_id
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([':task_id' => $taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addTaskComment(int $taskId, int $userId, string $comment): bool
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_task_comments (task_id, user_id, comment, created_at)
            VALUES (:task_id, :user_id, :comment, NOW())
        ");
        return $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':comment' => $comment,
        ]);
    }

    // ── Financial helpers ─────────────────────────────────────────────────────

    public function getTotalBilled(int $worksiteId): float
    {
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(totale_imponibile), 0)
             FROM bb_billing WHERE worksite_id = :wid AND emessa = 1"
        );
        $stmt->execute([':wid' => $worksiteId]);
        return (float)$stmt->fetchColumn();
    }
}
