<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Condividere con una parte del cantiere, non solo con delle persone.
 *
 * La Zone e' lo spazio che dividiamo con il cliente e con la squadra: le
 * condivisioni per nome non bastavano. Chi entrava in un cantiere dopo non
 * vedeva niente di quello che c'era gia', perche' le righe erano state
 * scritte per chi c'era quel giorno. Una riga qui dice "lo vede tutta la
 * squadra" o "lo vede il cliente", chiunque ci sia adesso o arrivi dopo.
 *
 * worksite_id c'e' per leggere in una volta tutto quello che una parte
 * vede in un cantiere, senza passare dalle tabelle degli oggetti.
 */
final class ZoneCondivisioniRuoli extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_condivisioni_ruoli')) {
            return;
        }
        // tutte NOT NULL: una colonna annullabile in una primaria fa fallire
        // la CREATE in MySQL
        $this->table('bb_zone_condivisioni_ruoli', ['id' => false, 'primary_key' => ['tipo', 'oggetto_id', 'ruolo']])
            ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('tipo', 'enum', [
                'values' => ['attivita', 'cartella', 'file', 'disegno', 'modulo', 'risposta'],
                'null'   => false,
            ])
            ->addColumn('oggetto_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('ruolo', 'enum', ['values' => ['cantiere', 'cliente'], 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['worksite_id', 'ruolo'], ['name' => 'idx_zcondr_parte'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_condivisioni_ruoli')) {
            $this->table('bb_zone_condivisioni_ruoli')->drop()->save();
        }
    }
}
