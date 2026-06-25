<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FieldwireWorksiteLink extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_worksites');

        if (!$this->getAdapter()->hasColumn('bb_worksites', 'fieldwire_project_id')) {
            $table
                ->addColumn('fieldwire_project_id', 'string',  ['limit' => 64,  'null' => true, 'default' => null])
                ->addColumn('fieldwire_enabled_at',  'datetime', ['null' => true, 'default' => null])
                ->addColumn('fieldwire_enabled_by',  'integer',  ['null' => true, 'default' => null, 'signed' => false])
                ->save();
        }
    }
}
