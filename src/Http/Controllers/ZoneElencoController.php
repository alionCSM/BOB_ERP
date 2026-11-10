<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

/**
 * BOB Zone, tutti i cantieri: l'elenco dei cantieri che hanno qualcosa
 * nella Zone (persone con accesso, attivita', segnalazioni, file, disegni,
 * messaggi), per chi ha il permesso Zone.
 *
 * Da qui si vede in un colpo dove c'e' lavoro aperto e dove il committente
 * e' gia' dentro, senza aprire i cantieri uno alla volta.
 */
final class ZoneElencoController
{
    public function __construct(private \PDO $conn) {}

    public function index(Request $request): void
    {
        $user      = $request->user();
        $companyId = (int)$user->getCompanyId();
        $io        = (int)($user->id ?? 0);

        $societa = $companyId !== 1 ? 'AND w.company_id = :c' : '';
        $st = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code, w.status, w.location,
                   COALESCE(w.zone_cliente, 0) AS zone_cliente,
                   c.name AS cliente,
                   (SELECT COUNT(*) FROM bb_zone_accessi a WHERE a.worksite_id = w.id) AS persone,
                   (SELECT a.ruolo FROM bb_zone_accessi a WHERE a.worksite_id = w.id AND a.user_id = :io LIMIT 1) AS mio_ruolo,
                   (SELECT COUNT(*) FROM bb_zone_tasks t WHERE t.worksite_id = w.id) AS attivita,
                   (SELECT COUNT(*) FROM bb_zone_tasks t WHERE t.worksite_id = w.id AND t.status IN ('open','in_progress')) AS aperte,
                   (SELECT COUNT(*) FROM bb_zone_segnalazioni s WHERE s.worksite_id = w.id AND s.stato <> 'risolta') AS segnalazioni,
                   (SELECT COUNT(*) FROM bb_zone_files f WHERE f.worksite_id = w.id) AS file,
                   (SELECT COUNT(*) FROM bb_worksite_documents d
                     WHERE d.worksite_id = w.id AND d.is_deleted = 0 AND d.file_path LIKE '%/Disegni/%') AS disegni,
                   GREATEST(
                       COALESCE((SELECT MAX(t.updated_at) FROM bb_zone_tasks t WHERE t.worksite_id = w.id), '1900-01-01'),
                       COALESCE((SELECT MAX(t.created_at) FROM bb_zone_tasks t WHERE t.worksite_id = w.id), '1900-01-01'),
                       COALESCE((SELECT MAX(m.created_at) FROM bb_zone_messaggi m WHERE m.worksite_id = w.id), '1900-01-01'),
                       COALESCE((SELECT MAX(s.created_at) FROM bb_zone_segnalazioni s WHERE s.worksite_id = w.id), '1900-01-01'),
                       COALESCE((SELECT MAX(f.created_at) FROM bb_zone_files f WHERE f.worksite_id = w.id), '1900-01-01')
                   ) AS ultima
            FROM bb_worksites w
            LEFT JOIN bb_clients c ON c.id = w.client_id
            WHERE w.is_draft = 0 $societa
              AND (   EXISTS (SELECT 1 FROM bb_zone_accessi a WHERE a.worksite_id = w.id)
                   OR EXISTS (SELECT 1 FROM bb_zone_tasks t WHERE t.worksite_id = w.id)
                   OR EXISTS (SELECT 1 FROM bb_zone_segnalazioni s WHERE s.worksite_id = w.id)
                   OR EXISTS (SELECT 1 FROM bb_zone_files f WHERE f.worksite_id = w.id)
                   OR EXISTS (SELECT 1 FROM bb_zone_messaggi m WHERE m.worksite_id = w.id))
            ORDER BY ultima DESC, w.name
        ");
        $st->execute([':io' => $io] + ($companyId !== 1 ? [':c' => $companyId] : []));
        $cantieri = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($cantieri as &$r) {
            $r['ultima'] = str_starts_with((string)$r['ultima'], '1900') ? null : $r['ultima'];
            foreach (['persone', 'attivita', 'aperte', 'segnalazioni', 'file', 'disegni', 'zone_cliente'] as $k) {
                $r[$k] = (int)$r[$k];
            }
        }
        unset($r);

        $stati = array_values(array_unique(array_filter(array_column($cantieri, 'status'))));
        sort($stati);

        Response::view('zone/elenco.html.twig', $request, [
            'cantieri' => $cantieri,
            'stati'    => $stati,
            'totali'   => [
                'cantieri'     => count($cantieri),
                'aperte'       => array_sum(array_column($cantieri, 'aperte')),
                'segnalazioni' => array_sum(array_column($cantieri, 'segnalazioni')),
                'cliente'      => count(array_filter($cantieri, fn($r) => $r['zone_cliente'] === 1)),
            ],
        ]);
    }
}
