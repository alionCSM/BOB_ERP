<?php

declare(strict_types=1);

namespace App\Service\Zone;

use PDO;

/**
 * Svuotare la Zone di un cantiere.
 *
 * Serve quando un cantiere e' stato usato per provare, o quando si
 * ricomincia da capo e il vecchio non serve piu'. **Non si annulla**, e
 * per questo:
 *
 *  - prima si contano le cose ({@see anteprima()}), cosi' chi preme sa
 *    quanto sta buttando via invece di fidarsi;
 *  - chi lo fa riscrive la sua password;
 *  - resta una riga nel registro, che il reset non tocca.
 *
 * **Due cose non si cancellano**, ed e' voluto:
 *
 *  - **chi accede.** Il cantiere torna vuoto ma pronto, con le stesse
 *    persone dentro: rimetterle a mano una per una sarebbe il modo
 *    migliore per non usare piu' il reset.
 *  - **i file nella cartella cloud.** Spariscono i riferimenti in BOB, il
 *    file resta sul disco. E' l'unico pezzo recuperabile se qualcuno
 *    sbaglia cantiere, e costa solo spazio.
 *
 * I disegni sono una scelta a parte: stanno in `bb_worksite_documents`,
 * che e' dei documenti del cantiere e si vede anche fuori dalla Zone.
 * Cancellarli vorrebbe dire toccare roba che la Zone non ha creato,
 * quindi lo si chiede esplicitamente.
 */
final class Reset
{
    /**
     * Cosa si cancella, e come arrivarci dal numero del cantiere.
     *
     * L'ordine conta: prima i figli, poi i padri. Non per le chiavi
     * esterne (qui non ce ne sono) ma perche' i figli si trovano solo
     * passando dal padre: cancellato il padre, le sue righe figlie
     * resterebbero li' per sempre senza che nessuno sappia piu' di chi
     * erano.
     *
     * @var array<string, string> tabella => come si lega al cantiere
     */
    private const TABELLE = [
        // ── figli, raggiunti attraverso il loro padre ───────────────────
        'bb_zone_task_comments'     => 'task_id IN (SELECT id FROM bb_zone_tasks WHERE worksite_id = :w)',
        'bb_zone_task_checklist'    => 'task_id IN (SELECT id FROM bb_zone_tasks WHERE worksite_id = :w)',
        'bb_zone_file_comments'     => 'file_id IN (SELECT id FROM bb_zone_files WHERE worksite_id = :w)',
        'bb_zone_richieste_messaggi' => 'richiesta_id IN (SELECT id FROM bb_zone_richieste WHERE worksite_id = :w)',

        // ── appesi direttamente al cantiere ─────────────────────────────
        'bb_zone_annotations'       => 'worksite_id = :w',
        'bb_zone_task_eventi'       => 'worksite_id = :w',
        'bb_zone_tasks'             => 'worksite_id = :w',
        'bb_zone_segnalazioni'      => 'worksite_id = :w',
        'bb_zone_messaggi'          => 'worksite_id = :w',
        'bb_zone_letture'           => 'worksite_id = :w',
        'bb_zone_files'             => 'worksite_id = :w',
        'bb_zone_folders'           => 'worksite_id = :w',
        'bb_zone_richieste'         => 'worksite_id = :w',
        'bb_zone_form_submissions'  => 'worksite_id = :w',
        'bb_zone_form_assegnazioni' => 'worksite_id = :w',
        'bb_zone_form_templates'    => 'worksite_id = :w',
        'bb_zone_presenze_manuali'  => 'worksite_id = :w',
        'bb_zone_condivisioni_ruoli' => 'worksite_id = :w',
        'bb_zone_riferimenti_cliente' => 'worksite_id = :w',
        'bb_zone_disegno_sync'      => 'worksite_id = :w',
    ];

    /**
     * Quello che si cancella solo se lo si chiede: i disegni.
     *
     * Stanno fra i documenti del cantiere, non fra le cose della Zone.
     *
     * @var array<string, string>
     */
    private const DISEGNI = [
        'bb_zone_doc_calibration' => 'document_id IN (SELECT id FROM bb_worksite_documents WHERE worksite_id = :w)',
        'bb_zone_dwg_render'      => 'document_id IN (SELECT id FROM bb_worksite_documents WHERE worksite_id = :w)',
    ];

