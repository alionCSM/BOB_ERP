<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fleet — refactor schema:
 *  - drop bb_fleet_vehicles.current_worksite_id (collegamento veicolo<->
 *    cantiere arrivera' via attendance/programmazione, non con FK diretta)
 *  - assegnazioni puntano a bb_workers (operai), non a bb_users.
 *    Rinominate user_id -> worker_id su tutte e tre le assignment table.
 *  - aggiornati i CHECK XOR di carte/telepass per riflettere worker_id.
 *
 * Niente FK su worker_id: stesso motivo di user_id prima (bb_workers ha
 * potenzialmente signedness diversa dall'unsigned generato da Phinx).
 *
 * Le tabelle fleet erano appena create e vuote, quindi gli ALTER sono
 * istantanei e non c'e' bisogno di backfill.
 */
final class FleetWorkersRefactor extends AbstractMigration
{
    public function change(): void
    {
        // ── 1) bb_fleet_vehicles: rimuovi current_worksite_id ────────────────
        if ($this->hasTable('bb_fleet_vehicles')) {
            $t = $this->table('bb_fleet_vehicles');
            if ($t->hasColumn('current_worksite_id')) {
                // togli anche l'index che la referenziava
                if ($t->hasIndex(['current_worksite_id'])) {
                    $t->removeIndex(['current_worksite_id'])->update();
                }
                $t->removeColumn('current_worksite_id')->update();
            }
        }

        // ── 2) bb_fleet_vehicle_assignments: user_id -> worker_id ────────────
        if ($this->hasTable('bb_fleet_vehicle_assignments')) {
            $t = $this->table('bb_fleet_vehicle_assignments');
            if ($t->hasColumn('user_id') && !$t->hasColumn('worker_id')) {
                $t->renameColumn('user_id', 'worker_id')->update();
                // ricrea l'index col nuovo nome di colonna
                if ($t->hasIndex(['user_id', 'to_date'])) {
                    $t->removeIndex(['user_id', 'to_date'])->update();
                }
                $t->addIndex(['worker_id', 'to_date'], ['name' => 'idx_worker_current'])->update();
            }
        }

        // ── 3) bb_fleet_fuel_card_assignments: user_id -> worker_id + CHECK ─
        if ($this->hasTable('bb_fleet_fuel_card_assignments')) {
            // CHECK constraint references user_id → drop prima del rename
            $this->execute("ALTER TABLE bb_fleet_fuel_card_assignments DROP CHECK chk_fuel_card_holder_xor");

            $t = $this->table('bb_fleet_fuel_card_assignments');
            if ($t->hasColumn('user_id') && !$t->hasColumn('worker_id')) {
                $t->renameColumn('user_id', 'worker_id')->update();
                if ($t->hasIndex(['user_id', 'to_date'])) {
                    $t->removeIndex(['user_id', 'to_date'])->update();
                }
                $t->addIndex(['worker_id', 'to_date'], ['name' => 'idx_worker_card'])->update();
            }

            $this->execute(
                "ALTER TABLE bb_fleet_fuel_card_assignments
                 ADD CONSTRAINT chk_fuel_card_holder_xor
                 CHECK ((vehicle_id IS NULL) <> (worker_id IS NULL))"
            );
        }

        // ── 4) bb_fleet_telepass_assignments: stesso giro ────────────────────
        if ($this->hasTable('bb_fleet_telepass_assignments')) {
            $this->execute("ALTER TABLE bb_fleet_telepass_assignments DROP CHECK chk_telepass_holder_xor");

            $t = $this->table('bb_fleet_telepass_assignments');
            if ($t->hasColumn('user_id') && !$t->hasColumn('worker_id')) {
                $t->renameColumn('user_id', 'worker_id')->update();
                if ($t->hasIndex(['user_id', 'to_date'])) {
                    $t->removeIndex(['user_id', 'to_date'])->update();
                }
                $t->addIndex(['worker_id', 'to_date'], ['name' => 'idx_worker_telepass'])->update();
            }

            $this->execute(
                "ALTER TABLE bb_fleet_telepass_assignments
                 ADD CONSTRAINT chk_telepass_holder_xor
                 CHECK ((vehicle_id IS NULL) <> (worker_id IS NULL))"
            );
        }
    }
}
