<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ferie e permessi degli operai.
 *
 * Registrati dall'ufficio (pagina Presenze → Ferie/Permessi) e mostrati
 * nel tab Ferie/Permessi del profilo operaio.
 *
 * - tipo: 'ferie' | 'permesso' (varchar per estensioni future, es. malattia)
 * - periodo: data_inizio..data_fine (per il permesso di un giorno le due
 *   date coincidono); ore valorizzato per i permessi a ore.
 */
final class FeriePermessi extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_ferie_permessi')) {
            return;
        }

        $this->table('bb_ferie_permessi', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('worker_id',   'integer', ['null' => false, 'signed' => false])
            ->addColumn('tipo',        'string',  ['limit' => 20, 'null' => false, 'default' => 'ferie'])
            ->addColumn('data_inizio', 'date',    ['null' => false])
            ->addColumn('data_fine',   'date',    ['null' => false])
            ->addColumn('ore',         'decimal', ['precision' => 4, 'scale' => 1, 'null' => true,
                                                   'comment' => 'Solo permessi a ore; NULL = giornata intera'])
            ->addColumn('note',        'text',    ['null' => true])
            ->addColumn('created_by',  'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['worker_id'],   ['name' => 'idx_fp_worker'])
            ->addIndex(['data_inizio'], ['name' => 'idx_fp_inizio'])
            ->create();
    }
}
