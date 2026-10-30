<?php

declare(strict_types=1);

namespace App\Repository\Zone;

use App\Service\Zone\Accesso;
use PDO;

/**
 * Chi accede alla Zone di un cantiere: chi lo legge e chi lo scrive.
 *
 * Separato da {@see Accesso}, che risponde solo alla domanda "che ruolo ha
 * questa persona qui?" decine di volte a richiesta: quello e' il pezzo
 * caldo, e tenerlo senza il resto lo lascia piccolo e leggibile.
 *
 * Qui dentro si scrivevano anche sei livelli per persona (attivita, file,
 * moduli, disegni, foto, report). Non li legge piu' nessuno: cosa puo' fare
 * un ruolo lo dice {@see \App\Service\Zone\Permessi}, in una tabella sola.
 * Le colonne restano nel database finche' non si e' sicuri, ma non si
 * scrivono e non decidono niente.
 */
final class AccessoRepository
{
    public function __construct(private PDO $conn) {}

    /**
     * Chi e' assegnato a un cantiere, col suo ruolo.
     *
     * Si parte da bb_worksite_users, non dagli accessi: l'assegnazione al
     * cantiere e' il fatto, la riga degli accessi su un'assegnazione
     * vecchia puo' mancare. Partendo dagli accessi, chi era gia' assegnato
     * da prima non comparirebbe e nessuno potrebbe dargli un ruolo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perCantiere(int $worksiteId): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id AS user_id,
                   u.username,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS nome,
                   u.type, u.company,
                   -- chi era assegnato da prima ha il ruolo che gli darebbe
                   -- l'assegnazione di oggi: cliente se e' un cliente
                   COALESCE(a.ruolo, IF(u.type = 'client', 'cliente', 'cantiere')) AS ruolo,
                   -- il modulo Zone di BOB: serve a proporgli il ruolo
                   -- Ufficio invece di farlo entrare come cantiere
                   EXISTS (
                       SELECT 1 FROM bb_user_permissions p
                       WHERE  p.user_id = u.id AND p.module = 'zone' AND p.allowed = 1
                       UNION
                       SELECT 1 FROM bb_user_company_permissions pc
                       WHERE  pc.user_id = u.id AND pc.module = 'zone' AND pc.allowed = 1
                   ) AS ha_modulo_zone,
                   a.id IS NOT NULL AS ha_accesso
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
            $r['user_id']        = (int)$r['user_id'];
            $r['ha_accesso']     = (bool)$r['ha_accesso'];
            $r['ha_modulo_zone'] = (bool)$r['ha_modulo_zone'];
            $r['nome']           = $r['nome'] !== '' ? $r['nome'] : $r['username'];
            // i ruoli vecchi letti come quelli nuovi: capo e operaio sono
            // Cantiere, e una riga non ancora migrata non deve mostrare una
            // parola che nell'elenco dei ruoli non c'e' piu'
            $r['ruolo'] = match ((string)$r['ruolo']) {
                'capo', 'operaio' => Accesso::CANTIERE,
                default           => (string)$r['ruolo'],
            };
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * I cantieri che una persona puo' vedere, perche' ce l'abbiamo messa.
     *
     * Un cliente compare solo dove l'ufficio ha acceso "Condividi col
     * cliente": un cantiere in elenco che poi non apre niente fa pensare a
     * un guasto.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cantieriDi(int $userId): array
    {
        $stmt = $this->conn->prepare("
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
     * Da' l'accesso, o cambia il ruolo.
     *
     * Un ON DUPLICATE KEY e non un cerca-poi-scrivi: la stessa persona
     * aggiunta due volte di fila da due schede aperte deve finire con una
     * riga sola, e l'unico su (cantiere, utente) lo garantisce qui invece
     * che sperando nei tempi.
     *
     * @param string|null $ruolo ufficio, cantiere, cliente; null = si tiene
     *                           quello che c'era (o si ricava dal tipo di account)
     */
    public function salva(int $worksiteId, int $userId, int $daChi, ?string $ruolo = null): void
    {
        $ruolo = $this->ruoloValido($ruolo, $userId, $worksiteId);

        $this->conn->prepare("
            INSERT INTO bb_zone_accessi (worksite_id, user_id, ruolo, created_by)
            VALUES (:w, :u, :ruolo, :da)
            ON DUPLICATE KEY UPDATE ruolo = VALUES(ruolo)
        ")->execute([
            ':w' => $worksiteId, ':u' => $userId, ':ruolo' => $ruolo, ':da' => $daChi ?: null,
        ]);

        // Dare il cantiere e dare la sua Zone sono la stessa cosa: non si
        // assegna qualcuno a un cantiere per poi non fargli vedere niente.
        // Le due tabelle restano due perche' bb_worksite_users la legge
        // mezzo BOB da anni, ma si scrivono sempre insieme — da qui, che e'
        // l'unico posto che le tocca.
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
     * Il ruolo, controllato.
     *
     * Un account cliente e' cliente e basta: nessun form, nemmeno scritto a
     * mano, lo fa diventare ufficio. Gli altri entrano come Cantiere se non
     * si dice altro.
     */
    private function ruoloValido(?string $ruolo, int $userId, int $worksiteId): string
    {
        $stmt = $this->conn->prepare('SELECT type FROM bb_users WHERE id = :u');
        $stmt->execute([':u' => $userId]);
        if ((string)$stmt->fetchColumn() === 'client') {
            return Accesso::CLIENTE;
        }
        if (in_array($ruolo, [Accesso::UFFICIO, Accesso::CANTIERE], true)) {
            return $ruolo;
        }

        // Nessun ruolo chiesto: si tiene quello che c'era. Se no, toccare
        // una riga per sbaglio farebbe scendere un impiegato a Cantiere
        // senza che nessuno l'abbia deciso.
        $c = $this->conn->prepare('SELECT ruolo FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u');
        $c->execute([':w' => $worksiteId, ':u' => $userId]);
        $attuale = (new Accesso($this->conn))->normalizza((string)$c->fetchColumn());

        return in_array($attuale, [Accesso::UFFICIO, Accesso::CANTIERE], true)
            ? $attuale
            : Accesso::CANTIERE;
    }
}
