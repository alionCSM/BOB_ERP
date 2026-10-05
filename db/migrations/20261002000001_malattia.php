<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La malattia.
 *
 * Prima non esisteva da nessuna parte in BOB. Chi era a casa malato
 * risultava pianificato come tutti, e si prendeva il promemoria delle venti
 * e quello delle ventuno che gli chiedevano di segnare una presenza che non
 * c'era: il modo piu' veloce per far disinstallare l'app a qualcuno che gia'
 * non sta bene.
 *
 * Non una tabella nuova ma un `tipo` in piu' in bb_ferie_permessi, che e' la
 * tabella delle assenze anche se si chiama cosi'. Stessa forma — operaio,
 * periodo, note, stato — stesse schermate, e il promemoria la esclude senza
 * imparare niente di nuovo. Una tabella a parte avrebbe voluto dire
 * ricontrollarla in ogni punto che chiede "chi e' via oggi".
 *
 * `tipo` e' un varchar, non un ENUM: il valore nuovo non richiede di
 * toccare la colonna.
 *
 * L'unica cosa che le ferie non hanno e' il numero del certificato. Sta in
 * una colonna sua e non dentro le note perche' in ufficio lo cercano: con
 * una nota libera si cerca a occhio.
 */
final class Malattia extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('bb_ferie_permessi');

        if (!$t->hasColumn('protocollo')) {
            $t->addColumn('protocollo', 'string', [
                'limit'   => 32,
                'null'    => true,
                'comment' => 'Numero del certificato di malattia',
            ]);
            $t->addIndex(['protocollo'], ['name' => 'idx_fp_protocollo']);
        }

        $t->update();
    }

    public function down(): void
    {
        $this->table('bb_ferie_permessi')
            ->removeIndexByName('idx_fp_protocollo')
            ->removeColumn('protocollo')
            ->update();
    }
}
