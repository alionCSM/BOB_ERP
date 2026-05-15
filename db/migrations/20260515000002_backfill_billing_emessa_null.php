<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Backfill any bb_billing.emessa NULL values to 0.
 *
 * Newer rows inserted before this fix (e.g. via the cantiere billing
 * form / the "Fattura" button on an extra) didn't set emessa explicitly,
 * so they inherited whatever DEFAULT the column had — NULL on legacy
 * installs. Those rows then disappeared from the fatturazione clienti
 * listing because the SUM(emessa = 0) check returned NULL/0 and the
 * client failed the HAVING clause.
 *
 * INSERTs and queries are now defensive (emessa=0 always set, NULL
 * treated as 0), but existing affected rows still need the one-time
 * backfill so the listing is correct without waiting for the per-client
 * Yard sync to run.
 */
final class BackfillBillingEmessaNull extends AbstractMigration
{
    public function change(): void
    {
        $this->execute('UPDATE bb_billing SET emessa = 0 WHERE emessa IS NULL');
    }
}
