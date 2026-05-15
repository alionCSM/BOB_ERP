<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Finance notes get a real workflow:
 *
 *   tipo       = 'fatturazione' | 'sconto' | 'generica'
 *                (default 'generica' on legacy rows)
 *   status     = 'aperta' | 'applicata'  (default 'aperta')
 *   applied_by = chi ha marcato la nota come applicata (any canSeePrices user)
 *   applied_at = quando l'ha marcata
 *
 * is_pinned (added in 20260515000004) is left in place but no longer used
 * in the UI — open notes float to the top of the list via status DESC.
 */
final class FinanceNotesTipoStatus extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_worksite_finance_notes');
        if (!$table->hasColumn('tipo')) {
            $table
                ->addColumn('tipo', 'enum', [
                    'values'  => ['fatturazione', 'sconto', 'generica'],
                    'default' => 'generica',
                    'null'    => false,
                    'after'   => 'content',
                ])
                ->addColumn('status', 'enum', [
                    'values'  => ['aperta', 'applicata'],
                    'default' => 'aperta',
                    'null'    => false,
                    'after'   => 'tipo',
                ])
                ->addColumn('applied_by', 'integer', ['null' => true, 'signed' => true, 'after' => 'status'])
                ->addColumn('applied_at', 'datetime', ['null' => true, 'after' => 'applied_by'])
                ->update();
        }
    }
}
