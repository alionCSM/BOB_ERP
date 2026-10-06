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
        // Si parte da bb_worksite_users, non dagli accessi: l'assegnazione
        // al cantiere e' il fatto, i livelli sono un dettaglio che su una
        // riga vecchia puo' mancare. Partendo dagli accessi, chi era gia'
        // assegnato da prima non comparirebbe e nessuno potrebbe dargliene.
        //
        // A chi non ce l'ha i livelli valgono zero, non "vede": far
        // comparire un accesso che nessuno ha dato, su assegnazioni di
        // chissa' quando, e' il modo giusto per aprire una porta senza
        // accorgersene. Si alzano a mano, e si vede subito chi e' a zero.
        // una riga per famiglia, scritta per esteso: un implode furbo qui
        // genera SQL che sembra giusto e non lo e'
        $campi = '';
        foreach (array_keys(Accesso::FAMIGLIE) as $f) {
            $campi .= "COALESCE(a.`$f`, 0) AS `$f`,
                   ";
        }

        $stmt = $this->conn->prepare("
            SELECT u.id AS user_id,
                   $campi
                   u.username,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS nome,
                   u.type, u.company,
                   -- chi era assegnato da prima ha il ruolo che gli darebbe
                   -- l'assegnazione di oggi: cliente se e' un cliente
                   COALESCE(a.ruolo, IF(u.type = 'client', 'cliente', 'operaio')) AS ruolo,
                   a.id IS NOT NULL AS ha_livelli
            FROM   bb_worksite_users wu
            JOIN   bb_users u ON u.id = wu.user_id
            LEFT JOIN bb_zone_accessi a
                   ON a.worksite_id = wu.worksite_id AND a.user_id = wu.user_id
            WHERE  wu.worksite_id = :w
              AND  u.removed = 'N'
              AND  u.active  = 'Y'
            ORDER BY nome, u.username
        ");
        $stmt->execute([':w' => $worksiteId]);

        return array_map(static function (array $r): array {
            foreach (array_keys(Accesso::FAMIGLIE) as $f) {
                $r[$f] = (int)($r[$f] ?? 0);
            }
            $r['user_id']    = (int)$r['user_id'];
            $r['ha_livelli'] = (bool)$r['ha_livelli'];
            $r['nome']       = $r['nome'] !== '' ? $r['nome'] : $r['username'];
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
        // Il ruolo decide, non piu' i sei livelli. Un cliente compare solo
        // dove l'ufficio ha acceso "Condividi col cliente": un cantiere in
        // elenco che poi non apre niente fa pensare a un guasto.
        $stmt  = $this->conn->prepare("
            SELECT w.id, w.worksite_code, w.name, w.location, w.status, a.ruolo
            FROM   bb_zone_accessi a
            JOIN   bb_worksites w ON w.id = a.worksite_id
            WHERE  a.user_id = :u
              AND  (a.ruolo <> 'cliente' OR w.zone_cliente = 1)
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
     * @param array<string, int> $livelli  i vecchi livelli: si scrivono ancora,
     *                                       finche' le pagine nuove non sono in uso
     * @param string|null $ruolo capo, operaio, cliente; null = dal tipo di account
     */
    public function salva(int $worksiteId, int $userId, array $livelli, int $daChi, ?string $ruolo = null): void
    {
        $ruolo = $this->ruoloValido($ruolo, $userId, $worksiteId);

        $famiglie = array_keys(Accesso::FAMIGLIE);
        $colonne  = '`' . implode('`, `', $famiglie) . '`';
        $segnali  = implode(', ', array_map(static fn($f) => ':' . $f, $famiglie));
        $aggiorna = implode(', ', array_map(static fn($f) => "`$f` = VALUES(`$f`)", $famiglie));

        $stmt = $this->conn->prepare("
            INSERT INTO bb_zone_accessi (worksite_id, user_id, ruolo, $colonne, created_by)
            VALUES (:w, :u, :ruolo, $segnali, :da)
            ON DUPLICATE KEY UPDATE ruolo = VALUES(ruolo), $aggiorna
        ");

        $parametri = [':w' => $worksiteId, ':u' => $userId, ':ruolo' => $ruolo, ':da' => $daChi ?: null];
        foreach ($famiglie as $f) {
            $parametri[':' . $f] = $this->livelloValido($livelli[$f] ?? Accesso::VEDE);
        }
        $stmt->execute($parametri);

        // Assegnare un cantiere e dare la sua Zone sono la stessa cosa: non
        // si assegna qualcuno a un cantiere per poi non fargli vedere
        // niente. Le due tabelle restano due perche' bb_worksite_users la
        // legge mezzo BOB da anni, ma si scrivono sempre insieme — da qui,
        // che e' l'unico posto che le tocca.
        $this->conn->prepare(
            'INSERT IGNORE INTO bb_worksite_users (worksite_id, user_id) VALUES (:w, :u)'
        )->execute([':w' => $worksiteId, ':u' => $userId]);
    }

    /** Toglie una persona dal cantiere, da tutte e due le tabelle. */
    public function elimina(int $worksiteId, int $userId): bool
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u'
        );
        $stmt->execute([':w' => $worksiteId, ':u' => $userId]);
        $tolto = $stmt->rowCount() > 0;

        $altro = $this->conn->prepare(
            'DELETE FROM bb_worksite_users WHERE worksite_id = :w AND user_id = :u'
        );
        $altro->execute([':w' => $worksiteId, ':u' => $userId]);

        // basta che sia sparito da una delle due: le righe vecchie di
        // bb_worksite_users non hanno un accesso Zone da togliere
        return $tolto || $altro->rowCount() > 0;
    }

    /**
     * Il ruolo, controllato. Un account cliente e' cliente e basta: nessun
     * form, nemmeno scritto a mano, lo fa diventare capo di una squadra. Un
     * account interno non diventa cliente.
     */
    private function ruoloValido(?string $ruolo, int $userId, int $worksiteId): string
    {
        $stmt = $this->conn->prepare('SELECT type FROM bb_users WHERE id = :u');
        $stmt->execute([':u' => $userId]);
        if ((string)$stmt->fetchColumn() === 'client') {
            return Accesso::CLIENTE;
        }
        if (in_array($ruolo, [Accesso::CAPO, Accesso::OPERAIO], true)) {
            return $ruolo;
        }
        // Nessun ruolo chiesto (la pagina vecchia manda solo i livelli): si
        // tiene quello che c'era. Se no, cambiare un livello a un capo lo
        // farebbe tornare operaio senza che nessuno l'abbia deciso.
        $c = $this->conn->prepare('SELECT ruolo FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u');
        $c->execute([':w' => $worksiteId, ':u' => $userId]);
        $attuale = (string)$c->fetchColumn();
        return in_array($attuale, [Accesso::CAPO, Accesso::OPERAIO], true) ? $attuale : Accesso::OPERAIO;
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
