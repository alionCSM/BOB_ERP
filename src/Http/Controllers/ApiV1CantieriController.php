<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

/**
 * Cantieri per l'app Android (rotte /api/v1/worksites).
 *
 * Non riusa WorksitesController::show(): quello prepara la pagina web e mette
 * insieme una quarantina di variabili — fatture, extra, disegni, note
 * finanziarie, attivita' — con decine di query. Su un telefono in cantiere,
 * spesso con mezza tacca di rete, sarebbe lento e per lo piu' inutile: chi
 * apre il cantiere dall'app vuole sapere come sta andando, non rifare la
 * contabilita'.
 *
 * Qui c'e' il sottoinsieme che serve davvero, con le STESSE query del web
 * dove i numeri devono coincidere (presenze equivalenti, squadra): se
 * l'app dicesse 12 giornate e il sito 13, nessuno si fiderebbe piu' di
 * nessuno dei due.
 *
 *   GET /api/v1/worksites          elenco con filtri e contatori
 *   GET /api/v1/worksites/{id}     scheda del singolo cantiere
 */
final class ApiV1CantieriController
{
    /** Come nel web: oltre questa soglia l'elenco non si sfoglia piu'. */
    private const LIMITE = 200;

    public function __construct(private \PDO $conn) {}

    // ── Elenco ───────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/worksites?status=&year=&q=
     *
     * Stessi filtri della pagina web, cosi' chi passa dal browser al telefono
     * ritrova gli stessi cantieri con le stesse scelte.
     */
    public function elenco(Request $request): never
    {
        $user = $this->utente($request);

        $stato  = (string)($_GET['status'] ?? 'In corso');
        $anno   = (string)($_GET['year'] ?? '');
        $cerca  = trim((string)($_GET['q'] ?? ''));

        $companyId = (int)($user->getCompanyId() ?? 0);

        // stesso repository del web: i filtri devono comportarsi identici,
        // riscriverne una seconda versione qui vorrebbe dire vederle divergere
        $repo = new \App\Repository\Worksites\WorksiteRepository($this->conn);

        $cantieri = $repo->getAllByCompany(
            $companyId,
            $stato === 'all' ? '' : $stato,
            $anno,
            '',
            $cerca,
            self::LIMITE
        );

        $prezzi = $user->canSeePrices();
        $aperti = $this->taskApertiPerCantiere(array_column($cantieri, 'id'));

        $out = [];
        foreach ($cantieri as $c) {
            $id = (int)$c['id'];
            $out[] = [
                'id'          => $id,
                'codice'      => (string)($c['worksite_code'] ?? ''),
                'nome'        => (string)($c['worksite_name'] ?? ''),
                'cliente'     => (string)($c['client_name'] ?? ''),
                'luogo'       => (string)($c['location'] ?? ''),
                'stato'       => (string)($c['status'] ?? ''),
                'dal'         => (string)($c['start_date'] ?? ''),
                'al'          => (string)($c['end_date'] ?? ''),
                // il margine e' un dato economico: lo vede solo chi puo'
                'margine'     => $prezzi && $c['margin'] !== null ? (float)$c['margin'] : null,
                'task_aperti' => $aperti[$id] ?? 0,
            ];
        }

        Response::json([
            'success'  => true,
            'filtri'   => [
                'stato' => $stato,
                'anno'  => $anno,
                'q'     => $cerca,
                'stati' => ['In corso', 'Completato', 'Sospeso', 'A rischio'],
                'anni'  => $this->anniDisponibili(),
            ],
            'conta'    => $this->contatori($companyId),
            'cantieri' => $out,
        ]);
    }

    // ── Scheda ───────────────────────────────────────────────────────────────

