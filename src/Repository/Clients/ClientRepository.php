<?php
declare(strict_types=1);

namespace App\Repository\Clients;
use PDO;
use App\Repository\Contracts\ClientRepositoryInterface;

final class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(private \PDO $conn)
    {
    }

    /**
     * Base list (no stats). Use only when you really need raw clients.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                id, name, via, cap, localita, filiale, vat, email
            FROM bb_clients
            ORDER BY name ASC
        ");
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * List + aggregated stats (offers/worksites) in ONE query.
     * This fixes your current N+1 problem in clients.php.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllWithStats(): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                c.id,
                c.name,
                c.via,
                c.cap,
                c.localita,
                c.filiale,
                c.vat,
                c.email,
                (
                    SELECT COUNT(*)
                    FROM bb_offers o
                    WHERE o.client_id = c.id
                ) AS total_offers,
                (
                    SELECT COUNT(*)
                    FROM bb_worksites w
                    WHERE w.client_id = c.id
                ) AS total_worksites
            FROM bb_clients c
            ORDER BY c.name ASC
        ");
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                id, name, via, cap, localita, filiale, vat, email
            FROM bb_clients
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_clients (name, via, cap, localita, filiale, vat, email)
            VALUES (:name, :via, :cap, :localita, :filiale, :vat, :email)
        ");

        $stmt->execute([
            ':name'     => (string)$data['name'],
            ':via'      => (string)$data['via'],
            ':cap'      => $data['cap'] !== '' ? (string)$data['cap'] : null,
            ':localita' => (string)$data['localita'],
            ':filiale'  => $data['filiale'] !== '' ? (string)$data['filiale'] : null,
            ':vat'      => $data['vat'] !== '' ? (string)$data['vat'] : null,
            ':email'    => $data['email'] !== '' ? (string)$data['email'] : null,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->conn->prepare("
            UPDATE bb_clients
            SET
                name = :name,
                via = :via,
                cap = :cap,
                localita = :localita,
                filiale = :filiale,
                vat = :vat,
                email = :email
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':name'     => (string)$data['name'],
            ':via'      => (string)$data['via'],
            ':cap'      => $data['cap'] !== '' ? (string)$data['cap'] : null,
            ':localita' => (string)$data['localita'],
            ':filiale'  => $data['filiale'] !== '' ? (string)$data['filiale'] : null,
            ':vat'      => $data['vat'] !== '' ? (string)$data['vat'] : null,
            ':email'    => $data['email'] !== '' ? (string)$data['email'] : null,
            ':id'       => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->conn->prepare("DELETE FROM bb_clients WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Get total count of worksites for a client.
     */
    public function countWorksitesByClientId(int $clientId): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as cnt
            FROM bb_worksites
            WHERE client_id = :client_id
        ");
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Get paginated worksites for a client.
     *
     * @return array{worksites: array<int, array<string, mixed>>, total: int}
     */
    public function getWorksitesByClientId(int $clientId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->conn->prepare("
            SELECT id, name, location, status, start_date
            FROM bb_worksites
            WHERE client_id = :client_id
            ORDER BY start_date DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':client_id', $clientId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'worksites' => $rows,
            'total' => $this->countWorksitesByClientId($clientId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastOfferInfoByClientId(int $clientId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT offer_number, created_at
            FROM bb_offers
            WHERE client_id = :client_id
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':client_id' => $clientId]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function countOffersByClientId(int $clientId): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM bb_offers WHERE client_id = :client_id");
        $stmt->execute([':client_id' => $clientId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchByName(string $query): array
    {
        $stmt = $this->conn->prepare('SELECT id, name, filiale FROM bb_clients WHERE name LIKE :query ORDER BY name ASC');
        $stmt->execute([':query' => '%' . $query . '%']);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
