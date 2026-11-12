<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * I Documenti della Zone come un cloud: cartelle dentro cartelle, e i
 * moduli dentro le cartelle insieme ai file.
 *
 * - bb_zone_folders.parent_id: la cartella che la contiene (NULL = in cima).
 * - bb_zone_form_templates.folder_id: la cartella del modulo (NULL = in cima).
 */
final class ZoneCloud extends AbstractMigration
{
    public function up(): void
    {
        $c = $this->table('bb_zone_folders');
        if (!$c->hasColumn('parent_id')) {
            $c->addColumn('parent_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'worksite_id'])
              ->addIndex(['parent_id'], ['name' => 'idx_parent'])
              ->update();
        }
        $t = $this->table('bb_zone_form_templates');
        if (!$t->hasColumn('folder_id')) {
            $t->addColumn('folder_id', 'integer', ['signed' => false, 'null' => true])
              ->addIndex(['folder_id'], ['name' => 'idx_folder'])
              ->update();
        }
    }

    public function down(): void
    {
        $c = $this->table('bb_zone_folders');
        if ($c->hasColumn('parent_id')) {
            $c->removeIndexByName('idx_parent')->removeColumn('parent_id')->update();
        }
        $t = $this->table('bb_zone_form_templates');
        if ($t->hasColumn('folder_id')) {
            $t->removeIndexByName('idx_folder')->removeColumn('folder_id')->update();
        }
    }
}