    /** GET /api/v1/worksites/{id} */
    public function scheda(Request $request): never
    {
        $user = $this->utente($request);
        $id   = $request->intParam('id');

        if ($id === 0) {
            Response::json(['success' => false, 'message' => 'Cantiere non valido'], 400);
        }

        $stmt = $this->conn->prepare("
            SELECT w.id, w.worksite_code, w.name, w.location, w.status,
                   w.start_date, w.end_date, w.order_number, w.order_date,
                   w.total_offer, w.ext_total_offer, w.company_id,
                   w.fieldwire_enabled_at,
                   c.name AS client_name,
                   fs.margin
            FROM   bb_worksites w
            LEFT JOIN bb_clients c ON c.id = w.client_id
            LEFT JOIN bb_worksite_financial_status fs ON fs.worksite_id = w.id
            WHERE  w.id = :id
            LIMIT  1
        ");
        $stmt->execute([':id' => $id]);
        $w = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$w) {
            Response::json(['success' => false, 'message' => 'Cantiere non trovato'], 404);
        }

        // Un utente legato a una sola azienda non deve vedere i cantieri
        // delle altre, nemmeno conoscendone l'id.
        $companyId = (int)($user->getCompanyId() ?? 0);
        if ($companyId !== 1 && (int)$w['company_id'] !== $companyId) {
            Response::json(['success' => false, 'message' => 'Cantiere non accessibile'], 403);
        }

        $prezzi = $user->canSeePrices();

        Response::json([
            'success'  => true,
            'cantiere' => [
                'id'       => $id,
                'codice'   => (string)($w['worksite_code'] ?? ''),
                'nome'     => (string)($w['name'] ?? ''),
                'cliente'  => (string)($w['client_name'] ?? ''),
                'luogo'    => (string)($w['location'] ?? ''),
                'stato'    => (string)($w['status'] ?? ''),
                'dal'      => (string)($w['start_date'] ?? ''),
                'al'       => (string)($w['end_date'] ?? ''),
                'ordine'   => (string)($w['order_number'] ?? ''),
                'ordineData' => (string)($w['order_date'] ?? ''),
            ],
            'avanzamento' => $this->avanzamento($id),
            'squadra'     => $this->squadra($id),
            // Economia solo a chi puo' vedere i prezzi: e' lo stesso
            // permesso view_prices del web, non una regola nuova dell'app.
            'economia'    => $prezzi ? [
                'contratto' => (float)(($companyId === 1 ? $w['total_offer'] : $w['ext_total_offer']) ?? 0),
                'margine'   => $w['margin'] !== null ? (float)$w['margin'] : null,
            ] : null,
            // BOB Zone funziona su OGNI cantiere: le tabelle bb_zone_* sono
            // la fonte, non una copia di Fieldwire. fieldwire_enabled_at dice
            // solo se il cantiere e' collegato a Fieldwire per la sincronia,
            // ed e' un'altra cosa — prendendolo per "Zone attivo" l'app
            // diceva "non attivo su questo cantiere" praticamente sempre.
            'zone'        => [
                // il permesso e' separato da 'worksites': si possono vedere i
                // cantieri senza poter entrare in Zone
                'permesso'   => (int)$user->id === 1 || $user->canAccess('zone'),
                'taskAperti' => $this->taskAperti($id),
                'fieldwire'  => !empty($w['fieldwire_enabled_at']),
            ],
        ]);
    }

    // ── Pezzi ────────────────────────────────────────────────────────────────

