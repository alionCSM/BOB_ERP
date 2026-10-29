<?php

declare(strict_types=1);

namespace App\Service\Ai;

use PDO;

/**
 * Le chat di BOB AI che restano scritte.
 *
 * Una conversazione e' di chi l'ha fatta: ogni lettura passa dal controllo
 * su user_id, anche quando l'id arriva dall'indirizzo. Non c'e' condivisione
 * fra utenti, nemmeno per il superadmin: le domande che uno fa all'AI sono
 * sue, e un elenco di quello che un collega ha chiesto non serve a niente a
 * nessuno.
 *
 * La distinzione che conta e' fra `testo` e `riassunto`:
 *
 * - `testo` e' quello che l'utente rilegge quando riapre la chat, tabella
 *   markdown compresa.
 * - `riassunto` e' una riga sola, ed e' quello che si rimanda al modello
 *   come contesto dei turni precedenti. Prima gli si rimandava la tabella
 *   intera: duecento righe di dati che riempivano il contesto, rallentavano
 *   la risposta e spingevano fuori lo schema, cioe' l'unica cosa che gli
 *   serviva davvero.
 */
final class Conversazioni
{
    /** Quanti turni passati si rimandano al modello. */
    private const TURNI_CONTESTO = 6;

    public function __construct(private PDO $conn) {}

