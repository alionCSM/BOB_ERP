<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tre ruoli nella Zone: Ufficio, Cantiere, Cliente.
 *
 * Sparisce la differenza fra capo squadra e operaio, perche' nella Zone
 * non c'e' mai stata: non ci entrano tutti gli operai di un cantiere, ci
 * entra chi gli diamo l'accesso — e quello, in pratica, e' il capo. Due
 * ruoli che fanno le stesse cose sono un ruolo solo scritto due volte, e
 * intanto il codice li distingueva in ventotto punti.
 *
 * Chi era capo e chi era operaio diventano **Cantiere**. Nessuno perde
 * niente: l'operaio semmai guadagna quello che prima era del capo, e in
 * cantiere erano gia' le stesse persone.
 */
final class ZoneRuoloCantiere extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_accessi')) {
            return;
        }

        // Prima si allarga l'elenco, se no l'UPDATE non ha dove scrivere.
        $this->table('bb_zone_accessi')
            ->changeColumn('ruolo', 'enum', [
                'values'  => ['capo', 'operaio', 'cliente', 'ufficio', 'cantiere'],
                'null'    => false,
                'default' => 'cantiere',
            ])
            ->update();

        $this->execute("UPDATE bb_zone_accessi SET ruolo = 'cantiere' WHERE ruolo IN ('capo', 'operaio')");

        $this->table('bb_zone_accessi')
            ->changeColumn('ruolo', 'enum', [
                'values'  => ['ufficio', 'cantiere', 'cliente'],
                'null'    => false,
                'default' => 'cantiere',
            ])
            ->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('bb_zone_accessi')) {
            return;
        }

        // Tornando indietro tutti diventano capi: lasciarli operai
        // toglierebbe loro meta' dei bottoni che avevano.
        $this->table('bb_zone_accessi')
            ->changeColumn('ruolo', 'enum', [
                'values'  => ['capo', 'operaio', 'cliente', 'ufficio', 'cantiere'],
                'null'    => false,
                'default' => 'operaio',
            ])
            ->update();

        $this->execute("UPDATE bb_zone_accessi SET ruolo = 'capo' WHERE ruolo = 'cantiere'");

        $this->table('bb_zone_accessi')
            ->changeColumn('ruolo', 'enum', [
                'values'  => ['capo', 'operaio', 'cliente', 'ufficio'],
                'null'    => false,
                'default' => 'operaio',
            ])
            ->update();
    }
}
