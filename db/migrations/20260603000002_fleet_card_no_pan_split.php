<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fleet — split Card No. e PAN come campi distinti.
 *
 * Problema risolto:
 *  - Il PAN Q8 e' un intero di 19 cifre (es. 7028012731700025018).
 *  - Excel lo salva come numerico → PhpSpreadsheet lo legge come float.
 *  - PHP float ha ~15.95 cifre di precisione → l'ultimo 3-4 cifre sono perse.
 *  - Match per PAN diretto da Excel → impossibile.
 *
 * Soluzione: due colonne distinte su bb_fleet_fuel_cards
 *    - card_no  (es. "25")  → match primario con Q8 "Card No."
 *    - pan      (es. "7028012731700025018")  → display/audit
 *
 * Il campo legacy "numero" resta e contiene gia' uno dei due (lo migriamo
 * a card_no se non c'e' gia'). I match continuano a funzionare anche su
 * vecchi dati.
 */
final class FleetCardNoPanSplit extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bb_fleet_fuel_cards')) return;

        $t = $this->table('bb_fleet_fuel_cards');

        if (!$t->hasColumn('card_no')) {
            $t->addColumn('card_no', 'string', [
                'limit' => 32, 'null' => true, 'after' => 'numero'
              ])
              ->addIndex(['card_no', 'fornitore'], ['name' => 'idx_card_no'])
              ->update();
        }

        if (!$t->hasColumn('pan')) {
            $t->addColumn('pan', 'string', [
                'limit' => 32, 'null' => true, 'after' => 'card_no'
              ])
              ->addIndex(['pan'], ['name' => 'idx_pan'])
              ->update();
        }

        // Backfill: se card_no e' vuoto, copia da numero (best-effort: rimuovi
        // zeri iniziali per match con interi Q8). Se numero sembra un PAN
        // (>= 13 cifre), copialo anche in pan.
        $this->execute("
            UPDATE bb_fleet_fuel_cards
            SET card_no = CASE
                WHEN card_no IS NULL OR card_no = '' THEN TRIM(LEADING '0' FROM REGEXP_REPLACE(numero, '[^0-9]', ''))
                ELSE card_no
            END
            WHERE numero IS NOT NULL AND numero <> ''
        ");

        $this->execute("
            UPDATE bb_fleet_fuel_cards
            SET pan = numero
            WHERE (pan IS NULL OR pan = '')
              AND numero IS NOT NULL
              AND LENGTH(REGEXP_REPLACE(numero, '[^0-9]', '')) >= 13
        ");
    }
}
