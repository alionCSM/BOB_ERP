<?php
namespace App\Domain;
use PDO;

class Extra {
    private $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    public function getByWorksiteId($worksite_id) {
        $stmt = $this->conn->prepare("SELECT * FROM bb_extra WHERE worksite_id = :wid ORDER BY created_at DESC");
        $stmt->execute(['wid' => $worksite_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM bb_extra WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $ordine = !empty($data['ordine']) ? $data['ordine'] : null;

        $stmt = $this->conn->prepare("
            INSERT INTO bb_extra (worksite_id, data, ordine, descrizione, totale)
            VALUES (:worksite_id, :data, :ordine, :descrizione, :totale)
        ");
        $stmt->execute([
            'worksite_id' => $data['worksite_id'],
            'data'        => $data['data'],
            'ordine'      => $ordine, // NULL se non valorizzato
            'descrizione' => $data['descrizione'],
            'totale'      => $data['totale']
        ]);
        return $this->conn->lastInsertId();
    }

    public function update($id, $data) {
        $ordine = !empty($data['ordine']) ? $data['ordine'] : null;

        $stmt = $this->conn->prepare("
            UPDATE bb_extra
            SET data = :data,
                ordine = :ordine,
                descrizione = :descrizione,
                totale = :totale
            WHERE id = :id
        ");

        $stmt->execute([
            'id'          => $id,
            'data'        => $data['data'],
            'ordine'      => $ordine, // NULL se vuoto
            'descrizione' => $data['descrizione'],
            'totale'      => $data['totale']
        ]);

        return $stmt->rowCount();
    }


    public function delete($id) {
        $stmt = $this->conn->prepare("DELETE FROM bb_extra WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount();
    }
}
