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

    // ── Le schede del cantiere, come sul sito ────────────────────────────────
    //
    // Le stesse schede della pagina del cantiere: presenze nostre, presenze
    // delle consorziate, mezzi di sollevamento, e la parte economica
    // (fatturazione, extra, ordini, costi). Dal telefono si guardano: si
    // inserisce e si modifica dal sito. I prezzi solo a chi li vede sul sito.

    /**
     * GET /api/v1/worksites/{id}/presenze?dal=&al=
     * Le presenze dei nostri, dalla piu' recente. Senza date: le ultime 500.
     */
    public function presenze(Request $request): never
    {
        [$user, $id] = $this->cantiere($request);
        $prezzi = $user->canSeePrices();

        $where  = 'p.worksite_id = :id';
        $par    = [':id' => $id];
        foreach (['dal' => '>=', 'al' => '<='] as $k => $op) {
            $v = (string)($_GET[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $where .= " AND p.data $op :$k";
                $par[":$k"] = $v;
            }
        }
        // stessa query di AttendanceRepository::getByWorksiteId, con le date
        $st = $this->conn->prepare("
            SELECT p.*, CONCAT(w.first_name, ' ', w.last_name) AS lavoratore
            FROM bb_presenze p
            JOIN bb_workers w ON p.worker_id = w.id
            WHERE $where
            ORDER BY p.data DESC, w.last_name, w.first_name
            LIMIT 500
        ");
        $st->execute($par);

        $righe = array_map(fn(array $p) => [
            'id'      => (int)$p['id'],
            'data'    => $p['data'],
            'nome'    => trim((string)$p['lavoratore']),
            'azienda' => $p['azienda'] ?? null,
            'turno'   => $p['turno'] ?? null,
            'pranzo'  => $p['pranzo'] ?? null,
            'cena'    => $p['cena'] ?? null,
            'hotel'   => $p['hotel'] ?? null,
            'auto'    => $p['targa_auto'] ?? null,
            'note'    => $p['note'] ?? null,
            'pranzo_prezzo' => $prezzi && isset($p['pranzo_prezzo']) ? (float)$p['pranzo_prezzo'] : null,
            'cena_prezzo'   => $prezzi && isset($p['cena_prezzo']) ? (float)$p['cena_prezzo'] : null,
        ], $st->fetchAll(\PDO::FETCH_ASSOC));

        Response::json([
            'success'  => true,
            'giornate' => $this->avanzamento($id)['giornateNostri'],
            'presenze' => $righe,
        ]);
    }

    /** GET /api/v1/worksites/{id}/consorziate — le giornate delle consorziate. */
    public function consorziate(Request $request): never
    {
        [$user, $id] = $this->cantiere($request);
        $prezzi = $user->canSeePrices();
        $righe = (new \App\Repository\AttendanceRepository($this->conn))
            ->getConsorziateFiltered(null, null, $id, null, 1000);

        Response::json([
            'success'  => true,
            'giornate' => $this->avanzamento($id)['giornateConsorziate'],
            'presenze' => array_map(fn(array $p) => [
                'id'       => (int)$p['id'],
                'data'     => $p['data_presenza'],
                'azienda'  => $p['consorziata_name'],
                'quantita' => (float)$p['quantita'],
                'pasti'    => $p['pasti'],
                'auto'     => $p['auto'],
                'hotel'    => $p['hotel'],
                'note'     => $p['note'],
                'costo'    => $prezzi && $p['costo_unitario'] !== null ? (float)$p['costo_unitario'] : null,
            ], $righe),
        ]);
    }

    /** GET /api/v1/worksites/{id}/mezzi — i mezzi di sollevamento noleggiati. */
    public function mezzi(Request $request): never
    {
        [$user, $id] = $this->cantiere($request);
        $prezzi = $user->canSeePrices();
        $righe = (new \App\Repository\Equipment\EquipmentRepository($this->conn))->getByWorksite($id);

        Response::json([
            'success' => true,
            'mezzi'   => array_map(fn(array $m) => [
                'id'          => (int)$m['id'],
                'mezzo'       => $m['mezzo_descrizione'],
                'tipo'        => $m['tipo_noleggio'] ?? null,
                'stato'       => $m['stato'] ?? null,
                'quantita'    => isset($m['quantita']) ? (float)$m['quantita'] : null,
                'dal'         => $m['data_inizio'] ?? null,
                'al'          => $m['data_fine'] ?? null,
                'calendario'  => $m['calendario'] ?? null,
                'festivi'     => (bool)($m['festivi_inclusi'] ?? false),
                'giorni_extra'=> (int)($m['extra_days_count'] ?? 0),
                'costo_giorno'=> $prezzi && isset($m['costo_giornaliero']) ? (float)$m['costo_giornaliero'] : null,
            ], $righe),
        ]);
    }

    /**
     * GET /api/v1/worksites/{id}/economia
     *
     * La fatturazione, gli extra, gli ordini alle consorziate e i costi: le
     * stesse cifre della pagina del cantiere (WorksitesController::show),
     * con gli stessi conti. Solo a chi vede i prezzi.
     *
     * Se Yard non risponde la scheda si apre lo stesso, senza lo stato
     * delle fatture: 'yard' => false lo dice.
     */
    public function economia(Request $request): never
    {
        [$user, $id] = $this->cantiere($request);
        if (!$user->canSeePrices()) {
            Response::json(['success' => false, 'message' => 'Non vedi i prezzi'], 403);
        }

        $ws     = new \App\Domain\Worksite($this->conn, $id);
        $stats  = new \App\Domain\WorksiteStats($this->conn, $id);
        $somma  = $stats->getSummary();
        $costi  = $somma['costi'];
        $ricavi = $somma['ricavi'];
        $margine = $somma['andamento'];

        // lo storico di Yard sommato ai numeri di BOB, come sul sito
        try {
            $st = $this->conn->prepare("
                SELECT * FROM bb_cantiere_stats_2025
                WHERE cantiere_id_sqlsrv = (SELECT yard_worksite_id FROM bb_worksites WHERE id = :wid LIMIT 1)
            ");
            $st->execute([':wid' => $id]);
            $y = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $y = null;
        }
        if ($y) {
            $margine -= (float)$y['totale_complessivo'];
            $costi['nostri']       += (float)$y['presenze_consorzio_costo'];
            $costi['ordini']       += (float)$y['correct_costo_consorziate'];
            $costi['mezzi']        += (float)$y['mezzi_costo'];
            $costi['hotel']        += (float)$y['hotel_costo'];
            $costi['pasti_nostri'] += (float)$y['pasti_costo'];
            $costi['tot_costi']    += (float)$y['presenze_consorzio_costo'] + (float)$y['correct_costo_consorziate']
                                    + (float)$y['mezzi_costo'] + (float)$y['hotel_costo'] + (float)$y['pasti_costo'];
        }

        // le righe di fatturazione, con lo stato vero da Yard
        $fatture = (new \App\Repository\Billing\BillingRepository($this->conn))->getByWorksiteId($id);
        $yard    = (new \App\Infrastructure\Config())->yardConfigured();
        $info    = [];
        if ($yard) {
            try {
                $info = (new \App\Domain\YardWorksiteBilling(
                    new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config())
                ))->getDocumentInfoMap(
                    array_column(array_filter($fatture, fn($f) => !empty($f['yard_id'])), 'yard_id')
                );
            } catch (\Throwable $e) {
                error_log('[ApiV1Cantieri] Yard non raggiungibile: ' . $e->getMessage());
                $yard = false;
            }
        }
        $totFatture = 0.0;
        $totEmesse  = 0.0;
        $righeFatt  = [];
        foreach ($fatture as $f) {
            $i   = !empty($f['yard_id']) ? ($info[(int)$f['yard_id']] ?? null) : null;
            $imp = (float)($f['totale_imponibile'] ?? 0);
            $emessa = (bool)($i['emessa'] ?? false);
            $totFatture += $imp;
            if ($emessa) {
                $totEmesse += $imp;
            }
            $righeFatt[] = [
                'id'          => (int)$f['id'],
                'data'        => $f['data'] ?? null,
                'descrizione' => $f['descrizione'] ?? null,
                'articolo'    => $f['articolo_descrizione'] ?? null,
                'imponibile'  => $imp,
                'emessa'      => $emessa,
                'numero'      => $i['numero_label'] ?? null,
                'data_doc'    => $i['tm_datdoc'] ?? null,
                'extra'       => !empty($f['extra_id']),
            ];
        }

        $extra = (new \App\Repository\Extra\ExtraRepository($this->conn))->getByWorksiteId($id);
        $totExtra = array_sum(array_map(fn($e) => (float)($e['totale'] ?? 0), $extra));

        // contratto + extra meno quello messo in fatturazione; sul consuntivo
        // la base e' il ricavo stimato dalle presenze. Puo' essere negativo:
        // vuol dire che si e' fatturato piu' di quanto vale.
        $base = ($ws->isConsuntivo() && !empty($ricavi['ricavo_stimato']))
            ? (float)$ricavi['ricavo_stimato'] + (float)($ricavi['extra'] ?? 0)
            : $ws->getTotalOffer() + $totExtra;

        $ordini = (new \App\Repository\Orders\OrderRepository($this->conn))->getByWorksiteId($id);

        Response::json([
            'success' => true,
            'yard'    => $yard,
            'riepilogo' => [
                'contratto'          => $ws->getTotalOffer(),
                'consuntivo'         => $ws->isConsuntivo(),
                'prezzo_persona'     => $ricavi['prezzo_persona'],
                'ricavo_stimato'     => $ricavi['ricavo_stimato'],
                'extra'              => $totExtra,
                'base'               => $base,
                'fatturato'          => $totFatture,
                'emesso'             => $totEmesse,
                'da_emettere'        => max(0.0, $totFatture - $totEmesse),
                'ancora_da_fatturare'=> $base - $totFatture,
                'ricavi'             => (float)$ricavi['tot_ricavi'],
                'costi'              => (float)$costi['tot_costi'],
                'margine'            => $margine,
            ],
            'costi' => [
                ['voce' => 'Presenze nostri', 'importo' => (float)$costi['nostri']],
                ['voce' => 'Consorziate',     'importo' => (float)$costi['ordini']],
                ['voce' => 'Pasti',           'importo' => (float)$costi['pasti_nostri'] + (float)$costi['pasti_cons']],
                ['voce' => 'Hotel',           'importo' => (float)$costi['hotel']],
                ['voce' => 'Mezzi sollev.',   'importo' => (float)$costi['mezzi']],
            ],
            'fatture' => $righeFatt,
            'extra'   => array_map(fn(array $e) => [
                'id'          => (int)$e['id'],
                'data'        => $e['data'] ?? null,
                'descrizione' => $e['descrizione'] ?? null,
                'totale'      => (float)($e['totale'] ?? 0),
                'fatturato'   => (float)($e['billing_total'] ?? 0),
                'da_seguire'  => (int)($e['tracks_billing'] ?? 0) === 1,
            ], $extra),
            'ordini'  => array_map(fn(array $o) => [
                'id'      => (int)$o['id'],
                'numero'  => $o['order_number'] ?? null,
                'data'    => $o['order_date'] ?? null,
                'azienda' => $o['company_name'] ?? null,
                'totale'  => isset($o['total']) ? (float)$o['total'] : null,
                'note'    => $o['note'] ?? null,
            ], $ordini),
        ]);
    }

    /**
     * Il cantiere chiesto, se questo utente lo puo' aprire: il permesso
     * 'worksites' e, per chi lavora in un'altra societa', solo i suoi.
     *
     * @return array{0: \User, 1: int}
     */
    private function cantiere(Request $request): array
    {
        $user = $this->utente($request);
        $id   = $request->intParam('id');
        $st = $this->conn->prepare('SELECT company_id FROM bb_worksites WHERE id = :id');
        $st->execute([':id' => $id]);
        $azienda = $st->fetchColumn();
        if ($azienda === false) {
            Response::json(['success' => false, 'message' => 'Cantiere non trovato'], 404);
        }
        $companyId = (int)($user->getCompanyId() ?? 0);
        if ($companyId !== 1 && (int)$azienda !== $companyId) {
            Response::json(['success' => false, 'message' => 'Cantiere non accessibile'], 403);
        }
        return [$user, $id];
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
