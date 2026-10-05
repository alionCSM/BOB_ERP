<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pianificazione: la trasferta, per operaio.
 *
 * Nel modulo squadre la trasferta non c'era: stava solo nella programmazione
 * mensile, che e' un'altra cosa e non e' collegata al piano giornaliero —
 * li' l'indirizzo e' testo libero e non si incrocia con un cantiere.
 *
 * Sulla riga dell'operaio e non su quella del cantiere: quasi sempre parte
 * tutta la squadra e dormono tutti fuori, ma capita quello che abita vicino
 * e la sera torna a casa. Con un interruttore sul cantiere quel caso non si
 * potrebbe scrivere, e in busta paga finirebbe una trasferta che non c'e'
 * stata. Nella pagina c'e' comunque un "copia a tutti", cosi' il caso
 * frequente resta un tocco solo.
 *
 * Serve anche all'app: quando l'operaio dichiara la cena o l'albergo su un
 * giorno che non era in trasferta, l'ufficio se lo vede segnalato in
 * approvazione invece di accorgersene a fine mese.
 */
final class PianificazioneTrasferta extends AbstractMigration
{
    public function up(): void
    {
        $n = $this->table('bb_pianificazione_nostri');

        if (!$n->hasColumn('trasferta')) {
            $n->addColumn('trasferta', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Dorme fuori: da qui l\'avviso se dichiara cena o hotel senza averla',
                'after'   => 'capo_squadra',
            ]);
            $n->update();
        }
    }

    public function down(): void
    {
        $this->table('bb_pianificazione_nostri')->removeColumn('trasferta')->update();
    }
}
