<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Campi necessari all'import dal vecchio database noleggi.
 *
 * origine_id conserva l'id della riga di provenienza: serve a poter
 * rilanciare l'import senza creare doppioni, e a ritrovare da dove viene
 * un dato quando qualcosa non torna.
 *
 * commerciale_testo tiene il nome scritto a mano nel vecchio sistema, dove
 * il commerciale non era un utente. Non si chiama commerciale_nome perche'
 * quello e' gia' l'alias con cui la query restituisce il nome dell'utente
 * collegato: due cose con lo stesso nome si confonderebbero.
 */
final class ImportOrigine extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pn_autocarrate')) {
            $t = $this->table('pn_autocarrate');
            if (!$t->hasColumn('origine_id')) {
                $t->addColumn('origine_id', 'integer', ['null' => true, 'signed' => false,
                      'comment' => 'id nel vecchio database noleggi'])
                  ->addIndex(['group_company_id', 'origine_id'], ['name' => 'idx_origine'])
                  ->update();
            }
        }

        if ($this->hasTable('pn_prenotazioni')) {
            $t = $this->table('pn_prenotazioni');
            if (!$t->hasColumn('origine_id')) {
                $t->addColumn('origine_id', 'integer', ['null' => true, 'signed' => false,
                      'comment' => 'id nel vecchio database noleggi']);
            }
            if (!$t->hasColumn('commerciale_testo')) {
                $t->addColumn('commerciale_testo', 'string', ['limit' => 120, 'null' => true,
                      'comment' => 'Nome del commerciale nei dati importati, quando non e' . "'" . ' un utente']);
            }
            $t->addIndex(['group_company_id', 'origine_id'], ['name' => 'idx_origine'])->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pn_prenotazioni')) {
            $t = $this->table('pn_prenotazioni');
            foreach (['origine_id', 'commerciale_testo'] as $c) {
                if ($t->hasColumn($c)) { $t->removeColumn($c); }
            }
            $t->update();
        }
        if ($this->hasTable('pn_autocarrate')) {
            $t = $this->table('pn_autocarrate');
            if ($t->hasColumn('origine_id')) { $t->removeColumn('origine_id')->update(); }
        }
    }
}
