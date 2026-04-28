<?php

namespace App\Domain;
use PDO;
use App\Infrastructure\Database;
use App\Domain\Extra;

class Worksite
{
    private $id;
    private $name;
    private $location;
    private $offerNumber;
    private $startDate;
    private $endDate;
    private $status;
    private $createdAt;
    private $updatedAt;
    private $totalOffer;
    private $isConsuntivo;
    private $prezzoPersona;
    private $isDraft;
    private $draftReason;
    private $conn;

    public function __construct($conn, $id = null)
    {
        $this->conn = $conn;

        if ($id !== null) {
            $this->loadById($id);
        }
    }


    // Carica un cantiere dal database in base all'ID
    private function loadById($id)
    {
        $query = "SELECT w.*, c.name AS client_name
                    FROM bb_worksites w
                    LEFT JOIN bb_clients c ON c.id = w.client_id
                    WHERE w.id = :id AND w.is_draft = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            $this->id = $data['id'];
            $this->worksiteCode = $data['worksite_code'];
            $this->name = $data['name'];
            $this->client_id = $data['client_id'];
            $this->location = $data['location'];
            $this->offerNumber = $data['offer_number'];
            $this->totalOffer    = $data['total_offer'];
            $this->isConsuntivo  = (int)($data['is_consuntivo'] ?? 0);
            $this->prezzoPersona = isset($data['prezzo_persona']) ? (float)$data['prezzo_persona'] : null;
            $this->orderNumber   = $data['order_number'];
            $this->orderDate   = $data['order_date'];
            $this->description   = $data['descrizione'];
            $this->startDate = $data['start_date'];
            $this->endDate = $data['end_date'];
            $this->status = $data['status'];
            $this->isDraft      = (int)$data['is_draft'];
            $this->draftReason = $data['draft_reason'];
            $this->createdAt = $data['created_at'];
            $this->updatedAt = $data['updated_at'];
            $this->network_path = $data['network_path'];
            $this->client_name = $data['client_name'];
            $this->yard_worksite_id = $data['yard_worksite_id'] ?? null;

        }
    }

    public function getClientName(): string
    {
        return $this->client_name ?? '';
    }

    public function getClientId(): ?int
    {
        return $this->client_id ? (int)$this->client_id : null;
    }

    public function getYardWorksiteId(): ?int
    {
        return $this->yard_worksite_id ? (int)$this->yard_worksite_id : null;
    }


    public function getOrderNumber(): ?string {
        return $this->orderNumber;
    }
    public function getOrderDate(): ?string {
        return $this->orderDate;
    }

    public function getDescription(): ?string {
        return $this->description;
    }

    public function isDraft(): bool {
        return $this->isDraft === 1;
    }

    public function getDraftReason(): ?string {
        return $this->draftReason;
    }


    // Recupera un cantiere per ID (alternativa a new Worksite($id))
    public static function getById($id)
    {
        $db = new Database();
        $conn = $db->connect();

        $query = "SELECT id FROM bb_worksites  WHERE id = :id
      AND is_draft = 0 ";
        $stmt = $conn->prepare($query);
        $stmt->execute([':id' => $id]);

        if ($stmt->fetchColumn()) {
            return new Worksite($conn, $id);
        } else {
            return null;
        }
    }

    public static function getAllIds()
    {
        $db = new Database();
        $conn = $db->connect();

        return $conn
            ->query("SELECT id FROM bb_worksites WHERE is_draft = 0")
            ->fetchAll(PDO::FETCH_COLUMN);
    }



    // Restituisce tutti i cantieri in base a companyID
    public static function getAllByCompany($companyId, $status = '', $year = '', $clientId = '', $search = '', $limit = 200)
    {
        $db = new Database();
        $conn = $db->connect();

        $query = "SELECT
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
          LEFT JOIN bb_clients c
                 ON w.client_id = c.id
          LEFT JOIN bb_worksite_financial_status fs
                 ON fs.worksite_id = w.id";

        $conditions = [];
        $params = [];

        $conditions[] = "w.is_draft = 0";

        if ($companyId != 1) {
            $conditions[] = "w.company_id = :company_id";
            $params[':company_id'] = $companyId;
        }

        if (!empty($status)) {
            if ($status === 'A rischio') {
                $contractField = ($companyId == 1) ? 'w.total_offer' : 'w.ext_total_offer';
                $conditions[] = "(fs.margin < 0 OR ((fs.margin / NULLIF({$contractField}, 0)) * 100 <= 30))";
            } else {
                $conditions[] = "w.status = :status";
                $params[':status'] = $status;
            }
        }

        if (!empty($year)) {
            $conditions[] = "YEAR(w.start_date) = :year";
            $params[':year'] = (int)$year;
        }

        if ($companyId == 1 && !empty($clientId)) {
            $conditions[] = "w.client_id = :client_id";
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
            $searchLike = '%' . $search . '%';
            $params[':search_name'] = $searchLike;
            $params[':search_code'] = $searchLike;
            $params[':search_location'] = $searchLike;
            $params[':search_client'] = $searchLike;
            $params[':search_order'] = $searchLike;
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $query .= " ORDER BY
        CAST(SUBSTRING(w.worksite_code, 2, 2) AS UNSIGNED) DESC,
        CAST(SUBSTRING_INDEX(w.worksite_code, '-', -1) AS UNSIGNED) DESC
        LIMIT :limit";

        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }





    // Salva un nuovo cantiere nel database
    public function createAdvanced($data) {

        $yearSuffix = date('y'); // 25
        $prefix = "C{$yearSuffix}-";

// Trova il massimo numero usato per quell'anno
        $stmt = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(worksite_code, '-', -1) AS UNSIGNED)) 
                              FROM bb_worksites 
                              WHERE worksite_code LIKE :prefix");
        $stmt->execute([':prefix' => $prefix . '%']);
        $lastNumber = $stmt->fetchColumn();
        $nextNumber = str_pad((int)$lastNumber + 1, 3, '0', STR_PAD_LEFT);

        $worksiteCode = $prefix . $nextNumber;




        $query = "INSERT INTO bb_worksites
