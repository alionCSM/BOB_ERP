<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Link each payment in bb_pagamenti_consorziate to the bb_ordini row it
 * settles. Nullable so existing rows (and future "miscellaneous" payments)
 * stay valid.
 *
 * No FK is created: the production DB user does not hold the REFERENCES
 * privilege. The application layer enforces the relation and ON DELETE
 * semantics through the repository.
 *
 * Idempotent — safe to re-run if a previous attempt partially applied.
 */
final class PagamentiConsorziateOrdineId extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_pagamenti_consorziate');

        if (!$table->hasColumn('ordine_id')) {
            $table->addColumn('ordine_id', 'integer', [
                'null'    => true,
                'default' => null,
                'after'   => 'worksite_id',
                'signed'  => true,
            ]);
        }

        if (!$table->hasIndexByName('idx_ordine_id')) {
            $table->addIndex(['ordine_id'], ['name' => 'idx_ordine_id']);
        }

        $table->update();
    }
}
