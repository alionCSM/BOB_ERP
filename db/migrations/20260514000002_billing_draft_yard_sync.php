<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds Yard sync tracking to bb_billing_draft_lines.
 *
 * When a draft is finalized ("Fattura ora"), each non-excluded line is
 * written back to bb_billing (BOB MySQL) and then to CNT_cantieri_brogliacci
 * (Yard SQL Server). Cross-DB atomicity isn't possible, so we record per-line
 * sync state so we can offer a retry button if Yard fails.
 *
 * Status values:
 *   - NULL or 'pending' → not attempted yet
 *   - 'synced'  → Yard updateBrogliaccio succeeded
 *   - 'failed'  → Yard call threw — see yard_sync_error
 *   - 'na'      → no yard_id on the source bb_billing row, nothing to sync
 */
final class BillingDraftYardSync extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_billing_draft_lines');
        if (!$table->hasColumn('yard_sync_status')) {
            $table
                ->addColumn('yard_sync_status', 'enum', [
                    'values'  => ['pending', 'synced', 'failed', 'na'],
                    'null'    => true,
                    'after'   => 'display_order',
                ])
                ->addColumn('yard_sync_error', 'text', [
                    'null'  => true,
                    'after' => 'yard_sync_status',
                ])
                ->addColumn('yard_sync_attempted_at', 'datetime', [
                    'null'  => true,
                    'after' => 'yard_sync_error',
                ])
                ->update();
        }
    }
}
