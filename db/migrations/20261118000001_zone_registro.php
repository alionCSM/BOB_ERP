<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Il registro della Zone: chi ha cancellato o cambiato cosa, e quando.
 *
 * Nasce per il reset di un cantiere — un'operazione che non si annulla e
 * che svuota tutto — ma serve a tenere anche il resto: una cancellazione
 * senza traccia, in uno spazio dove lavorano insieme noi, il committente e
 * la squadra, e' una discussione che nessuno puo' chiudere.
 *
 * **Il registro sopravvive al reset.** E' l'unica tabella della Zone che
 * il reset non tocca: se sparisse insieme al resto non servirebbe a
 * niente, perche' l'unica cosa che si vuole sapere dopo e' proprio chi
 * l'ha fatto.
 *
 * In `prima` ci va una copia di quello che c'era. Senza, il registro dice
 * che qualcosa e' cambiato ma non cosa, e vale quanto non averlo: per il
 * reset e' il conto di quello che e' stato cancellato, tabella per
 * tabella.
 */
final class ZoneRegistro extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('bb_zone_registro')) {
            return;
        }

        // Niente chiave esterna: l'utente MySQL di produzione non ha
        // REFERENCES. E qui sarebbe anche sbagliata: le righe restano
        // valide quando la cosa a cui si riferiscono e' stata cancellata —
        // e' tutto il punto.
        $this->table('bb_zone_registro')
            ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
            // 'reset', 'cancellazione', 'modifica'
            ->addColumn('azione', 'string', ['limit' => 32, 'null' => false])
            // su cosa: 'cantiere', 'attivita', 'documento', 'segnalazione'...
            ->addColumn('oggetto', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('oggetto_id', 'integer', ['null' => true, 'signed' => false])
            // come si chiamava, per leggere il registro senza cercare altrove
            ->addColumn('etichetta', 'string', ['limit' => 255, 'null' => true])
            // com'era prima, in JSON. Per il reset: il conto per tabella.
            ->addColumn('prima', 'text', ['null' => true])
            ->addColumn('dopo', 'text', ['null' => true])
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            // il nome si copia qui: fra due anni l'utente puo' non esserci
            // piu', e "chi e' stato" deve restare leggibile
            ->addColumn('user_nome', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['worksite_id', 'created_at'], ['name' => 'idx_zreg_cantiere'])
            ->addIndex(['azione'], ['name' => 'idx_zreg_azione'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_registro')) {
            $this->table('bb_zone_registro')->drop()->save();
        }
    }
}
