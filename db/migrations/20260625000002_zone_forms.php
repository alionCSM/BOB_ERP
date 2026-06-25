<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moduli / Form builder di BOB Zone.
 *
 * Template: definizione dei campi (JSON). worksite_id NULL = template
 * universale (riusabile su ogni cantiere); valorizzato = template del
 * singolo cantiere.
 *
 * Submission: una compilazione. I valori sono JSON. Firma e foto vengono
 * salvate come file e nei valori resta l'URL.
 */
final class ZoneForms extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bb_zone_form_templates')) {
            $this->table('bb_zone_form_templates', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id', 'integer',  ['null' => true, 'signed' => false]) // null = universale
                ->addColumn('name',        'string',   ['limit' => 200, 'null' => false])
                ->addColumn('description', 'text',     ['null' => true])
                ->addColumn('fields',      'text',     ['null' => false])  // JSON schema campi
                ->addColumn('active',      'boolean',  ['default' => true])
                ->addColumn('created_by',  'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_form_submissions')) {
            $this->table('bb_zone_form_submissions', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('template_id',    'integer',  ['null' => false, 'signed' => false])
                ->addColumn('worksite_id',    'integer',  ['null' => false, 'signed' => false])
                ->addColumn('template_name',  'string',   ['limit' => 200, 'null' => true]) // snapshot
                ->addColumn('values',         'text',     ['null' => false]) // JSON valori
                ->addColumn('submitter_name', 'string',   ['limit' => 160, 'null' => true])
                ->addColumn('submitted_by',   'integer',  ['null' => true, 'signed' => false])
                ->addColumn('source',         'enum',     ['values' => ['internal','link'], 'default' => 'internal'])
                ->addColumn('created_at',      'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['template_id'], ['name' => 'idx_template'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->create();
        }
    }
}
