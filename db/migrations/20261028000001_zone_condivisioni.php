<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * BOB Zone: chi vede una cosa si scrive coi nomi.
 *
 * Prima ogni contenuto aveva un'etichetta — ufficio, squadra, capi,
 * cliente, tutti — e chi la vedeva si ricavava dal ruolo. Funzionava, ma
 * l'ufficio non scegliera' mai "squadra": sceglie Rossi, Bequiri e il
 * geometra del committente. L'etichetta lo obbligava a tradurre quello che
 * aveva in testa in una categoria, e la categoria includeva sempre
 * qualcuno in piu' di quanto voleva.
 *
 * Adesso: una riga per persona per oggetto. Le etichette restano nelle
 * colonne di prima, non le legge piu' nessuno, e un ritorno indietro non
 * perde niente.
 *
 * Due cose non cambiano, perche' senza di loro la Zone si rompe:
 *
 * - **l'ufficio vede tutto** e non sta in questa tabella. Mettercelo
 *   vorrebbe dire aggiungere una riga per ogni impiegato a ogni file, e
 *   dimenticarne uno significa nascondere un documento a chi lo cerca.
 * - **un file senza righe proprie segue la cartella.** L'ufficio decide
 *   una volta per "Contratti" e non file per file.
 *
 * La conversione mantiene esattamente quello che la gente vede oggi:
 * ogni etichetta diventa l'elenco delle persone che quell'etichetta
 * faceva entrare, cantiere per cantiere. Una cosa cambia, ed e'
 * inevitabile avendo chiesto i nomi: **chi viene assegnato al cantiere
 * domani non vede quello che e' stato condiviso ieri** — prima il ruolo
 * glielo apriva da solo. Va aggiunto a mano alle cose che gli servono.
 */
final class ZoneCondivisioni extends AbstractMigration
{
    /** tipo => [tabella, colonna con la vecchia etichetta] */
    private const OGGETTI = [
        'attivita'  => ['bb_zone_tasks',             'visibilita'],
        'cartella'  => ['bb_zone_folders',           'visibilita'],
        'file'      => ['bb_zone_files',             'visibilita'],
        'disegno'   => ['bb_worksite_documents',     'zone_visibilita'],
        'modulo'    => ['bb_zone_form_assegnazioni', 'visibilita'],
        'risposta'  => ['bb_zone_form_submissions',  'visibilita'],
    ];

    public function up(): void
    {
        if (!$this->hasTable('bb_zone_condivisioni')) {
            // Niente chiave esterna: l'utente MySQL di produzione non ha
            // REFERENCES, e una migrazione che li' non passa non serve.
            //
            // La chiave primaria e' (tipo, oggetto_id, user_id) e tutte e tre
            // sono NOT NULL per forza: in MySQL una colonna annullabile dentro
            // una primaria fa fallire la CREATE con "All parts of a PRIMARY KEY
            // must be NOT NULL", e ci siamo gia' cascati una volta.
            $this->table('bb_zone_condivisioni', ['id' => false, 'primary_key' => ['tipo', 'oggetto_id', 'user_id']])
                ->addColumn('tipo', 'enum', [
                    'values' => array_keys(self::OGGETTI),
                    'null'   => false,
                ])
                ->addColumn('oggetto_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('user_id',    'integer', ['null' => false, 'signed' => false])
                ->addColumn('created_by', 'integer', ['null' => true,  'signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                // il verso che si usa a ogni apertura della Zone: "di questa
                // persona, cosa vede"
                ->addIndex(['user_id', 'tipo'], ['name' => 'idx_zcond_persona'])
                ->create();
        }

        // ── Dalle etichette ai nomi ─────────────────────────────────────────
        foreach (self::OGGETTI as $tipo => [$tabella, $colonna]) {
            if (!$this->hasTable($tabella)) {
                continue;
            }
            // Una colonna che non c'e' vuol dire che la migrazione delle
            // etichette non e' passata su questo database: non c'e' niente
            // da convertire, e pretenderlo fermerebbe l'installazione.
            if (!$this->table($tabella)->hasColumn($colonna)) {
                continue;
            }

            // 'ufficio' non genera righe: l'ufficio vede tutto comunque.
            // Un file con l'etichetta vuota nemmeno: segue la cartella.
            $this->execute("
                INSERT IGNORE INTO bb_zone_condivisioni (tipo, oggetto_id, user_id)
                SELECT '{$tipo}', o.id, a.user_id
                FROM   {$tabella} o
                JOIN   bb_zone_accessi a ON a.worksite_id = o.worksite_id
                -- l'ufficio fuori: vede tutto per conto suo, e una riga qui
                -- falserebbe il conteggio che l'ufficio legge sul bottone
                WHERE  a.ruolo <> 'ufficio'
                  AND  o.`{$colonna}` IS NOT NULL
                  AND (
                        o.`{$colonna}` = 'tutti'
                     OR (o.`{$colonna}` = 'squadra' AND a.ruolo IN ('capo', 'operaio'))
                     OR (o.`{$colonna}` = 'capi'    AND a.ruolo = 'capo')
                     OR (o.`{$colonna}` = 'cliente' AND a.ruolo = 'cliente')
                  )
            ");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_condivisioni')) {
            $this->table('bb_zone_condivisioni')->drop()->save();
        }
    }
}
