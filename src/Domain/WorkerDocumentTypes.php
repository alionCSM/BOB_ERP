<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Single source of truth per i tipi di documento operaio.
 *
 * Usato da:
 *   - views/documents/documenti_aziendali.php (datalist nel modal di upload)
 *   - public/assets/js/views/documents/documenti_aziendali.js (validity defaults)
 *   - App\Service\DocumentVerifierService (cron notturno + suggerimento AI)
 *
 * Per aggiungere un tipo:
 *   1. Aggiungi qui sotto.
 *   2. Se vuoi anche un default scadenza, aggiungi in
 *      WORKER_DOC_VALIDITY in documenti_aziendali.js.
 */
final class WorkerDocumentTypes
{
    /** @return string[] */
    public static function all(): array
    {
        return [
            "Documento d'identit\u{00E0}",
            'Verbale consegna DPI',
            'Visita medica',
            'Unilav',
            'Formazione sicurezza',
            'Lavori in quota DPI',
            'Piattaforma',
            'Carrello elevatore',
            'Braccio telescopico',
            'Preposto',
            'Antincendio',
            'Primo soccorso',
            'Gru a torre',
            'Gru mobile',
            'Saldatura',
        ];
    }
}
