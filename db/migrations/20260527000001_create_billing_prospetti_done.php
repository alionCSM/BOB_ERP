<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tracks when the operator has "marked as done" the monthly prospetto
 * fatturazione for a given client. One row per (client, year, month).
 *
 * Used by /billing/clients to render either:
 *   - "Segna prospetto fatto" button (no row for current period)
 *   - "Prospetto fatto: <Mese Anno>" chip (row exists)
 *
 * The window in which the action is offered (current month + 10 days of
 * grace into the next month) is enforced controller-side, not in DB.
 */
final class CreateBillingProspettiDone extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_billing_prospetti_done')) {
            return;
        }

        $this->table('bb_billing_prospetti_done', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('client_id', 'integer',       ['null' => false, 'signed' => true])
            ->addColumn('year',      'smallinteger',  ['null' => false, 'signed' => false])
            ->addColumn('month',     'tinyinteger',   ['null' => false, 'signed' => false])
            ->addColumn('done_at',   'datetime',      ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('done_by',   'integer',       ['null' => true, 'signed' => true])
            ->addColumn('note',      'string',        ['null' => true, 'limit' => 255])
            ->addIndex(['client_id', 'year', 'month'], ['unique' => true, 'name' => 'uk_client_period'])
            ->addIndex(['year', 'month'], ['name' => 'idx_period'])
            ->create();
    }
}
