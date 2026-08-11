<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prenotazioni autocarrate: contratto, importo e commerciale.
 *
 * L'importo e' distinto dal totale gia' presente: totale e' quello che
 * esce dal calcolo giorni x tariffa, importo e' quello scritto nel
 * contratto. Tenerli separati permette di vedere quando i due non
 * coincidono, che e' proprio il caso da controllare.
 *
 * Il commerciale e' un riferimento a bb_users: si salva l'id e si mostra
 * il nome, cosi' se la persona cambia nome la prenotazione resta legata.
 */
final class PrenotazioniContratto extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('pn_prenotazioni');

        if (!$t->hasColumn('contratto')) {
            $t->addColumn('contratto', 'string', ['limit' => 100, 'null' => true,
                          'comment' => 'Riferimento del contratto']);
        }
        if (!$t->hasColumn('importo')) {
            $t->addColumn('importo', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true,
                          'comment' => 'Importo di contratto, distinto dal totale calcolato']);
        }
        if (!$t->hasColumn('commerciale_user_id')) {
            $t->addColumn('commerciale_user_id', 'integer', ['null' => true, 'signed' => false,
                          'comment' => 'Utente di bb_users che ha seguito la trattativa'])
              ->addIndex(['commerciale_user_id'], ['name' => 'idx_commerciale']);
        }

        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('pn_prenotazioni');
        foreach (['contratto', 'importo', 'commerciale_user_id'] as $c) {
            if ($t->hasColumn($c)) {
                $t->removeColumn($c);
            }
        }
        $t->update();
    }
}
