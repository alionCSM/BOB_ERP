<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moduli e permessi configurati per societa' del gruppo.
 *
 * Prima di questa migration c'erano due difetti che si sommavano.
 *
 * 1) I moduli di una societa' stavano in bb_group_companies.moduli, un CSV
 *    dove "vuoto" voleva dire "tutti". Un campo vuoto significava quindi due
 *    cose diverse — "non l'ho ancora configurata" e "le do tutto" — e non
 *    c'era modo di distinguerle. Peggio: siccome mezzo BOB decideva cosa
 *    mostrare guardando se quel campo era vuoto, il solo fatto di spuntare
 *    dei moduli su una societa' ne cambiava la dashboard.
 *    Ora la scelta e' esplicita: `tutti_moduli` dice "tutti, anche quelli che
 *    aggiungeremo", altrimenti valgono le righe di bb_company_modules.
 *
 * 2) I permessi stavano in bb_user_permissions, per utente e basta. Ma un
 *    utente puo' stare in piu' societa', e cosi' non si poteva dire "in CSM
 *    lavora in ufficio, in Poti e' un tecnico": dandogli un modulo glielo si
 *    dava ovunque. Ora i permessi sono per utente E societa'.
 *
 * bb_user_permissions non sparisce: resta come proiezione "questo utente ha
 * il permesso in almeno una societa'", che e' l'unica domanda sensata fuori
 * da una richiesta web (cron notturni, mail di avviso: li' non c'e' nessuna
 * societa' attiva). La riscrive AccessControl a ogni salvataggio.
 */
final class PermessiPerSocieta extends AbstractMigration
{
    public function up(): void
    {
        $this->creaTabelle();
        $this->portaModuliSocieta();
        $this->portaPermessiUtenti();
    }

    public function down(): void
    {
        // i dati d'origine (moduli CSV e bb_user_permissions) non sono mai
        // stati toccati, quindi tornare indietro e' solo togliere il nuovo
        if ($this->hasTable('bb_user_company_permissions')) {
            $this->table('bb_user_company_permissions')->drop()->save();
        }
        if ($this->hasTable('bb_company_modules')) {
            $this->table('bb_company_modules')->drop()->save();
        }
        if ($this->table('bb_group_companies')->hasColumn('tutti_moduli')) {
            $this->table('bb_group_companies')->removeColumn('tutti_moduli')->save();
        }
    }

    // ── Struttura ───────────────────────────────────────────────────────────

    private function creaTabelle(): void
    {
        if (!$this->table('bb_group_companies')->hasColumn('tutti_moduli')) {
            $this->table('bb_group_companies')
                ->addColumn('tutti_moduli', 'boolean', [
                    'default' => false,
                    'null'    => false,
                    'after'   => 'moduli',
                    'comment' => 'Tutti i moduli, compresi quelli aggiunti in futuro',
                ])
                ->update();
        }

        if (!$this->hasTable('bb_company_modules')) {
            $this->table('bb_company_modules', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('modulo', 'string', ['limit' => 60, 'null' => false])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['group_company_id', 'modulo'], ['unique' => true, 'name' => 'uq_societa_modulo'])
                ->create();
        }

        if (!$this->hasTable('bb_user_company_permissions')) {
            // niente chiavi esterne: l'utente MySQL di produzione non ha il
            // privilegio REFERENCES, come per tutte le altre tabelle di BOB
            $this->table('bb_user_company_permissions', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('group_company_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('module', 'string', ['limit' => 60, 'null' => false])
                ->addColumn('allowed', 'boolean', ['default' => false, 'null' => false])
                ->addIndex(['user_id', 'group_company_id', 'module'], ['unique' => true, 'name' => 'uq_utente_societa_modulo'])
                ->addIndex(['module', 'allowed'], ['name' => 'idx_modulo_allowed'])
                ->create();
        }
    }

    // ── Travaso ─────────────────────────────────────────────────────────────

    /**
     * Moduli: il CSV diventa righe, e il CSV vuoto diventa il flag
     * "tutti_moduli". Cosi' il giorno dopo la migration ogni societa' si
     * comporta esattamente come il giorno prima.
     */
    private function portaModuliSocieta(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $societa = $pdo->query('SELECT id, moduli FROM bb_group_companies')
                       ->fetchAll(\PDO::FETCH_ASSOC);

        $tutti = $pdo->prepare('UPDATE bb_group_companies SET tutti_moduli = 1 WHERE id = :id');
        $ins   = $pdo->prepare(
            'INSERT IGNORE INTO bb_company_modules (group_company_id, modulo) VALUES (:id, :m)'
        );

        foreach ($societa as $s) {
            $id  = (int)$s['id'];
            $csv = trim((string)($s['moduli'] ?? ''));

            if ($csv === '') {
                $tutti->execute([':id' => $id]);
                continue;
            }

            foreach (array_unique(array_filter(array_map('trim', explode(',', $csv)))) as $modulo) {
                $ins->execute([':id' => $id, ':m' => $modulo]);
            }
        }
    }

    /**
     * Permessi: ogni riga di bb_user_permissions viene copiata su tutte le
     * societa' dell'utente. E' quello che di fatto succedeva prima, visto
     * che il permesso valeva ovunque; da adesso in poi pero' si possono
     * scollegare fra loro.
     *
     * Chi non ha nessuna societa' assegnata lavora nel Consorzio (id 1), la
     * stessa regola che applica CurrentCompany al login.
     */
    private function portaPermessiUtenti(): void
    {
        $this->execute("
            INSERT IGNORE INTO bb_user_company_permissions (user_id, group_company_id, module, allowed)
            SELECT p.user_id, uc.group_company_id, p.module, p.allowed
            FROM   bb_user_permissions p
            JOIN   bb_user_companies uc ON uc.user_id = p.user_id
        ");

        $this->execute("
            INSERT IGNORE INTO bb_user_company_permissions (user_id, group_company_id, module, allowed)
            SELECT p.user_id, 1, p.module, p.allowed
            FROM   bb_user_permissions p
            WHERE  NOT EXISTS (
                       SELECT 1 FROM bb_user_companies uc WHERE uc.user_id = p.user_id
                   )
        ");
    }
}
