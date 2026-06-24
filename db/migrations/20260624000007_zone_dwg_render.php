<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Render vettoriale dei DWG: ogni disegno DWG viene convertito in SVG
 * (DWG→DXF→SVG via scripts/dwg/dwg_to_svg.py) per essere visualizzato e
 * misurato nel browser.
 *
 * extents + meters_per_unit consentono misure ESATTE: il viewBox dell'SVG
 * corrisponde all'estensione reale del disegno in unita' CAD.
 */
final class ZoneDwgRender extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_zone_dwg_render')) {
            return;
        }
        $this->table('bb_zone_dwg_render', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('document_id',     'integer',  ['null' => false, 'signed' => false])
            ->addColumn('svg_path',        'string',   ['limit' => 500, 'null' => true])
            ->addColumn('minx',            'decimal',  ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('miny',            'decimal',  ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('maxx',            'decimal',  ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('maxy',            'decimal',  ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('insunits',        'integer',  ['null' => true])
            ->addColumn('meters_per_unit', 'decimal',  ['precision' => 18, 'scale' => 9, 'null' => true])
            ->addColumn('status',          'enum',     ['values' => ['pending','ok','error'], 'default' => 'pending'])
            ->addColumn('error',           'text',     ['null' => true])
            ->addColumn('created_at',      'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at',      'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['document_id'], ['unique' => true, 'name' => 'uq_document'])
            ->create();
    }
}
