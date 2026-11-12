<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Richieste nei Documenti della Zone.
 *
 * Una richiesta sta in una cartella ed e' una piccola discussione: un
 * oggetto, dei messaggi, degli allegati. Chi la vede e' chi vede la
 * cartella. Gli allegati sono documenti veri della cartella (bb_zone_files),
 * cosi' restano li' anche quando la richiesta e' completata.
 *
 * Toglie anche le tabelle della prima versione (stato di compilazione sul
 * singolo file), se erano state create.
 */
final class ZoneRichieste extends AbstractMigration
{
    public function up(): void
    {
        foreach (['bb_zone_file_versioni', 'bb_zone_file_compilazioni'] as $vecchia) {
            if ($this->hasTable($vecchia)) {
                $this->table($vecchia)->drop()->save();
            }
        }
        if (!$this->hasTable('bb_zone_richieste')) {
            $this->table('bb_zone_richieste', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',   'integer',  ['signed' => false])
                ->addColumn('folder_id',     'integer',  ['signed' => false])
                ->addColumn('titolo',        'string',   ['limit' => 200])
                ->addColumn('stato',         'string',   ['limit' => 16, 'default' => 'aperta'])   // aperta | completata
                ->addColumn('created_by',    'integer',  ['signed' => false, 'null' => true])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('completata_da', 'integer',  ['signed' => false, 'null' => true])
                ->addColumn('completata_at', 'datetime', ['null' => true])
                ->addColumn('updated_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id', 'folder_id'], ['name' => 'idx_cartella'])
                ->create();
        }
        if (!$this->hasTable('bb_zone_richieste_messaggi')) {
            $this->table('bb_zone_richieste_messaggi', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('richiesta_id', 'integer',  ['signed' => false])
                ->addColumn('user_id',      'integer',  ['signed' => false, 'null' => true])
                ->addColumn('testo',        'text',     ['null' => true])
                ->addColumn('file_ids',     'string',   ['limit' => 500, 'null' => true])   // documenti allegati, separati da virgola
                ->addColumn('sistema',      'string',   ['limit' => 32, 'null' => true])    // completata | riaperta
                ->addColumn('created_at',   'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['richiesta_id'], ['name' => 'idx_richiesta'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['bb_zone_richieste_messaggi', 'bb_zone_richieste'] as $t) {
            if ($this->hasTable($t)) {
                $this->table($t)->drop()->save();
            }
        }
    }
}
