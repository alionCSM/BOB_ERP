<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Aggiunge gli indici su fw_id per bb_zone_task_comments e bb_zone_task_checklist.
 *
 * Servono al WebhookHandler per risolvere velocemente "questo bubble/check_item
 * Fieldwire esiste gia' in BOB?" via findCommentByFwId / findChecklistItemByFwId.
 * Senza indice ogni webhook fa un table scan.
 *
 * NB: niente UNIQUE perche' per i task BOB-native nati senza Fieldwire fw_id
 * resta NULL e potrebbero essercene molti.
 */
final class ZoneSubitemsFwIndex extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_zone_task_comments')) {
            $t = $this->table('bb_zone_task_comments');
            if (!$t->hasIndexByName('idx_fw_id')) {
                $t->addIndex(['fw_id'], ['name' => 'idx_fw_id'])->update();
            }
        }

        if ($this->hasTable('bb_zone_task_checklist')) {
            $t = $this->table('bb_zone_task_checklist');
            if (!$t->hasIndexByName('idx_fw_id')) {
                $t->addIndex(['fw_id'], ['name' => 'idx_fw_id'])->update();
            }
        }
    }
}
