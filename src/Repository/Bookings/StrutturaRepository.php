<?php

declare(strict_types=1);

namespace App\Repository\Bookings;
use PDO;
use App\Repository\Contracts\StrutturaRepositoryInterface;

class StrutturaRepository implements StrutturaRepositoryInterface
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Search strutture by name/city, optionally filtered by type
     */
    public function search(string $query, ?string $type = null): array
    {
        $where = ['(s.nome LIKE :q1 OR s.citta LIKE :q2 OR s.indirizzo LIKE :q3)'];
        $params = [
            ':q1' => '%' . $query . '%',
            ':q2' => '%' . $query . '%',
            ':q3' => '%' . $query . '%',
        ];

        if ($type !== null && in_array($type, ['hotel', 'restaurant'], true)) {
            $where[] = 's.type = :type';
            $params[':type'] = $type;
        }

        $sql = "SELECT s.id, s.type, s.nome, s.telefono, s.indirizzo, s.citta, s.provincia, s.country, s.ragione_sociale
                FROM bb_strutture s
                WHERE " . implode(' AND ', $where) . "
                ORDER BY s.nome ASC
                LIMIT 30";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM bb_strutture WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_strutture (type, nome, telefono, indirizzo, citta, provincia, country, ragione_sociale, note)
            VALUES (:type, :nome, :telefono, :indirizzo, :citta, :provincia, :country, :ragione_sociale, :note)
        ");

        $stmt->execute([
            ':type'             => $data['type'],
            ':nome'             => $data['nome'],
            ':telefono'         => $data['telefono'] ?: null,
            ':indirizzo'        => $data['indirizzo'] ?: null,
            ':citta'            => $data['citta'] ?: null,
            ':provincia'        => $data['provincia'] ?: null,
            ':country'          => $data['country'] ?: 'Italia',
            ':ragione_sociale'  => $data['ragione_sociale'] ?: null,
            ':note'             => $data['note'] ?: null,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_strutture SET
                nome = :nome,
                telefono = :telefono,
                indirizzo = :indirizzo,
                citta = :citta,
                provincia = :provincia,
                country = :country,
                ragione_sociale = :ragione_sociale
            WHERE id = :id
        ");

        $stmt->execute([
            ':id'               => $id,
            ':nome'             => $data['nome'],
            ':telefono'         => $data['telefono'] ?: null,
            ':indirizzo'        => $data['indirizzo'] ?: null,
            ':citta'            => $data['citta'] ?: null,
            ':provincia'        => $data['provincia'] ?: null,
            ':country'          => $data['country'] ?: 'Italia',
            ':ragione_sociale'  => $data['ragione_sociale'] ?: null,
        ]);
    }
}
