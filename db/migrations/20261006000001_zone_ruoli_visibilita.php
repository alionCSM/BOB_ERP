<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * BOB Zone: un ruolo per persona, e per ogni contenuto chi lo vede.
 *
 * Prima c'erano sei livelli per persona per cantiere (attivita, file,
 * moduli, disegni, foto, report: 0, 1, 2). Dicevano QUALI SEZIONI uno apre,
 * non QUALI COSE vede: un capo con "File: vede" vedeva anche offerte,
 * contratti e lettere al cliente. E sei numeri per persona per cantiere,
 * con centoquaranta operai, non li tiene aggiornati nessuno.
 *
 * Adesso:
 *
 * - **ruolo** sulla persona: capo, operaio, cliente. L'ufficio resta chi ha
 *   il modulo `zone` in BOB e vede tutto. L'accesso resta dato a mano.
 *
 * - **visibilita** sul contenuto: ufficio, squadra (capi e operai), capi,
 *   cliente (ufficio e cliente), tutti. Il filtro si fa sul server: una cosa
 *   che uno non deve vedere non arriva nemmeno al suo telefono.
 *
 * - i file prendono la visibilita' della cartella (`NULL` = come la
 *   cartella; nella radice vale "squadra"), cosi' l'ufficio decide una volta
 *   per "Contratti" e non file per file.
 *
 * - i commenti possono essere **nota interna** (solo ufficio), le foto
 *   **per il cliente** (le uniche che un cliente vede di un'attivita').
 *
 * - il cliente entra solo se l'ufficio accende **Condividi col cliente** sul
 *   cantiere: senza, anche un account cliente assegnato non vede niente.
 *
 * - i moduli diventano **da compilare**: un modello assegnato a una persona
 *   o a un ruolo, una volta, ogni giorno o ogni settimana, con scadenza.
 *
 * I sei livelli restano nella tabella finche' le pagine nuove non sono in
 * uso: il ruolo si ricava da loro, e un ritorno indietro non perde niente.
 */
final class ZoneRuoliVisibilita extends AbstractMigration
{
    private const VISIBILITA = ['ufficio', 'squadra', 'capi', 'cliente', 'tutti'];