    /**
     * Presenze in giornate equivalenti: mezzo turno vale mezza giornata.
     * Stesse due query del web (WorksitesController::show), perche' i due
     * numeri devono coincidere.
     */
    private function avanzamento(int $id): array
    {
        $stmt = $this->conn->prepare("
            SELECT SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END)
            FROM bb_presenze WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $id]);
        $nostri = (float)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(quantita),0) FROM bb_presenze_consorziate WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $id]);
        $consorziate = (float)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("
            SELECT MAX(data) FROM bb_presenze WHERE worksite_id = :wid
        ");
        $stmt->execute([':wid' => $id]);
        $ultima = (string)($stmt->fetchColumn() ?: '');

        return [
            'giornateNostri'      => $nostri,
            'giornateConsorziate' => $consorziate,
            'giornateTotali'      => $nostri + $consorziate,
            'ultimaPresenza'      => $ultima,
        ];
    }

    /**
     * Quanti task ci sono ancora da fare.
     *
     * Serve a dare un motivo per entrare in Zone: "3 task aperti" dice
     * qualcosa, "Task, file, moduli e disegni" e' solo un elenco di parole.
     */
    private function taskAperti(int $id): int
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) FROM bb_zone_tasks
                WHERE worksite_id = :wid AND status IN ('open', 'in_progress')
            ");
            $stmt->execute([':wid' => $id]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // tabella non ancora creata: meglio nessun numero che una scheda
            // che non si apre
            return 0;
        }
    }

    /** @return array<int, array{nome:string}> */
    private function squadra(int $id): array
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''),
                            u.username) AS nome
            FROM   bb_worksite_users wu
            JOIN   bb_users u ON u.id = wu.user_id
            WHERE  wu.worksite_id = :wid
            ORDER BY u.first_name
        ");
        $stmt->execute([':wid' => $id]);

        return array_map(
            static fn(string $n): array => ['nome' => $n],
            array_filter($stmt->fetchAll(\PDO::FETCH_COLUMN))
        );
    }

    /**
     * Quanti task aperti ha ciascun cantiere dell'elenco.
     *
     * Prima qui si guardava fieldwire_enabled_at per dire "Zone attivo": e'
     * sbagliato due volte. Zone funziona su OGNI cantiere — le bb_zone_* sono
     * la fonte, non una copia di Fieldwire — e quel campo dice solo se il
     * cantiere e' collegato a Fieldwire per la sincronia. Il risultato era
     * che l'icona non compariva quasi mai, e nella scheda il pulsante per
     * entrare in Zone restava spento.
     *
     * Il numero dei task aperti e' anche piu' utile: dice dove c'e' del
     * lavoro, invece di ripetere su ogni riga una cosa sempre vera.
     *
     * Una query sola per tutta la pagina: con 200 cantieri, una per riga
     * sarebbero 200 andate e ritorno al database.
     *
     * @param  array<int, mixed> $ids
     * @return array<int, int>   id cantiere => task aperti
     */
    private function taskApertiPerCantiere(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        try {
            $segnaposto = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->conn->prepare("
                SELECT worksite_id, COUNT(*) AS n
                FROM   bb_zone_tasks
                WHERE  worksite_id IN ({$segnaposto})
                  AND  status IN ('open', 'in_progress')
                GROUP BY worksite_id
            ");
            $stmt->execute($ids);

            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['worksite_id']] = (int)$r['n'];
            }
            return $out;
        } catch (\Throwable $e) {
            // tabella non ancora creata: l'elenco dei cantieri deve aprirsi
            // lo stesso, senza il contatore
            error_log('[ApiV1Cantieri] taskAperti: ' . $e->getMessage());
            return [];
        }
    }

    /** @return int[] */
    private function anniDisponibili(): array
    {
        $anni = $this->conn->query(
            "SELECT DISTINCT YEAR(start_date) AS anno FROM bb_worksites
             WHERE start_date IS NOT NULL ORDER BY anno DESC"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('intval', $anni)));
    }

    /**
     * I quattro contatori in cima all'elenco, come nel web.
     *
     * "In corso" conta solo i cantieri che hanno almeno una presenza
     * registrata: uno aperto in anagrafica ma dove non e' ancora andato
     * nessuno non e' un cantiere in corso.
     */
    private function contatori(int $companyId): array
    {
        $filtro   = $companyId !== 1 ? 'AND w.company_id = ' . $companyId : '';
        $contratto = $companyId === 1 ? 'w.total_offer' : 'w.ext_total_offer';

        $riga = $this->conn->query("
            SELECT
                COUNT(DISTINCT CASE WHEN w.status IN ('Attivo','In corso') AND (
                        EXISTS (SELECT 1 FROM bb_presenze p WHERE p.worksite_id = w.id)
                     OR EXISTS (SELECT 1 FROM bb_presenze_consorziate pc WHERE pc.worksite_id = w.id))
                    THEN w.id END) AS in_corso,
                COUNT(DISTINCT CASE WHEN w.status = 'Completato' THEN w.id END) AS completato,
                COUNT(DISTINCT CASE WHEN w.status = 'Sospeso' THEN w.id END) AS sospeso,
                COUNT(DISTINCT CASE WHEN w.status IN ('Attivo','In corso') AND (
                        EXISTS (SELECT 1 FROM bb_presenze p WHERE p.worksite_id = w.id)
                     OR EXISTS (SELECT 1 FROM bb_presenze_consorziate pc WHERE pc.worksite_id = w.id))
                     AND (fs.margin < 0 OR ((fs.margin / NULLIF({$contratto},0))*100 <= 30))
                    THEN w.id END) AS a_rischio
            FROM bb_worksites w
            LEFT JOIN bb_worksite_financial_status fs ON fs.worksite_id = w.id
            WHERE w.is_draft = 0 {$filtro}
        ")->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'inCorso'    => (int)($riga['in_corso'] ?? 0),
            'completato' => (int)($riga['completato'] ?? 0),
            'sospeso'    => (int)($riga['sospeso'] ?? 0),
            'aRischio'   => (int)($riga['a_rischio'] ?? 0),
        ];
    }

    /**
     * Chi puo' vedere i cantieri: lo stesso permesso del web, nessuna regola
     * nuova introdotta dall'app.
     */
    private function utente(Request $request): \User
    {
        $user = $request->user();
        if (!$user) {
            Response::json(['success' => false, 'message' => 'Accesso negato'], 403);
        }
        if ((int)$user->id !== 1 && !$user->canAccess('worksites')) {
            Response::json(['success' => false, 'message' => "Permesso 'worksites' richiesto"], 403);
        }
        return $user;
    }
}
