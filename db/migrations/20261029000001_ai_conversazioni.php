<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le chat di BOB AI restano scritte.
 *
 * Finora la conversazione viveva in una variabile JavaScript: un refresh, un
 * click su un'altra pagina, il telefono che chiude la scheda, e sparivano
 * sia le domande sia le risposte. Uno che aveva tirato fuori l'elenco giusto
 * dopo tre tentativi doveva rifare i tre tentativi.
 *
 * - **conversazioni**: una riga per chat, con un titolo preso dalla prima
 *   domanda. La societa' attiva viene registrata perche' i permessi (e quindi
 *   le risposte) dipendono da quella: una chat fatta in una societa' non si
 *   riapre in un'altra come se niente fosse.
 *
 * - **messaggi**: la domanda e la risposta, nell'ordine. Oltre al testo che
 *   l'utente rilegge c'e' `riassunto`: una riga sola ("23 cantieri in corso")
 *   che e' quello che si rimanda al modello come contesto dei turni
 *   precedenti. Prima gli si rimandava la tabella markdown intera, centinaia
 *   di righe di dati che riempivano il contesto e rallentavano tutto senza
 *   aggiungere niente di utile.
 *
 * `sql_query`, `righe`, `durata_ms` e `errore` restano per messaggio: servono
 * a far vedere la query eseguita quando si riapre una chat vecchia, e a
 * capire dopo perche' una risposta era sbagliata. bb_ai_sql_logs continua a
 * esistere e a fare il suo lavoro di log tecnico trasversale.
 */
final class AiConversazioni extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_ai_conversazioni')) {
            $this->table('bb_ai_conversazioni')
                ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
                // 0 = nessuna societa' scelta (installazione a societa' singola)
                ->addColumn('company_id', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
                ->addColumn('titolo', 'string', ['limit' => 160, 'null' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('archiviata', 'boolean', ['default' => false])
                // l'elenco in barra laterale: le mie, non archiviate, le piu'
                // recenti in cima
                ->addIndex(['user_id', 'archiviata', 'updated_at'], ['name' => 'idx_aiconv_utente'])
                ->create();
        }

        if (!$this->hasTable('bb_ai_messaggi')) {
            $this->table('bb_ai_messaggi')
                ->addColumn('conversazione_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('ruolo', 'enum', ['values' => ['utente', 'bob'], 'null' => false])
                // la risposta come la rilegge l'utente (tabella markdown compresa)
                ->addColumn('testo', 'text', ['null' => false])
                // la riga sola che si rimanda al modello come contesto
                ->addColumn('riassunto', 'string', ['limit' => 300, 'null' => true])
                ->addColumn('sql_query', 'text', ['null' => true])
                ->addColumn('righe', 'integer', ['null' => true])
                ->addColumn('durata_ms', 'integer', ['null' => true])
                ->addColumn('errore', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['conversazione_id', 'id'], ['name' => 'idx_aimsg_conv'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_ai_messaggi')) {
            $this->table('bb_ai_messaggi')->drop()->save();
        }
        if ($this->hasTable('bb_ai_conversazioni')) {
            $this->table('bb_ai_conversazioni')->drop()->save();
        }
    }
}