    public function up(): void
    {
        // ── Chi: il ruolo sul cantiere ──────────────────────────────────────
        $a = $this->table('bb_zone_accessi');
        if (!$a->hasColumn('ruolo')) {
            $a->addColumn('ruolo', 'enum', [
                'values'  => ['capo', 'operaio', 'cliente'],
                'null'    => false,
                'default' => 'operaio',
                'after'   => 'user_id',
            ])->update();

            // chi poteva modificare qualcosa era, di fatto, un capo; gli
            // account cliente diventano clienti
            $this->execute("
                UPDATE bb_zone_accessi a
                LEFT JOIN bb_users u ON u.id = a.user_id
                SET a.ruolo = CASE
                    WHEN u.type = 'client' THEN 'cliente'
                    WHEN GREATEST(a.attivita, a.file, a.moduli, a.disegni, a.foto, a.report) >= 2 THEN 'capo'
                    ELSE 'operaio'
                END
            ");
        }

        $w = $this->table('bb_worksites');
        if (!$w->hasColumn('zone_cliente')) {
            $w->addColumn('zone_cliente', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => 'Il cliente vede nella Zone quello che gli e\' condiviso',
            ])->update();
        }

        // ── Cosa: chi vede ogni contenuto ───────────────────────────────────
        $vis = fn(?string $default) => [
            'values'  => self::VISIBILITA,
            'null'    => $default === null,
            'default' => $default,
        ];

        $t = $this->table('bb_zone_tasks');
        if (!$t->hasColumn('visibilita')) {
            $t->addColumn('visibilita', 'enum', $vis('squadra') + ['after' => 'priority'])
              // a chi e' assegnata: una persona (assignee_user_id), oppure
              // tutta la squadra, i capi, l'ufficio, il cliente
              ->addColumn('assegnata_a', 'enum', [
                  'values'  => ['persona', 'squadra', 'capi', 'ufficio', 'cliente'],
                  'null'    => false,
                  'default' => 'squadra',
                  'after'   => 'assignee_user_id',
              ])
              ->update();
            $this->execute("UPDATE bb_zone_tasks SET assegnata_a = 'persona' WHERE assignee_user_id IS NOT NULL");
        }

        $c = $this->table('bb_zone_task_comments');
        if (!$c->hasColumn('interna')) {
            $c->addColumn('interna', 'boolean', ['null' => false, 'default' => false, 'after' => 'file_url'])
              ->addColumn('per_cliente', 'boolean', ['null' => false, 'default' => false, 'after' => 'interna'])
              ->addColumn('author_user_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'author_name'])
              ->update();
        }

        $f = $this->table('bb_zone_folders');
        if (!$f->hasColumn('visibilita')) {
            $f->addColumn('visibilita', 'enum', $vis('squadra') + ['after' => 'name'])->update();
        }

        $fi = $this->table('bb_zone_files');
        if (!$fi->hasColumn('visibilita')) {
            $fi->addColumn('visibilita', 'enum', $vis(null) + ['after' => 'folder_id'])->update();
        }

        $d = $this->table('bb_worksite_documents');
        if (!$d->hasColumn('zone_visibilita')) {
            $d->addColumn('zone_visibilita', 'enum', $vis('squadra') + [
                'comment' => 'Chi vede il disegno nella Zone',
            ])->update();
        }

        $s = $this->table('bb_zone_form_submissions');
        if (!$s->hasColumn('visibilita')) {
            $s->addColumn('visibilita', 'enum', $vis('ufficio') + ['after' => 'source'])
              ->addColumn('assegnazione_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'template_id'])
              ->update();
        }

        // ── Moduli da compilare ─────────────────────────────────────────────
        if (!$this->hasTable('bb_zone_form_assegnazioni')) {
            $this->table('bb_zone_form_assegnazioni')
                ->addColumn('worksite_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('template_id', 'integer', ['null' => false, 'signed' => false])
                // a una persona precisa, o a tutti quelli con quel ruolo
                ->addColumn('a_ruolo', 'enum', [
                    'values' => ['capo', 'operaio', 'cliente', 'ufficio'],
                    'null'   => true,
                ])
                ->addColumn('a_user_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('frequenza', 'enum', [
                    'values'  => ['una_volta', 'giornaliera', 'settimanale'],
                    'null'    => false,
                    'default' => 'una_volta',
                ])
                ->addColumn('scadenza', 'date', ['null' => true])
                // chi vede le risposte: il modulo DPI lo legge solo l'ufficio,
                // il verbale di consegna anche il cliente
                ->addColumn('visibilita', 'enum', $vis('ufficio'))
                ->addColumn('attiva', 'boolean', ['null' => false, 'default' => true])
                ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['worksite_id', 'attiva'], ['name' => 'idx_zfa_cantiere'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_form_assegnazioni')) {
            $this->table('bb_zone_form_assegnazioni')->drop()->save();
        }
        $this->table('bb_zone_form_submissions')->removeColumn('visibilita')->removeColumn('assegnazione_id')->update();
        $this->table('bb_worksite_documents')->removeColumn('zone_visibilita')->update();
        $this->table('bb_zone_files')->removeColumn('visibilita')->update();
        $this->table('bb_zone_folders')->removeColumn('visibilita')->update();
        $this->table('bb_zone_task_comments')->removeColumn('interna')->removeColumn('per_cliente')->removeColumn('author_user_id')->update();
        $this->table('bb_zone_tasks')->removeColumn('visibilita')->removeColumn('assegnata_a')->update();
        $this->table('bb_worksites')->removeColumn('zone_cliente')->update();
        $this->table('bb_zone_accessi')->removeColumn('ruolo')->update();
    }
}
