<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateZoneTasks extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bb_zone_tasks')) {
            $this->table('bb_zone_tasks', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',   'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_id',         'string',   ['limit' => 64,  'null' => true, 'default' => null])
                ->addColumn('name',          'string',   ['limit' => 500, 'null' => false])
                ->addColumn('description',   'text',     ['null' => true])
                ->addColumn('status',        'string',   ['limit' => 32,  'null' => false, 'default' => 'open'])
                ->addColumn('category',      'string',   ['limit' => 128, 'null' => true])
                ->addColumn('assignee_name', 'string',   ['limit' => 128, 'null' => true])
                ->addColumn('start_date',    'date',     ['null' => true])
                ->addColumn('due_date',      'date',     ['null' => true])
                ->addColumn('priority',      'integer',  ['null' => false, 'default' => 0])
                ->addColumn('created_by',    'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->addIndex(['fw_id'],       ['name' => 'idx_fw_id'])
                ->addIndex(['status'],      ['name' => 'idx_status'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_task_comments')) {
            $this->table('bb_zone_task_comments', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('task_id',     'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_id',       'string',   ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('text',        'text',     ['null' => true])
                ->addColumn('author_name', 'string',   ['limit' => 128, 'null' => true])
                ->addColumn('file_url',    'text',     ['null' => true])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['task_id'], ['name' => 'idx_task'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_task_checklist')) {
            $this->table('bb_zone_task_checklist', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('task_id',   'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_id',     'string',   ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('name',      'string',   ['limit' => 500, 'null' => false])
                ->addColumn('completed', 'boolean',  ['null' => false, 'default' => false])
                ->addColumn('position',  'integer',  ['null' => false, 'default' => 0])
                ->addColumn('created_at','datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at','datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['task_id'], ['name' => 'idx_task'])
                ->create();
        }
    }
}
