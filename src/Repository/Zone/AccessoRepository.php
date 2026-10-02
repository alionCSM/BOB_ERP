<?php

declare(strict_types=1);

namespace App\Repository\Zone;

use App\Service\Zone\Accesso;
use PDO;

/**
 * Gli accessi alla Zone di un cantiere: chi li legge e chi li scrive.
 *
 * Separato da {@see Accesso}, che risponde solo alla domanda "questo puo'?"
 * quaranta volte a richiesta: quello e' il pezzo caldo, e tenerlo senza il
 * resto lo lascia piccolo e leggibile.
 */
final class AccessoRepository
{
    public function __construct(private PDO $conn) {}

    /**
     * Chi e' assegnato a un cantiere, coi suoi livelli.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perCantiere(int $worksiteId): array
    {
        // con i backtick: `file` e' una parola che MySQL conosce, e una
        // colonna che si chiama come una parola del linguaggio e' il tipo di
        // sorpresa che si scopre in produzione
        $campi = implode('`, a.`', array_keys(Accesso::FAMIGLIE));
        $stmt  = $this->conn->prepare("
            SELECT a.id, a.user_id, a.`$campi`,
                   u.username,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS nome,
                   u.type, u.company
            FROM   bb_zone_accessi a
            JOIN   bb_users u ON u.id = a.user_id
            WHERE  a.worksite_id = :w
              AND  u.removed = 'N'
            ORDER BY nome, u.username
        ");
        $stmt->execute([':w' => $worksiteId]);

        return array_map(static function (array $r): array {
            foreach (array_keys(Accesso::FAMIGLIE) as $f) {
                $r[$f] = (int)$r[$f];
            }
            $r['id']      = (int)$r['id'];
            $r['user_id'] = (int)$r['user_id'];
            $r['nome']    = $r['nome'] !== '' ? $r['nome'] : $r['username'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * I cantieri che una persona puo' vedere, perche' assegnata.
     *
     * Solo quelli dove vede almeno qualcosa: un'assegnazione con tutte le
     * sezioni a zero e' una riga rimasta li', non un cantiere da mostrare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cantieriDi(int $userId): array
    {
        $somma = implode('` + a.`', array_keys(Accesso::FAMIGLIE));
        $stmt  = $this->conn->prepare("
            SELECT w.id, w.worksite_code, w.name, w.location, w.status
            FROM   bb_zone_accessi a
            JOIN   bb_worksites w ON w.id = a.worksite_id
            WHERE  a.user_id = :u
              AND  (a.`$somma`) > 0
            ORDER BY w.worksite_code DESC
        ");
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assegna o aggiorna, in un colpo solo.
     *
     * Un ON DUPLICATE KEY e non un cerca-poi-scrivi: la stessa persona
     * assegnata due volte di fila da due schede aperte deve finire con una
     * riga sola, e l'unico su (cantiere, utente) lo garantisce qui invece
     * che sperando nei tempi.
     *
     * @param array<string, int> $livelli
     */
    public function salva(int $worksiteId, int $userId, array $livelli, int $daChi): void
    {
        $famiglie = array_keys(Accesso::FAMIGLIE);
        $colonne  = '`' . implode('`, `', $famiglie) . '`';
        $segnali  = implode(', ', array_map(static fn($f) => ':' . $f, $famiglie));
        $aggiorna = implode(', ', array_map(static fn($f) => "`$f` = VALUES(`$f`)", $famiglie));

        $stmt = $this->conn->prepare("
            INSERT INTO bb_zone_accessi (worksite_id, user_id, $colonne, created_by)
            VALUES (:w, :u, $segnali, :da)
            ON DUPLICATE KEY UPDATE $aggiorna
        ");

        $parametri = [':w' => $worksiteId, ':u' => $userId, ':da' => $daChi ?: null];
        foreach ($famiglie as $f) {
            $parametri[':' . $f] = $this->livelloValido($livelli[$f] ?? Accesso::VEDE);
        }
        $stmt->execute($parametri);
    }

    /** Toglie una persona dal cantiere. */
    public function elimina(int $worksiteId, int $userId): bool
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u'
        );
        $stmt->execute([':w' => $worksiteId, ':u' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Un livello che non viene dal form ma dalla richiesta grezza vale
     * quanto quello che qualcuno si e' scritto a mano: fuori dai tre
     * ammessi si ricade sul piu' basso, mai sul piu' alto.
     */
    private function livelloValido(mixed $v): int
    {
        $n = (int)$v;
        return in_array($n, [Accesso::NIENTE, Accesso::VEDE, Accesso::MODIFICA], true)
            ? $n
            : Accesso::NIENTE;
    }
}
