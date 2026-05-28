<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Modulo Flotta — step 1: catalogo + storico assegnazioni.
 *
 * Schema:
 *   - bb_fleet_vehicles                veicoli (furgoni/auto/camion)
 *   - bb_fleet_vehicle_assignments     storico assegnazione veicolo -> utente
 *   - bb_fleet_fuel_cards              catalogo carte carburante (Q8, ...)
 *   - bb_fleet_fuel_card_assignments   storico holder (veicolo XOR utente)
 *   - bb_fleet_telepass                catalogo telepass
 *   - bb_fleet_telepass_assignments    storico holder (veicolo XOR utente)
 *
 * Convenzione storico: to_date NULL = assegnazione corrente. Per ogni
 * "soggetto" (vehicle/card/telepass) deve esistere al massimo 1 riga con
 * to_date NULL — vincolo applicato a livello repository, perche' UNIQUE
 * parziale non e' supportato uniformemente in MySQL.
 *
 * Holder polimorfico delle carte/telepass: due colonne nullable
 * (vehicle_id, user_id) con CHECK XOR. Tiene FK reali, niente polymorphic
 * hack. MySQL 8 enforce il CHECK; 5.7 lo ignora ma il repo lo enforce.
 */
final class CreateFleetTables extends AbstractMigration
{
    public function change(): void
    {
        // ── Veicoli ──────────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_vehicles')) {
            $this->table('bb_fleet_vehicles', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('targa',               'string',   ['limit' => 16, 'null' => false])
                ->addColumn('modello',             'string',   ['limit' => 120, 'null' => true])
                ->addColumn('tipo',                'enum',     ['values' => ['furgone','auto','camion','altro'], 'default' => 'furgone'])
                ->addColumn('gps_device_id',       'string',   ['limit' => 80, 'null' => true])
                ->addColumn('current_worksite_id', 'integer',  ['null' => true, 'signed' => true])
                ->addColumn('notes',               'text',     ['null' => true])
                ->addColumn('active',              'boolean',  ['default' => true])
                ->addColumn('created_at',          'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at',          'datetime', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['targa'], ['unique' => true, 'name' => 'uk_targa'])
                ->addIndex(['current_worksite_id'], ['name' => 'idx_worksite'])
                ->addIndex(['active'], ['name' => 'idx_active'])
                ->create();
        }

        // ── Assegnazioni veicolo -> utente ───────────────────────────────────
        if (!$this->hasTable('bb_fleet_vehicle_assignments')) {
            $this->table('bb_fleet_vehicle_assignments', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('vehicle_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('user_id',    'integer',  ['null' => false, 'signed' => true])
                ->addColumn('from_date',  'date',     ['null' => false])
                ->addColumn('to_date',    'date',     ['null' => true])
                ->addColumn('notes',      'string',   ['limit' => 255, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('created_by', 'integer',  ['null' => true, 'signed' => true])
                ->addForeignKey('vehicle_id', 'bb_fleet_vehicles', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['vehicle_id', 'to_date'], ['name' => 'idx_vehicle_current'])
                ->addIndex(['user_id', 'to_date'],    ['name' => 'idx_user_current'])
                ->create();
        }

        // ── Carte carburante ─────────────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_fuel_cards')) {
            $this->table('bb_fleet_fuel_cards', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('numero',     'string',   ['limit' => 64, 'null' => false])
                ->addColumn('fornitore',  'string',   ['limit' => 60, 'default' => 'Q8'])
                ->addColumn('notes',      'string',   ['limit' => 255, 'null' => true])
                ->addColumn('active',     'boolean',  ['default' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['numero', 'fornitore'], ['unique' => true, 'name' => 'uk_numero_fornitore'])
                ->addIndex(['active'], ['name' => 'idx_active'])
                ->create();
        }

        if (!$this->hasTable('bb_fleet_fuel_card_assignments')) {
            $this->table('bb_fleet_fuel_card_assignments', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('card_id',    'integer',  ['null' => false, 'signed' => false])
                ->addColumn('vehicle_id', 'integer',  ['null' => true,  'signed' => false])
                ->addColumn('user_id',    'integer',  ['null' => true,  'signed' => true])
                ->addColumn('from_date',  'date',     ['null' => false])
                ->addColumn('to_date',    'date',     ['null' => true])
                ->addColumn('notes',      'string',   ['limit' => 255, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('created_by', 'integer',  ['null' => true, 'signed' => true])
                ->addForeignKey('card_id', 'bb_fleet_fuel_cards', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['card_id', 'to_date'],    ['name' => 'idx_card_current'])
                ->addIndex(['vehicle_id', 'to_date'], ['name' => 'idx_vehicle_card'])
                ->addIndex(['user_id', 'to_date'],    ['name' => 'idx_user_card'])
                ->create();

            // XOR check: esattamente uno tra vehicle_id e user_id.
            // MySQL 8.0+ enforce; 5.7 ignora ma resta documentazione viva.
            $this->execute(
                "ALTER TABLE bb_fleet_fuel_card_assignments
                 ADD CONSTRAINT chk_fuel_card_holder_xor
                 CHECK ((vehicle_id IS NULL) <> (user_id IS NULL))"
            );
        }

        // ── Telepass ─────────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_telepass')) {
            $this->table('bb_fleet_telepass', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('numero',     'string',   ['limit' => 64, 'null' => false])
                ->addColumn('tipo',       'string',   ['limit' => 60, 'null' => true])
                ->addColumn('notes',      'string',   ['limit' => 255, 'null' => true])
                ->addColumn('active',     'boolean',  ['default' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['numero'], ['unique' => true, 'name' => 'uk_numero'])
                ->addIndex(['active'], ['name' => 'idx_active'])
                ->create();
        }

        if (!$this->hasTable('bb_fleet_telepass_assignments')) {
            $this->table('bb_fleet_telepass_assignments', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('telepass_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('vehicle_id',  'integer',  ['null' => true,  'signed' => false])
                ->addColumn('user_id',     'integer',  ['null' => true,  'signed' => true])
                ->addColumn('from_date',   'date',     ['null' => false])
                ->addColumn('to_date',     'date',     ['null' => true])
                ->addColumn('notes',       'string',   ['limit' => 255, 'null' => true])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('created_by',  'integer',  ['null' => true, 'signed' => true])
                ->addForeignKey('telepass_id', 'bb_fleet_telepass', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['telepass_id', 'to_date'], ['name' => 'idx_telepass_current'])
                ->addIndex(['vehicle_id', 'to_date'],  ['name' => 'idx_vehicle_telepass'])
                ->addIndex(['user_id', 'to_date'],     ['name' => 'idx_user_telepass'])
                ->create();

            $this->execute(
                "ALTER TABLE bb_fleet_telepass_assignments
                 ADD CONSTRAINT chk_telepass_holder_xor
                 CHECK ((vehicle_id IS NULL) <> (user_id IS NULL))"
            );
        }
    }
}