(name, client_id, location, descrizione, ext_descrizione, start_date, offer_number, yard_client_id, yard_worksite_id, company_id, is_placeholder_client,
 total_offer, is_consuntivo, prezzo_persona, order_number, commessa, order_date,
 ext_total_offer, ext_order_number, ext_order_date, worksite_code, is_draft, draft_reason
)
VALUES
(:name, :client_id, :location, :descrizione, :ext_descrizione, :start_date, :offer_number, :yard_client_id, :yard_worksite_id, :company_id, :is_placeholder_client,
 :total_offer, :is_consuntivo, :prezzo_persona, :order_number, :commessa, :order_date,
 :ext_total_offer, :ext_order_number, :ext_order_date, :worksite_code, :is_draft, :draft_reason )";


        $stmt = $this->conn->prepare($query);

        return $stmt->execute([
            ':name' => $data['name'],
            ':client_id' => $data['client_id'],
            ':location' => $data['location'],
            ':descrizione' => $data['descrizione'],
            ':ext_descrizione' => $data['ext_descrizione'] ?? null, // nuova descrizione esterna
            ':start_date' => $data['start_date'],
            ':offer_number' => $data['offer_number'],
            ':yard_client_id' => $data['yard_client_id'],
            ':yard_worksite_id' => $data['yard_worksite_id'],
            ':company_id' => $data['company_id'],
            ':is_placeholder_client' => $data['is_placeholder_client'],
            ':total_offer'    => $data['total_offer'] ?? null,
            ':is_consuntivo'  => $data['is_consuntivo'] ?? 0,
            ':prezzo_persona' => $data['prezzo_persona'] ?? null,
            ':order_number'   => $data['order_number'] ?? null,
            ':commessa' => $data['commessa'] ?? null,
            ':order_date' => empty($data['order_date']) ? null : $data['order_date'],
            ':ext_total_offer' => $data['ext_total_offer'] ?? null,
            ':ext_order_number' => $data['ext_order_number'] ?? null,
            ':ext_order_date' => $data['ext_order_date'] ?? null,
            ':worksite_code' => $worksiteCode,
            ':is_draft'      => $data['is_draft'] ?? 0,
            ':draft_reason'  => $data['draft_reason'] ?? null,

        ]);

    }




    // Aggiorna un cantiere esistente
    public function update()
    {
        $query = "UPDATE bb_worksites SET 
                    name = :name, 
                    location = :location, 
                    offer_number = :offerNumber, 
                    start_date = :startDate, 
                    end_date = :endDate, 
                    status = :status
                  WHERE id = :id";
        $stmt = $this->conn->prepare($query);

        return $stmt->execute([
            ':id' => $this->id,
            ':name' => $this->name,
            ':location' => $this->location,
            ':offerNumber' => $this->offerNumber,
            ':startDate' => $this->startDate,
            ':endDate' => $this->endDate,
            ':status' => $this->status
        ]);
    }

    // Elimina un cantiere
    public function delete()
    {
        $query = "DELETE FROM bb_worksites WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $this->id]);
    }

    // Setters e Getters
    public function getName() { return $this->name; }
    public function getLocation() { return $this->location; }
    public function getOfferNumber() { return $this->offerNumber; }

    public function getWorksiteCode() { return $this->worksiteCode; }



    public function getTasks()
    {
        $query = "SELECT * FROM bb_worksite_tasks WHERE worksite_id = :worksite_id ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':worksite_id' => $this->id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addTask($title, $description, $assignedTo, $status, $dueDate)
    {
        $query = "INSERT INTO bb_worksite_tasks (worksite_id, title, description, assigned_to, status, due_date)
              VALUES (:worksite_id, :title, :description, :assigned_to, :status, :due_date)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':worksite_id' => $this->id,
            ':title' => $title,
            ':description' => $description,
            ':assigned_to' => $assignedTo,
            ':status' => $status,
            ':due_date' => $dueDate
        ]);
    }

    public function updateTask($taskId, $status)
    {
        $query = "UPDATE bb_worksite_tasks SET status = :status WHERE id = :task_id AND worksite_id = :worksite_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':task_id' => $taskId,
            ':worksite_id' => $this->id,
            ':status' => $status
        ]);
    }

    public function deleteTask($taskId)
    {
        $query = "DELETE FROM bb_worksite_tasks WHERE id = :task_id AND worksite_id = :worksite_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':task_id' => $taskId,
            ':worksite_id' => $this->id
        ]);
    }

    public function getTaskComments($taskId) {
        $query = "SELECT c.comment, c.created_at, u.username 
              FROM bb_task_comments c
              JOIN bb_users u ON c.user_id = u.id
              WHERE c.task_id = :task_id
              ORDER BY c.created_at ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function addTaskComment($taskId, $userId, $comment) {
        $query = "INSERT INTO bb_task_comments (task_id, user_id, comment, created_at) 
              VALUES (:task_id, :user_id, :comment, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':comment', $comment, PDO::PARAM_STR);
        return $stmt->execute();
    }

    public function updateAdvanced($data)
    {
        $sql = "UPDATE bb_worksites SET
    name = :name,
    client_id = :client_id,
    location = :location,
    descrizione = :descrizione,
    ext_descrizione = :ext_descrizione,
    start_date = :start_date,
    offer_number = :offer_number,
    status = :status,
    end_date = :end_date,
    is_consuntivo = :is_consuntivo,
    prezzo_persona = :prezzo_persona";


        if ($data['company_id'] == 1) {
            $sql .= ",
                total_offer = :total_offer,
                order_number = :order_number,
                commessa = :commessa,
                order_date = :order_date";
        } else {
            $sql .= ",
                ext_total_offer = :ext_total_offer,
                ext_order_number = :ext_order_number,
                ext_order_date = :ext_order_date";
        }
        $sql .= " WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        // Bind comuni
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':client_id', $data['client_id']);
        $stmt->bindValue(':location', $data['location']);
        $stmt->bindValue(':descrizione', $data['descrizione']);
        $stmt->bindValue(':ext_descrizione', $data['ext_descrizione']);
        $stmt->bindValue(':start_date', $data['start_date']);
        $stmt->bindValue(':offer_number', $data['offer_number']);
        $stmt->bindValue(':status', $data['status'] ?? 'In corso');
        $stmt->bindValue(':end_date', $data['end_date']);
        $stmt->bindValue(':id', $this->id);
        $stmt->bindValue(':is_consuntivo',  (int)($data['is_consuntivo'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':prezzo_persona', !empty($data['prezzo_persona']) ? (float)$data['prezzo_persona'] : null);
        // Bind condizionali
        if ($data['company_id'] == 1) {
            $stmt->bindValue(':total_offer', $data['total_offer']);
            $stmt->bindValue(':order_number', $data['order_number']);
            $stmt->bindValue(':commessa', $data['commessa']);
            $stmt->bindValue(
                ':order_date',
                $data['order_date'],
                $data['order_date'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );        } else {
            $stmt->bindValue(':ext_total_offer', $data['ext_total_offer']);
            $stmt->bindValue(':ext_order_number', $data['ext_order_number']);
            $stmt->bindValue(':ext_order_date', $data['ext_order_date']);
        }
        return $stmt->execute();
    }


    public function getAllMinimal()
    {
        $query = "SELECT id, name FROM bb_worksites WHERE is_draft = 0
 ORDER BY name ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getTotalOffer(): float {
        return (float)($this->totalOffer ?? 0);
    }

    public function isConsuntivo(): bool {
        return $this->isConsuntivo === 1;
    }

    public function getPrezzoPersona(): ?float {
        return $this->prezzoPersona;
    }

    public static function getDrafts()
    {
        $db = new Database();
        $conn = $db->connect();

        return $conn->query("
        SELECT *
        FROM bb_worksites
        WHERE is_draft = 1
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function activateDraft($id, $yardWorksiteId)
    {
        $db = new Database();
        $conn = $db->connect();

        $stmt = $conn->prepare("
        UPDATE bb_worksites
        SET is_draft = 0,
            draft_reason = NULL,
            yard_worksite_id = :yard_id
        WHERE id = :id
          AND is_draft = 1
    ");

        return $stmt->execute([
            ':id' => $id,
            ':yard_id' => $yardWorksiteId
        ]);
    }




    /**
     * Calcola la somma degli extra per il cantiere usando Extra class
     */
    public function getTotalExtras(): float
    {
        $extraService = new Extra($this->conn);
        $items = $extraService->getByWorksiteId($this->id);
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += (float) $item['totale'];
        }
        return $sum;
    }

    /**
     * Calcola l'importo già fatturato per il cantiere
     */
    public function getTotalBilled(): float
    {
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(totale_imponibile), 0) AS billed_sum FROM bb_billing WHERE worksite_id = :wid"
        );
        $stmt->execute([':wid' => $this->id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) $row['billed_sum'];
    }

    /**
     * Restituisce l'importo totale offerta + extra
     */
    public function getGrossTotal(): float
    {
        return $this->getTotalOffer() + $this->getTotalExtras();
    }

    /**
     * Calcola quanto resta da fatturare: gross total - billed
     */
    public function getRemainingToBill(): float
    {
        $gross = $this->getGrossTotal();
        $billed = $this->getTotalBilled();
        $remain = $gross - $billed;
        return $remain >= 0 ? $remain : 0;
    }

    public function getNetworkPath(): ?string
    {
        return $this->network_path ?? null;
    }

    public function toCloudArray(): array
    {
        return [
            'client_name'   => $this->getClientName(),
            'worksite_code' => $this->getWorksiteCode(),
            'worksite_name' => $this->getName(),
            'start_date'    => $this->startDate,
            'created_at'    => $this->createdAt,
        ];
    }


}
?>
