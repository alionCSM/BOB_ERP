<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Connect extras to fatturazione.
 *
 *  - bb_billing.extra_id : nullable FK-style int. When a billing row was
 *    created from "Fattura" on an extra, this points back to bb_extra.id.
 *
 *  - bb_extra.tracks_billing : boolean flag, default 1 for new extras.
 *    The cantiere page shows an "avviso da fatturare" only for extras
 *    with tracks_billing=1 AND no linked bb_billing row. Existing extras
 *    are backfilled to 0 so they don't suddenly trigger warnings.
 */
final class ExtrasToBillingLink extends AbstractMigration
{
    public function change(): void
    {
        $billing = $this->table('bb_billing');
        if (!$billing->hasColumn('extra_id')) {
            $billing
                ->addColumn('extra_id', 'integer', [
                    'null'   => true,
                    'signed' => true,
                ])
                ->addIndex(['extra_id'], ['name' => 'idx_billing_extra_id'])
                ->update();
        }

        $extra = $this->table('bb_extra');
        $needsBackfill = !$extra->hasColumn('tracks_billing');
        if ($needsBackfill) {
            $extra
                ->addColumn('tracks_billing', 'boolean', [
                    'default' => true,
                    'null'    => false,
                ])
                ->update();

            // Grandfather all existing extras — they were created before this
            // feature, so we don't want them to trigger "da fatturare" alerts.
            $this->execute('UPDATE bb_extra SET tracks_billing = 0');
        }
    }
}
