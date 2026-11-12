<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le richieste si fanno anche fuori dalle cartelle, in cima ai Documenti:
 * folder_id diventa facoltativo (NULL = in cima).
 */
final class ZoneRichiesteInCima extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_richieste')) {
            $this->table('bb_zone_richieste')
                ->changeColumn('folder_id', 'integer', ['signed' => false, 'null' => true])
                ->update();
        }
    }

    public function down(): void
    {
    }
}
