<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * API tokens for the BOB Android app.
 *
 * Un token API punta a un utente ESISTENTE di bb_users (user_id): non crea
 * utenti nuovi e non introduce permessi nuovi — i moduli concessi restano
 * quelli di bb_user_permissions, letti allo stesso modo del web.
 *
 * Il token grezzo (64 hex) va solo al client: in tabella si conserva il
 * sha256, cosi' un dump del database non espone token utilizzabili.
 *
 * revoked_at (invece di DELETE) permette di capire quando e da quando un
 * token e' stato revocato; expires da scadenza temporale (90 giorni).
 */
final class ApiTokens extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_api_tokens')) {
            return;
        }

        $this->table('bb_api_tokens', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false,
                                                'comment' => 'bb_users.id del titolare del token'])
            ->addColumn('group_company_id', 'integer', ['null' => true, 'signed' => false,
                'comment' => "Societa' del gruppo attiva su questo token. Il client mobile non "
                           . "tiene la sessione PHP: la scelta e' stateless e vive qui, "
                           . 'riletta dal middleware a ogni richiesta. NULL = da scegliere '
                           . '(o fallback Consorzio storico).'])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false,
                                                 'comment' => 'sha256 del token grezzo'])
            ->addColumn('device_name', 'string', ['limit' => 120, 'null' => true,
                                                  'comment' => 'Nome dispositivo scelto dall utente'])
            ->addColumn('device_info', 'string', ['limit' => 255, 'null' => true,
                                                  'comment' => 'User-Agent / modello + versione app'])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => false,
                                                   'comment' => 'Scadenza assoluta (default 90 giorni)'])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true,
                                                   'comment' => 'Valorizzata = token revocato'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['user_id'], ['name' => 'idx_api_tokens_user'])
            ->addIndex(['group_company_id'], ['name' => 'idx_api_tokens_company'])
            ->addIndex(['token_hash'], ['name' => 'uq_api_tokens_hash', 'unique' => true])
            ->addIndex(['expires_at'], ['name' => 'idx_api_tokens_expires'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_api_tokens')) {
            $this->table('bb_api_tokens')->drop()->update();
        }
    }
}
