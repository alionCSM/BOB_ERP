<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sostituita da 20261112000003_zone_richieste: le richieste sono discussioni
 * dentro le cartelle dei Documenti, non uno stato del singolo file. Resta
 * vuota perche' potrebbe essere gia' stata eseguita; le sue tabelle, se ci
 * sono, le toglie la migrazione successiva.
 */
final class ZoneFileDaCompilare extends AbstractMigration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
}
