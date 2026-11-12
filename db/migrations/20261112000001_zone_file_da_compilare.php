<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * PDF da compilare nei Documenti della Zone.
 *
 * Chi carica un PDF (di solito il committente) chiede che venga compilato;
 * chi lo compila lo fa nel browser e salva. L'originale resta com'e': la
 * copia compilata e' una versione del file, e se ne possono avere piu' d'una.
 *
 * - bb_zone_file_compilazioni: le richieste (chi, a chi, nota, stato).
 * - bb_zone_file_versioni:     le copie compilate di un file.
 */
final class ZoneFileDaCompilare extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_file_compilazioni')) {
            $this->table('bb_zone_file_compilazioni', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',  'integer',  ['signed' => false])
                ->addColumn('file_id',      'integer',  ['signed' => false])
                ->addColumn('richiesto_da', 'integer',  ['signed' => false, 'null' => true])
                ->addColumn('per_ruolo',    'string',   ['limit' => 16])            // ufficio | cliente | cantiere
                ->addColumn('nota',         'text',     ['null' => true])
                ->addColumn('stato',        'string',   ['limit' => 16, 'default' => 'da_compilare']) // da_compilare | compilato | annullata
                ->addColumn('compilato_da', 'integer',  ['signed' => false, 'null' => true])
                ->addColumn('compilato_at', 'datetime', ['null' => true])
                ->addColumn('versione_id',  'integer',  ['signed' => false, 'null' => true])
                ->addColumn('created_at',   'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id', 'stato'], ['name' => 'idx_cantiere_stato'])
                ->addIndex(['file_id'], ['name' => 'idx_file'])
                ->create();
        }
        if (!$this->hasTable('bb_zone_file_versioni')) {
            $this->table('bb_zone_file_versioni', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('worksite_id',     'integer',  ['signed' => false])
                ->addColumn('file_id',         'integer',  ['signed' => false])
                ->addColumn('file_path',       'string',   ['limit' => 500])
                ->addColumn('size_bytes',      'integer',  ['signed' => false, 'null' => true])
                ->addColumn('compilazione_id', 'integer',  ['signed' => false, 'null' => true])
                ->addColumn('creato_da',       'integer',  ['signed' => false, 'null' => true])
                ->addColumn('created_at',      'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['file_id'], ['name' => 'idx_file'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['bb_zone_file_versioni', 'bb_zone_file_compilazioni'] as $t) {
            if ($this->hasTable($t)) {
                $this->table($t)->drop()->save();
            }
        }
    }
}
