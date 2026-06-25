<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Annotazioni sui disegni in BOB Zone (pin→task, misurazioni, markup).
 *
 * Coordinate: normalizzate sulla LARGHEZZA della pagina (sia x che y sono
 * frazioni della width). Cosi' l'aspect ratio e' preservato e la matematica
 * delle distanze e' corretta in ogni direzione con un singolo fattore di scala.
 *   x in [0,1], y in [0, height/width].
 *
 * geom (JSON) per tipo:
 *   pin          {"x":..,"y":..}
 *   measurement  {"a":{x,y},"b":{x,y},"m":metri}
 *   arrow        {"a":{x,y},"b":{x,y}}
 *   rectangle    {"x":,"y":,"w":,"h":}
 *   ellipse      {"x":,"y":,"w":,"h":}   (bounding box)
 *   cloud        {"x":,"y":,"w":,"h":}
 *   text         {"x":,"y":}             (testo nel campo `text`)
 *   drawing      {"pts":[[x,y],...]}     (freehand)
 *
 * Le annotazioni sono BOB-native (funzionano per ogni cantiere). fw_id e'
 * predisposto per la futura sync verso i markup Fieldwire.
 */
final class ZoneAnnotations extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bb_zone_annotations')) {
            $this->table('bb_zone_annotations', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('document_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('page',        'integer',  ['null' => false, 'default' => 1])
                ->addColumn('type',        'string',   ['limit' => 32, 'null' => false])
                ->addColumn('geom',        'text',     ['null' => false])  // JSON
                ->addColumn('task_id',     'integer',  ['null' => true, 'signed' => false])
                ->addColumn('text',        'text',     ['null' => true])
                ->addColumn('color',       'string',   ['limit' => 16, 'null' => true, 'default' => '#ef4444'])
                ->addColumn('fw_id',       'string',   ['limit' => 64, 'null' => true])
                ->addColumn('created_by',  'integer',  ['null' => true, 'signed' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['document_id', 'page'], ['name' => 'idx_doc_page'])
                ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
                ->addIndex(['task_id'], ['name' => 'idx_task'])
                ->create();
        }

        // Calibrazione scala per (documento, pagina): metri per unita' di
        // larghezza-frazione. Usata per convertire le misure in metri.
        if (!$this->hasTable('bb_zone_doc_calibration')) {
            $this->table('bb_zone_doc_calibration', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('document_id',   'integer', ['null' => false, 'signed' => false])
                ->addColumn('page',          'integer', ['null' => false, 'default' => 1])
                ->addColumn('m_per_wfrac',   'decimal', ['precision' => 14, 'scale' => 6, 'null' => false])
                ->addColumn('created_by',    'integer', ['null' => true, 'signed' => false])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['document_id', 'page'], ['unique' => true, 'name' => 'uq_doc_page'])
                ->create();
        }
    }
}
