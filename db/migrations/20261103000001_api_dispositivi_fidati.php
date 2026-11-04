<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * I telefoni fidati dell'app.
 *
 * Finora l'app si fidava di un indirizzo IP, come il sito: il codice via
 * email arrivava a ogni login da un IP nuovo. Su un telefono l'IP cambia di
 * continuo (4G, Wi-Fi del cantiere, dell'albergo), quindi il codice arrivava
 * quasi sempre.
 *
 * Adesso ci si fida del telefono: dopo il primo codice giusto il telefono
 * riceve un segreto lungo, lo tiene nel portachiavi (Keychain / Keystore) e
 * lo manda ai login successivi. Qui sta solo l'impronta (sha256), mai il
 * segreto. Si revoca da solo cambiando la password, e scade se il telefono
 * non entra da sei mesi.
 */
final class ApiDispositiviFidati extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_api_dispositivi')) {
            return;
        }
        $this->table('bb_api_dispositivi')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('segreto_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('nome', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_used_at', 'timestamp', ['null' => true])
            ->addColumn('revoked_at', 'timestamp', ['null' => true])
            ->addIndex(['segreto_hash'], ['unique' => true, 'name' => 'uq_segreto'])
            ->addIndex(['user_id'], ['name' => 'idx_user'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_api_dispositivi')) {
            $this->table('bb_api_dispositivi')->drop()->save();
        }
    }
}
