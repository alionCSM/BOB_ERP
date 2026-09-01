<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Poti Noleggi — carburante e foto su uscita e rientro.
 *
 * CARBURANTE. Testo libero e non un numero: chi segna il livello lo legge da
 * strumenti diversi a seconda del mezzo. Su un'autocarrata sono tacche
 * ("3/4"), su un'altra una percentuale ("80%"), su un telescopico i litri
 * ("45 L"). Costringere a una sola unita' vorrebbe dire far convertire a
 * mente in piazzale, che e' il modo piu' veloce per avere numeri sbagliati.
 * Quaranta caratteri bastano per "mezzo serbatoio, spia accesa".
 *
 * Due campi e non uno: il senso di questo dato e' il confronto fra come e'
 * uscito e come e' tornato. Con un campo solo il secondo cancellerebbe il
 * primo e non si saprebbe piu' quanto ne e' stato consumato.
 *
 * FOTO. Una tabella sola per tutti e due i moduli: la foto di un'autocarrata
 * che esce e quella di un telescopico che rientra sono la stessa cosa, e
 * due tabelle gemelle vorrebbero dire scrivere due volte ogni query e
 * dimenticarsene una. `entita` dice a quale delle due righe si riferisce.
 *
 * I file stanno fuori dalla cartella pubblica, come le foto di BOB Zone: a
 * database c'e' solo il percorso, e per vederle si passa da una rotta che
 * controlla i permessi.
 *
 * Niente foreign key: in produzione l'utente del database non ha il
 * permesso REFERENCES.
 */
final class PotiCarburanteFoto extends AbstractMigration
{
    public function up(): void
    {
        foreach (['pn_prenotazioni', 'pn_noleggi_righe'] as $tabella) {
            $t = $this->table($tabella);

            if (!$t->hasColumn('carburante_uscita')) {
                $t->addColumn('carburante_uscita', 'string', [
                    'limit'   => 40,
                    'null'    => true,
                    'comment' => 'Livello alla consegna, come lo si legge: 3/4, 80%, 45 L',
                ]);
            }
            if (!$t->hasColumn('carburante_rientro')) {
                $t->addColumn('carburante_rientro', 'string', [
                    'limit'   => 40,
                    'null'    => true,
                    'comment' => 'Livello al rientro, nella stessa unita\' di quello in uscita',
                ]);
            }
            $t->update();
        }

        if (!$this->hasTable('pn_foto')) {
            $this->table('pn_foto', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('entita', 'string', ['limit' => 20, 'null' => false,
                            'comment' => 'prenotazione (autocarrata) | riga (mezzo di sollevamento)'])
                ->addColumn('entita_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('momento', 'string', ['limit' => 10, 'null' => false,
                            'comment' => 'uscita | rientro'])
                ->addColumn('percorso', 'string', ['limit' => 400, 'null' => false,
                            'comment' => 'Percorso relativo a CLOUD_ROOT, mai servito direttamente'])
                ->addColumn('mime', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('dimensione', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                // le foto si leggono sempre per riga e momento insieme:
                // "fammi vedere com'era quando e' uscito"
                ->addIndex(['group_company_id', 'entita', 'entita_id', 'momento'],
                           ['name' => 'idx_entita_momento'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['pn_prenotazioni', 'pn_noleggi_righe'] as $tabella) {
            $this->table($tabella)
                ->removeColumn('carburante_uscita')
                ->removeColumn('carburante_rientro')
                ->update();
        }

        // i file restano dove sono: cancellare le righe non deve portarsi
        // via delle foto che qualcuno potrebbe rivolere
        if ($this->hasTable('pn_foto')) {
            $this->table('pn_foto')->drop()->update();
        }
    }
}
