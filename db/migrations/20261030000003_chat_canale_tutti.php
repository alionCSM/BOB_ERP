<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * I canali della chat diventano tre, e sono tre cose che si capiscono dal
 * nome: Squadra, Cliente, Tutti.
 *
 * Spariva il canale "Capi", e il motivo e' che non esisteva la differenza
 * che pretendeva di fare: nella Zone non ci entrano tutti gli operai di un
 * cantiere, ci entra chi gli diamo l'accesso — e quello, in pratica, e' il
 * capo squadra. Il canale "Squadra" e quello "Capi" avevano dentro le
 * stesse persone, e due chat con le stesse persone sono una chat sola in
 * cui nessuno sa dove scrivere.
 *
 * E cambia il canale "Cliente": prima dentro c'erano anche i capi, adesso
 * sono l'ufficio e il committente e basta. Per parlare tutti insieme c'e'
 * "Tutti", che prima mancava.
 *
 * I messaggi scritti in "Capi" non si buttano: passano in "Squadra", che e'
 * dove sarebbero finiti oggi.
 */
final class ChatCanaleTutti extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_messaggi')) {
            return;
        }

        // Prima si allarga l'elenco, se no l'UPDATE non avrebbe dove
        // scrivere; e 'capi' resta dentro finche' non si e' svuotato.
        $this->table('bb_zone_messaggi')
            ->changeColumn('canale', 'enum', [
                'values' => ['squadra', 'capi', 'cliente', 'tutti'],
                'null'   => true,
            ])
            ->update();

        $this->execute("UPDATE bb_zone_messaggi SET canale = 'squadra' WHERE canale = 'capi'");

        // Le righe "letto fino a" puntano al canale per nome.
        if ($this->hasTable('bb_zone_letture')) {
            // Chi aveva letto i capi fino a un certo punto: la riga diventa
            // quella della squadra solo se li' non ce n'e' gia' una, se no
            // si contraddirebbero e la piu' vecchia farebbe ricomparire
            // come "da leggere" roba gia' letta.
            $this->execute("
                DELETE l FROM bb_zone_letture l
                WHERE l.chiave = 'capi'
                  AND EXISTS (
                        SELECT 1 FROM (SELECT * FROM bb_zone_letture) x
                        WHERE x.user_id = l.user_id
                          AND x.worksite_id = l.worksite_id
                          AND x.chiave = 'squadra'
                  )
            ");
            $this->execute("UPDATE bb_zone_letture SET chiave = 'squadra' WHERE chiave = 'capi'");
        }

        $this->table('bb_zone_messaggi')
            ->changeColumn('canale', 'enum', [
                'values' => ['squadra', 'cliente', 'tutti'],
                'null'   => true,
            ])
            ->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('bb_zone_messaggi')) {
            return;
        }
        // Si torna all'elenco di prima. I messaggi restano dove sono: in
        // "Squadra", che e' dove li avrebbe messi anche la versione vecchia.
        $this->execute("UPDATE bb_zone_messaggi SET canale = 'squadra' WHERE canale = 'tutti'");
        $this->table('bb_zone_messaggi')
            ->changeColumn('canale', 'enum', [
                'values' => ['squadra', 'capi', 'cliente'],
                'null'   => true,
            ])
            ->update();
    }
}
