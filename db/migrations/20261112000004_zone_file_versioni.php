<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Il legame fra i documenti della Zone.
 *
 * - origine_file_id: il documento da cui questo e' stato compilato. Nella
 *   cartella la copia compilata compare sotto l'originale, come versione.
 * - richiesta_id: la richiesta con cui il documento e' arrivato.
 */
final class ZoneFileVersioni extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_zone_files');
        if (!$t->hasColumn('origine_file_id')) {
            $t->addColumn('origine_file_id', 'integer', ['signed' => false, 'null' => true])
              ->addIndex(['origine_file_id'], ['name' => 'idx_origine']);
        }
        if (!$t->hasColumn('richiesta_id')) {
            $t->addColumn('richiesta_id', 'integer', ['signed' => false, 'null' => true]);
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('bb_zone_files');
        if ($t->hasColumn('origine_file_id')) {
            $t->removeIndexByName('idx_origine')->removeColumn('origine_file_id');
        }
        if ($t->hasColumn('richiesta_id')) {
            $t->removeColumn('richiesta_id');
        }
        $t->update();
    }
}
