<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Una segnalazione appuntata su un punto del disegno.
 *
 * "Manca un tirante" non dice niente finche' non si sa dove. Oggi si
 * scrive, si allega una foto, e chi legge cerca di capire in che campata
 * siamo: dieci messaggi per arrivare a un punto che si indicherebbe col
 * dito in due secondi.
 *
 * Il posto dove metterla c'era gia': `bb_zone_annotations` tiene il
 * documento, la pagina e la geometria, e sa gia' puntare a un'attivita'
 * (`task_id`). Manca una colonna: lo stesso spillo, verso una
 * segnalazione.
 *
 * Due colonne separate e non una sola polimorfa: un'annotazione puo'
 * essere tutte e due le cose — una segnalazione che diventa un'attivita'
 * resta dov'e' e si porta dietro il suo spillo — e una colonna "tipo +
 * id" costringerebbe a scegliere.
 */
final class SegnalazioneSulDisegno extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bb_zone_annotations')) {
            return;
        }

        $t = $this->table('bb_zone_annotations');
        if (!$t->hasColumn('segnalazione_id')) {
            // Niente chiave esterna: l'utente MySQL di produzione non ha
            // REFERENCES. Una segnalazione cancellata lascia lo spillo
            // orfano, e la query lo scarta con una LEFT JOIN.
            $t->addColumn('segnalazione_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'after'   => 'task_id',
                'comment' => 'La segnalazione appuntata qui sopra',
            ])
              ->addIndex(['segnalazione_id'], ['name' => 'idx_ann_segnalazione'])
              ->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('bb_zone_annotations')
            && $this->table('bb_zone_annotations')->hasColumn('segnalazione_id')) {
            $this->table('bb_zone_annotations')->removeColumn('segnalazione_id')->update();
        }
    }
}
