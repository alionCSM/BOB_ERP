<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tracking ordini for non-consorziate aziende.
 *
 * Different from bb_ordini (consorziate) in that:
 * - One order = one azienda + one calendar month (anno/mese), not per-cantiere
 * - Total is decided manually by the operator at creation time
 * - Descrizione is auto-prefilled with the cantieri list pulled from
 *   bb_presenze for (azienda, anno, mese), but stored as plain text and
 *   editable
 */
final class CreateOrdiniAziende extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_ordini_aziende')) {
            return;
        }

        $this->table('bb_ordini_aziende', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('azienda_id',   'integer',  ['null' => false, 'signed' => true])
            ->addColumn('anno',         'smallinteger', ['null' => false, 'signed' => false])
            ->addColumn('mese',         'tinyinteger',  ['null' => false, 'signed' => false])
            ->addColumn('order_number', 'string',   ['limit' => 50, 'null' => false])
            ->addColumn('order_date',   'date',     ['null' => false])
            ->addColumn('total',        'decimal',  ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('descrizione',  'text',     ['null' => true])
            ->addColumn('note',         'text',     ['null' => true])
            ->addColumn('created_at',   'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('created_by',   'integer',  ['null' => true, 'signed' => true])
            ->addIndex(['order_number'], ['unique' => true, 'name' => 'uk_order_number'])
            ->addIndex(['azienda_id', 'anno', 'mese'], ['name' => 'idx_azienda_period'])
            ->addIndex(['order_date'], ['name' => 'idx_order_date'])
            ->create();
    }
}