    /** Come si chiamano le cose quando si leggono, non quando si scrivono. */
    private const NOMI = [
        'bb_zone_tasks'             => 'Attivita',
        'bb_zone_task_comments'     => 'Messaggi sulle attivita',
        'bb_zone_task_checklist'    => 'Voci di checklist',
        'bb_zone_task_eventi'       => 'Passaggi delle attivita',
        'bb_zone_segnalazioni'      => 'Segnalazioni',
        'bb_zone_messaggi'          => 'Messaggi in chat',
        'bb_zone_letture'           => 'Segni di lettura',
        'bb_zone_files'             => 'Documenti',
        'bb_zone_file_comments'     => 'Commenti ai documenti',
        'bb_zone_folders'           => 'Cartelle',
        'bb_zone_richieste'         => 'Richieste',
        'bb_zone_richieste_messaggi' => 'Messaggi delle richieste',
        'bb_zone_form_templates'    => 'Modelli di modulo',
        'bb_zone_form_assegnazioni' => 'Moduli da compilare',
        'bb_zone_form_submissions'  => 'Moduli compilati',
        'bb_zone_presenze_manuali'  => 'Presenze scritte a mano',
        'bb_zone_annotations'       => 'Segni sui disegni',
        'bb_zone_condivisioni_ruoli' => 'Condivisioni per ruolo',
        'bb_zone_riferimenti_cliente' => 'Riferimenti del committente',
        'bb_zone_disegno_sync'      => 'Collegamenti dei disegni',
        'bb_zone_doc_calibration'   => 'Calibrazioni dei disegni',
        'bb_zone_dwg_render'        => 'Conversioni DWG',
        'bb_zone_condivisioni'      => 'Chi vede cosa',
    ];

    public function __construct(private PDO $conn) {}

    /**
     * Quanto c'e' da buttare via, cosa per cosa.
     *
     * Si mostra prima di chiedere la password: "sicuro?" senza un numero
     * davanti non e' una domanda, e chi la legge preme di si'.
     *
     * @return array{righe: array<int, array{cosa: string, quante: int}>, totale: int}
     */
    public function anteprima(int $worksiteId, bool $ancheDisegni = false): array
    {
        $righe  = [];
        $totale = 0;

        foreach ($this->tabelle($ancheDisegni) as $tabella => $dove) {
            $n = $this->quante($tabella, $dove, $worksiteId);
            $totale += $n;
            if ($n > 0) {
                $righe[] = ['cosa' => self::NOMI[$tabella] ?? $tabella, 'quante' => $n];
            }
        }

        // "Chi vede cosa" non si conta per tabella: si arriva alle sue
        // righe solo passando dagli oggetti del cantiere
        $n = count($this->idCondivisioni($worksiteId));
        if ($n > 0) {
            $righe[] = ['cosa' => self::NOMI['bb_zone_condivisioni'], 'quante' => $n];
            $totale += $n;
        }

        usort($righe, static fn(array $a, array $b): int => $b['quante'] <=> $a['quante']);

        return ['righe' => $righe, 'totale' => $totale];
    }

