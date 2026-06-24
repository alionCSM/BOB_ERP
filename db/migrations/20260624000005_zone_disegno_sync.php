<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Traccia quali disegni BOB (bb_worksite_documents categoria Disegni) sono
 * stati spinti su Fieldwire come sheet_upload.
 *
 * Non leghiamo direttamente al floorplan Fieldwire perche' la creazione del
 * floorplan e' asincrona (avviene dopo il confirm dell'upload, notificata via
 * webhook floorplan.created). Salviamo lo sheet_upload_id + timestamp per
 * mostrare il badge "sincronizzato" sul disegno in BOB Zone.
 */
final class ZoneDisegnoSync extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_zone_disegno_sync')) {
            return;
        }
        $this->table('bb_zone_disegno_sync', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('document_id',         'integer',  ['null' => false, 'signed' => false])
            ->addColumn('worksite_id',         'integer',  ['null' => false, 'signed' => false])
            ->addColumn('fw_sheet_upload_id',  'string',   ['limit' => 64, 'null' => true])
            ->addColumn('fw_floorplan_id',     'string',   ['limit' => 64, 'null' => true])
            ->addColumn('pushed_at',           'datetime', ['null' => true])
            ->addColumn('pushed_by',           'integer',  ['null' => true, 'signed' => false])
            ->addColumn('created_at',          'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['document_id'], ['unique' => true, 'name' => 'uq_document'])
            ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
            ->create();
    }
}
