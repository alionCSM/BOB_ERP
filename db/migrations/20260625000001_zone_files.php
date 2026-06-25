<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sezione "File" di BOB Zone: repository documenti del cantiere con
 * cartelle, upload di qualsiasi tipo, metadati (chi/quando) e commenti
 * per file. Distinta da Disegni (tavole annotabili) e Galleria (foto task).
 */
final class ZoneFiles extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bb_zone_folders')) {
            $this->table('bb_zone_folders', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('name',        'string',   ['limit' => 255, 'null' => false])
                ->addColumn('created_by',  'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_files')) {
            $this->table('bb_zone_files', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('folder_id',   'integer',  ['null' => true, 'signed' => false])
                ->addColumn('file_name',   'string',   ['limit' => 255, 'null' => false])
                ->addColumn('file_path',   'string',   ['limit' => 500, 'null' => false])
                ->addColumn('file_type',   'string',   ['limit' => 16,  'null' => true])
                ->addColumn('size_bytes',  'biginteger', ['null' => true, 'signed' => false])
                ->addColumn('uploaded_by', 'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id', 'folder_id'], ['name' => 'idx_ws_folder'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_file_comments')) {
            $this->table('bb_zone_file_comments', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('file_id',     'integer',  ['null' => false, 'signed' => false])
                ->addColumn('text',        'text',     ['null' => false])
                ->addColumn('author_name', 'string',   ['limit' => 128, 'null' => true])
                ->addColumn('created_by',  'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['file_id'], ['name' => 'idx_file'])
                ->create();
        }
    }
}
