<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Anche l'ufficio entra nella Zone di un cantiere solo se lo mettiamo dentro.
 *
 * Prima il modulo `zone` di BOB faceva due cose insieme: dava il diritto di
 * decidere chi accede, e apriva la Zone di ogni cantiere. Le due cose non
 * sono la stessa: in ufficio chi gestisce gli accessi sono due o tre
 * persone, ma il modulo ce l'hanno in otto, e tutti e otto si ritrovavano
 * dentro ogni cantiere — e con le notifiche di ogni cantiere.
 *
 * Adesso:
 *
 * - il **modulo `zone`** dice chi puo' dare gli accessi. Non apre niente da
 *   solo: chi ce l'ha ed e' fuori da un cantiere vede la scheda del
 *   cantiere come sempre, ma della Zone trova solo "Chi accede", per
 *   aggiungersi o aggiungere qualcuno;
 * - **l'elenco degli accessi** dice chi entra, e adesso ha un ruolo in piu':
 *   `ufficio`. Chi e' li' dentro come ufficio vede tutto quel cantiere e
 *   riceve i suoi avvisi, come prima. Ma di quel cantiere.
 *
 * La conversione non chiude fuori nessuno che fosse gia' dentro: chi ha il
 * modulo `zone` ed era attaccato a un cantiere — per accesso Zone o per la
 * vecchia assegnazione — ci resta, col ruolo `ufficio`. Chi non c'era non
 * entra: e' esattamente il cambiamento voluto, e si rimedia dal pannello
 * "Chi accede" in un clic.
 */
final class ZoneRuoloUfficio extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_accessi')) {
            return;
        }

        // L'enum cresce di un valore. Il default resta 'operaio': una riga
        // scritta senza dire il ruolo non deve nascere ufficio.
        $this->table('bb_zone_accessi')
            ->changeColumn('ruolo', 'enum', [
                'values'  => ['capo', 'operaio', 'cliente', 'ufficio'],
                'null'    => false,
                'default' => 'operaio',
            ])
            ->update();

        // Chi ha il modulo Zone, in un posto solo: il permesso vive in due
        // tabelle (per societa' e per utente) e vanno guardate tutte e due.
        $conZone = "
            SELECT user_id FROM bb_user_company_permissions WHERE module = 'zone' AND allowed = 1
            UNION
            SELECT user_id FROM bb_user_permissions         WHERE module = 'zone' AND allowed = 1
        ";

        // 1. Chi era gia' nell'elenco di un cantiere e ha il modulo: diventa
        //    ufficio. Prima il suo ruolo li' dentro non contava — il modulo
        //    gli apriva tutto comunque — quindi non perde niente.
        $this->execute("
            UPDATE bb_zone_accessi
            SET    ruolo = 'ufficio'
            WHERE  user_id IN ($conZone)
        ");

        // 2. Chi era attaccato al cantiere solo dalla vecchia tabella: entra
        //    nell'elenco come ufficio, se no da domani non aprirebbe piu' un
        //    cantiere che apriva ieri.
        $this->execute("
            INSERT INTO bb_zone_accessi (worksite_id, user_id, ruolo)
            SELECT wu.worksite_id, wu.user_id, 'ufficio'
            FROM   bb_worksite_users wu
            WHERE  wu.user_id IN ($conZone)
              AND  NOT EXISTS (
                    SELECT 1 FROM bb_zone_accessi a
                    WHERE  a.worksite_id = wu.worksite_id AND a.user_id = wu.user_id
              )
        ");
    }

    public function down(): void
    {
        if (!$this->hasTable('bb_zone_accessi')) {
            return;
        }

        // Si torna indietro passando per 'capo': chi era ufficio in un
        // cantiere ci stava per decidere, e lasciarlo operaio gli
        // toglierebbe meta' dei bottoni.
        $this->execute("UPDATE bb_zone_accessi SET ruolo = 'capo' WHERE ruolo = 'ufficio'");

        $this->table('bb_zone_accessi')
            ->changeColumn('ruolo', 'enum', [
                'values'  => ['capo', 'operaio', 'cliente'],
                'null'    => false,
                'default' => 'operaio',
            ])
            ->update();
    }
}
