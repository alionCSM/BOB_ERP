<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FieldwireSyncTables extends AbstractMigration
{
    public function change(): void
    {
        // ── Tasks ─────────────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fw_tasks')) {
            $this->table('bb_fw_tasks', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',   'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_id',         'string',   ['limit' => 64,  'null' => false])
                ->addColumn('name',          'string',   ['limit' => 500, 'null' => false, 'default' => ''])
                ->addColumn('description',   'text',     ['null' => true])
                ->addColumn('status',        'string',   ['limit' => 64,  'null' => true])
                ->addColumn('category_name', 'string',   ['limit' => 128, 'null' => true])
                ->addColumn('assignee_name', 'string',   ['limit' => 128, 'null' => true])
                ->addColumn('due_date',      'date',     ['null' => true])
                ->addColumn('fw_created_at', 'datetime', ['null' => true])
                ->addColumn('fw_updated_at', 'datetime', ['null' => true])
                ->addColumn('synced_at',     'datetime', ['null' => true])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['fw_id'],       ['unique' => true, 'name' => 'uq_fw_task'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->create();
        }

        // ── Check items ───────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fw_check_items')) {
            $this->table('bb_fw_check_items', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_task_id',  'string',   ['limit' => 64, 'null' => false])
                ->addColumn('fw_id',       'string',   ['limit' => 64, 'null' => false])
                ->addColumn('name',        'string',   ['limit' => 500, 'null' => false, 'default' => ''])
                ->addColumn('completed',   'boolean',  ['null' => false, 'default' => false])
                ->addColumn('synced_at',   'datetime', ['null' => true])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['fw_id'],      ['unique' => true, 'name' => 'uq_fw_check_item'])
                ->addIndex(['fw_task_id'], ['name' => 'idx_fw_task'])
                ->addIndex(['worksite_id'],['name' => 'idx_worksite'])
                ->create();
        }

        // ── Bubbles (messages/comments) ───────────────────────────────────────
        if (!$this->hasTable('bb_fw_bubbles')) {
            $this->table('bb_fw_bubbles', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',   'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_task_id',    'string',   ['limit' => 64,  'null' => false])
                ->addColumn('fw_id',         'string',   ['limit' => 64,  'null' => false])
                ->addColumn('kind',          'string',   ['limit' => 32,  'null' => true])
                ->addColumn('text',          'text',     ['null' => true])
                ->addColumn('creator_name',  'string',   ['limit' => 128, 'null' => true])
                ->addColumn('creator_email', 'string',   ['limit' => 128, 'null' => true])
                ->addColumn('file_url',      'text',     ['null' => true])
                ->addColumn('fw_created_at', 'datetime', ['null' => true])
                ->addColumn('synced_at',     'datetime', ['null' => true])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['fw_id'],      ['unique' => true, 'name' => 'uq_fw_bubble'])
                ->addIndex(['fw_task_id'], ['name' => 'idx_fw_task'])
                ->addIndex(['worksite_id'],['name' => 'idx_worksite'])
                ->create();
        }

        // ── Floorplans ────────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fw_floorplans')) {
            $this->table('bb_fw_floorplans', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',   'integer',  ['null' => false, 'signed' => false])
                ->addColumn('fw_id',         'string',   ['limit' => 64,  'null' => false])
                ->addColumn('name',          'string',   ['limit' => 500, 'null' => false, 'default' => ''])
                ->addColumn('sheets_count',  'integer',  ['null' => true])
                ->addColumn('fw_updated_at', 'datetime', ['null' => true])
                ->addColumn('synced_at',     'datetime', ['null' => true])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['fw_id'],       ['unique' => true, 'name' => 'uq_fw_floorplan'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->create();
        }
    }
}
