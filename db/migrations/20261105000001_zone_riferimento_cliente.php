<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Come chiama il cliente un nostro cantiere.
 *
 * Il committente ha i suoi nomi e i suoi codici commessa: "Hub Nord
 * fase 2", "CO-2026-114". Nella Zone li scrive lui, e da li' in poi li
 * vede accanto ai nostri, nell'elenco, nella Zone e nel rapporto. Anche
 * l'ufficio li vede: quando il cliente chiama col suo codice, si sa di
 * che cantiere parla.
 *
 * Uno per cliente (azienda), non per persona: due geometri dello stesso
 * committente chiamano il cantiere nello stesso modo.
 */
final class ZoneRiferimentoCliente extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_riferimenti_cliente')) {
            return;
        }
        $this->table('bb_zone_riferimenti_cliente', ['id' => false, 'primary_key' => ['worksite_id', 'client_id']])
            ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('client_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('nome', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('codice', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('updated_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_riferimenti_cliente')) {
            $this->table('bb_zone_riferimenti_cliente')->drop()->save();
        }
    }
}
