<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * BOB AI come permesso.
 *
 * L'accesso alla chat BOB AI (/ai/chat) passava da una allowlist fissa nel
 * codice (AiSqlController::USERS_WITH_AI_ACCESS + menu). Ora e' il modulo
 * 'ai_chat' in bb_user_permissions, assegnabile da Utenti → Permessi.
 *
 * Questa migration concede il permesso agli utenti che erano gia' nella
 * allowlist, cosi' nessuno perde l'accesso al deploy.
 */
final class AiChatPermission extends AbstractMigration
{
    private const LEGACY_USERS = ['alion', 'laura', 'osman', 'elena', 'ermal'];

    public function up(): void
    {
        $quoted = implode(',', array_map(fn($u) => "'{$u}'", self::LEGACY_USERS));

        // INSERT ... SELECT: solo per utenti esistenti, senza duplicare
        // eventuali righe ai_chat gia' presenti.
        $this->execute("
            INSERT INTO bb_user_permissions (user_id, module, allowed)
            SELECT u.id, 'ai_chat', 1
            FROM bb_users u
            WHERE u.username IN ({$quoted})
              AND NOT EXISTS (
                  SELECT 1 FROM bb_user_permissions p
                  WHERE p.user_id = u.id AND p.module = 'ai_chat'
              )
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM bb_user_permissions WHERE module = 'ai_chat'");
    }
}
