<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Il nome e il codice commessa che il cliente da' a un nostro cantiere.
 *
 * Li scrive il cliente, uno per azienda cliente e cantiere. Si mostrano
 * accanto ai nostri: a lui nell'elenco dei cantieri e nella Zone,
 * all'ufficio nella Zone e nel rapporto.
 */
final class RiferimentoCliente
{
    public function __construct(private PDO $conn) {}

    /** @return array{nome:string, codice:string}|null quello del cliente del cantiere */
    public function delCantiere(int $worksiteId): ?array
    {
        try {
            $st = $this->conn->prepare('
                SELECT r.nome, r.codice FROM bb_zone_riferimenti_cliente r
                JOIN bb_worksites w ON w.id = r.worksite_id AND w.client_id = r.client_id
                WHERE r.worksite_id = :w
            ');
            $st->execute([':w' => $worksiteId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // migrazione non ancora lanciata
        }
        if (!$r || (trim((string)$r['nome']) === '' && trim((string)$r['codice']) === '')) {
            return null;
        }
        return ['nome' => (string)$r['nome'], 'codice' => (string)$r['codice']];
    }

    /**
     * Quelli di un cliente, per tutti i suoi cantieri.
     *
     * @return array<int, array{nome:string, codice:string}> worksite_id => riferimento
     */
    public function diCliente(int $clientId): array
    {
        try {
            $st = $this->conn->prepare('SELECT worksite_id, nome, codice FROM bb_zone_riferimenti_cliente WHERE client_id = :c');
            $st->execute([':c' => $clientId]);
        } catch (\PDOException $e) {
            return [];
        }
        $fuori = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fuori[(int)$r['worksite_id']] = ['nome' => (string)$r['nome'], 'codice' => (string)$r['codice']];
        }
        return $fuori;
    }

    public function salva(int $worksiteId, int $clientId, string $nome, string $codice, int $daChi): array
    {
        $nome   = mb_substr(trim($nome), 0, 120);
        $codice = mb_substr(trim($codice), 0, 60);
        if ($nome === '' && $codice === '') {
            $this->conn->prepare('DELETE FROM bb_zone_riferimenti_cliente WHERE worksite_id = :w AND client_id = :c')
                ->execute([':w' => $worksiteId, ':c' => $clientId]);
        } else {
            $this->conn->prepare('
                INSERT INTO bb_zone_riferimenti_cliente (worksite_id, client_id, nome, codice, updated_by)
                VALUES (:w, :c, :n, :k, :u)
                ON DUPLICATE KEY UPDATE nome = VALUES(nome), codice = VALUES(codice), updated_by = VALUES(updated_by)
            ')->execute([':w' => $worksiteId, ':c' => $clientId, ':n' => $nome ?: null, ':k' => $codice ?: null, ':u' => $daChi ?: null]);
        }
        return ['nome' => $nome, 'codice' => $codice];
    }
}
