<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Storico esecuzioni dei job schedulati (cron).
 *
 * Ogni script in includes/cron registra qui inizio, fine, esito ed eventuale
 * errore, cosi' il pannello "Servizi" in alto puo' mostrare se il job di oggi
 * e' andato a buon fine, a che ora, e quale errore ha dato.
 *
 * Nota: senza questa tabella i cron continuano a funzionare — la scrittura
 * dello stato e' best-effort e non deve mai far fallire il job.
 */
final class CronRuns extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_cron_runs')) {
            return;
        }

        $this->table('bb_cron_runs', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('job',         'string',   ['limit' => 64, 'null' => false])
            ->addColumn('status',      'string',   ['limit' => 16, 'null' => false, 'default' => 'running',
                                                    'comment' => 'running | ok | error'])
            ->addColumn('started_at',  'datetime', ['null' => false])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addColumn('duration_ms', 'integer',  ['null' => true, 'signed' => false])
            ->addColumn('message',     'text',     ['null' => true,
                                                    'comment' => 'Riepilogo se ok, messaggio di errore se error'])
            ->addIndex(['job', 'started_at'], ['name' => 'idx_job_started'])
            ->addIndex(['started_at'],        ['name' => 'idx_started'])
            ->create();
    }
}
