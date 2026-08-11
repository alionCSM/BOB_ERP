<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prenotazioni autocarrate: stato del pagamento.
 *
 * Il valore predefinito e' 'da_pagare' perche' e' la condizione in cui
 * nasce ogni prenotazione: si segna come pagata dopo, non prima.
 */
final class PrenotazioniPagamento extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('pn_prenotazioni');

        if (!$t->hasColumn('pagamento')) {
            $t->addColumn('pagamento', 'string', [
                    'limit'   => 20,
                    'null'    => false,
                    'default' => 'da_pagare',
                    'comment' => 'da_pagare | pagata',
                ])
              ->addIndex(['group_company_id', 'pagamento'], ['name' => 'idx_societa_pagamento'])
              ->update();
        }
    }

    public function down(): void
    {
        $t = $this->table('pn_prenotazioni');
        if ($t->hasColumn('pagamento')) {
            $t->removeColumn('pagamento')->update();
        }
    }
}
