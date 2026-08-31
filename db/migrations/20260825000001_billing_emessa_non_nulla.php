<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le righe di fatturazione con emessa NULL erano invisibili.
 *
 * L'elenco clienti conta con SUM(b.emessa = 0) e SUM(b.emessa = 1). In SQL
 * NULL = 0 non e' ne' vero ne' falso: e' NULL, e SUM lo salta. Una riga con
 * emessa NULL non finiva quindi ne' fra le "da emettere" ne' fra le
 * "emesse" — spariva dai conteggi, e il cliente sembrava avere solo fatture
 * gia' emesse.
 *
 * Ricompariva aprendo la scheda del cliente, perche' li' parte la
 * sincronizzazione da Yard che scrive 0 o 1 sopra il NULL. Da fuori sembrava
 * che "aprire il cliente" aggiustasse qualcosa, ma stava solo riempiendo un
 * campo che non era mai stato scritto.
 *
 * Qui si fa due volte il lavoro: si riempiono le righe gia' esistenti, e si
 * mette il default a 0 con NOT NULL perche' non ricapiti. Chi crea le righe
 * adesso scrive comunque lo zero da solo (Billing::create), ma un vincolo
 * regge anche per le strade che oggi non prevediamo.
 */
final class BillingEmessaNonNulla extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        // 0 e non 1: una riga di cui non sappiamo niente e' da emettere, non
        // emessa. Se in Yard risultasse gia' emessa, la prima apertura della
        // scheda cliente la corregge — mentre il contrario farebbe sparire
        // una fattura ancora da fare, che e' l'errore piu' caro dei due.
        $n = $pdo->exec("UPDATE bb_billing SET emessa = 0 WHERE emessa IS NULL");
        error_log("[migration] righe di fatturazione rimesse a 0: " . (int)$n);

        $this->table('bb_billing')
            ->changeColumn('emessa', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => '0 = da emettere, 1 = emessa. Mai NULL: vedi migration 20260825000001',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('bb_billing')
            ->changeColumn('emessa', 'boolean', ['null' => true, 'default' => null])
            ->update();
    }
}
