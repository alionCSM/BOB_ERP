<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * BOB Zone: segnalazioni dal cantiere e la chat del cantiere.
 *
 * Oggi un problema in cantiere (manca materiale, un ponteggio non e' a
 * posto, il cliente ha rotto qualcosa) passa per telefonate e WhatsApp: non
 * resta scritto, l'ufficio non sa a che punto e', e dopo un mese nessuno si
 * ricorda chi l'aveva detto. E i gruppi WhatsApp del cantiere mescolano
 * squadra, capi e a volte il cliente, con dentro foto di tutto.
 *
 * - **segnalazioni**: un problema con tipo, gravita', testo e foto, che
 *   l'ufficio prende in carico e chiude. Chi l'ha fatta sa sempre a che punto
 *   e'. Se serve lavoro, diventa un'attivita'.
 *
 * - **messaggi**: la chat del cantiere, divisa in canali per chi legge
 *   (squadra, capi, cliente), con foto, avvisi fissati in alto e messaggi
 *   rapidi ("Materiale arrivato") che ognuno legge nella sua lingua. La
 *   stessa tabella tiene i messaggi di ogni segnalazione.
 *
 * - **letture**: fin dove uno ha letto, per canale o segnalazione. Serve per
 *   i "non letti", per il "letto da" e per non mandare un push a ogni
 *   messaggio a chi non ha ancora aperto i precedenti.
 */
final class ZoneSegnalazioniChat extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_segnalazioni')) {
            $this->table('bb_zone_segnalazioni')
                ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('tipo', 'enum', [
                    'values'  => ['sicurezza', 'materiale', 'danno', 'ritardo', 'qualita', 'altro'],
                    'default' => 'altro',
                ])
                ->addColumn('gravita', 'enum', [
                    'values'  => ['bassa', 'alta', 'blocca'],
                    'default' => 'bassa',
                ])
                ->addColumn('testo', 'text', ['null' => false])
                ->addColumn('stato', 'enum', [
                    'values'  => ['aperta', 'presa', 'risolta'],
                    'default' => 'aperta',
                ])
                // chi la legge oltre a chi l'ha fatta: da un operaio o un capo
                // "capi" (ufficio e capi), dal cliente "cliente"
                ->addColumn('visibilita', 'enum', [
                    'values'  => ['ufficio', 'squadra', 'capi', 'cliente', 'tutti'],
                    'default' => 'capi',
                ])
                ->addColumn('created_by', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('presa_da', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('presa_at', 'datetime', ['null' => true])
                ->addColumn('risolta_da', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('risolta_at', 'datetime', ['null' => true])
                ->addColumn('esito', 'text', ['null' => true])
                // se e' diventata un'attivita'
                ->addColumn('task_id', 'integer', ['null' => true, 'signed' => false])
                ->addIndex(['worksite_id', 'stato'], ['name' => 'idx_zseg_cantiere'])
                ->addIndex(['created_by'], ['name' => 'idx_zseg_autore'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_messaggi')) {
            $this->table('bb_zone_messaggi')
                ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
                // un canale della chat, oppure il filo di una segnalazione
                ->addColumn('canale', 'enum', [
                    'values' => ['squadra', 'capi', 'cliente'],
                    'null'   => true,
                ])
                ->addColumn('segnalazione_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('testo', 'text', ['null' => true])
                // messaggio rapido: si salva la chiave, ognuno lo legge nella sua lingua
                ->addColumn('rapido', 'string', ['limit' => 40, 'null' => true])
                ->addColumn('foto', 'string', ['limit' => 500, 'null' => true])
                // scritto da BOB: "Presa in carico da Rossi"
                ->addColumn('sistema', 'boolean', ['default' => false])
                ->addColumn('fissato', 'boolean', ['default' => false])
                ->addColumn('fissato_da', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('eliminato_at', 'datetime', ['null' => true])
                ->addIndex(['worksite_id', 'canale', 'id'], ['name' => 'idx_zmsg_canale'])
                ->addIndex(['segnalazione_id', 'id'], ['name' => 'idx_zmsg_segn'])
                ->create();
        }

        if (!$this->hasTable('bb_zone_letture')) {
            $this->table('bb_zone_letture', ['id' => false, 'primary_key' => ['user_id', 'worksite_id', 'chiave']])
                ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
                // 'squadra', 'capi', 'cliente' o 's<id>' per una segnalazione
                ->addColumn('chiave', 'string', ['limit' => 20])
                ->addColumn('ultimo_id', 'integer', ['default' => 0, 'signed' => false])
                ->addColumn('letto_at', 'datetime', ['null' => true])
                // l'ultimo push mandato: fino a che non legge, non se ne mandano altri
                ->addColumn('avvisato_at', 'datetime', ['null' => true])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['bb_zone_letture', 'bb_zone_messaggi', 'bb_zone_segnalazioni'] as $t) {
            if ($this->hasTable($t)) {
                $this->table($t)->drop()->save();
            }
        }
    }
}
