<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Link each payment in bb_pagamenti_consorziate to the bb_ordini row it
 * settles. Nullable so existing rows (and future "miscellaneous" payments)
 * stay valid; ON DELETE SET NULL keeps the payment record if the order
 * is later removed.
 */
final class PagamentiConsorziateOrdineId extends AbstractMigration
{
    public function change(): void
    {
        $this->table('bb_pagamenti_consorziate')
            ->addColumn('ordine_id', 'integer', [
                'null'    => true,
                'default' => null,
                'after'   => 'worksite_id',
                'signed'  => true,
            ])
            ->addIndex(['ordine_id'], ['name' => 'idx_ordine_id'])
            ->addForeignKey(
                'ordine_id',
                'bb_ordini',
                'id',
                ['delete' => 'SET_NULL', 'update' => 'NO_ACTION', 'constraint' => 'fk_pag_cons_ordine']
            )
            ->update();
    }
}
