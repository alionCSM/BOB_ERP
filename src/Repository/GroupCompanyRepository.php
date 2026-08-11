<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Anagrafica delle societa' del gruppo e assegnazione degli utenti.
 *
 * Da non confondere con bb_companies (aziende consorziate): qui stanno le
 * societa' del gruppo, indipendenti fra loro.
 */
final class GroupCompanyRepository
{
    public function __construct(private PDO $conn) {}

    /** Tutte le societa', con il numero di utenti assegnati. */
    public function all(): array
    {
        $sql = "
            SELECT c.*, (
                SELECT COUNT(*) FROM bb_user_companies uc
                WHERE uc.group_company_id = c.id
            ) AS utenti
            FROM bb_group_companies c
            ORDER BY c.ordinamento ASC, c.nome ASC
        ";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM bb_group_companies WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Crea o aggiorna una societa'. Ritorna l'id. */
    public function save(?int $id, array $dati): int
    {
        $params = [
            ':nome'        => $dati['nome'],
            ':codice'      => $dati['codice'],
            ':colore'      => $dati['colore'],
            ':moduli'      => $dati['moduli'] !== '' ? $dati['moduli'] : null,
            ':attiva'      => $dati['attiva'] ? 1 : 0,
            ':ordinamento' => $dati['ordinamento'],
        ];

        if ($id) {
            $stmt = $this->conn->prepare("
                UPDATE bb_group_companies
                SET nome = :nome, codice = :codice, colore = :colore,
                    moduli = :moduli, attiva = :attiva, ordinamento = :ordinamento
                WHERE id = :id
            ");
            $stmt->execute($params + [':id' => $id]);
            return $id;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO bb_group_companies (nome, codice, colore, moduli, attiva, ordinamento)
            VALUES (:nome, :codice, :colore, :moduli, :attiva, :ordinamento)
        ");
        $stmt->execute($params);
        return (int)$this->conn->lastInsertId();
    }

    /** Utenti interni, con l'indicazione di quelli assegnati alla societa'. */
    public function usersForCompany(int $companyId): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.username, u.email, u.type,
                   CASE WHEN uc.id IS NULL THEN 0 ELSE 1 END AS assegnato,
                   COALESCE(uc.is_default, 0) AS is_default
            FROM   bb_users u
            LEFT JOIN bb_user_companies uc
                   ON uc.user_id = u.id AND uc.group_company_id = :cid
            WHERE  u.type NOT IN ('worker', 'client')
            ORDER BY u.username ASC
        ");
        $stmt->execute([':cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Riscrive l'elenco degli utenti di una societa'.
     *
     * Le righe si cancellano e si reinseriscono dentro una transazione: se
     * qualcosa fallisce a meta' nessuno resta senza societa' assegnata.
     *
     * @param int[] $userIds
     */
    public function setUsers(int $companyId, array $userIds): void
    {
        // i "predefinita" gia' impostati vanno conservati, altrimenti
        // riordinare gli utenti azzererebbe la societa' proposta al login
        $pre  = $this->conn->prepare(
            'SELECT user_id FROM bb_user_companies WHERE group_company_id = :cid AND is_default = 1'
        );
        $pre->execute([':cid' => $companyId]);
        $default = array_map('intval', $pre->fetchAll(PDO::FETCH_COLUMN));

        $this->conn->beginTransaction();
        try {
            $del = $this->conn->prepare('DELETE FROM bb_user_companies WHERE group_company_id = :cid');
            $del->execute([':cid' => $companyId]);

            if ($userIds) {
                $ins = $this->conn->prepare("
                    INSERT INTO bb_user_companies (user_id, group_company_id, is_default)
                    VALUES (:uid, :cid, :def)
                ");
                foreach (array_unique($userIds) as $uid) {
                    $ins->execute([
                        ':uid' => (int)$uid,
                        ':cid' => $companyId,
                        ':def' => in_array((int)$uid, $default, true) ? 1 : 0,
                    ]);
                }
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /** True se il codice e' gia' usato da un'altra societa'. */
    public function codiceInUso(string $codice, ?int $escludiId = null): bool
    {
        $sql  = 'SELECT COUNT(*) FROM bb_group_companies WHERE codice = :c';
        $args = [':c' => $codice];
        if ($escludiId) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $escludiId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($args);
        return (int)$stmt->fetchColumn() > 0;
    }
}
