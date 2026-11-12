<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Un link di "Doc condivisi" collegato al cantiere.
 *
 * - bb_worksites.zone_share_link_id: il link collegato alla Zone del cantiere.
 * - bb_zone_folders.sorgente / sorgente_id: le cartelle create dal link
 *   ("Documenti azienda" con una cartella per azienda, "Documenti operai"
 *   con una cartella per operaio). Sono cartelle vere, cosi' valgono le
 *   regole di sempre (chi le vede, le richieste dentro), ma il loro
 *   contenuto arriva dal link e non si modifica dalla Zone.
 */
final class ZoneLinkDocumenti extends AbstractMigration
{
    public function up(): void
    {
        $w = $this->table('bb_worksites');
        if (!$w->hasColumn('zone_share_link_id')) {
            $w->addColumn('zone_share_link_id', 'integer', ['signed' => false, 'null' => true])->update();
        }
        $c = $this->table('bb_zone_folders');
        if (!$c->hasColumn('sorgente')) {
            $c->addColumn('sorgente', 'string', ['limit' => 20, 'null' => true])      // link_aziende | link_azienda | link_operai | link_operaio
              ->addColumn('sorgente_id', 'integer', ['signed' => false, 'null' => true]) // azienda o operaio
              ->update();
        }
    }

    public function down(): void
    {
        $w = $this->table('bb_worksites');
        if ($w->hasColumn('zone_share_link_id')) {
            $w->removeColumn('zone_share_link_id')->update();
        }
        $c = $this->table('bb_zone_folders');
        if ($c->hasColumn('sorgente')) {
            $c->removeColumn('sorgente')->removeColumn('sorgente_id')->update();
        }
    }
}
