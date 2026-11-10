<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Presenze scritte a mano da CSM nella Zone.
 *
 * Per ora le presenze del cantiere si scrivono a mano: CSM registra chi
 * c'era (una persona, o un'azienda con quante persone ha mandato). Restano
 * fuori dal registro presenze di BOB — quello dei conti, con pasti, albergo
 * e approvazioni — e nella Zone si leggono insieme a quelle del registro.
 */
final class ZonePresenzeManuali extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_presenze_manuali')) {
            return;
        }
        $this->table('bb_zone_presenze_manuali')
            ->addColumn('worksite_id', 'integer')
            ->addColumn('data', 'date')
            ->addColumn('nome', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('azienda', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('persone', 'integer', ['default' => 1])
            ->addColumn('mezza', 'boolean', ['default' => false])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['worksite_id', 'data'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_presenze_manuali')) {
            $this->table('bb_zone_presenze_manuali')->drop()->save();
        }
    }
}
