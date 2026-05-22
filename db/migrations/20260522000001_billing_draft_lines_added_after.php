<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add `added_after_create` flag on bb_billing_draft_lines.
 *
 * When the bozza editor is opened, the service snapshots any new
 * bb_billing rows that have appeared on the client's cantieri after
 * the bozza was created. Those snapshot rows get added_after_create=1
 * so the UI can paint them blue. Rows snapshotted at bozza creation
 * keep the default 0.
 */
final class BillingDraftLinesAddedAfter extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_billing_draft_lines');
        if (!$table->hasColumn('added_after_create')) {
            $table
                ->addColumn('added_after_create', 'boolean', [
                    'default' => false,
                    'null'    => false,
                    'after'   => 'modified',
                ])
                ->update();
        }
    }
}
