<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Extend bb_worksite_finance_notes with pin + edit tracking.
 *
 * - is_pinned : flag to keep an important note at the top of the list
 * - updated_by: who last edited the note (NULL = original author / never edited)
 *
 * created_by stays implicit on user_id (set at insert time and never changed).
 */
final class WorksiteFinanceNotesPinEdit extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_worksite_finance_notes');
        if (!$table->hasColumn('is_pinned')) {
            $table
                ->addColumn('is_pinned',  'boolean', ['default' => false, 'null' => false, 'after' => 'content'])
                ->addColumn('updated_by', 'integer', ['null' => true, 'signed' => true, 'after' => 'is_pinned'])
                ->update();
        }
    }
}
