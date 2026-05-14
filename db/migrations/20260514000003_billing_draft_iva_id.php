<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Track the IVA code (FK to bb_billing_vat_codes) per draft line, so the
 * inline editor can offer the same dropdown the cantiere billing form uses.
 *
 * aliquota_iva (the % stored alongside on bb_billing) is derived from
 * bb_billing_vat_codes.aliquota when the user changes iva_id — keeping
 * the two fields in sync.
 */
final class BillingDraftIvaId extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_billing_draft_lines');
        if (!$table->hasColumn('iva_id')) {
            $table
                ->addColumn('iva_id', 'integer', [
                    'null'    => true,
                    'signed'  => true,
                    'after'   => 'aliquota_iva',
                ])
                ->addColumn('original_iva_id', 'integer', [
                    'null'    => true,
                    'signed'  => true,
                    'after'   => 'original_aliquota_iva',
                ])
                ->update();
        }
    }
}
