<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Backfill iva_id / original_iva_id on bb_billing_draft_lines from the
 * source bb_billing row, for any draft lines that pre-date the iva_id
 * column (snapshot didn't populate it).
 *
 * Separate from 20260514000003 so it runs even on environments where
 * that migration was already applied before we added the inline backfill.
 *
 * Defensive only — the getLinesForDraft query already COALESCEs the
 * value at read time, so this is just to persist a cleaner state.
 */
final class BackfillBillingDraftIvaId extends AbstractMigration
{
    public function change(): void
    {
        $this->execute("
            UPDATE bb_billing_draft_lines l
            JOIN bb_billing b ON b.id = l.bb_billing_id
            SET
                l.iva_id          = COALESCE(l.iva_id,          b.iva_id),
                l.original_iva_id = COALESCE(l.original_iva_id, b.iva_id)
            WHERE l.iva_id IS NULL OR l.original_iva_id IS NULL
        ");
    }
}
