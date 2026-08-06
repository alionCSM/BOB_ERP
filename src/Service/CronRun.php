<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use Throwable;

/**
 * Registra l'esecuzione di un job schedulato in bb_cron_runs.
 *
 * Uso negli script di includes/cron:
 *
 *     $run = CronRun::start($conn, 'sync_emessa_yard');
 *     try {
 *         ... lavoro ...
 *         $run->ok('Righe controllate: 120, aggiornate: 3');
 *     } catch (\Throwable $e) {
 *         $run->fail($e->getMessage());
 *         throw $e;   // opzionale: il cron decide se propagare
 *     }
 *
 * IMPORTANTE: la registrazione e' best-effort. Se la tabella non esiste
 * ancora (migration non applicata) o il DB non risponde, i metodi non
 * sollevano eccezioni: il job non deve mai fallire per colpa del tracciamento.
 */
final class CronRun
{
    /**
     * Job conosciuti: chiave usata negli script => etichetta mostrata in UI.
     * Serve anche a far comparire nel pannello i job che NON sono mai partiti
     * oggi (altrimenti un cron morto sarebbe invisibile: nessuna riga = nessun
     * problema apparente).
     */
    public const JOBS = [
        'document_expiry_alerts'        => 'Avvisi scadenza documenti',
        'ai_anomaly_check'              => 'Controllo anomalie (BOB AI)',
        'ai_document_verifier'          => 'Verifica documenti (BOB AI)',
        'lifting_calendar_check'        => 'Noleggi: presenze fuori calendario',
        'sync_emessa_yard'              => 'Sync fatture emesse da Yard',
        'yard_worksite_status_check'    => 'Controllo stato cantieri su Yard',
        'programmazione_deadline_check' => 'Scadenze programmazione',
        'recalculate_worksite_stats'    => 'Ricalcolo statistiche cantieri',
    ];

    private ?int $id = null;
    private float $startedAt;

    private function __construct(
        private ?PDO $conn,
        private string $job
    ) {
        $this->startedAt = microtime(true);
    }

    /** Apre una riga in stato "running". */
    public static function start(?PDO $conn, string $job): self
    {
        $run = new self($conn, $job);
        $run->safely(function (PDO $c) use ($run, $job): void {
            $stmt = $c->prepare("
                INSERT INTO bb_cron_runs (job, status, started_at)
                VALUES (:job, 'running', NOW())
            ");
            $stmt->execute([':job' => $job]);
            $run->id = (int)$c->lastInsertId();
        });
        return $run;
    }

    /** Chiude la riga come completata, con un riepilogo facoltativo. */
    public function ok(?string $message = null): void
    {
        $this->finish('ok', $message);
    }

    /** Chiude la riga come fallita, con il messaggio d'errore. */
    public function fail(?string $message = null): void
    {
        $this->finish('error', $message);
    }

    private function finish(string $status, ?string $message): void
    {
        $durationMs = (int)round((microtime(true) - $this->startedAt) * 1000);

        $this->safely(function (PDO $c) use ($status, $message, $durationMs): void {
            if ($this->id !== null) {
                $stmt = $c->prepare("
                    UPDATE bb_cron_runs
                       SET status = :st, finished_at = NOW(), duration_ms = :ms, message = :msg
                     WHERE id = :id
                ");
                $stmt->execute([
                    ':st'  => $status,
                    ':ms'  => $durationMs,
                    ':msg' => $this->trim($message),
                    ':id'  => $this->id,
                ]);
                return;
            }

            // start() non era riuscito a inserire (es. DB non raggiungibile
            // in quel momento): scriviamo comunque l'esito finale.
            $stmt = $c->prepare("
                INSERT INTO bb_cron_runs (job, status, started_at, finished_at, duration_ms, message)
                VALUES (:job, :st, DATE_SUB(NOW(), INTERVAL :sec SECOND), NOW(), :ms, :msg)
            ");
            $stmt->execute([
                ':job' => $this->job,
                ':st'  => $status,
                ':sec' => (int)round($durationMs / 1000),
                ':ms'  => $durationMs,
                ':msg' => $this->trim($message),
            ]);
        });
    }

    /** Il messaggio finisce in un TEXT: tronchiamo per sicurezza. */
    private function trim(?string $message): ?string
    {
        if ($message === null || $message === '') return null;
        return mb_substr($message, 0, 4000);
    }

    /**
     * Esegue l'operazione ignorando qualunque errore di scrittura: il
     * tracciamento non deve mai interrompere il job vero e proprio.
     */
    private function safely(callable $fn): void
    {
        if (!$this->conn instanceof PDO) return;
        try {
            $fn($this->conn);
        } catch (Throwable $e) {
            error_log('[CronRun] tracciamento fallito per ' . $this->job . ': ' . $e->getMessage());
        }
    }
}