    /**
     * Le chat di un utente, le piu' recenti in cima.
     *
     * @return list<array{id:int, titolo:string, updated_at:string, messaggi:int}>
     */
    public function elenco(int $userId, int $limite = 50): array
    {
        $stmt = $this->conn->prepare("
            SELECT c.id, c.titolo, c.updated_at,
                   (SELECT COUNT(*) FROM bb_ai_messaggi m WHERE m.conversazione_id = c.id) AS messaggi
            FROM   bb_ai_conversazioni c
            WHERE  c.user_id = :uid AND c.archiviata = 0
            ORDER BY c.updated_at DESC
            LIMIT  :lim
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn(array $r) => [
                'id'         => (int)$r['id'],
                'titolo'     => (string)$r['titolo'],
                'updated_at' => (string)$r['updated_at'],
                'messaggi'   => (int)$r['messaggi'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function crea(int $userId, int $companyId, string $titolo): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ai_conversazioni (user_id, company_id, titolo, created_at, updated_at)
            VALUES (:uid, :cid, :tit, NOW(), NOW())
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':cid' => $companyId,
            ':tit' => $this->titoloDa($titolo),
        ]);

        return (int)$this->conn->lastInsertId();
    }

    /** True se la conversazione esiste ed e' di questo utente. */
    public function appartiene(int $id, int $userId): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM bb_ai_conversazioni WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * I messaggi per rimettere la chat sullo schermo.
     *
     * @return list<array{ruolo:string, testo:string, sql:?string, righe:?int, errore:?string, created_at:string}>
     */
    public function messaggi(int $convId): array
    {
        $stmt = $this->conn->prepare("
            SELECT ruolo, testo, sql_query, righe, errore, created_at
            FROM   bb_ai_messaggi
            WHERE  conversazione_id = :cid
            ORDER BY id ASC
        ");
        $stmt->execute([':cid' => $convId]);

        return array_map(
            fn(array $r) => [
                'ruolo'      => (string)$r['ruolo'],
                'testo'      => (string)$r['testo'],
                'sql'        => $r['sql_query'] !== null ? (string)$r['sql_query'] : null,
                'righe'      => $r['righe'] !== null ? (int)$r['righe'] : null,
                'errore'     => $r['errore'] !== null ? (string)$r['errore'] : null,
                'created_at' => (string)$r['created_at'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Il contesto da rimandare al modello: le ultime domande e, per le
     * risposte, la riga di riassunto con la query — non i dati.
     *
     * @return list<array{role:string, content:string}>
     */
    public function contesto(int $convId, int $turni = self::TURNI_CONTESTO): array
    {
        $stmt = $this->conn->prepare("
            SELECT ruolo, testo, riassunto, sql_query
            FROM   bb_ai_messaggi
            WHERE  conversazione_id = :cid
            ORDER BY id DESC
            LIMIT  :lim
        ");
        $stmt->bindValue(':cid', $convId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $turni * 2, PDO::PARAM_INT);
        $stmt->execute();

        $righe = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        $out = [];
        foreach ($righe as $r) {
            if ($r['ruolo'] === 'utente') {
                $out[] = ['role' => 'user', 'content' => (string)$r['testo']];
                continue;
            }

            // Per le risposte: il riassunto e la query che l'ha prodotta. Con
            // questi due il modello sa di cosa si stava parlando e puo'
            // modificare la query precedente, che e' quello che chiede chi
            // scrive "e di quelli chiusi?".
            $pezzi = [];
            if (!empty($r['riassunto'])) {
                $pezzi[] = (string)$r['riassunto'];
            }
            if (!empty($r['sql_query'])) {
                $pezzi[] = 'Query usata: ' . (string)$r['sql_query'];
            }
            if ($pezzi === []) {
                $pezzi[] = mb_substr((string)$r['testo'], 0, 200);
            }

            $out[] = ['role' => 'assistant', 'content' => implode("\n", $pezzi)];
        }

        return $out;
    }

    /**
     * Le tabelle di cui si e' parlato in questa chat, dalle query gia' fatte.
     * Servono al catalogo per capire il contesto di una domanda che da sola
     * non dice niente ("e quelli chiusi?").
     *
     * @return list<string>
     */
    public function tabelleRecenti(int $convId, int $turni = self::TURNI_CONTESTO): array
    {
        $stmt = $this->conn->prepare("
            SELECT sql_query
            FROM   bb_ai_messaggi
            WHERE  conversazione_id = :cid AND sql_query IS NOT NULL
            ORDER BY id DESC
            LIMIT  :lim
        ");
        $stmt->bindValue(':cid', $convId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $turni, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sql) {
            if (preg_match_all('/\b(bb_[a-z0-9_]+)\b/i', (string)$sql, $m)) {
                foreach ($m[1] as $t) {
                    $out[strtolower($t)] = true;
                }
            }
        }

        return array_keys($out);
    }

    public function aggiungiDomanda(int $convId, string $testo): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ai_messaggi (conversazione_id, ruolo, testo, created_at)
            VALUES (:cid, 'utente', :testo, NOW())
        ");
        $stmt->execute([':cid' => $convId, ':testo' => $testo]);

        $this->tocca($convId);
    }

    public function aggiungiRisposta(
        int $convId,
        string $testo,
        ?string $riassunto = null,
        ?string $sql = null,
        ?int $righe = null,
        ?int $durataMs = null,
        ?string $errore = null,
    ): void {
        $stmt = $this->conn->prepare("
            INSERT INTO bb_ai_messaggi
                (conversazione_id, ruolo, testo, riassunto, sql_query, righe, durata_ms, errore, created_at)
            VALUES
                (:cid, 'bob', :testo, :riass, :sql, :righe, :dur, :err, NOW())
        ");
        $stmt->execute([
            ':cid'   => $convId,
            ':testo' => $testo,
            ':riass' => $riassunto !== null ? mb_substr($riassunto, 0, 300) : null,
            ':sql'   => $sql,
            ':righe' => $righe,
            ':dur'   => $durataMs,
            ':err'   => $errore !== null ? mb_substr($errore, 0, 500) : null,
        ]);

        $this->tocca($convId);
    }

    public function rinomina(int $id, int $userId, string $titolo): bool
    {
        $titolo = $this->titoloDa($titolo);
        if ($titolo === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE bb_ai_conversazioni SET titolo = :tit WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':tit' => $titolo, ':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cancella davvero: chi chiude una chat non si aspetta che resti in giro.
     * Il log tecnico in bb_ai_sql_logs non viene toccato — serve a capire
     * cosa e' stato chiesto al database, ed e' un'altra cosa.
     */
    public function elimina(int $id, int $userId): bool
    {
        if (!$this->appartiene($id, $userId)) {
            return false;
        }

        $this->conn->beginTransaction();
        try {
            $this->conn->prepare('DELETE FROM bb_ai_messaggi WHERE conversazione_id = :id')
                ->execute([':id' => $id]);
            $this->conn->prepare('DELETE FROM bb_ai_conversazioni WHERE id = :id AND user_id = :uid')
                ->execute([':id' => $id, ':uid' => $userId]);
            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    private function tocca(int $convId): void
    {
        $this->conn->prepare('UPDATE bb_ai_conversazioni SET updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $convId]);
    }

    /**
     * Il titolo della chat: la prima domanda, ripulita e tagliata.
     *
     * Niente giro dal modello per farselo scrivere: costerebbe una chiamata
     * intera per una cosa che la domanda dell'utente dice gia' meglio.
     */
    public function titoloDa(string $domanda): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', $domanda));
        $t = trim($t, " \t\n\r\0\x0B?.!,;:");

        if ($t === '') {
            return 'Nuova chat';
        }

        if (mb_strlen($t) > 70) {
            $t = mb_substr($t, 0, 70);
            // taglia sull'ultima parola intera, non a meta' di una parola
            $spazio = mb_strrpos($t, ' ');
            if ($spazio !== false && $spazio > 30) {
                $t = mb_substr($t, 0, $spazio);
            }
            $t .= '…';
        }

        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }
}
