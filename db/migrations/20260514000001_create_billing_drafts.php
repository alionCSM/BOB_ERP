<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fatturazione clienti — editable invoice draft.
 *
 * Workflow:
 *   1. User opens fatturazione clienti → clicks "Crea bozza"
 *   2. All emessa=0 rows for that client are snapshotted into bb_billing_draft_lines
 *   3. User edits inline (data/descrizione/imponibile/iva) + can mark rows excluded
 *   4. Excel generated from draft → sent to client
 *   5. State machine: bozza → inviata_cliente → approvata → fatturata
 *   6. On "Fattura ora": draft_line values propagate back to bb_billing
 *      AND to Yard SQL Server (CNT_cantieri_brogliacci). bb_billing.emessa=1.
 *
 * One active draft per client (enforced in service layer — MySQL pre-8 doesn't
 * do conditional unique cleanly). "Active" = status NOT IN (fatturata, annullata).
 */
final class CreateBillingDrafts extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bb_billing_drafts')) {
            $this->table('bb_billing_drafts', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('client_id',          'integer',  ['null' => false, 'signed' => true])
                ->addColumn('period_label',       'string',   ['limit' => 100, 'null' => true])
                ->addColumn('status',             'enum',     [
                    'values' => ['bozza', 'inviata_cliente', 'da_modificare', 'approvata', 'fatturata', 'annullata'],
                    'default' => 'bozza',
                    'null' => false,
                ])
                ->addColumn('invoice_number',     'string',   ['limit' => 50,  'null' => true])
                ->addColumn('invoice_date',       'date',     ['null' => true])
                ->addColumn('excel_path',         'string',   ['limit' => 500, 'null' => true])
                ->addColumn('excel_generated_at', 'datetime', ['null' => true])
                ->addColumn('notes',              'text',     ['null' => true])
                ->addColumn('created_by',         'integer',  ['null' => true, 'signed' => true])
                ->addColumn('created_at',         'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',         'datetime', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update'  => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['client_id', 'status'], ['name' => 'idx_client_status'])
                ->addIndex(['status'],              ['name' => 'idx_status'])
                ->create();
        }

        if (!$this->hasTable('bb_billing_draft_lines')) {
            $this->table('bb_billing_draft_lines', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('draft_id',       'integer', ['null' => false, 'signed' => true])
                ->addColumn('bb_billing_id',  'integer', ['null' => false, 'signed' => true])
                ->addColumn('worksite_id',    'integer', ['null' => false, 'signed' => true])

                // Editable snapshot (what goes on Excel + propagated to bb_billing)
                ->addColumn('data',               'date',    ['null' => true])
                ->addColumn('descrizione',        'text',    ['null' => true])
                ->addColumn('totale_imponibile', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
                ->addColumn('aliquota_iva',      'decimal', ['precision' => 5,  'scale' => 2, 'default' => '0.00'])

                // Frozen at draft creation — for diff display & safe revert
                ->addColumn('original_data',              'date',    ['null' => true])
                ->addColumn('original_descrizione',       'text',    ['null' => true])
                ->addColumn('original_totale_imponibile', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
                ->addColumn('original_aliquota_iva',      'decimal', ['precision' => 5,  'scale' => 2, 'default' => '0.00'])

                ->addColumn('excluded',            'boolean', ['default' => false])
                ->addColumn('excluded_reason',     'string',  ['limit' => 255, 'null' => true])
                ->addColumn('modified',            'boolean', ['default' => false])
                ->addColumn('modification_notes',  'text',    ['null' => true])
                ->addColumn('display_order',       'integer', ['default' => 0])

                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update'  => 'CURRENT_TIMESTAMP',
                ])

                ->addIndex(['draft_id'],          ['name' => 'idx_draft'])
                ->addIndex(['bb_billing_id'],     ['name' => 'idx_bb_billing'])
                ->addIndex(['worksite_id'],       ['name' => 'idx_worksite'])
                ->create();
        }
    }
}
