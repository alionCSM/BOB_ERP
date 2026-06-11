<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fleet — telemetria, transazioni carburante, anomalie.
 *
 * Schema phase A (storage):
 *  - bb_fleet_imports          audit di ogni upload (file, tipo, esito)
 *  - bb_fleet_gps_trips        una riga per tratta da "Riepilogo tratte"
 *  - bb_fleet_fuel_tx          una riga per transazione Q8 (carta)
 *  - bb_fleet_reconciliation_runs   audit di ogni esecuzione del matcher
 *  - bb_fleet_anomalies        risultati del matcher (1 riga per anomalia)
 *
 * Vehicle_id non e' FK su bb_fleet_vehicles per non bloccare l'import
 * quando un veicolo del file non esiste ancora in catalogo (lo
 * inseriremo a mano poi). Il matching e' best-effort per targa.
 */
final class CreateFleetTelemetry extends AbstractMigration
{
    public function change(): void
    {
        // ── Audit upload ─────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_imports')) {
            $this->table('bb_fleet_imports', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('source',        'enum',     ['values' => ['gps','q8'], 'null' => false])
                ->addColumn('filename',      'string',   ['limit' => 255])
                ->addColumn('storage_path',  'string',   ['limit' => 500, 'null' => true])
                ->addColumn('rows_total',    'integer',  ['default' => 0, 'signed' => false])
                ->addColumn('rows_imported', 'integer',  ['default' => 0, 'signed' => false])
                ->addColumn('rows_skipped',  'integer',  ['default' => 0, 'signed' => false])
                ->addColumn('period_from',   'date',     ['null' => true])
                ->addColumn('period_to',     'date',     ['null' => true])
                ->addColumn('status',        'enum',     ['values' => ['ok','partial','error'], 'default' => 'ok'])
                ->addColumn('error_log',     'text',     ['null' => true])
                ->addColumn('created_at',    'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('created_by',    'integer',  ['null' => true, 'signed' => true])
                ->addIndex(['source','created_at'], ['name' => 'idx_source_date'])
                ->create();
        }

        // ── Tratte GPS ──────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_gps_trips')) {
            $this->table('bb_fleet_gps_trips', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('import_id',         'integer',  ['null' => false, 'signed' => false])
                ->addColumn('vehicle_targa',     'string',   ['limit' => 32])
                ->addColumn('vehicle_id',        'integer',  ['null' => true, 'signed' => false])
                ->addColumn('driver_name',       'string',   ['limit' => 120, 'null' => true])
                ->addColumn('driver_person_code','string',   ['limit' => 64,  'null' => true])
                ->addColumn('start_at',          'datetime', ['null' => false])
                ->addColumn('end_at',            'datetime', ['null' => false])
                ->addColumn('start_address',     'string',   ['limit' => 255, 'null' => true])
                ->addColumn('end_address',       'string',   ['limit' => 255, 'null' => true])
                ->addColumn('start_city',        'string',   ['limit' => 80,  'null' => true])
                ->addColumn('start_prov',        'string',   ['limit' => 20,  'null' => true])
                ->addColumn('end_city',          'string',   ['limit' => 80,  'null' => true])
                ->addColumn('end_prov',          'string',   ['limit' => 20,  'null' => true])
                ->addColumn('end_lat',           'decimal',  ['precision' => 10, 'scale' => 7, 'null' => true])
                ->addColumn('end_lng',           'decimal',  ['precision' => 10, 'scale' => 7, 'null' => true])
                ->addColumn('km_done',           'decimal',  ['precision' => 8, 'scale' => 2, 'null' => true])
                ->addColumn('km_odometer',       'decimal',  ['precision' => 10, 'scale' => 2, 'null' => true])
                ->addColumn('avg_speed',         'decimal',  ['precision' => 6, 'scale' => 2, 'null' => true])
                ->addColumn('max_speed',         'integer',  ['null' => true, 'signed' => false])
                ->addColumn('drive_seconds',     'integer',  ['null' => true, 'signed' => false])
                ->addColumn('refuels_count',     'tinyinteger', ['default' => 0, 'signed' => false])
                ->addColumn('refuels_liters',    'decimal',  ['precision' => 7, 'scale' => 2, 'default' => 0])
                ->addColumn('raw_hash',          'string',   ['limit' => 64, 'null' => true])
                ->addColumn('created_at',        'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['vehicle_targa','start_at'], ['name' => 'idx_targa_date'])
                ->addIndex(['vehicle_id','start_at'],    ['name' => 'idx_vehicle_date'])
                ->addIndex(['raw_hash'], ['unique' => true, 'name' => 'uk_raw_hash'])
                ->addIndex(['import_id'], ['name' => 'idx_import'])
                ->create();
        }

        // ── Transazioni carburante Q8 ────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_fuel_tx')) {
            $this->table('bb_fleet_fuel_tx', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('import_id',       'integer',  ['null' => false, 'signed' => false])
                ->addColumn('card_numero',     'string',   ['limit' => 64])
                ->addColumn('card_id',         'integer',  ['null' => true, 'signed' => false])
                ->addColumn('vehicle_id_at_tx','integer',  ['null' => true, 'signed' => false])
                ->addColumn('tx_at',           'datetime', ['null' => false])
                ->addColumn('importo',         'decimal',  ['precision' => 8, 'scale' => 2])
                ->addColumn('litri',           'decimal',  ['precision' => 7, 'scale' => 2])
                ->addColumn('prezzo_unit',     'decimal',  ['precision' => 6, 'scale' => 3, 'null' => true])
                ->addColumn('carburante',      'string',   ['limit' => 40,  'null' => true])
                ->addColumn('distributore',    'string',   ['limit' => 255, 'null' => true])
                ->addColumn('city',            'string',   ['limit' => 80,  'null' => true])
                ->addColumn('prov',            'string',   ['limit' => 20,  'null' => true])
                ->addColumn('km_dichiarati',   'integer',  ['null' => true, 'signed' => false])
                ->addColumn('raw_hash',        'string',   ['limit' => 64, 'null' => true])
                ->addColumn('created_at',      'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['card_numero','tx_at'], ['name' => 'idx_card_date'])
                ->addIndex(['tx_at'], ['name' => 'idx_date'])
                ->addIndex(['raw_hash'], ['unique' => true, 'name' => 'uk_raw_hash'])
                ->addIndex(['import_id'], ['name' => 'idx_import'])
                ->create();
        }

        // ── Esecuzione del matcher ───────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_reconciliation_runs')) {
            $this->table('bb_fleet_reconciliation_runs', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('period_year',  'smallinteger', ['null' => false, 'signed' => false])
                ->addColumn('period_month', 'tinyinteger',  ['null' => false, 'signed' => false])
                ->addColumn('vehicle_id',   'integer',      ['null' => true, 'signed' => false])
                ->addColumn('tx_total',     'integer',      ['default' => 0, 'signed' => false])
                ->addColumn('trips_total',  'integer',      ['default' => 0, 'signed' => false])
                ->addColumn('anomalies',    'integer',      ['default' => 0, 'signed' => false])
                ->addColumn('duration_ms',  'integer',      ['null' => true, 'signed' => false])
                ->addColumn('started_at',   'datetime',     ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('started_by',   'integer',      ['null' => true, 'signed' => true])
                ->addIndex(['period_year','period_month'], ['name' => 'idx_period'])
                ->create();
        }

        // ── Anomalie ─────────────────────────────────────────────────────────
        if (!$this->hasTable('bb_fleet_anomalies')) {
            $this->table('bb_fleet_anomalies', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('run_id',      'integer',  ['null' => false, 'signed' => false])
                ->addColumn('vehicle_id',  'integer',  ['null' => true,  'signed' => false])
                ->addColumn('worker_id',   'integer',  ['null' => true,  'signed' => false])
                ->addColumn('rule_code',   'string',   ['limit' => 64])
                ->addColumn('severity',    'enum',     ['values' => ['info','low','medium','high'], 'default' => 'medium'])
                ->addColumn('event_at',    'datetime', ['null' => true])
                ->addColumn('summary',     'string',   ['limit' => 255])
                ->addColumn('detail',      'text',     ['null' => true])
                ->addColumn('ref_tx_id',   'integer',  ['null' => true, 'signed' => false])
                ->addColumn('ref_trip_id', 'integer',  ['null' => true, 'signed' => false])
                ->addColumn('status',      'enum',     ['values' => ['open','dismissed','resolved'], 'default' => 'open'])
                ->addColumn('reviewed_by', 'integer',  ['null' => true, 'signed' => true])
                ->addColumn('reviewed_at', 'datetime', ['null' => true])
                ->addColumn('note',        'text',     ['null' => true])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['run_id'], ['name' => 'idx_run'])
                ->addIndex(['vehicle_id','event_at'], ['name' => 'idx_vehicle_event'])
                ->addIndex(['severity','status'], ['name' => 'idx_sev_status'])
                // niente FK: alcuni utenti DB su prod non hanno REFERENCES
                ->create();
        }
    }
}
