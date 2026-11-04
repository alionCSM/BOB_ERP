<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dove si fa un'attivita': "campata 3, livello 2", "lato carico".
 *
 * In cantiere la prima domanda e' "dove?", e finiva scritta a meta' nel
 * nome o persa nella descrizione.
 */
final class ZoneTaskLuogo extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_zone_tasks');
        if (!$t->hasColumn('luogo')) {
            $t->addColumn('luogo', 'string', ['limit' => 160, 'null' => true, 'after' => 'description'])->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('bb_zone_tasks');
        if ($t->hasColumn('luogo')) {
            $t->removeColumn('luogo')->update();
        }
    }
}
