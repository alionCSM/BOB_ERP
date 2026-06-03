<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Q8 nelle fatture identifica il veicolo con un alias proprio
 * ("Plate number") che NON e' la targa fisica, ma un nome breve
 * tipo "JOLLY", "JOLLY 2", "JOLLY 3". Aggiungiamo:
 *
 *  - bb_fleet_vehicles.plate_alias_q8     mappa la targa BOB all'alias Q8
 *  - bb_fleet_fuel_tx.plate_alias_q8      salva il valore raw del file
 *
 * Cosi' il matcher tx→veicolo funziona: alias Q8 → bb_fleet_vehicles.
 */
final class FleetQ8PlateAlias extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bb_fleet_vehicles')) {
            $t = $this->table('bb_fleet_vehicles');
            if (!$t->hasColumn('plate_alias_q8')) {
                $t->addColumn('plate_alias_q8', 'string', [
                        'limit' => 64, 'null' => true, 'after' => 'gps_device_id'
                    ])
                  ->addIndex(['plate_alias_q8'], ['name' => 'idx_plate_alias_q8'])
                  ->update();
            }
        }

        if ($this->hasTable('bb_fleet_fuel_tx')) {
            $t = $this->table('bb_fleet_fuel_tx');
            if (!$t->hasColumn('plate_alias_q8')) {
                $t->addColumn('plate_alias_q8', 'string', [
                        'limit' => 64, 'null' => true, 'after' => 'card_numero'
                    ])
                  ->addIndex(['plate_alias_q8'], ['name' => 'idx_plate_alias'])
                  ->update();
            }
        }
    }
}
