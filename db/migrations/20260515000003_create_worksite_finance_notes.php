<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Notes tied to a cantiere, visible only to users with fatturazione
 * (canSeePrices) access. Used to record commercial/financial reminders
 * that shouldn't be exposed to workers or clients.
 *
 * Separate from bb_worksite_tasks (operational tasks with status flow)
 * and bb_task_comments (per-task comments) — those are general team
 * todos, while these are finance-only freeform notes.
 */
final class CreateWorksiteFinanceNotes extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_worksite_finance_notes')) {
            return;
        }

        $this->table('bb_worksite_finance_notes', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('worksite_id', 'integer',  ['null' => false, 'signed' => true])
            ->addColumn('user_id',     'integer',  ['null' => true,  'signed' => true])
            ->addColumn('content',     'text',     ['null' => false])
            ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at',  'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['worksite_id'], ['name' => 'idx_worksite'])
            ->addIndex(['worksite_id', 'created_at'], ['name' => 'idx_worksite_created'])
            ->create();
    }
}
