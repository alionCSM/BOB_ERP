<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add IVA percentage to bb_ordini_aziende. The `total` column stays as
 * imponibile (net); IVA and totale documento are derived at display time
 * from total * iva_percentage / 100.
 */
final class OrdiniAziendeAddIva extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bb_ordini_aziende');

        if (!$table->hasColumn('iva_percentage')) {
            $table->addColumn('iva_percentage', 'decimal', [
                'precision' => 5,
                'scale'     => 2,
                'null'      => false,
                'default'   => '22.00',
                'after'     => 'total',
            ]);
        }

        $table->update();
    }
}