    /**
     * Svuota, e lascia la riga nel registro.
     *
     * Tutto dentro una transazione: un reset fatto a meta' e' peggio di un
     * reset non fatto, perche' nessuno sa a che punto si e' fermato.
     *
     * @return array{totale: int, righe: array<int, array{cosa: string, quante: int}>}
     */
    public function esegui(int $worksiteId, bool $ancheDisegni, int $userId, string $userNome): array
    {
        // Si conta PRIMA, dentro la transazione: e' quello che finisce nel
        // registro, e dopo non ci sarebbe piu' niente da contare.
        $this->conn->beginTransaction();
        try {
            $conto = $this->anteprima($worksiteId, $ancheDisegni);

            // le condivisioni per prime: dopo, gli oggetti da cui si
            // arriva alle loro righe non ci sono piu'
            $this->cancellaCondivisioni($worksiteId);

            foreach ($this->tabelle($ancheDisegni) as $tabella => $dove) {
                $st = $this->conn->prepare("DELETE FROM `$tabella` WHERE $dove");
                $st->execute([':w' => $worksiteId]);
            }

            $this->conn->prepare("
                INSERT INTO bb_zone_registro
                    (worksite_id, azione, oggetto, oggetto_id, etichetta, prima, user_id, user_nome)
                VALUES (:w, 'reset', 'cantiere', :w2, :etichetta, :prima, :u, :nome)
            ")->execute([
                ':w'  => $worksiteId,
                ':w2' => $worksiteId,
                ':etichetta' => $ancheDisegni
                    ? 'Zone svuotata, disegni compresi'
                    : 'Zone svuotata',
                ':prima' => json_encode($conto, JSON_UNESCAPED_UNICODE),
                ':u'     => $userId ?: null,
                ':nome'  => $userNome !== '' ? $userNome : null,
            ]);

            $this->conn->commit();
            return $conto;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /** Le righe del registro di un cantiere, dalla piu' recente. */
    public function registro(int $worksiteId, int $quante = 50): array
    {
        $quante = max(1, min(500, $quante));
        $st = $this->conn->prepare("
            SELECT azione, oggetto, oggetto_id, etichetta, prima, user_nome, created_at
            FROM   bb_zone_registro
            WHERE  worksite_id = :w
            ORDER BY id DESC
            LIMIT $quante
        ");
        $st->execute([':w' => $worksiteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Interni ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function tabelle(bool $ancheDisegni): array
    {
        return $ancheDisegni ? self::TABELLE + self::DISEGNI : self::TABELLE;
    }

    /**
     * Quante righe, saltando le tabelle che su questa installazione non
     * ci sono: la Zone e' cresciuta per pezzi, e un database non ancora
     * migrato non deve far fallire un conteggio.
     */
    private function quante(string $tabella, string $dove, int $worksiteId): int
    {
        try {
            $st = $this->conn->prepare("SELECT COUNT(*) FROM `$tabella` WHERE $dove");
            $st->execute([':w' => $worksiteId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Le righe di "chi vede cosa" di questo cantiere.
     *
     * Non hanno il numero del cantiere: si arriva a loro dagli oggetti,
     * tipo per tipo. Per questo vanno tolte per prime.
     *
     * @return array<string, int[]> tipo => id
     */
    private function idCondivisioni(int $worksiteId): array
    {
        $fonti = [
            'attivita' => 'SELECT id FROM bb_zone_tasks   WHERE worksite_id = :w',
            'cartella' => 'SELECT id FROM bb_zone_folders WHERE worksite_id = :w',
            'file'     => 'SELECT id FROM bb_zone_files   WHERE worksite_id = :w',
            'disegno'  => 'SELECT id FROM bb_worksite_documents WHERE worksite_id = :w',
            'modulo'   => 'SELECT id FROM bb_zone_form_assegnazioni WHERE worksite_id = :w',
            'risposta' => 'SELECT id FROM bb_zone_form_submissions  WHERE worksite_id = :w',
        ];

        $fuori = [];
        foreach ($fonti as $tipo => $sql) {
            try {
                $st = $this->conn->prepare("
                    SELECT oggetto_id FROM bb_zone_condivisioni
                    WHERE tipo = '$tipo' AND oggetto_id IN ($sql)
                ");
                $st->execute([':w' => $worksiteId]);
                $id = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                if ($id) {
                    $fuori[$tipo] = $id;
                }
            } catch (\Throwable $e) {
                // tabella non ancora creata su questa installazione
            }
        }
        return $fuori;
    }

    private function cancellaCondivisioni(int $worksiteId): void
    {
        foreach ($this->idCondivisioni($worksiteId) as $tipo => $id) {
            // a blocchi: un IN con qualche migliaio di numeri dentro e' una
            // query che il server rifiuta
            foreach (array_chunk($id, 500) as $pezzo) {
                $this->conn->prepare(
                    "DELETE FROM bb_zone_condivisioni
                     WHERE tipo = '$tipo' AND oggetto_id IN (" . implode(',', $pezzo) . ')'
                )->execute();
            }
        }
    }
}
