<?php
declare(strict_types=1);

use App\Fieldwire\Api\BubblesApi;
use App\Fieldwire\Api\CheckItemsApi;
use App\Fieldwire\Api\FloorplansApi;
use App\Fieldwire\Api\ProjectsApi;
use App\Fieldwire\Api\TasksApi;
use App\Fieldwire\FieldwireClient;
use App\Fieldwire\Sync\FloorplanSync;
use App\Fieldwire\Sync\InitialSyncService;
use App\Fieldwire\Sync\OutboundSyncService;
use App\Fieldwire\Sync\ProjectSync;
use App\Fieldwire\Webhook\WebhookHandler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Repository\Fieldwire\FwFloorplanRepository;
use App\Repository\Fieldwire\ZoneAnnotationRepository;
use App\Repository\Fieldwire\ZoneFileRepository;
use App\Repository\Fieldwire\ZoneFormRepository;
use App\Repository\Fieldwire\ZoneTaskRepository;
use App\Repository\Worksites\WorksiteRepository;
use App\Service\Zone\Permessi as P;

/**
 * BOB Zone + integrazione Fieldwire.
 *
 * Architettura:
 *  - bb_zone_* sono la SoT (source of truth). Funzionano per OGNI cantiere.
 *  - Se il cantiere ha fieldwire_project_id, ogni mutazione locale viene
 *    pushata su Fieldwire e il fw_id ritornato viene salvato. La sync
 *    inversa (Fieldwire → BOB) e' gestita dai webhook.
 *  - Le bb_fw_* tables sono deprecate; non vengono piu' scritte (eccetto
 *    bb_fw_floorplans che resta come cache metadata).
 */
final class FieldwireController
{
    private ZoneTaskRepository       $zoneRepo;
    private FwFloorplanRepository    $fwFloorplanRepo;
    private ZoneAnnotationRepository $annRepo;
    private ZoneFileRepository       $fileRepo;
    private ZoneFormRepository       $formRepo;
    private \App\Service\Zone\Accesso $accesso;
    private \App\Service\Zone\Condivisione $condivisione;

    /** La guardia e' passata per questa richiesta? La controlla assertZone. */
    private bool $guardiaPassata = false;

    /**
     * Cosa serve per ogni endpoint della Zone.
     *
     * Una riga per endpoint, e a destra l'azione: chi la puo' fare lo dice
     * {@see \App\Service\Zone\Permessi}, in una tabella sola. Prima qui
     * c'erano "sezione + livello" (file 1, attivita 2...) e la tabella dei
     * livelli stava altrove: per sapere se un operaio poteva chiudere
     * un'attivita' bisognava leggere quattro file.
     *
     * Tre voci non sono azioni della tabella perche' non riguardano il
     * cantiere:
     *   - 'pagina'   la Zone aperta col browser: rimanda alla dashboard
     *                invece di rispondere in JSON;
     *   - 'gestione' chi puo' DARE gli accessi, cioe' il modulo `zone` di
     *                BOB. Vale ovunque, anche su un cantiere in cui non si
     *                e' dentro: e' la porta di servizio che impedisce di
     *                chiudersi fuori.
     */
    private const ACCESSI = [
        'page'                  => 'pagina',

        // ── Guardare ────────────────────────────────────────────────────
        'tasks'                 => P::VEDERE,
        'comments'              => P::VEDERE,
        'checklist'             => P::VEDERE,
        'bobUsers'              => P::VEDERE,
        'files'                 => P::VEDERE,
        'downloadFile'          => P::VEDERE,
        'fileComments'          => P::VEDERE,
        'richiesta'             => P::VEDERE,
        'linkCondivisi'         => P::VEDERE,
        'documentoLink'         => P::VEDERE,
        'collegaLink'           => P::GESTIRE,
        'creaRichiesta'         => P::SCRIVERE,
        'rispondiRichiesta'     => P::SCRIVERE,
        'statoRichiesta'        => P::SCRIVERE,
        'eliminaRichiesta'      => P::SCRIVERE,
        'disegni'               => P::VEDERE,
        'fileDisegno'           => P::VEDERE,
        'floorplans'            => P::VEDERE,
        'annotations'           => P::VEDERE,
        'dwgMeta'               => P::VEDERE,
        'dwgSvg'                => P::VEDERE,
        'media'                 => P::VEDERE,
        'zonePhoto'             => P::VEDERE,
        'formTemplates'         => P::VEDERE,
        'formTemplate'          => P::VEDERE,
        'formSubmissions'       => P::VEDERE,
        'formSubmission'        => P::VEDERE,
        'formFile'              => P::VEDERE,
        'assegnazioniModuli'    => P::VEDERE,
        'report'                => P::VEDERE,
        'presenze'              => P::VEDERE,
        'presenzeGiorni'        => P::VEDERE,
        // gli avvisi di questo cantiere, per il campanello della Zone
        'avvisi'                => P::VEDERE,
        'avvisiLetti'           => P::VEDERE,
        'avvisiElimina'         => P::VEDERE,
        // le presenze scritte a mano: solo CSM
        'aggiungiPresenza'      => P::GESTIRE,
        'eliminaPresenza'       => P::GESTIRE,
        'modificaPresenza'      => P::GESTIRE,
        'presenzeAlCliente'     => P::GESTIRE,
        // il nome e il codice che il cliente da' al cantiere: lo scrive
        // il cliente, il metodo controlla il ruolo
        'salvaRiferimento'      => P::VEDERE,

        // ── Scrivere ────────────────────────────────────────────────────
        // commenti, foto e voci di checklist: le mani di tutti i giorni
        'postComment'           => P::SCRIVERE,
        'postPhoto'             => P::SCRIVERE,
        'postFileComment'       => P::SCRIVERE,
        'addChecklistItem'      => P::SCRIVERE,
        // un messaggio proprio si cancella sempre: il controllo che sia
        // proprio lo fa il metodo
        'deleteComment'         => P::SCRIVERE,

        // ── Portare avanti il lavoro ────────────────────────────────────
        'updateTaskStatus'      => P::COMPLETARE,
        'completeChecklistItem' => P::COMPLETARE,
        'submitForm'            => P::COMPILARE,
        'uploadFile'            => P::CARICARE,
        'createFolder'          => P::CARICARE,

        // ── Spilli sui disegni ──────────────────────────────────────────
        'saveAnnotation'        => P::ANNOTARE,
        'deleteAnnotation'      => P::ANNOTARE,

        // ── Aprire lavoro nuovo ─────────────────────────────────────────
        'createTask'            => P::ATTIVITA,
        'updateTask'            => P::ATTIVITA,
        // i passaggi: li toglie chi ha creato l'attivita' (o l'ufficio),
        // controllato nel metodo
        'deleteChecklistItem'   => P::SCRIVERE,
        'dwgConvert'            => P::DISEGNI,
        'pushDisegno'           => P::DISEGNI,
        'setCalibration'        => P::DISEGNI,
        // assegnare: CSM tutti i modelli, il committente quelli della sua
        // azienda (controllato nel metodo)
        'salvaAssegnazione'     => P::CREARE_MODULI,
        'disattivaAssegnazione' => P::CREARE_MODULI,
        'saveFormTemplate'      => P::CREARE_MODULI,
        'deleteFormTemplate'    => P::CREARE_MODULI,

        // ── Cancellare ──────────────────────────────────────────────────
        // Le proprie cose le cancella chiunque le abbia messe; quelle degli
        // altri solo l'ufficio (CANCELLARE). Il controllo "e' tua?" lo fa
        // il metodo, con pretendeMio().
        'deleteTask'            => P::SCRIVERE,
        'deleteFolder'          => P::SCRIVERE,
        'deleteFile'            => P::SCRIVERE,

        // ── Decidere ────────────────────────────────────────────────────
        // chi vede cosa, e com'e' collegato il cantiere: roba nostra
        'condivisione'          => P::GESTIRE,
        'salvaCondivisione'     => P::GESTIRE,
        'flagMessaggio'         => P::GESTIRE,
        'enable'                => P::GESTIRE,
        'disable'               => P::GESTIRE,

        // ── Chi accede: basta il modulo Zone di BOB ─────────────────────
        'accessi'               => 'gestione',
        'condividiCliente'      => 'gestione',
        'salvaAccesso'          => 'gestione',
        'eliminaAccesso'        => 'gestione',
    ];

    public function __construct(
        private Config             $config,
        private WorksiteRepository $worksiteRepo,
        private \PDO               $conn
    ) {
        $this->zoneRepo        = new ZoneTaskRepository($conn);
        $this->fwFloorplanRepo = new FwFloorplanRepository($conn);
        $this->annRepo         = new ZoneAnnotationRepository($conn);
        $this->fileRepo        = new ZoneFileRepository($conn);
        $this->formRepo        = new ZoneFormRepository($conn);
        $this->accesso         = new \App\Service\Zone\Accesso($conn);
        $this->condivisione    = new \App\Service\Zone\Condivisione($conn, $this->accesso);
    }

    /**
     * Il controllo, prima di qualunque cosa.
     *
     * Si chiama con __FUNCTION__ dalla prima riga di ogni metodo: cosi' la
     * regola sta nella mappa e qui c'e' un posto solo dove sbagliarla.
     */
    private function guardia(string $metodo, Request $request): void
    {
        $regola = self::ACCESSI[$metodo] ?? null;

        if ($regola === null) {
            Response::json(['success' => false, 'message' => 'Non consentito'], 403);
        }

        $utente = $request->user();

        $w = (int)($request->param('id') ?? 0);

        // Dare gli accessi: basta il modulo Zone di BOB, e vale su qualsiasi
        // cantiere. E' la porta di servizio che impedisce di chiudersi
        // fuori: un cantiere dove non e' rimasto dentro nessuno dell'ufficio
        // si riapre da qui, aggiungendosi.
        if ($regola === 'gestione') {
            if (!$this->accesso->gestisce($utente)) {
                Response::json([
                    'success' => false,
                    'message' => 'Serve il permesso Zone di BOB per decidere chi accede',
                ], 403);
            }
            $this->guardiaPassata = true;
            return;
        }

        if ($regola === 'pagina') {
            // Chi arriva qui sta navigando col browser: senza accesso
            // merita un rimando alla dashboard, non un blocco di JSON in
            // faccia, che sembra un guasto.
            //
            // Chi ha il modulo entra lo stesso, ma la pagina gli mostra solo
            // "Chi accede" (vedi $soloAccessi): deve poter aggiungere
            // qualcuno, non leggere il cantiere.
            if (!$this->accesso->qualcosa($utente, $w) && !$this->accesso->gestisce($utente)) {
                // operai e clienti tornano ai loro cantieri, con due righe
                // che spiegano; la dashboard per loro non c'e'
                Response::redirect(in_array($utente->type ?? '', ['worker', 'client'], true)
                    ? '/worksites/my?non_disponibile=1'
                    : '/dashboard?no_permission=1');
            }
            $this->guardiaPassata = true;
            return;
        }

        // Tutto il resto e' un'azione della tabella: il ruolo su QUESTO
        // cantiere, e quello che quel ruolo puo' fare.
        $ruolo = $this->accesso->ruolo($utente, $w);
        if ($ruolo === null) {
            \App\Service\Zone\Accesso::nega('Non sei fra chi accede a questo cantiere');
        }
        if (!P::puo($ruolo, (string)$regola)) {
            \App\Service\Zone\Accesso::nega('Il tuo ruolo in questo cantiere non lo prevede');
        }

        $this->guardiaPassata = true;
    }

    // ─── Chi vede cosa ────────────────────────────────────────────────────────

    /**
     * Un filtro da dare a array_filter: tiene le righe che chi chiede vede.
     *
     * Una volta sola per elenco, non una query per riga: le condivisioni di
     * questa persona si leggono tutte insieme e il confronto si fa in
     * memoria. Su una Zone con duecento file la differenza e' fra una
     * interrogazione e duecento.
     *
     * @param string $campoId      la colonna con l'id dell'oggetto condiviso
     *                             (per le foto e' task_id: una foto segue
     *                             l'attivita' dov'e' appesa)
     * @param string $campoCreatore chi l'ha caricata: la vede sempre
     */
    private function filtro(
        Request $request,
        string $tipo,
        string $campoId = 'id',
        string $campoCreatore = 'created_by'
    ): callable {
        $utente  = $request->user();
        $w       = (int)($request->param('id') ?? 0);
        $ruolo   = $this->accesso->ruolo($utente, $w);
        $ufficio = $ruolo === \App\Service\Zone\Accesso::UFFICIO;
        $io      = (int)($utente->id ?? 0);
        $visti   = $io > 0 && $ruolo !== null ? ($this->condivisione->vistiDa($io)[$tipo] ?? []) : [];
        // quello condiviso con tutta la sua parte (la squadra, il cliente)
        $parte   = $this->condivisione->vistiDaParte($w, $ruolo)[$tipo] ?? [];

        return static function (array $r) use ($ufficio, $io, $visti, $parte, $campoId, $campoCreatore): bool {
            if ($ufficio) {
                return true;
            }
            if ($io > 0 && (int)($r[$campoCreatore] ?? 0) === $io) {
                return true;
            }
            $id = (int)($r[$campoId] ?? 0);
            return isset($visti[$id]) || isset($parte[$id]);
        };
    }

    /**
     * Attacca a ogni riga con chi e' condivisa, per l'ufficio.
     *
     * Una query per l'intero elenco, non una per riga. Agli altri non
     * arriva: chi altro vede un documento non e' affare di chi lo apre, e
     * un elenco di nomi che parte verso un telefono e' un elenco di nomi
     * uscito di casa.
     *
     * @param  array<int, array<string, mixed>> $righe
     * @return array<int, array<string, mixed>>
     */
    private function conChiSuRighe(Request $request, string $tipo, array $righe, string $campoId = 'id'): array
    {
        if (!$righe || !$this->accesso->daUfficio($request->user(), (int)($request->param('id') ?? 0))) {
            return $righe;
        }

        $ids     = array_column($righe, $campoId);
        $persone = $this->condivisione->conChi($tipo, $ids);
        $parti   = $this->condivisione->partiDi($tipo, $ids);
        foreach ($righe as &$r) {
            $r['condivisa_con']   = $persone[(int)($r[$campoId] ?? 0)] ?? [];
            $r['condivisa_parti'] = $parti[(int)($r[$campoId] ?? 0)] ?? [];
        }
        return $righe;
    }

    /** Lo stesso, per un oggetto solo. */
    private function condiviso(Request $request, string $tipo, int $oggettoId, int $creatoDa = 0): bool
    {
        return $this->condivisione->vede(
            $request->user(),
            (int)($request->param('id') ?? 0),
            $tipo,
            $oggettoId,
            $creatoDa
        );
    }

    // ─── Pagina BOB Zone ──────────────────────────────────────────────────────

    public function page(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        // il permesso l'ha gia' guardato la guardia, che per questa pagina
        // rimanda alla dashboard invece di rispondere in JSON
        $worksiteId = (int) ($request->param('id') ?? 0);
        $worksite   = $this->worksiteRepo->findById($worksiteId);
        if (!$worksite) { http_response_code(404); exit; }

        // nome cliente
        $clientName = null;
        if (!empty($worksite['client_id'])) {
            $cs = $this->conn->prepare("SELECT name FROM bb_clients WHERE id = :id");
            $cs->execute([':id' => $worksite['client_id']]);
            $clientName = $cs->fetchColumn() ?: null;
        }

        // conteggi task per stato
        $counts = ['open' => 0, 'in_progress' => 0, 'complete' => 0, 'verified' => 0, 'total' => 0];
        $cstmt = $this->conn->prepare("SELECT status, COUNT(*) n FROM bb_zone_tasks WHERE worksite_id = :w GROUP BY status");
        $cstmt->execute([':w' => $worksiteId]);
        foreach ($cstmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['n'];
            $counts['total'] += (int)$r['n'];
        }

        Response::view('worksites/fieldwire.html.twig', $request, [
            'worksite_id'          => $worksiteId,
            'worksite'             => $worksite,
            'clientName'           => $clientName,
            'taskCounts'           => $counts,
            'fieldwire_project_id' => $worksite['fieldwire_project_id'] ?? null,
            'fieldwire_enabled'    => $this->config->fieldwireEnabled(),
            // le sezioni che questa persona puo' vedere: nascondere una
            // scheda che risponderebbe 403 e' meglio che farcela sbattere
            'accessi'              => self::livelliPagina($this->accesso->ruolo($request->user(), $worksiteId)),
            'ruolo'                => $this->accesso->ruolo($request->user(), $worksiteId),
            'daUfficio'            => $this->accesso->daUfficio($request->user(), $worksiteId),
            // le presenze: al committente solo se CSM le condivide
            'vedePresenze'         => (new \App\Service\Zone\Presenze($this->conn))
                                          ->visibiliA($this->accesso->ruolo($request->user(), $worksiteId), $worksiteId),
            // caricare disegni: CSM e il committente (vedi uploadDisegno)
            'caricaDisegni'        => $this->accesso->puo($request->user(), $worksiteId, \App\Service\Zone\Permessi::DISEGNI),
            // chi ha il modulo ma non e' dentro: della Zone vede solo
            // "Chi accede", per aggiungersi o aggiungere qualcuno
            'soloAccessi'          => !$this->accesso->qualcosa($request->user(), $worksiteId)
                                      && $this->accesso->gestisce($request->user()),
            'puoGestire'           => $this->accesso->gestisce($request->user()),
            // come chiama il cliente questo cantiere (lo scrive lui)
            'riferimento'          => (new \App\Service\Zone\RiferimentoCliente($this->conn))->delCantiere($worksiteId),
        ]);
    }

    /**
     * Le sezioni della pagina, nel formato che la pagina legge.
     *
     * La pagina chiede un livello per sezione (0 niente, 1 guarda, 2 anche
     * scrive) — era il formato dei sei livelli di una volta. Quando i
     * permessi sono diventati la tabella di {@see P}, alla pagina e' andato
     * l'elenco delle azioni vere/false, e true > 1 e' falso: sparivano a
     * tutti le schede Foto e File, il Report PDF, "Nuovo Task" e "Carica
     * file", e al ruolo Cantiere anche Attivita', Disegni e Moduli.
     *
     * Qui la tabella si traduce in livelli, cosi' la pagina resta com'e'.
     *
     * @return array<string, int>
     */
    private static function livelliPagina(?string $ruolo): array
    {
        $vede = P::puo($ruolo, P::VEDERE) ? 1 : 0;
        $piu  = static fn(string $azione): int => $vede && P::puo($ruolo, $azione) ? 2 : $vede;
        return [
            'attivita' => $piu(P::ATTIVITA),
            'disegni'  => $piu(P::ANNOTARE),
            'foto'     => $piu(P::CARICARE),
            'file'     => $piu(P::CARICARE),
            'moduli'   => $piu(P::COMPILARE),
            'report'   => $vede,
        ];
    }

    // ─── Tasks ────────────────────────────────────────────────────────────────

    public function tasks(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $righe = $this->conChiSuRighe($request, 'attivita', array_values(array_filter(
                $this->zoneRepo->allForWorksite($worksiteId),
                $this->filtro($request, 'attivita')
            )));
            // lo spillo sul disegno, se c'e' e il disegno si vede: il dettaglio
            // ha "Sul disegno" per aprirlo
            try {
                $st = $this->conn->prepare("
                    SELECT a.task_id, a.document_id, a.page, d.file_name, d.file_type, d.created_by
                    FROM bb_zone_annotations a JOIN bb_worksite_documents d ON d.id = a.document_id AND d.is_deleted = 0
                    WHERE a.worksite_id = :w AND a.task_id IS NOT NULL ORDER BY a.id
                ");
                $st->execute([':w' => $worksiteId]);
                $vede = $this->filtro($request, 'disegno');
                $spilli = [];
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $a) {
                    if ($vede(['id' => $a['document_id'], 'created_by' => $a['created_by']])) {
                        $spilli[(int)$a['task_id']] ??= [
                            'document_id' => (int)$a['document_id'], 'page' => (int)$a['page'],
                            'nome' => $a['file_name'], 'tipo' => $a['file_type'],
                        ];
                    }
                }
                foreach ($righe as &$r) {
                    $r['disegno'] = $spilli[(int)$r['id']] ?? null;
                }
                unset($r);
            } catch (\PDOException $e) {
                // tabelle dei disegni non pronte: le attivita' si mostrano lo stesso
            }
            return $this->conCronologia($worksiteId, $righe);
        });
    }

    /**
     * Chi ha creato ogni attivita' e la sua cronologia (chi l'ha portata
     * avanti, chiusa, verificata, riaperta), con i nomi.
     *
     * @param array<int, array<string, mixed>> $righe
     * @return array<int, array<string, mixed>>
     */
    private function conCronologia(int $worksiteId, array $righe): array
    {
        $nomeDi = function (array $ids): array {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids) return [];
            $st = $this->conn->prepare('SELECT id, TRIM(CONCAT(COALESCE(first_name,""), " ", COALESCE(last_name,""))) AS nome, username
                FROM bb_users WHERE id IN (' . implode(',', $ids) . ')');
            $st->execute();
            $fuori = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $u) {
                $fuori[(int)$u['id']] = $u['nome'] !== '' ? $u['nome'] : (string)$u['username'];
            }
            return $fuori;
        };
        $eventi = [];
        try {
            $st = $this->conn->prepare('SELECT task_id, user_id, stato_da, stato_a, created_at FROM bb_zone_task_eventi
                WHERE worksite_id = :w ORDER BY created_at, id');
            $st->execute([':w' => $worksiteId]);
            $eventi = $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // tabella non ancora creata
        }
        $nomi = $nomeDi(array_merge(array_column($righe, 'created_by'), array_column($eventi, 'user_id')));
        $per = [];
        foreach ($eventi as $e) {
            $per[(int)$e['task_id']][] = [
                'chi'    => $nomi[(int)$e['user_id']] ?? '—',
                'da'     => $e['stato_da'],
                'a'      => $e['stato_a'],
                'quando' => $e['created_at'],
            ];
        }
        foreach ($righe as &$r) {
            $r['creata_da_nome'] = $nomi[(int)($r['created_by'] ?? 0)] ?? null;
            $r['cronologia']     = $per[(int)$r['id']] ?? [];
        }
        unset($r);
        return $righe;
    }

    public function createTask(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $user       = $request->user();
            $body       = $this->jsonBody();

            if (empty($body['name'])) {
                throw new \RuntimeException('Scrivi il nome dell\'attività');
            }
            // creare attivita' e' del capo e dell'ufficio
            $ruolo = (string)$this->ruoloQui($request);

            // un'attivita' nuova e' da fare: lo stato si cambia lavorandoci
            $body['status'] = 'open';
            $taskId = $this->zoneRepo->create($worksiteId, $body, (int)($user?->id ?? 0));
            $this->scriviDettagliTask($taskId, $body);
            // i passaggi della checklist, scritti insieme all'attivita':
            // prima si creava e poi si tornava dentro ad aggiungerli
            foreach (array_slice((array)($body['checklist'] ?? []), 0, 50) as $voce) {
                $voce = mb_substr(trim((string)$voce), 0, 200);
                if ($voce !== '') {
                    $this->zoneRepo->addChecklistItem($taskId, $voce);
                }
            }
            $this->scriviVisibilitaTask($worksiteId, $taskId, $ruolo, $body, true, $request);
            // "Dove": il punto scelto su un disegno diventa lo spillo dell'attivita'
            if (is_array($body['punto'] ?? null)) {
                $pt  = $body['punto'];
                $doc = (int)($pt['document_id'] ?? 0);
                if ($doc > 0 && $this->disegnoDelCantiere($request, $doc)) {
                    $this->annRepo->create([
                        'worksite_id' => $worksiteId,
                        'document_id' => $doc,
                        'page'        => max(1, (int)($pt['page'] ?? 1)),
                        'type'        => 'pin',
                        'geom'        => ['x' => (float)($pt['x'] ?? 0), 'y' => (float)($pt['y'] ?? 0)],
                        'task_id'     => $taskId,
                        'segnalazione_id' => null,
                        'text'        => null,
                        'color'       => '#3b82f6',
                        'created_by'  => (int)($user?->id ?? 0),
                    ]);
                    // i segni disegnati insieme al punto (un'area, una freccia,
                    // una nota): restano collegati all'attivita'
                    foreach (array_slice((array)($pt['segni'] ?? []), 0, 30) as $sg) {
                        $tipo = (string)($sg['type'] ?? '');
                        if (!in_array($tipo, ['rectangle', 'ellipse', 'cloud', 'arrow', 'drawing', 'text'], true)
                            || !is_array($sg['geom'] ?? null)) {
                            continue;
                        }
                        $colore = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($sg['color'] ?? '')) ? $sg['color'] : '#3b82f6';
                        $this->annRepo->create([
                            'worksite_id' => $worksiteId,
                            'document_id' => $doc,
                            'page'        => max(1, (int)($pt['page'] ?? 1)),
                            'type'        => $tipo,
                            'geom'        => $sg['geom'],
                            'task_id'     => $taskId,
                            'segnalazione_id' => null,
                            'text'        => $tipo === 'text' ? mb_substr(trim((string)($sg['text'] ?? '')), 0, 300) : null,
                            'color'       => $colore,
                            'created_by'  => (int)($user?->id ?? 0),
                        ]);
                    }
                }
            }
            $this->pushTaskToFieldwire($worksiteId, $taskId, $body);

            // avvisa a chi tocca: la persona, la squadra, i capi, il cliente
            $this->avvisa(fn($a) => $a->attivita($taskId, (int)($user?->id ?? 0)));

            return $this->zoneRepo->find($taskId);
        });
    }

    public function updateTask(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();

            $existing = $this->taskVisibile($request, $taskId);
            $ruolo = (string)$this->ruoloQui($request);

            // Cambiare un'attivita' (nome, descrizione, date, a chi tocca):
            // CSM e il committente tutte quelle che vedono — sui nostri
            // cantieri il committente dirige; la squadra quelle create da lei.
            if (!in_array($ruolo, [\App\Service\Zone\Accesso::UFFICIO, \App\Service\Zone\Accesso::CLIENTE], true)
                && (int)($existing['created_by'] ?? 0) !== (int)($request->user()->id ?? -1)) {
                \App\Service\Zone\Accesso::nega('Questa attivita\' puo\' essere modificata solo da chi l\'ha creata, da CSM o dal committente');
            }
            // Lo stato passa solo da /status, che controlla chi puo' cosa:
            // dentro la modifica arrivava a "verificato" anche chi non puo'.
            unset($body['status']);

            $merged = array_merge($existing, $body);
            $this->zoneRepo->update($taskId, $merged);
            $this->scriviDettagliTask($taskId, $body);
            $this->scriviVisibilitaTask($worksiteId, $taskId, $ruolo, $body, false, $request);

            // avvisa se e' cambiato a chi tocca
            $dopo = $this->zoneRepo->find($taskId) ?? [];
            if ((int)($dopo['assignee_user_id'] ?? 0) !== (int)($existing['assignee_user_id'] ?? 0)
                || ($dopo['assegnata_a'] ?? '') !== ($existing['assegnata_a'] ?? '')) {
                $this->avvisa(fn($a) => $a->attivita($taskId, (int)($request->user()?->id ?? 0)));
            }

            // push update su Fieldwire se collegato
            if (!empty($existing['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new TasksApi($this->makeClient()))->update(
                            $worksite['fieldwire_project_id'],
                            (string)$existing['fw_id'],
                            $this->taskFieldsForFieldwire($merged)
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push update task] ' . $e->getMessage());
                    }
                }
            }
            return $this->zoneRepo->find($taskId);
        });
    }

    public function updateTaskStatus(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();
            $status     = $body['status'] ?? 'open';

            $prima = $this->taskVisibile($request, $taskId);
            $this->pretendeStato($request, $prima, (string)$status);
            $this->zoneRepo->updateStatus($taskId, $status);
            // la cronologia: chi l'ha portata avanti, chiusa, verificata o
            // riaperta, e quando
            if ((string)($prima['status'] ?? '') !== (string)$status) {
                try {
                    $this->conn->prepare('
                        INSERT INTO bb_zone_task_eventi (task_id, worksite_id, user_id, stato_da, stato_a)
                        VALUES (:t, :w, :u, :da, :a)
                    ')->execute([
                        ':t' => $taskId, ':w' => $worksiteId, ':u' => (int)($request->user()->id ?? 0) ?: null,
                        ':da' => $prima['status'] ?? null, ':a' => (string)$status,
                    ]);
                } catch (\PDOException $e) {
                    // tabella non ancora creata: lo stato e' cambiato lo stesso
                }
            }

            // push status su Fieldwire
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    (new TasksApi($this->makeClient()))->update(
                        $worksite['fieldwire_project_id'],
                        (string)$task['fw_id'],
                        ['status' => $status]
                    );
                } catch (\Throwable $e) {
                    error_log('[FW push status] ' . $e->getMessage());
                }
            }
            return ['updated' => true, 'status' => $status];
        });
    }

    public function deleteTask(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            // di QUESTO cantiere: prima bastava il numero, e l'ufficio di un
            // cantiere cancellava le attivita' di un altro
            $task       = $this->taskVisibile($request, $taskId);
            $this->pretendeMio($request, (int)($task['created_by'] ?? 0), 'attivita');

            // delete su Fieldwire prima di cancellare in locale
            if (!empty($task['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new TasksApi($this->makeClient()))->delete(
                            $worksite['fieldwire_project_id'],
                            (string)$task['fw_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push delete task] ' . $e->getMessage());
                    }
                }
            }
            $this->zoneRepo->delete($taskId);
            return ['deleted' => true];
        });
    }

    // ─── Comments ─────────────────────────────────────────────────────────────

    public function comments(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            $this->taskVisibile($request, $taskId);
            return $this->filtraCommenti(
                $this->zoneRepo->commentsForTask($taskId),
                $this->ruoloQui($request),
                (int)($request->user()->id ?? 0)
            );
        });
    }

    public function postComment(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $user       = $request->user();
            $body       = $this->jsonBody();
            $text       = trim($body['text'] ?? '');

            if ($text === '') throw new \RuntimeException('Il messaggio non può essere vuoto');
            $this->taskVisibile($request, $taskId);

            $authorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                         ?: ($user->username ?? 'Utente');

            $id = $this->zoneRepo->addComment($taskId, $text, $authorName);
            $this->scriviCommento($id, $request, $body);
            $interna = $this->accesso->daUfficio($user, $worksiteId) && !empty($body['interna']);
            $this->avvisa(fn($a) => $a->commento($taskId, (int)($user?->id ?? 0), $text, $interna));
            // chi e' stato menzionato con @, fra chi legge questi messaggi
            if (!empty($body['menzioni'])) {
                try {
                    $mz = new \App\Service\Zone\Menzioni($this->conn);
                    $io = (int)($user?->id ?? 0);
                    $mz->avvisa(
                        new \App\Service\Notifications\NotificationService($this->conn, $this->config),
                        $worksiteId,
                        \App\Service\Zone\Menzioni::valide($text, (array)$body['menzioni'],
                            $mz->lettori($worksiteId, 'attivita', ['id' => $taskId, 'interna' => $interna], $io)),
                        $io,
                        'attivita-' . $taskId,
                        $text
                    );
                } catch (\Throwable $e) {
                    error_log('[Zone menzioni] ' . $e->getMessage());
                }
            }

            // push su Fieldwire
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    $fw = (new BubblesApi($this->makeClient()))->postComment(
                        $worksite['fieldwire_project_id'], $task['fw_id'], $text
                    );
                    if (!empty($fw['id'])) $this->zoneRepo->setCommentFwId($id, (string)$fw['id']);
                } catch (\Throwable $e) {
                    error_log('[FW push comment] ' . $e->getMessage());
                }
            }
            return ['id' => $id, 'text' => $text, 'author_name' => $authorName, 'created_at' => date('Y-m-d H:i:s')];
        });
    }

    /** Upload foto su un task → crea un commento con file_url. Multipart. */
    public function postPhoto(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        // NB: multipart, non JSON. Risponde JSON.
        header('Content-Type: application/json');
        try {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $user       = $request->user();
            $this->taskVisibile($request, $taskId);

            if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Nessuna foto ricevuta');
            }
            $f = $_FILES['photo'];
            if (($f['size'] ?? 0) > 25 * 1024 * 1024) {
                throw new \RuntimeException('Foto troppo grande (max 25 MB)');
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
                throw new \RuntimeException('Formato immagine non consentito');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
            if (strpos((string)$mime, 'image/') !== 0 && $mime !== 'application/octet-stream') {
                throw new \RuntimeException('Il file non è un\'immagine');
            }

            $dir  = \CloudPath::ensureZonePhotosDir($worksiteId);
            $name = 't' . $taskId . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
            $dest = $dir . DIRECTORY_SEPARATOR . $name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new \RuntimeException('Salvataggio foto fallito');
            }
            $rel = \CloudPath::relativeToRoot($dest);

            $authorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                         ?: ($user->username ?? 'Utente');
            $text = trim($_POST['text'] ?? '');
            $fileUrl = "/worksites/{$worksiteId}/zone/photo?f=" . rawurlencode($rel);

            $id = $this->zoneRepo->addComment($taskId, $text, $authorName, $fileUrl);
            $this->scriviCommento($id, $request, $_POST);
            // una foto sull'attivita' e' un messaggio come gli altri: avvisa
            // chi segue l'attivita' (una nota interna resta a CSM)
            $this->avvisa(fn($a) => $a->commento(
                $taskId,
                (int)($user?->id ?? 0),
                $text !== '' ? $text : \App\Service\Lingua::testo('zona_foto_allegata', 'it'),
                !empty($_POST['interna'])
            ));

            echo json_encode(['ok' => true, 'data' => [
                'id' => $id, 'text' => $text, 'author_name' => $authorName,
                'file_url' => $fileUrl, 'created_at' => date('Y-m-d H:i:s'),
            ]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** Stream di una foto BOB Zone (path relativo in ?f=). */
    public function zonePhoto(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $rel = (string) ($_GET['f'] ?? '');
        // sicurezza: deve stare sotto BOBZone/<worksiteId>/ e niente traversal
        $expectedPrefix = 'BOBZone/' . $worksiteId . '/';
        $relNorm = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($relNorm, '..') !== false || strpos($relNorm, $expectedPrefix) !== 0) {
            http_response_code(403); exit('Accesso negato');
        }
        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $rel;
        $real = realpath($abs);
        $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0 || !is_file($real)) {
            http_response_code(404); exit('Foto non trovata');
        }
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','heic'=>'image/heic'];
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        header('X-Frame-Options: SAMEORIGIN');
        readfile($real);
        exit;
    }

    public function deleteComment(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $commentId  = (int) $request->param('commentId');

            $comment = $this->zoneRepo->findComment($commentId);
            if (!$comment) throw new \RuntimeException('Commento non trovato');
            $this->taskVisibile($request, (int)$comment['task_id']);
            if ($this->ruoloQui($request) !== \App\Service\Zone\Accesso::UFFICIO
                && (int)($comment['author_user_id'] ?? 0) !== (int)($request->user()->id ?? -1)) {
                \App\Service\Zone\Accesso::nega('Puoi cancellare solo i tuoi messaggi');
            }

            if (!empty($comment['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new BubblesApi($this->makeClient()))->delete(
                            $worksite['fieldwire_project_id'], (string)$comment['fw_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push delete bubble] ' . $e->getMessage());
                    }
                }
            }
            $this->zoneRepo->deleteComment($commentId);
            return ['deleted' => true];
        });
    }

    // ─── Checklist ────────────────────────────────────────────────────────────

    public function checklist(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $taskId = (int) $request->param('taskId');
            $this->taskVisibile($request, $taskId);
            return $this->zoneRepo->checklistForTask($taskId);
        });
    }

    public function addChecklistItem(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $body       = $this->jsonBody();
            $name       = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome elemento obbligatorio');
            $task = $this->taskVisibile($request, $taskId);
            // un passaggio lo aggiunge chi l'ha creata o chi ci lavora: prima
            // bastava vederla, e il cliente riempiva la checklist della squadra
            if ((int)($task['created_by'] ?? 0) !== (int)($request->user()->id ?? -1)) {
                $this->pretendeChiCiLavora($request, $task);
            }

            $id = $this->zoneRepo->addChecklistItem($taskId, $name);

            // push su Fieldwire
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($task['fw_id']) && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    $fw = (new CheckItemsApi($this->makeClient()))->create(
                        $worksite['fieldwire_project_id'], (string)$task['fw_id'], $name
                    );
                    if (!empty($fw['id'])) $this->zoneRepo->setChecklistItemFwId($id, (string)$fw['id']);
                } catch (\Throwable $e) {
                    error_log('[FW push check_item create] ' . $e->getMessage());
                }
            }
            return ['id' => $id, 'name' => $name, 'completed' => false];
        });
    }

    public function completeChecklistItem(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $itemId     = (int) $request->param('itemId');
            $body       = $this->jsonBody();
            $done       = (bool) ($body['completed'] ?? true);
            // spuntare e' di chi ci lavora: le stesse regole dello stato
            $task = $this->taskVisibile($request, $taskId);
            $this->pretendeChiCiLavora($request, $task);
            $this->voceDelTask($itemId, $taskId);

            $this->zoneRepo->completeChecklistItem($itemId, $done);

            // push su Fieldwire
            $item     = $this->zoneRepo->findChecklistItem($itemId);
            $task     = $this->zoneRepo->find($taskId);
            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (!empty($item['fw_id']) && !empty($task['fw_id'])
                && !empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                try {
                    (new CheckItemsApi($this->makeClient()))->update(
                        $worksite['fieldwire_project_id'],
                        (string)$task['fw_id'],
                        (string)$item['fw_id'],
                        ['completed' => $done]
                    );
                } catch (\Throwable $e) {
                    error_log('[FW push check_item update] ' . $e->getMessage());
                }
            }
            return ['completed' => $done];
        });
    }

    public function deleteChecklistItem(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $taskId     = (int) $request->param('taskId');
            $itemId     = (int) $request->param('itemId');

            $task = $this->taskVisibile($request, $taskId);
            // toglierne uno: chi ha creato l'attivita', o l'ufficio
            $this->pretendeMio($request, (int)($task['created_by'] ?? 0), 'passaggi (sono di chi ha creato l\'attività)');
            $this->voceDelTask($itemId, $taskId);
            $item = $this->zoneRepo->findChecklistItem($itemId);
            if (!$item) throw new \RuntimeException('Elemento checklist non trovato');

            $task = $this->zoneRepo->find($taskId);
            if (!empty($item['fw_id']) && !empty($task['fw_id'])) {
                $worksite = $this->worksiteRepo->findById($worksiteId);
                if (!empty($worksite['fieldwire_project_id']) && $this->config->fieldwireEnabled()) {
                    try {
                        (new CheckItemsApi($this->makeClient()))->delete(
                            $worksite['fieldwire_project_id'],
                            (string)$task['fw_id'],
                            (string)$item['fw_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[FW push check_item delete] ' . $e->getMessage());
                    }
                }
            }
            $this->zoneRepo->deleteChecklistItem($itemId);
            return ['deleted' => true];
        });
    }

    /** Report PDF (punch list) dei task del cantiere. */
    public function report(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $worksite   = $this->worksiteRepo->findById($worksiteId);
        if (!$worksite) { http_response_code(404); exit('Cantiere non trovato'); }

        // il periodo: ?periodo=7|30|tutto, o ?dal=&al= (di base l'ultima settimana)
        $al  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['al'] ?? '')) ? $_GET['al'] : date('Y-m-d');
        $dal = match (true) {
            (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dal'] ?? '')) => $_GET['dal'],
            ($_GET['periodo'] ?? '') === '30'    => date('Y-m-d', strtotime($al . ' -29 days')),
            ($_GET['periodo'] ?? '') === 'tutto' => '2000-01-01',
            default                              => date('Y-m-d', strtotime($al . ' -6 days')),
        };
        if ($dal === '2000-01-01') {
            // "tutto": dal primo giorno in cui nella Zone e' successo qualcosa
            $st = $this->conn->prepare('SELECT DATE(MIN(created_at)) FROM bb_zone_tasks WHERE worksite_id = :w');
            $st->execute([':w' => $worksiteId]);
            $dal = (string)($st->fetchColumn() ?: date('Y-m-d', strtotime($al . ' -6 days')));
        }

        try {
            // le stesse regole della Zone: solo quello che chi scarica vede
            $ruolo = $this->ruoloQui($request);
            $io    = (int)($request->user()->id ?? 0);
            $segn  = (new \App\Service\Zone\Segnalazioni($this->conn, new \App\Service\Zone\Messaggi($this->conn)))
                         ->elenco($worksiteId, $ruolo, $io);
            $worksite['riferimento'] = (new \App\Service\Zone\RiferimentoCliente($this->conn))->delCantiere($worksiteId);
            $report = new \App\Service\Fieldwire\ZoneReportService($this->conn);
            // il committente ha le presenze nel riepilogo solo se le vede nella Zone
            $report->conPresenze = (new \App\Service\Zone\Presenze($this->conn))->visibiliA($ruolo, $worksiteId);
            $pdf = $report->generate(
                $worksiteId,
                $worksite,
                $dal,
                $al,
                $this->filtro($request, 'attivita'),
                fn(array $foto) => $this->filtraCommenti($foto, $ruolo, $io),
                $this->filtro($request, 'risposta', 'id', 'submitted_by'),
                $segn,
                \App\Service\Zone\Permessi::RUOLI[$ruolo] ?? $ruolo,
            );
        } catch (\Throwable $e) {
            error_log('[Zone rapporto] ' . $e->getMessage());
            http_response_code(500);
            exit('Il rapporto non si e\' potuto creare. Riprova, o avvisa CSM.');
        }

        $fname = 'riepilogo_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($worksite['worksite_code'] ?? $worksiteId))
               . '_' . str_replace('-', '', $dal) . '-' . str_replace('-', '', $al) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ─── Moduli / Form builder ────────────────────────────────────────────────

    public function formTemplates(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            // il committente vede i modelli della sua azienda, non quelli
            // interni di CSM (DPI, verbali della squadra)
            $w = (int)$request->param('id');
            return $this->formRepo->templatesFor($w, $this->clienteDiChi($request));
        });
    }

    public function formTemplate(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            return $this->templateDelCantiere($request, (int)$request->param('tplId'));
        });
    }

    public function saveFormTemplate(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $user = $request->user();
            $body = $this->jsonBody();
            $name = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome modulo obbligatorio');
            $fields = $body['fields'] ?? [];
            if (!is_array($fields) || !count($fields)) throw new \RuntimeException('Aggiungi almeno un campo');

            // Di questo cantiere o per tutti i cantieri. Per CSM "tutti" vuol
            // dire tutti i cantieri di BOB; per il committente tutti i SUOI
            // cantieri: il modello porta il suo cliente e altrove non compare.
            $cliente = $this->clienteDiChi($request);
            $prima   = !empty($body['id']) ? $this->templateDelCantiere($request, (int)$body['id'], true) : null;
            // un modello del committente resta suo anche se lo ritocca CSM
            $clientId = $cliente ?? (isset($prima['client_id']) ? (int)$prima['client_id'] : null);
            $wsId = !empty($body['universal']) ? null : $worksiteId;
            // la cartella dei Documenti: solo per i modelli di questo cantiere,
            // e solo una cartella che chi salva vede
            $cartella = array_key_exists('folder_id', $body)
                ? ($body['folder_id'] !== null && $body['folder_id'] !== '' ? (int)$body['folder_id'] : null)
                : (isset($prima['folder_id']) ? (int)$prima['folder_id'] : null);
            if ($wsId === null) {
                $cartella = null;
            } elseif ($cartella !== null) {
                $visibili = $this->cartelleVisibili($request, $worksiteId);
                if (!isset($visibili[$cartella])) {
                    \App\Service\Zone\Accesso::nega('Non hai accesso a questa cartella');
                }
                if (\App\Service\Zone\LinkDocumenti::daLink($visibili[$cartella])) {
                    throw new \RuntimeException(self::SOLO_LINK);
                }
            }

            $data = [
                'worksite_id' => $wsId,
                'client_id'   => $clientId,
                'name'        => mb_substr($name, 0, 200),
                'description' => trim($body['description'] ?? '') ?: null,
                'folder_id'   => $cartella,
                'fields'      => $fields,
                'created_by'  => (int)($user?->id ?? 0),
            ];
            if (!empty($body['id'])) {
                $this->formRepo->update((int)$body['id'], $data);
                return ['id' => (int)$body['id']];
            }
            return ['id' => $this->formRepo->create($data)];
        });
    }

    public function deleteFormTemplate(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $tplId = (int)$request->param('tplId');
            $this->templateDelCantiere($request, $tplId, true);
            $this->formRepo->delete($tplId);
            return ['deleted' => true];
        });
    }

    public function submitForm(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $tplId      = (int) $request->param('tplId');
            $user       = $request->user();
            $body       = $this->jsonBody();

            $tpl = $this->templateDelCantiere($request, $tplId);

            $values = is_array($body['values'] ?? null) ? $body['values'] : [];

            // converti firme/foto (data URI) in file salvati su disco
            foreach ($tpl['fields'] as $f) {
                $fid  = $f['id'] ?? '';
                $type = $f['type'] ?? '';
                if (!in_array($type, ['signature', 'photo'], true)) continue;
                if (empty($values[$fid]) || !is_string($values[$fid])) continue;
                if (str_starts_with($values[$fid], 'data:')) {
                    $values[$fid] = $this->saveFormDataUri($worksiteId, $values[$fid], $type . '_' . $fid);
                }
            }

            $submitterName = trim($body['submitter_name'] ?? '');
            if ($submitterName === '' && $user) {
                $submitterName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? '');
            }

            $assegnazione = $this->assegnazioneDi($worksiteId, (int)($body['assegnazione_id'] ?? 0));
            // La compilazione conta per l'assegnazione solo se e' quel
            // modulo e tocca a chi lo compila. Prima un modulo qualsiasi,
            // mandato con il numero del DPI, segnava il DPI come fatto e
            // finiva a chi legge le risposte del DPI.
            $ufficio = $this->accesso->daUfficio($user, $worksiteId);
            if ($assegnazione) {
                $ruoloMio = $this->accesso->ruolo($user, $worksiteId);
                $aRuolo   = $assegnazione['a_ruolo'] !== null ? $this->accesso->normalizza((string)$assegnazione['a_ruolo']) : null;
                $tocca    = (int)($assegnazione['a_user_id'] ?? 0) === (int)($user?->id ?? -1)
                    || ($aRuolo !== null && $aRuolo === $ruoloMio);
                if ((int)$assegnazione['template_id'] !== $tplId || (!$tocca && !$ufficio)) {
                    \App\Service\Zone\Accesso::nega('Questo modulo non e\' assegnato a te');
                }
            } elseif (!$ufficio) {
                // fuori da CSM si compila quello che ci e' stato assegnato,
                // come gia' fa l'app; il committente anche i moduli della sua
                // azienda, che ha creato lui
                $cliente = $this->clienteDiChi($request);
                if ($cliente === null || (int)($tpl['client_id'] ?? 0) !== $cliente) {
                    \App\Service\Zone\Accesso::nega('Puoi compilare solo i moduli assegnati a te. Per informazioni, contatta CSM.');
                }
            }

            $id = $this->formRepo->createSubmission([
                'template_id'    => $tplId,
                'worksite_id'    => $worksiteId,
                'template_name'  => $tpl['name'],
                'values'         => $values,
                'submitter_name' => $submitterName ?: null,
                'submitted_by'   => (int)($user?->id ?? 0) ?: null,
                'source'         => 'internal',
            ]);
            $this->conn->prepare('UPDATE bb_zone_form_submissions SET assegnazione_id = :a WHERE id = :id')
                ->execute([
                    ':a'  => $assegnazione ? (int)$assegnazione['id'] : null,
                    ':id' => $id,
                ]);

            // Chi legge la risposta: le stesse persone del "da compilare".
            // Senza assegnazione resta all'ufficio, che e' giusto: una
            // risposta a un modulo DPI non la deve leggere la squadra
            // perche' qualcuno ha dimenticato di dire a chi va.
            if ($assegnazione) {
                $aid     = (int)$assegnazione['id'];
                $persone = $this->condivisione->conChi('modulo', [$aid]);
                $this->condivisione->salva(
                    $worksiteId,
                    'risposta',
                    $id,
                    $persone[$aid] ?? [],
                    (int)($user?->id ?? 0)
                );
                $this->condivisione->salvaParti(
                    $worksiteId, 'risposta', $id,
                    $this->condivisione->partiDi('modulo', [$aid])[$aid] ?? []
                );
            }
            return ['id' => $id];
        });
    }

    public function formSubmissions(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $tplId = isset($_GET['template']) && $_GET['template'] !== '' ? (int)$_GET['template'] : null;
            // le proprie si vedono sempre: chi l'ha compilato deve poter
            // rileggere cosa ha scritto
            return array_values(array_filter(
                $this->formRepo->submissions($worksiteId, $tplId),
                $this->filtro($request, 'risposta', 'id', 'submitted_by')
            ));
        });
    }

    public function formSubmission(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $sub = $this->formRepo->findSubmission((int)$request->param('subId'));
            if (!$sub || (int)$sub['worksite_id'] !== (int)$request->param('id')) {
                throw new \RuntimeException('Compilazione non trovata');
            }
            if (!$this->condiviso($request, 'risposta', (int)$sub['id'], (int)($sub['submitted_by'] ?? 0))) {
                \App\Service\Zone\Accesso::nega('Non hai accesso a questa compilazione');
            }
            $tpl = $this->formRepo->find((int)$sub['template_id']);
            $sub['fields'] = $tpl['fields'] ?? [];
            return $sub;
        });
    }

    /** Stream firma/foto modulo (?f=). */
    public function formFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $rel = (string)($_GET['f'] ?? '');
        $prefix = 'BOBZone/' . $worksiteId . '/forms/';
        $relNorm = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($relNorm, '..') !== false || strpos($relNorm, $prefix) !== 0) {
            http_response_code(403); exit('Accesso negato');
        }
        $real = realpath(\CloudPath::getRoot() . DIRECTORY_SEPARATOR . $rel);
        $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || strpos($real, $rootReal) !== 0 || !is_file($real)) { http_response_code(404); exit('Non trovato'); }
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mm = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
        header('Content-Type: ' . ($mm[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($real));
        readfile($real); exit;
    }

    /** Salva un data URI (firma/foto modulo) su disco, ritorna l'URL servito. */
    private function saveFormDataUri(int $worksiteId, string $dataUri, string $prefix): string
    {
        if (!preg_match('/^data:(image\/[a-z]+);base64,(.+)$/s', $dataUri, $m)) {
            throw new \RuntimeException('Dato immagine non valido');
        }
        $ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$m[1]] ?? 'png';
        $bin = base64_decode($m[2], true);
        if ($bin === false) throw new \RuntimeException('Decodifica immagine fallita');
        if (strlen($bin) > 15 * 1024 * 1024) throw new \RuntimeException('Immagine troppo grande');

        $dir  = \CloudPath::ensureZoneFormsDir($worksiteId);
        $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($dest, $bin);
        return "/worksites/{$worksiteId}/zone/form-file?f=" . rawurlencode(\CloudPath::relativeToRoot($dest));
    }

    // ─── Sezione File (repository documenti con cartelle) ─────────────────────

    public function files(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $folderId   = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;
            // le cartelle del link condiviso seguono il link (aziende e operai aggiunti o tolti)
            $link = $this->linkDocumenti();
            try {
                if ($link->collegato($worksiteId)) $link->sincronizza($worksiteId, (int)($request->user()->id ?? 0));
            } catch (\PDOException $e) {
                // colonne del link non ancora create: i Documenti funzionano lo stesso
            }
            $cartelle = $this->cartelleVisibili($request, $worksiteId);
            if ($folderId !== null && !isset($cartelle[$folderId])) {
                \App\Service\Zone\Accesso::nega('Non hai accesso a questa cartella');
            }
            $files = array_values(array_filter(
                $this->fileRepo->files($worksiteId, $folderId),
                fn(array $f) => $this->vedeFile($request, $f, $cartelle)
            ));
            // arricchisci con url download/preview
            foreach ($files as &$f) {
                $f['download_url'] = "/worksites/{$worksiteId}/zone/files/{$f['id']}/download";
                $f['is_image'] = in_array(strtolower($f['file_type'] ?? ''), ['jpg','jpeg','png','webp','gif'], true);
            }
            unset($f);
            return [
                'folders'        => $this->conChiSuRighe($request, 'cartella', array_values($cartelle)),
                'files'          => $this->conChiSuRighe($request, 'file', $files),
                // i moduli che stanno in questa cartella: per chi crea moduli
                // (CSM e il committente); la squadra compila quelli assegnati
                'moduli'         => $this->moduliInCartella($request, $worksiteId, $folderId, $cartelle),
                // le richieste della cartella, e quante sono aperte in ogni cartella
                'richieste'      => $folderId !== null ? (new \App\Service\Zone\Richieste($this->conn))->diCartella($worksiteId, $folderId) : [],
                'richieste_aperte' => array_intersect_key((new \App\Service\Zone\Richieste($this->conn))->apertePerCartella($worksiteId), $cartelle),
                // in una cartella di un'azienda o di un operaio: i documenti dal link
                'documenti_link' => $folderId !== null && \App\Service\Zone\LinkDocumenti::daLink($cartelle[$folderId] ?? null)
                    ? array_map(fn($d) => $d + ['url' => "/worksites/{$worksiteId}/zone/link-documento/{$d['tipo']}/{$d['id']}"],
                                $link->documenti($worksiteId, $cartelle[$folderId]))
                    : [],
                'current_folder' => $folderId,
            ];
        });
    }

    public function createFolder(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $body = $this->jsonBody();
            $name = trim($body['name'] ?? '');
            if ($name === '') throw new \RuntimeException('Nome cartella obbligatorio');
            // dentro un'altra cartella: si crea solo dove si vede, e chi la
            // vede e' lo stesso della cartella che la contiene (si cambia dopo)
            $parent = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;
            if ($parent !== null) {
                $visibili = $this->cartelleVisibili($request, $worksiteId);
                if (!isset($visibili[$parent])) {
                    \App\Service\Zone\Accesso::nega('Non hai accesso a questa cartella');
                }
                if (\App\Service\Zone\LinkDocumenti::daLink($visibili[$parent])) {
                    throw new \RuntimeException(self::SOLO_LINK);
                }
                if (!is_array($body['parti'] ?? null) && !is_array($body['persone'] ?? null)) {
                    $body['parti']   = $this->condivisione->partiDi('cartella', [$parent])[$parent] ?? [];
                    $body['persone'] = $this->condivisione->conChi('cartella', [$parent])[$parent] ?? [];
                }
            }
            $id = $this->fileRepo->createFolder($worksiteId, mb_substr($name, 0, 255), (int)($request->user()?->id ?? 0), $parent);
            $con = $this->condivisioneNuova($request, $worksiteId, 'cartella', $id, $body);
            return ['id' => $id, 'name' => $name, 'condivisa_con' => $con];
        });
    }

    public function deleteFolder(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $folderId   = (int) $request->param('folderId');
            $st = $this->conn->prepare('SELECT * FROM bb_zone_folders WHERE id = :f AND worksite_id = :w');
            $st->execute([':f' => $folderId, ':w' => $worksiteId]);
            $riga = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$riga) throw new \RuntimeException('Cartella non trovata');
            if (\App\Service\Zone\LinkDocumenti::daLink($riga)) {
                throw new \RuntimeException('Questa cartella arriva dal link condiviso collegato al cantiere: per toglierla, modificare o scollegare il link.');
            }
            $creata = $riga['created_by'];
            $this->pretendeMio($request, (int)$creata, 'cartelle');
            // una cartella non dell'ufficio si cancella solo vuota: dentro
            // ci possono essere file di altri
            if (!$this->accesso->daUfficio($request->user(), $worksiteId)) {
                $c = $this->conn->prepare('SELECT COUNT(*) FROM bb_zone_files WHERE folder_id = :f');
                $c->execute([':f' => $folderId]);
                if ((int)$c->fetchColumn() > 0) {
                    throw new \RuntimeException('La cartella non e\' vuota: togli prima i file');
                }
            }
            $this->fileRepo->deleteFolder($worksiteId, $folderId);
            return ['deleted' => true];
        });
    }

    public function uploadFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        header('Content-Type: application/json');
        try {
            $worksiteId = (int) $request->param('id');
            $user       = $request->user();
            $folderId   = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
            // si carica solo dove si vede, e non nelle cartelle del link
            $visibili = $this->cartelleVisibili($request, $worksiteId);
            if ($folderId !== null && !isset($visibili[$folderId])) {
                \App\Service\Zone\Accesso::nega('Non hai accesso a questa cartella');
            }
            if ($folderId !== null && \App\Service\Zone\LinkDocumenti::daLink($visibili[$folderId])) {
                throw new \RuntimeException(self::SOLO_LINK);
            }

            $caricati = $this->fileRicevuti('file');
            if (!$caricati) {
                throw new \RuntimeException('Nessun file ricevuto');
            }
            // una copia compilata in BOB va nella cartella dell'originale, come sua versione
            $orig = $this->origineCompilata($request);
            if ($orig) {
                $folderId = $orig['folder_id'] !== null ? (int)$orig['folder_id'] : null;
            }
            $id = $this->salvaFileZona($worksiteId, $folderId, $caricati[0], (int)($user?->id ?? 0),
                ['origine_file_id' => $orig ? (int)$orig['id'] : null]);

            // Nella radice non c'e' una cartella da cui ereditare: un file
            // caricato li' nasce condiviso come prima faceva l'etichetta —
            // con la squadra se lo carica un capo o un operaio, con nessuno
            // se lo carica l'ufficio, che poi decide. Dentro una cartella
            // non si scrive niente: eredita, e l'ufficio decide una volta
            // sola per tutta la cartella.
            if ($folderId === null) {
                $this->condivisioneNuova($request, $worksiteId, 'file', $id, []);
            }
            // a chi lo vede, dopo che la condivisione e' scritta
            $this->avvisa(fn($a) => $a->file($worksiteId, $id, (int)($user?->id ?? 0)));

            echo json_encode(['ok' => true, 'data' => ['id' => $id]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * I file arrivati con un modulo multipart sotto un nome, uno o piu'
     * (name="file" o name="file[]").
     *
     * @return array<int, array<string, mixed>>
     */
    private function fileRicevuti(string $campo): array
    {
        $f = $_FILES[$campo] ?? null;
        if (!$f) {
            return [];
        }
        if (!is_array($f['name'])) {
            return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $fuori = [];
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $fuori[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i],
                        'error' => $f['error'][$i], 'size' => $f['size'][$i] ?? 0];
        }
        return $fuori;
    }

    /**
     * Salva un file caricato fra i documenti della Zone, in una cartella.
     *
     * @param array{origine_file_id?:?int, richiesta_id?:?int} $legami
     */
    private function salvaFileZona(int $worksiteId, ?int $folderId, array $f, int $userId, array $legami = []): int
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Caricamento del documento non riuscito.');
        }
        if (($f['size'] ?? 0) > 50 * 1024 * 1024) throw new \RuntimeException('File troppo grande (max 50 MB)');

        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        // blocca eseguibili/script
        $blocked = ['php','phtml','exe','sh','bat','cmd','js','jar','com','msi','dll','app','scr'];
        if ($ext === '' || in_array($ext, $blocked, true)) {
            throw new \RuntimeException('Tipo di file non consentito');
        }

        $dir  = \CloudPath::ensureZoneFilesDir($worksiteId);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo((string)$f['name'], PATHINFO_FILENAME));
        $stored = $safe . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file((string)$f['tmp_name'], $dest)) throw new \RuntimeException('Salvataggio fallito');

        return $this->fileRepo->create([
            'worksite_id' => $worksiteId,
            'folder_id'   => $folderId,
            'file_name'   => mb_substr((string)$f['name'], 0, 255),
            'file_path'   => \CloudPath::relativeToRoot($dest),
            'file_type'   => $ext,
            'size_bytes'  => (int)($f['size'] ?? 0),
            'uploaded_by' => $userId ?: null,
            'origine_file_id' => $legami['origine_file_id'] ?? null,
            'richiesta_id'    => $legami['richiesta_id'] ?? null,
        ]);
    }

    /**
     * Il documento da cui si e' compilata una copia (campo "da_file_id"):
     * deve essere di questo cantiere e visibile a chi carica. Una copia di
     * una copia punta all'originale.
     */
    private function origineCompilata(Request $request): ?array
    {
        $da = (int)($_POST['da_file_id'] ?? 0);
        if ($da <= 0) {
            return null;
        }
        $orig = $this->fileRepo->find($da);
        $this->fileVisibile($request, $orig);
        if (!empty($orig['origine_file_id'])) {
            $prima = $this->fileRepo->find((int)$orig['origine_file_id']);
            if ($prima && (int)$prima['worksite_id'] === (int)$orig['worksite_id']) {
                $orig = $prima;
            }
        }
        return $orig;
    }

    public function deleteFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $fileId     = (int) $request->param('fileId');
            $file = $this->fileRepo->find($fileId);
            if (!$file || (int)$file['worksite_id'] !== $worksiteId) throw new \RuntimeException('File non trovato');
            $this->pretendeMio($request, (int)($file['uploaded_by'] ?? 0), 'file');
            $row = $this->fileRepo->delete($worksiteId, $fileId);
            // rimuovi anche il file fisico
            if ($row && !empty($row['file_path'])) {
                $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $row['file_path'];
                if (is_file($abs)) @unlink($abs);
            }
            return ['deleted' => true];
        });
    }

    public function downloadFile(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $worksiteId = (int) $request->param('id');
        $fileId     = (int) $request->param('fileId');
        $file = $this->fileRepo->find($fileId);
        if (!$file || (int)$file['worksite_id'] !== $worksiteId) { http_response_code(404); exit('File non trovato'); }
        $this->fileVisibile($request, $file);

        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $file['file_path'];
        $real = realpath($abs); $rootReal = realpath(\CloudPath::getRoot());
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0 || !is_file($real)) {
            http_response_code(404); exit('File non trovato su disco');
        }
        $ext = strtolower($file['file_type'] ?? '');
        $inlineTypes = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        if (isset($inlineTypes[$ext])) {
            header('Content-Type: ' . $inlineTypes[$ext]);
            header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
        }
        header('Content-Length: ' . filesize($real));
        header('X-Frame-Options: SAMEORIGIN');
        readfile($real);
        exit;
    }

    public function fileComments(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $fileId = (int)$request->param('fileId');
            $this->fileVisibile($request, $this->fileRepo->find($fileId));
            return $this->fileRepo->comments($fileId);
        });
    }

    public function postFileComment(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $fileId = (int) $request->param('fileId');
            $user   = $request->user();
            $body   = $this->jsonBody();
            $text   = trim($body['text'] ?? '');
            if ($text === '') throw new \RuntimeException('Commento vuoto');
            $this->fileVisibile($request, $this->fileRepo->find($fileId));
            $author = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? 'Utente');
            $id = $this->fileRepo->addComment($fileId, $text, $author, (int)($user?->id ?? 0));
            return ['id' => $id, 'text' => $text, 'author_name' => $author, 'created_at' => date('Y-m-d H:i:s')];
        });
    }

    /**
     * I modelli di modulo che stanno in una cartella. In cima (null) anche
     * quelli per tutti i cantieri e quelli la cui cartella non si vede piu'.
     *
     * @param array<int, array<string, mixed>> $cartelle le cartelle visibili
     */
    private function moduliInCartella(Request $request, int $w, ?int $folderId, array $cartelle): array
    {
        $ruolo = $this->ruoloQui($request);
        if (!\App\Service\Zone\Permessi::puo($ruolo, \App\Service\Zone\Permessi::CREARE_MODULI)) {
            return [];
        }
        $fuori = [];
        foreach ($this->formRepo->templatesFor($w, $this->clienteDiChi($request)) as $t) {
            $cart = $t['worksite_id'] !== null && !empty($t['folder_id']) ? (int)$t['folder_id'] : null;
            if ($cart !== null && !isset($cartelle[$cart])) {
                $cart = null;
            }
            if ($cart === $folderId) {
                $fuori[] = $t;
            }
        }
        return $fuori;
    }

    // ─── Link di Doc condivisi collegato al cantiere ──────────────────────────

    private const SOLO_LINK = 'I documenti di questa cartella arrivano dal link condiviso e non si modificano da qui. È possibile creare una richiesta.';

    private function linkDocumenti(): \App\Service\Zone\LinkDocumenti
    {
        return new \App\Service\Zone\LinkDocumenti($this->conn, $this->condivisione);
    }

    /** Per CSM: il link collegato e quelli fra cui scegliere. */
    public function linkCondivisi(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)$request->param('id');
            if ($this->ruoloQui($request) !== \App\Service\Zone\Accesso::UFFICIO) {
                \App\Service\Zone\Accesso::nega('Solo CSM collega un link condiviso al cantiere');
            }
            $l = $this->linkDocumenti();
            return ['collegato' => $l->collegato($w), 'disponibili' => $l->disponibili()];
        });
    }

    /** Collega (link_id) o scollega (link_id vuoto) un link al cantiere. */
    public function collegaLink(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w  = (int)$request->param('id');
            $id = (int)($this->jsonBody()['link_id'] ?? 0);
            if ($id > 0) {
                $st = $this->conn->prepare('SELECT id FROM bb_shared_links WHERE id = :id AND is_active = 1');
                $st->execute([':id' => $id]);
                if (!$st->fetchColumn()) {
                    throw new \RuntimeException('Link condiviso non trovato o non attivo.');
                }
            }
            $this->linkDocumenti()->collega($w, $id > 0 ? $id : null, (int)($request->user()->id ?? 0));
            return ['collegato' => $this->linkDocumenti()->collegato($w)];
        });
    }

    /** Un documento del link, a chi vede la cartella in cui compare. */
    public function documentoLink(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);
        $this->assertZone();

        $w   = (int)$request->param('id');
        $doc = $this->linkDocumenti()->documento($w, (string)$request->param('tipo'), (int)$request->param('docId'));
        $vede = false;
        if ($doc) {
            foreach ($this->cartelleVisibili($request, $w) as $c) {
                if (($c['sorgente'] ?? '') === $doc['sorgente'] && (int)$c['sorgente_id'] === $doc['sorgente_id']) { $vede = true; break; }
            }
        }
        $base = realpath(dirname(APP_ROOT) . '/cloud');
        $real = $doc && $vede && $base ? realpath($base . '/' . $doc['percorso']) : false;
        if (!$real || strpos($real, $base) !== 0 || !is_file($real)) {
            http_response_code(404);
            exit('Documento non trovato');
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $doc['nome']) . '.pdf"');
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, no-store');
        header('X-Frame-Options: SAMEORIGIN');
        readfile($real);
        exit;
    }

    // ─── Richieste (nei Documenti) ────────────────────────────────────────────

    /** La richiesta, se e' di questo cantiere e chi chiede ne vede la cartella. */
    private function richiestaVisibile(Request $request, int $id): array
    {
        $w = (int)$request->param('id');
        $r = (new \App\Service\Zone\Richieste($this->conn))->trova($id);
        if (!$r || (int)$r['worksite_id'] !== $w || !isset($this->cartelleVisibili($request, $w)[(int)$r['folder_id']])) {
            Response::json(['ok' => false, 'error' => 'Richiesta non trovata'], 404);
        }
        return $r;
    }

    /** Gli allegati del multipart salvati nella cartella della richiesta. @return int[] */
    private function allegatiRichiesta(Request $request, int $w, int $folderId, ?int $richiestaId = null): array
    {
        $orig = $this->origineCompilata($request);
        $ids = [];
        foreach (array_slice($this->fileRicevuti('file'), 0, 10) as $f) {
            $ids[] = $this->salvaFileZona($w, $folderId, $f, (int)($request->user()->id ?? 0), [
                'origine_file_id' => $orig ? (int)$orig['id'] : null,
                'richiesta_id'    => $richiestaId,
            ]);
        }
        return $ids;
    }

    /** Nuova richiesta in una cartella (multipart: folder_id, titolo, testo, file[]). */
    public function creaRichiesta(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w  = (int)$request->param('id');
            $io = (int)($request->user()->id ?? 0);
            $cartella = (int)($_POST['folder_id'] ?? 0);
            if ($cartella <= 0 || !isset($this->cartelleVisibili($request, $w)[$cartella])) {
                throw new \RuntimeException('Aprire una cartella per creare la richiesta.');
            }
            $titolo = trim((string)($_POST['titolo'] ?? ''));
            if ($titolo === '') {
                throw new \RuntimeException('Inserire l\'oggetto della richiesta.');
            }
            $allegati = $this->allegatiRichiesta($request, $w, $cartella);
            $id = (new \App\Service\Zone\Richieste($this->conn))->crea($w, $cartella, $titolo, $io, (string)($_POST['testo'] ?? ''), $allegati);
            if ($allegati) {
                $this->conn->prepare('UPDATE bb_zone_files SET richiesta_id = ? WHERE id IN (' . implode(',', array_map('intval', $allegati)) . ')')
                    ->execute([$id]);
            }
            $this->avvisa(fn($a) => $a->richiestaNuova($id, $io));
            return ['id' => $id];
        });
    }

    /** Una richiesta con la sua discussione. */
    public function richiesta(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w  = (int)$request->param('id');
            $io = (int)($request->user()->id ?? 0);
            $r  = $this->richiestaVisibile($request, (int)$request->param('rid'));
            $msg = (new \App\Service\Zone\Richieste($this->conn))->messaggi((int)$r['id']);
            foreach ($msg as &$m) {
                $m['mio'] = (int)($m['user_id'] ?? 0) === $io;
                foreach ($m['allegati'] as &$a) {
                    if ($a['esiste']) $a['download_url'] = "/worksites/{$w}/zone/files/{$a['id']}/download";
                }
                unset($a);
            }
            unset($m);
            return [
                'richiesta'     => $r,
                'messaggi'      => $msg,
                'cartella'      => $this->cartelleVisibili($request, $w)[(int)$r['folder_id']]['name'] ?? '',
                'puo_eliminare' => $this->ruoloQui($request) === \App\Service\Zone\Accesso::UFFICIO || (int)$r['created_by'] === $io,
            ];
        });
    }

    /** Risposta (multipart: testo, file[], completa=1 per chiuderla insieme). */
    public function rispondiRichiesta(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w  = (int)$request->param('id');
            $io = (int)($request->user()->id ?? 0);
            $r  = $this->richiestaVisibile($request, (int)$request->param('rid'));
            $svc = new \App\Service\Zone\Richieste($this->conn);
            $testo = (string)($_POST['testo'] ?? '');
            if (trim($testo) === '' && !$this->fileRicevuti('file')) {
                throw new \RuntimeException('Scrivere un messaggio o allegare un documento.');
            }
            $allegati = $this->allegatiRichiesta($request, $w, (int)$r['folder_id'], (int)$r['id']);
            $svc->scrivi((int)$r['id'], $io, $testo, $allegati);
            $anteprima = trim($testo) !== '' ? $testo : \App\Service\Lingua::testo('zona_documento_allegato', 'it');
            $this->avvisa(fn($a) => $a->richiestaRisposta((int)$r['id'], $io, $anteprima));
            if (!empty($_POST['completa']) && $r['stato'] !== \App\Service\Zone\Richieste::COMPLETATA) {
                $svc->cambiaStato((int)$r['id'], \App\Service\Zone\Richieste::COMPLETATA, $io);
                $this->avvisa(fn($a) => $a->richiestaCompletata((int)$r['id'], $io));
            }
            return ['ok' => true];
        });
    }

    /** Completata / riaperta. */
    public function statoRichiesta(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $io = (int)($request->user()->id ?? 0);
            $r  = $this->richiestaVisibile($request, (int)$request->param('rid'));
            $stato = (string)($this->jsonBody()['stato'] ?? '');
            if ($stato === $r['stato']) {
                return ['stato' => $stato];
            }
            (new \App\Service\Zone\Richieste($this->conn))->cambiaStato((int)$r['id'], $stato, $io);
            if ($stato === \App\Service\Zone\Richieste::COMPLETATA) {
                $this->avvisa(fn($a) => $a->richiestaCompletata((int)$r['id'], $io));
            }
            return ['stato' => $stato];
        });
    }

    /** Elimina una richiesta: chi l'ha aperta, o CSM. I documenti restano nella cartella. */
    public function eliminaRichiesta(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $r = $this->richiestaVisibile($request, (int)$request->param('rid'));
            $this->pretendeMio($request, (int)($r['created_by'] ?? 0), 'richieste');
            (new \App\Service\Zone\Richieste($this->conn))->elimina((int)$r['id']);
            return ['eliminata' => true];
        });
    }

    /** Galleria: tutte le foto caricate sui task del cantiere. */
    public function media(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $stmt = $this->conn->prepare("
                SELECT c.id, c.task_id, c.file_url, c.text, c.author_name, c.created_at,
                       c.interna, c.per_cliente, c.author_user_id,
                       t.name AS task_name, t.status AS task_status, t.visibilita
                FROM bb_zone_task_comments c
                JOIN bb_zone_tasks t ON t.id = c.task_id
                WHERE t.worksite_id = :w
                  AND c.file_url IS NOT NULL AND c.file_url <> ''
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([':w' => $worksiteId]);
            $ruolo = $this->ruoloQui($request);
            // una foto segue l'attivita' a cui e' appesa: per questo si
            // filtra su task_id, non sull'id della foto
            $foto  = array_filter(
                $stmt->fetchAll(\PDO::FETCH_ASSOC),
                $this->filtro($request, 'attivita', 'task_id', 'author_user_id')
            );
            return $this->filtraCommenti(array_values($foto), $ruolo, (int)($request->user()->id ?? 0));
        });
    }

    // ─── Registro presenze ────────────────────────────────────────────────────

    /**
     * Chi c'era in cantiere, un giorno.
     *
     * Legge le presenze vere di BOB. Pasti, albergo, auto e costi restano
     * fuori: vedi {@see \App\Service\Zone\Presenze}.
     */
    public function presenze(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)$request->param('id');

            $data = (string)($_GET['data'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                // senza data: l'ultimo giorno in cui c'e' stato qualcuno,
                // che e' quello che si vuole guardare nove volte su dieci
                $giorni = (new \App\Service\Zone\Presenze($this->conn))->giorni($w, 1);
                $data   = $giorni[0]['data'] ?? date('Y-m-d');
            }

            $presenze = new \App\Service\Zone\Presenze($this->conn);
            $this->pretendePresenze($request, $presenze, $w);
            return $presenze->giorno($w, $data) + [
                'totali' => $presenze->totali($w),
                // a CSM: se il committente le vede (l'interruttore in alto)
                'al_cliente' => $presenze->alCliente($w),
            ];
        });
    }

    /** I giorni in cui c'e' stato qualcuno, per il calendario del registro. */
    public function presenzeGiorni(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $presenze = new \App\Service\Zone\Presenze($this->conn);
            $this->pretendePresenze($request, $presenze, (int)$request->param('id'));
            return $presenze->giorni((int)$request->param('id'), 60);
        });
    }

    /** Il committente le legge solo se CSM le condivide con lui. */
    private function pretendePresenze(Request $request, \App\Service\Zone\Presenze $p, int $w): void
    {
        if (!$p->visibiliA($this->ruoloQui($request), $w)) {
            \App\Service\Zone\Accesso::nega('Le presenze di questo cantiere non sono condivise con il committente.');
        }
    }

    /** CSM decide se il committente vede le presenze di questo cantiere. */
    public function presenzeAlCliente(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $si = !empty($this->jsonBody()['visibile']);
            (new \App\Service\Zone\Presenze($this->conn))->mostraAlCliente((int)$request->param('id'), $si);
            return ['al_cliente' => $si];
        });
    }

    // ─── Il campanello della Zone ─────────────────────────────────────────────

    /**
     * La sezione della Zone a cui porta un avviso, dal suo link
     * (/worksites/5/zone#attivita-12 → attivita).
     */
    private static function sezioneAvviso(string $link): string
    {
        $dove = (string)(parse_url($link, PHP_URL_FRAGMENT) ?? '');
        return match (true) {
            str_starts_with($dove, 'attivita-')     => 'attivita',
            str_starts_with($dove, 'disegno')       => 'disegni',
            str_starts_with($dove, 'file-')         => 'files',
            str_starts_with($dove, 'richiesta-')    => 'files',
            $dove === 'moduli'                      => 'forms',
            str_starts_with($dove, 'segnalazione-') => 'segnalazioni',
            str_starts_with($dove, 'chat-')         => 'chat',
            default                                 => '',
        };
    }

    /**
     * Gli avvisi di chi guarda su questo cantiere: le stesse notifiche di
     * BOB (campanello in alto, app), filtrate sul cantiere. Con quanti non
     * letti ci sono in tutto e per sezione, per i numeri sul menu.
     */
    public function avvisi(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w  = (int)$request->param('id');
            $io = (int)($request->user()->id ?? 0);
            $st = $this->conn->prepare("
                SELECT id, title, message, link, is_read, created_at
                FROM bb_notifications
                WHERE user_id = :u AND (link = :l1 OR link LIKE :l2)
                ORDER BY created_at DESC, id DESC
                LIMIT 200
            ");
            $st->execute([':u' => $io, ':l1' => '/worksites/' . $w . '/zone', ':l2' => '/worksites/' . $w . '/zone#%']);

            $elenco = [];
            $nonLette = 0;
            $perSezione = ['attivita' => 0, 'disegni' => 0, 'files' => 0, 'forms' => 0, 'segnalazioni' => 0, 'chat' => 0];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $n) {
                $sez  = self::sezioneAvviso((string)$n['link']);
                $letta = (int)$n['is_read'] === 1;
                if (!$letta) {
                    $nonLette++;
                    if (isset($perSezione[$sez])) $perSezione[$sez]++;
                }
                if (count($elenco) < 40) {
                    $elenco[] = [
                        'id'       => (int)$n['id'],
                        'titolo'   => (string)$n['title'],
                        'testo'    => (string)$n['message'],
                        'dove'     => (string)(parse_url((string)$n['link'], PHP_URL_FRAGMENT) ?? ''),
                        'sezione'  => $sez,
                        'letta'    => $letta,
                        'quando'   => (string)$n['created_at'],
                    ];
                }
            }
            return ['avvisi' => $elenco, 'non_lette' => $nonLette, 'per_sezione' => $perSezione];
        });
    }

    /**
     * Segna come letti gli avvisi di questo cantiere: uno (id), quelli di
     * una sezione (aprendola), o tutti.
     */
    public function avvisiLetti(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $io   = (int)($request->user()->id ?? 0);
            $body = $this->jsonBody();
            $st = $this->conn->prepare("
                SELECT id, link FROM bb_notifications
                WHERE user_id = :u AND is_read = 0 AND (link = :l1 OR link LIKE :l2)
            ");
            $st->execute([':u' => $io, ':l1' => '/worksites/' . $w . '/zone', ':l2' => '/worksites/' . $w . '/zone#%']);
            $id  = (int)($body['id'] ?? 0);
            $sez = (string)($body['sezione'] ?? '');
            $ids = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $n) {
                if ($id > 0 ? (int)$n['id'] === $id
                    : ($sez === '' || self::sezioneAvviso((string)$n['link']) === $sez)) {
                    $ids[] = (int)$n['id'];
                }
            }
            if ($ids) {
                // read_at dove c'e' (lo usa la cronologia del campanello di BOB)
                try {
                    $segna = $this->conn->prepare('UPDATE bb_notifications SET is_read = 1, read_at = NOW() WHERE user_id = :u AND id = :id');
                    $segna->execute([':u' => $io, ':id' => $ids[0]]);
                } catch (\PDOException $e) {
                    $segna = $this->conn->prepare('UPDATE bb_notifications SET is_read = 1 WHERE user_id = :u AND id = :id');
                }
                foreach ($ids as $x) {
                    $segna->execute([':u' => $io, ':id' => $x]);
                }
            }
            return ['segnate' => count($ids)];
        });
    }

    /**
     * Elimina gli avvisi di questo cantiere di chi guarda: uno (id) o
     * tutti quelli gia' letti (lette). Solo i propri, come nel campanello.
     */
    public function avvisiElimina(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $io   = (int)($request->user()->id ?? 0);
            $body = $this->jsonBody();
            $id   = (int)($body['id'] ?? 0);
            $dove = ' AND (link = :l1 OR link LIKE :l2)';
            $arg  = [':u' => $io, ':l1' => '/worksites/' . $w . '/zone', ':l2' => '/worksites/' . $w . '/zone#%'];
            if ($id > 0) {
                $st = $this->conn->prepare('DELETE FROM bb_notifications WHERE user_id = :u AND id = :id' . $dove);
                $st->execute($arg + [':id' => $id]);
            } elseif (!empty($body['lette'])) {
                $st = $this->conn->prepare('DELETE FROM bb_notifications WHERE user_id = :u AND is_read = 1' . $dove);
                $st->execute($arg);
            } else {
                throw new \InvalidArgumentException('Indicare la notifica da eliminare.');
            }
            return ['eliminate' => $st->rowCount()];
        });
    }

    /** CSM registra a mano una presenza (una persona o un'azienda). */
    public function aggiungiPresenza(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $id = (new \App\Service\Zone\Presenze($this->conn))->aggiungi(
                (int)$request->param('id'),
                $this->jsonBody(),
                (int)($request->user()->id ?? 0)
            );
            return ['id' => $id];
        });
    }

    /** CSM corregge una presenza scritta a mano. */
    public function modificaPresenza(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $ok = (new \App\Service\Zone\Presenze($this->conn))
                ->modifica((int)$request->param('id'), (int)$request->param('pId'), $this->jsonBody());
            if (!$ok) {
                throw new \RuntimeException('Presenza non trovata');
            }
            return ['ok' => true];
        });
    }

    /** CSM toglie una presenza scritta a mano. */
    public function eliminaPresenza(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $ok = (new \App\Service\Zone\Presenze($this->conn))
                ->elimina((int)$request->param('id'), (int)$request->param('pId'));
            if (!$ok) {
                throw new \RuntimeException('Presenza non trovata');
            }
            return ['ok' => true];
        });
    }

    // ─── Il riferimento del cliente ───────────────────────────────────────────

    /** Il cliente scrive come chiama lui questo cantiere: un nome, un codice commessa. */
    public function salvaRiferimento(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)$request->param('id');
            if ($this->ruoloQui($request) !== \App\Service\Zone\Accesso::CLIENTE) {
                \App\Service\Zone\Accesso::nega('Il riferimento lo scrive il cliente');
            }
            $body = $this->jsonBody();
            return (new \App\Service\Zone\RiferimentoCliente($this->conn))->salva(
                $w,
                (int)($request->user()->client_id ?? 0),
                (string)($body['nome'] ?? ''),
                (string)($body['codice'] ?? ''),
                (int)($request->user()->id ?? 0)
            );
        });
    }

    // ─── Lookup utenti BOB (per dropdown assignee) ────────────────────────────

    public function bobUsers(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)($request->param('id') ?? 0);

            // Fuori dall'ufficio si vede solo chi sta in QUESTO cantiere.
            // Prima l'elenco era tutti gli utenti attivi di BOB, e bastava
            // essere un operaio — o un cliente — per farsi dare nomi e
            // cognomi di tutta l'azienda. L'ufficio li vede tutti perche'
            // assegna le attivita' e aggiunge la gente ai cantieri.
            // ?qui=1: solo chi sta in questo cantiere anche per l'ufficio.
            // Serve ad "Assegnato a": un'attivita' data a qualcuno che nel
            // cantiere non c'e' non la puo' aprire nessuno.
            $soloQui = $this->accesso->daUfficio($request->user(), $w) && empty($_GET['qui'])
                ? ''
                : ' AND u.id IN (SELECT user_id FROM bb_zone_accessi WHERE worksite_id = ' . $w . ')';

            $stmt = $this->conn->query("
                SELECT u.id, u.username, u.type, COALESCE(u.company, '') AS company,
                       TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS full_name,
                       EXISTS (
                           SELECT 1 FROM bb_user_permissions p
                           WHERE  p.user_id = u.id AND p.module = 'zone' AND p.allowed = 1
                           UNION
                           SELECT 1 FROM bb_user_company_permissions pc
                           WHERE  pc.user_id = u.id AND pc.module = 'zone' AND pc.allowed = 1
                       ) AS ha_modulo_zone
                FROM bb_users u
                WHERE u.active = 'Y' AND u.removed = 'N'
                      -- i clienti di un altro committente qui non entrano:
                      -- proporli vorrebbe dire farsi rispondere di no
                      AND (u.type <> 'client' OR u.client_id = (SELECT client_id FROM bb_worksites WHERE id = $w))
                      $soloQui
                ORDER BY u.username ASC
            ");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            // ha_modulo_zone serve al pannello per aggiungerlo come ufficio
            // invece che come operaio, e per proporgli quel ruolo
            return array_map(fn($r) => [
                'id'             => (int)$r['id'],
                'label'          => $r['full_name'] !== '' ? $r['full_name'] : $r['username'],
                // l'azienda accanto al nome: due Rossi si distinguono cosi'
                'azienda'        => (string)$r['company'],
                'tipo'           => (string)$r['type'],
                'ha_modulo_zone' => (bool)$r['ha_modulo_zone'],
            ], $rows);
        });
    }

    // ─── Chi entra nel cantiere ───────────────────────────────────────────────

    /** Gli assegnati di questo cantiere, coi loro livelli. */
    public function accessi(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)$request->param('id');
            $st = $this->conn->prepare('
                SELECT w.zone_cliente, c.name AS cliente
                FROM bb_worksites w LEFT JOIN bb_clients c ON c.id = w.client_id
                WHERE w.id = :w
            ');
            $st->execute([':w' => $w]);
            $c = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            return [
                'persone'      => (new \App\Repository\Zone\AccessoRepository($this->conn))
                                      ->perCantiere($w),
                'zone_cliente' => !empty($c['zone_cliente']),
                // "Condividi col cliente" apre la porta agli account di
                // questo cliente messi qui sotto: il pannello lo scrive
                'cliente'      => (string)($c['cliente'] ?? ''),
            ];
        });
    }

    /** Assegna una persona, o cambia i suoi livelli. */
    public function salvaAccesso(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w      = (int)$request->param('id');
            $userId = (int)($_POST['user_id'] ?? 0);

            if (!$userId) {
                throw new \RuntimeException('Serve la persona da assegnare');
            }

            $c = $this->conn->prepare('SELECT 1 FROM bb_zone_accessi WHERE worksite_id = :w AND user_id = :u');
            $c->execute([':w' => $w, ':u' => $userId]);
            $nuovo = !$c->fetchColumn();

            (new \App\Repository\Zone\AccessoRepository($this->conn))->salva(
                $w, $userId, (int)($request->user()->id ?? 0),
                isset($_POST['ruolo']) ? (string)$_POST['ruolo'] : null
            );
            if ($nuovo) {
                $ruolo = $this->accesso->normalizza((string)$this->conn
                    ->query('SELECT ruolo FROM bb_zone_accessi WHERE worksite_id = ' . $w . ' AND user_id = ' . $userId)
                    ->fetchColumn());
                if ($ruolo === \App\Service\Zone\Accesso::CLIENTE || $ruolo === \App\Service\Zone\Accesso::CANTIERE) {
                    $this->condivisione->comeGliAltri($w, $userId, $ruolo);
                }
                $this->avvisa(fn($a) => $a->accesso($w, $userId, (int)($request->user()->id ?? 0)));
            }

            return ['ok' => true];
        });
    }

    /** Toglie una persona dal cantiere. */
    public function eliminaAccesso(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $tolto = (new \App\Repository\Zone\AccessoRepository($this->conn))->elimina(
                (int)$request->param('id'),
                (int)($_POST['user_id'] ?? 0)
            );
            return ['ok' => $tolto];
        });
    }

    // ─── Floorplans (Fieldwire only) ──────────────────────────────────────────

    public function floorplans(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            return $this->fwFloorplanRepo->allForWorksite($worksiteId);
        });
    }

    // ─── Disegni / Tavole (BOB-native + Fieldwire) ────────────────────────────

    /**
     * Lista disegni BOB del cantiere (categoria Disegni) con stato sync FW,
     * piu' le floorplan Fieldwire che non hanno corrispondenza BOB.
     */
    public function disegni(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            $fwProjectId = $worksite['fieldwire_project_id'] ?? null;

            // disegni BOB (sorgente di verita') + stato sync
            $stmt = $this->conn->prepare("
                SELECT d.id, d.file_name, d.file_type, d.note, d.subcategory,
                       d.created_at, d.created_by, d.current_version,
                       (SELECT COUNT(*) FROM bb_worksite_document_versions v WHERE v.document_id = d.id) AS n_versioni,
                       (SELECT COUNT(*) FROM bb_zone_annotations an WHERE an.document_id = d.id) AS n_segni,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS uploader,
                       s.fw_sheet_upload_id, s.pushed_at,
                       r.status AS dwg_status, r.error AS dwg_error
                FROM bb_worksite_documents d
                LEFT JOIN bb_users u             ON u.id = d.created_by
                LEFT JOIN bb_zone_disegno_sync s ON s.document_id = d.id
                LEFT JOIN bb_zone_dwg_render r   ON r.document_id = d.id
                WHERE d.worksite_id = :wid AND d.is_deleted = 0
                  AND d.file_path LIKE '%/Disegni/%'
                ORDER BY d.created_at DESC
            ");
            $stmt->execute([':wid' => $worksiteId]);
            $righe = array_filter(
                $stmt->fetchAll(\PDO::FETCH_ASSOC),
                $this->filtro($request, 'disegno')
            );
            // CSM legge perche' una conversione DWG non e' riuscita
            $ufficio = $this->accesso->daUfficio($request->user(), $worksiteId);
            $bob = array_map(function ($r) use ($worksiteId, $ufficio) {
                $type = strtolower($r['file_type'] ?? '');
                $isDwg = in_array($type, ['dwg', 'dxf'], true); // entrambi passano dal render vettoriale
                return [
                    'id'           => (int)$r['id'],
                    'file_name'    => $r['file_name'],
                    'file_type'    => $r['file_type'],
                    'note'         => $r['note'],
                    'folder'       => $r['subcategory'] ?: 'altri',
                    'uploader'     => trim($r['uploader']) ?: '—',
                    'created_at'   => $r['created_at'],
                    // chi l'ha caricato: il committente aggiorna i suoi
                    'created_by'   => (int)($r['created_by'] ?? 0),
                    // le versioni: quella in uso e quante ce ne sono di prima
                    'versione'     => (int)($r['current_version'] ?? 1) ?: 1,
                    'n_versioni'   => (int)($r['n_versioni'] ?? 0),
                    // quante annotazioni ha: si sceglie se aprirlo con o senza
                    'n_segni'      => (int)($r['n_segni'] ?? 0),
                    'view_url'     => "/worksites/{$worksiteId}/disegni/{$r['id']}/view",
                    'fw_synced'    => !empty($r['pushed_at']),
                    'fw_pushed_at' => $r['pushed_at'],
                    // DWG: stato della conversione vettoriale
                    'is_dwg'       => $isDwg,
                    'dwg_status'   => $isDwg ? ($r['dwg_status'] ?? 'pending') : null,
                    // perche' la conversione non e' riuscita: a CSM, che la
                    // puo' rilanciare o far sistemare sul server
                    'dwg_error'    => $isDwg && ($r['dwg_status'] ?? '') === 'error'
                                      && $ufficio
                                      ? (string)($r['dwg_error'] ?? '') : null,
                    // annotabile se immagine/pdf, oppure dwg convertito con successo
                    'annotatable'  => in_array($type, ['pdf','png','jpg','jpeg'], true)
                                      || ($isDwg && ($r['dwg_status'] ?? '') === 'ok'),
                ];
            }, array_values($righe));
            $bob = $this->conChiSuRighe($request, 'disegno', $bob);

            // floorplan Fieldwire (sola lettura, con deep link)
            $fw = [];
            foreach ($this->fwFloorplanRepo->allForWorksite($worksiteId) as $fp) {
                $fw[] = [
                    'fw_id'        => $fp['fw_id'],
                    'name'         => $fp['name'],
                    'sheets_count' => $fp['sheets_count'],
                    'open_url'     => $fwProjectId
                        ? "https://app.fieldwire.com/projects/{$fwProjectId}/sheets/{$fp['fw_id']}"
                        : null,
                ];
            }

            return [
                'bob'             => $bob,
                'fieldwire'       => $fw,
                'fieldwire_ready' => !empty($fwProjectId) && $this->config->fieldwireEnabled(),
            ];
        });
    }

    /** Push di un disegno BOB su Fieldwire come sheet (flusso S3). */
    public function pushDisegno(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $user       = $request->user();

            $worksite = $this->worksiteRepo->findById($worksiteId);
            if (empty($worksite['fieldwire_project_id'])) {
                throw new \RuntimeException('Cantiere non collegato a Fieldwire');
            }
            if (!$this->config->fieldwireEnabled()) {
                throw new \RuntimeException('Fieldwire non configurato (FIELDWIRE_API_TOKEN)');
            }

            // recupera il disegno + path assoluto
            $stmt = $this->conn->prepare("
                SELECT id, file_name, file_path
                FROM bb_worksite_documents
                WHERE id = :id AND worksite_id = :wid AND is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([':id' => $docId, ':wid' => $worksiteId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$doc) throw new \RuntimeException('Disegno non trovato');

            $absolutePath = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $doc['file_path'];

            $sync   = new FloorplanSync(new FloorplansApi($this->makeClient()));
            $upload = $sync->pushFile($worksite['fieldwire_project_id'], $absolutePath, $doc['file_name']);

            // registra/aggiorna lo stato sync
            $this->conn->prepare("
                INSERT INTO bb_zone_disegno_sync (document_id, worksite_id, fw_sheet_upload_id, pushed_at, pushed_by)
                VALUES (:doc, :wid, :su, NOW(), :uid)
                ON DUPLICATE KEY UPDATE
                    fw_sheet_upload_id = VALUES(fw_sheet_upload_id),
                    pushed_at          = NOW(),
                    pushed_by          = VALUES(pushed_by)
            ")->execute([
                ':doc' => $docId,
                ':wid' => $worksiteId,
                ':su'  => $upload,
                ':uid' => (int)($user?->id ?? 0),
            ]);

            return ['pushed' => true, 'sheet_upload_id' => $upload];
        });
    }

    // ─── Annotazioni disegni (pin / misure / markup) ──────────────────────────

    /** Lista annotazioni + calibrazione di un documento (pagina opzionale). */
    public function annotations(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            $this->documentoVisibile($request, $docId);
            $page  = (int) ($_GET['page'] ?? 1);
            return [
                'annotations' => $this->annRepo->allForDocument($docId, $page),
                'calibration' => $this->annRepo->getCalibration($docId, $page),
            ];
        });
    }

    /**
     * Crea o aggiorna un'annotazione.
     *
     * Un pin puo' portarsi dietro un'attivita' (`create_task`) o una
     * segnalazione (`create_segnalazione`): "manca un tirante" non dice
     * niente finche' non si sa dove, e il punto sul disegno lo si indica
     * una volta invece di descriverlo in dieci messaggi.
     */
    public function saveAnnotation(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $user       = $request->user();
            $body       = $this->jsonBody();

            $type = $body['type'] ?? '';
            if ($type === '') throw new \RuntimeException('Tipo annotazione mancante');
            if (empty($body['geom'])) throw new \RuntimeException('Geometria mancante');

            // il disegno e' di questo cantiere e chi scrive lo vede
            if (!$this->disegnoDelCantiere($request, $docId)) {
                throw new \RuntimeException('Disegno non trovato');
            }
            // cambiare un segno: solo il proprio, o l'ufficio
            if (!empty($body['id'])) {
                $vecchio = $this->annRepo->find((int)$body['id']);
                if (!$vecchio || (int)$vecchio['worksite_id'] !== $worksiteId) {
                    throw new \RuntimeException('Segno non trovato');
                }
                $this->pretendeMio($request, (int)($vecchio['created_by'] ?? 0), 'segni');
            }

            $taskId = !empty($body['task_id']) ? (int)$body['task_id'] : null;
            $segnId = !empty($body['segnalazione_id']) ? (int)$body['segnalazione_id'] : null;
            // collegare un'attivita' esistente: di questo cantiere, e che si vede
            if ($taskId) {
                $this->taskVisibile($request, $taskId);
            }

            // Una segnalazione aperta da qui: la scrive il servizio delle
            // segnalazioni, non questo metodo. Li' dentro ci sono la
            // visibilita' per ruolo e l'avviso a chi la deve prendere in
            // mano, e riscriverli qui vorrebbe dire scordarsene uno.
            if ($type === 'pin' && !empty($body['create_segnalazione']) && !$segnId) {
                $segn = new \App\Service\Zone\Segnalazioni(
                    $this->conn,
                    new \App\Service\Zone\Messaggi($this->conn)
                );
                $segnId = $segn->crea(
                    $worksiteId,
                    (string)$this->ruoloQui($request),
                    (int)($user?->id ?? 0),
                    [
                        'testo'      => (string)($body['segnalazione_testo'] ?? $body['text'] ?? ''),
                        'tipo'       => $body['segnalazione_tipo'] ?? null,
                        'gravita'    => $body['segnalazione_gravita'] ?? null,
                        'visibilita' => $body['segnalazione_visibilita'] ?? null,
                    ]
                );
                $this->avvisaSegnalazione($segnId);
            }

            // pin che deve creare un task nuovo "qui"
            // Le stesse regole della "Nuova attivita'": chi puo' crearla, a chi
            // tocca, chi la vede, l'avviso. Prima da qui nasceva senza
            // niente di questo: la poteva creare anche chi non puo', non la
            // vedeva nessuno e nessuno era avvisato.
            if ($type === 'pin' && !empty($body['create_task']) && !$taskId) {
                $this->accesso->pretende($user, $worksiteId, P::ATTIVITA);
                $ruolo    = (string)$this->ruoloQui($request);
                $taskName = trim($body['task_name'] ?? '') ?: ($body['text'] ?? 'Attività dal disegno');
                $nuova    = ['name' => mb_substr($taskName, 0, 200), 'status' => 'open']
                          + array_intersect_key($body, array_flip(['assegnata_a', 'assignee_user_id', 'due_date', 'priority', 'luogo', 'persone', 'parti']));
                $taskId = $this->zoneRepo->create($worksiteId, $nuova, (int)($user?->id ?? 0));
                $this->scriviDettagliTask($taskId, $nuova);
                $this->scriviVisibilitaTask($worksiteId, $taskId, $ruolo, $nuova, true, $request);
                $this->avvisa(fn($a) => $a->attivita($taskId, (int)($user?->id ?? 0)));
                $this->pushTaskToFieldwire($worksiteId, $taskId, []);
            }

            $data = [
                'worksite_id' => $worksiteId,
                'document_id' => $docId,
                'page'        => (int)($body['page'] ?? 1),
                'type'        => $type,
                'geom'        => $body['geom'],
                'task_id'         => $taskId,
                'segnalazione_id' => $segnId,
                'text'        => $body['text'] ?? null,
                'color'       => $body['color'] ?? '#ef4444',
                'created_by'  => (int)($user?->id ?? 0),
            ];

            if (!empty($body['id'])) {
                $this->annRepo->update((int)$body['id'], $data);
                $id = (int)$body['id'];
            } else {
                $id = $this->annRepo->create($data);
            }
            return $this->annRepo->find($id);
        });
    }

    public function deleteAnnotation(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $annId = (int) $request->param('annId');
            // di questo cantiere, e tua (o sei l'ufficio): prima chiunque
            // cancellava gli spilli di chiunque, anche di altri cantieri
            $ann = $this->annRepo->find($annId);
            if (!$ann || (int)($ann['worksite_id'] ?? 0) !== (int)$request->param('id')) {
                throw new \RuntimeException('Segno non trovato');
            }
            $this->pretendeMio($request, (int)($ann['created_by'] ?? 0), 'segni');
            $this->annRepo->delete($annId);
            return ['deleted' => true];
        });
    }

    // ─── DWG render (SVG vettoriale + meta per misure esatte) ─────────────────

    /** Stato + meta del render DWG (svg url, extents, meters_per_unit). */
    public function dwgMeta(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $docId      = (int) $request->param('docId');
            $this->documentoVisibile($request, $docId);
            $conv = new \App\Service\Fieldwire\DwgConverter($this->conn);
            $row  = $conv->status($docId);
            if (!$row) return ['status' => 'none'];
            return [
                'status'          => $row['status'],
                'error'           => $row['error'],
                'svg_url'         => $row['status'] === 'ok'
                    ? "/worksites/{$worksiteId}/zone/disegni/{$docId}/dwg-svg" : null,
                'minx'            => (float)$row['minx'],
                'miny'            => (float)$row['miny'],
                'maxx'            => (float)$row['maxx'],
                'maxy'            => (float)$row['maxy'],
                'insunits'        => $row['insunits'] !== null ? (int)$row['insunits'] : null,
                'meters_per_unit' => $row['meters_per_unit'] !== null ? (float)$row['meters_per_unit'] : null,
            ];
        });
    }

    /** Rilancia la conversione DWG→SVG (retry manuale). */
    public function dwgConvert(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            return (new \App\Service\Fieldwire\DwgConverter($this->conn))->convert($docId);
        });
    }

    /** Stream dell'SVG generato dal DWG. */
    public function dwgSvg(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->assertZone();

        $docId = (int) $request->param('docId');
        $this->documentoVisibile($request, $docId);
        $row = (new \App\Service\Fieldwire\DwgConverter($this->conn))->status($docId);
        if (!$row || $row['status'] !== 'ok' || empty($row['svg_path'])) {
            http_response_code(404);
            exit('SVG non disponibile');
        }
        $abs = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $row['svg_path'];
        if (!is_file($abs)) {
            http_response_code(404);
            exit('File SVG non trovato');
        }
        header('Content-Type: image/svg+xml');
        header('Content-Length: ' . filesize($abs));
        header('X-Frame-Options: SAMEORIGIN');
        readfile($abs);
        exit;
    }

    /** Salva la calibrazione scala (metri per frazione-larghezza). */
    public function setCalibration(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $docId = (int) $request->param('docId');
            $user  = $request->user();
            $body  = $this->jsonBody();
            $page  = (int)($body['page'] ?? 1);
            $scale = (float)($body['m_per_wfrac'] ?? 0);
            if ($scale <= 0) throw new \RuntimeException('Scala non valida');
            $this->annRepo->setCalibration($docId, $page, $scale, (int)($user?->id ?? 0));
            return ['ok' => true, 'm_per_wfrac' => $scale];
        });
    }

    // ─── Enable / Disable Fieldwire ───────────────────────────────────────────

    public function enable(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $user       = $request->user();
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite) throw new \RuntimeException('Cantiere non trovato');

            $client = $this->makeClient();
            $sync   = new ProjectSync(new ProjectsApi($client), $this->conn);
            $fwId   = $sync->enable($worksite, $user->id);

            // pull Fieldwire → BOB
            $initial = new InitialSyncService(
                new TasksApi($client), new CheckItemsApi($client),
                new BubblesApi($client), new FloorplansApi($client),
                $this->zoneRepo, $this->fwFloorplanRepo,
                new \App\Fieldwire\FwLookup($client)
            );
            $pullStats = $initial->run($worksiteId, $fwId);

            // push BOB → Fieldwire (task BOB-only)
            $outbound = new OutboundSyncService(
                new TasksApi($client), new CheckItemsApi($client),
                new BubblesApi($client), $this->zoneRepo
            );
            $pushStats = $outbound->run($worksiteId, $fwId);

            return [
                'fieldwire_project_id' => $fwId,
                'pulled'               => $pullStats,
                'pushed'               => $pushStats,
            ];
        });
    }

    public function disable(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $worksiteId = (int) $request->param('id');
            $worksite   = $this->worksiteRepo->findById($worksiteId);
            if (!$worksite) throw new \RuntimeException('Cantiere non trovato');
            (new ProjectSync(new ProjectsApi($this->makeClient()), $this->conn))->disable($worksite);
            return ['disabled' => true];
        });
    }

    // ─── Webhook ──────────────────────────────────────────────────────────────

    public function webhook(Request $request): void
    {
        $raw = (string) file_get_contents('php://input');
        try {
            $handler = new WebhookHandler(
                $this->worksiteRepo, $this->zoneRepo, $this->fwFloorplanRepo
            );
            $result = $handler->dispatch($handler->handle($raw));
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'action' => $result]);
        } catch (\Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Push di un task BOB appena creato verso Fieldwire (best-effort). */
    private function pushTaskToFieldwire(int $worksiteId, int $taskId, array $bodyFromForm): void
    {
        $worksite = $this->worksiteRepo->findById($worksiteId);
        if (empty($worksite['fieldwire_project_id']) || !$this->config->fieldwireEnabled()) return;

        try {
            $task = $this->zoneRepo->find($taskId);
            $fw   = (new TasksApi($this->makeClient()))->create(
                $worksite['fieldwire_project_id'],
                $this->taskFieldsForFieldwire($task)
            );
            if (!empty($fw['id'])) {
                $this->zoneRepo->setFwId($taskId, (string)$fw['id']);
            }
        } catch (\Throwable $e) {
            error_log('[FW push task] ' . $e->getMessage());
        }
    }

    /** Mappa un task BOB nel payload accettato da Fieldwire. */
    private function taskFieldsForFieldwire(array $bob): array
    {
        return array_filter([
            'name'          => $bob['name']          ?? '',
            'description'   => $bob['description']   ?? '',
            'status'        => $bob['status']        ?? 'open',
            'category_name' => $bob['category']      ?? null,
            'assignee_name' => $bob['assignee_name'] ?? null,
            'start_date'    => $bob['start_date']    ?? null,
            'due_date'      => $bob['due_date']      ?? null,
            'priority'      => (int)($bob['priority'] ?? 0),
        ], fn($v) => $v !== null);
    }

    /** Inserisce una notifica BOB per l'operaio/utente assegnato a un task. */
    private function makeClient(): FieldwireClient
    {
        if (!$this->config->fieldwireEnabled()) {
            throw new \RuntimeException('FIELDWIRE_API_TOKEN non configurato in .env');
        }
        return new FieldwireClient($this->config->fieldwireToken(), $this->config->fieldwireRegion());
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Wrap controller action in JSON response.
     *
     * Output buffer trick: cattura qualsiasi PHP warning/notice (o output
     * accidentale di altre includes) PRIMA che venga emesso. Senza questo,
     * un warning silenzioso bombarda il JSON e il frontend mostra
     * "Risposta non valida dal server".
     */
    /**
     * Chi puo' usare BOB Zone.
     *
     * Fino a ora il controllo non c'era affatto: bastava il permesso
     * 'worksites', perche' le rotte stanno sotto /worksites e il middleware
     * del sito controlla quello. Adesso Zone ha un permesso suo, e serve un
     * controllo qui dentro perche' l'app arriva da /api/v1, dove quel
     * middleware non passa.
     *
     * L'utente si legge da $GLOBALS: lo riempiono entrambi i middleware, web
     * e API, quindi il metodo funziona identico da tutte e due le parti.
     */
    /**
     * L'ultima rete, sotto a jsonResponse.
     *
     * Prima chiedeva il modulo `zone` e basta, e con quello solo l'ufficio
     * entrava: un capo squadra assegnato al suo cantiere si sarebbe preso
     * 403 qui, prima ancora che qualcuno guardasse i suoi livelli.
     *
     * Ora chiede che la guardia sia passata. E' piu' stretto di prima, non
     * piu' largo: la guardia decide caso per caso — famiglia, livello,
     * cantiere — e un metodo che arrivasse a rispondere senza esserci
     * passato viene fermato qui invece di scivolare fuori.
     */
    private function assertZone(): void
    {
        if ($this->guardiaPassata) {
            return;
        }

        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok'    => false,
            'error' => 'Non hai accesso a questa parte del cantiere',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── Ufficio: condivisione, visibilita', moduli da compilare ──────────────

    /** "Condividi col cliente": senza, un account cliente non vede niente. */
    public function condividiCliente(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $attivo = !empty($this->jsonBody()['attivo']);
            $w = (int)$request->param('id');
            $prima = $this->conn->prepare('SELECT zone_cliente FROM bb_worksites WHERE id = :w');
            $prima->execute([':w' => $w]);
            $eraAcceso = (bool)$prima->fetchColumn();

            $this->conn->prepare('UPDATE bb_worksites SET zone_cliente = :a WHERE id = :w')
                ->execute([':a' => $attivo ? 1 : 0, ':w' => $w]);

            if ($attivo && !$eraAcceso) {
                $cl = $this->conn->prepare("SELECT user_id FROM bb_zone_accessi WHERE worksite_id = :w AND ruolo = 'cliente'");
                $cl->execute([':w' => $w]);
                foreach ($cl->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
                    $this->avvisa(fn($a) => $a->accesso($w, (int)$uid, (int)($request->user()->id ?? 0)));
                }
            }
            return ['zone_cliente' => $attivo];
        });
    }

    /**
     * Chi vede una cosa, e fra chi si puo' scegliere.
     *
     * Un punto solo per cartelle, file, disegni, attivita' e moduli: e' la
     * stessa decisione su oggetti diversi, e sempre dell'ufficio.
     *
     * I messaggi di un'attivita' restano fuori: hanno i loro due
     * interruttori ("nota interna", "per il cliente") che si decidono
     * mentre si scrive. Fermarsi a spuntare nomi per rispondere a una
     * domanda non lo farebbe nessuno.
     */
    public function condivisione(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $tipo = (string)($_GET['tipo'] ?? '');
            $id   = (int)($_GET['oggetto'] ?? 0);

            if (!in_array($tipo, \App\Service\Zone\Condivisione::TIPI, true)) {
                throw new \RuntimeException('Tipo non valido');
            }
            $this->oggettoDelCantiere($tipo, $id, $w);

            return [
                'gruppi' => $this->condivisione->persone($w),
                'con'    => $this->condivisione->conChi($tipo, [$id])[$id] ?? [],
                'parti'  => $this->condivisione->partiDi($tipo, [$id])[$id] ?? [],
            ];
        });
    }

    /**
     * "Nota interna" e "per il cliente" su un messaggio di un'attivita'.
     *
     * Stavano nello stesso endpoint di chi-vede-cosa, e li' non tornavano
     * piu': un messaggio non ha un elenco di nomi, ha due interruttori che
     * si decidono mentre si scrive.
     */
    public function flagMessaggio(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $body = $this->jsonBody();
            $quale = (string)($body['quale'] ?? '');
            $id    = (int)($body['id'] ?? 0);

            if (!in_array($quale, ['interna', 'per_cliente'], true)) {
                throw new \RuntimeException('Tipo non valido');
            }

            // il messaggio deve essere di un'attivita' di questo cantiere
            $st = $this->conn->prepare("
                SELECT c.id FROM bb_zone_task_comments c
                JOIN bb_zone_tasks t ON t.id = c.task_id
                WHERE c.id = :id AND t.worksite_id = :w
            ");
            $st->execute([':id' => $id, ':w' => $w]);
            if (!$st->fetchColumn()) {
                throw new \RuntimeException('Messaggio non trovato');
            }

            // il nome della colonna viene dal controllo qui sopra, non dalla
            // richiesta
            $this->conn->prepare("UPDATE bb_zone_task_comments SET `$quale` = :v WHERE id = :id")
                ->execute([':v' => !empty($body['valore']) ? 1 : 0, ':id' => $id]);
            return ['ok' => true];
        });
    }

    /** Scrive chi vede una cosa: esattamente queste persone, non altre. */
    public function salvaCondivisione(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $body = $this->jsonBody();
            $tipo = (string)($body['tipo'] ?? '');
            $id   = (int)($body['oggetto'] ?? 0);

            if (!in_array($tipo, \App\Service\Zone\Condivisione::TIPI, true)) {
                throw new \RuntimeException('Tipo non valido');
            }
            $this->oggettoDelCantiere($tipo, $id, $w);

            $con = $this->condivisione->salva(
                $w,
                $tipo,
                $id,
                is_array($body['persone'] ?? null) ? $body['persone'] : [],
                (int)($request->user()->id ?? 0)
            );
            // le parti intere: senza il campo (pagine o app di prima) si
            // lasciano come sono
            $parti = is_array($body['parti'] ?? null)
                ? $this->condivisione->salvaParti($w, $tipo, $id, $body['parti'])
                : ($this->condivisione->partiDi($tipo, [$id])[$id] ?? []);
            return ['con' => $con, 'parti' => $parti];
        });
    }

    /**
     * L'oggetto e' di questo cantiere? Se no si ferma qui.
     *
     * Senza questo controllo l'indirizzo del cantiere A servirebbe a
     * cambiare chi vede un documento del cantiere B: la guardia guarda che
     * chi chiede sia dell'ufficio, non che l'oggetto sia di qui.
     */
    private function oggettoDelCantiere(string $tipo, int $oggettoId, int $worksiteId): void
    {
        $tabella = match ($tipo) {
            'attivita' => 'bb_zone_tasks',
            'cartella' => 'bb_zone_folders',
            'file'     => 'bb_zone_files',
            'disegno'  => 'bb_worksite_documents',
            'modulo'   => 'bb_zone_form_assegnazioni',
            'risposta' => 'bb_zone_form_submissions',
            default    => throw new \RuntimeException('Tipo non valido'),
        };

        // il nome della tabella viene dal match qui sopra, non dalla
        // richiesta: niente che arrivi da fuori finisce nella query
        $st = $this->conn->prepare("SELECT 1 FROM `$tabella` WHERE id = :id AND worksite_id = :w");
        $st->execute([':id' => $oggettoId, ':w' => $worksiteId]);
        if (!$st->fetchColumn()) {
            throw new \RuntimeException('Non trovato in questo cantiere');
        }
    }

    /**
     * Con chi nasce condivisa una cosa appena creata.
     *
     * Se chi la crea ha mandato i nomi, quelli. Se no, e solo quando la
     * cosa e' nuova, il comportamento di prima: un capo o un operaio
     * creano per la squadra, il cliente per i clienti, l'ufficio per
     * l'ufficio. Chi carica una foto non deve fermarsi a spuntare nomi.
     *
     * Un non-ufficio non puo' mandarla al cliente nemmeno scrivendo gli id
     * a mano: cosa arriva al committente lo decide l'ufficio.
     *
     * @param  array<string, mixed> $body
     * @return int[] le persone che ci sono finite
     */
    private function condivisioneNuova(
        Request $request,
        int $worksiteId,
        string $tipo,
        int $oggettoId,
        array $body,
        bool $nuova = true
    ): array {
        $ruolo = $this->ruoloQui($request);
        $haPersone = is_array($body['persone'] ?? null);
        $haParti   = is_array($body['parti'] ?? null);

        if (!$haPersone && !$haParti && !$nuova) {
            return [];
        }

        // Le parti: quelle scelte, o tutte e due se nessuno ha scelto. La
        // Zone e' lo spazio comune del cantiere: cio' che uno mette lo
        // vedono gli altri, e l'ufficio stringe quando serve.
        $parti = $haParti
            ? $body['parti']
            : ($haPersone ? [] : \App\Service\Zone\Condivisione::PARTI_PREDEFINITE);
        $this->condivisione->salvaParti($worksiteId, $tipo, $oggettoId, $parti);

        $persone = $haPersone ? array_map('intval', $body['persone']) : [];
        // chi la crea la vede sempre, anche se non si e' messo nell'elenco
        $io = (int)($request->user()->id ?? 0);
        if ($io > 0 && $ruolo !== \App\Service\Zone\Accesso::UFFICIO) {
            $persone[] = $io;
        }

        return $this->condivisione->salva($worksiteId, $tipo, $oggettoId, $persone, $io);
    }

    /**
     * I moduli da compilare del cantiere. L'ufficio li vede tutti; gli altri
     * solo quelli che toccano a loro (per nome o per ruolo).
     */
    public function assegnazioniModuli(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w     = (int)$request->param('id');
            $ruolo = $this->ruoloQui($request);
            $io    = (int)($request->user()->id ?? 0);

            $st = $this->conn->prepare("
                SELECT a.*, t.name AS modulo, t.client_id AS modulo_cliente,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS persona,
                       (SELECT MAX(s.created_at) FROM bb_zone_form_submissions s
                         WHERE s.assegnazione_id = a.id) AS ultima_compilazione
                FROM   bb_zone_form_assegnazioni a
                JOIN   bb_zone_form_templates t ON t.id = a.template_id
                LEFT JOIN bb_users u ON u.id = a.a_user_id
                WHERE  a.worksite_id = :w AND a.attiva = 1
                ORDER BY t.name
            ");
            $st->execute([':w' => $w]);
            $tutte = $st->fetchAll(\PDO::FETCH_ASSOC);

            if ($ruolo !== \App\Service\Zone\Accesso::UFFICIO) {
                $acc      = new \App\Service\Zone\Accesso($this->conn);
                $cantiere = $ruolo === \App\Service\Zone\Accesso::CANTIERE;
                // i colleghi di cantiere: dei loro moduli personali si vede
                // chi l'ha fatto e chi no, senza compilarli
                $colleghi = [];
                if ($cantiere) {
                    $q = $this->conn->prepare("SELECT user_id, ruolo FROM bb_zone_accessi WHERE worksite_id = :w");
                    $q->execute([':w' => $w]);
                    foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                        if ($acc->normalizza((string)$r['ruolo']) === \App\Service\Zone\Accesso::CANTIERE) {
                            $colleghi[(int)$r['user_id']] = true;
                        }
                    }
                }
                $fuori = [];
                $cliente = $this->clienteDiChi($request);
                foreach ($tutte as $a) {
                    // il committente vede anche le assegnazioni dei moduli
                    // della sua azienda, per seguirle e toglierle
                    $gestita = $cliente !== null && (int)($a['modulo_cliente'] ?? 0) === $cliente;
                    // capo e operaio scritti da prima sono Cantiere
                    $aRuolo = $a['a_ruolo'] !== null ? $acc->normalizza((string)$a['a_ruolo']) : null;
                    $mio = (int)($a['a_user_id'] ?? 0) === $io || ($aRuolo !== null && $aRuolo === $ruolo);
                    $squadra = $cantiere && !$mio && isset($colleghi[(int)($a['a_user_id'] ?? 0)]);
                    if ($mio || $squadra || $gestita) {
                        $a['solo_stato'] = !$mio;
                        $a['gestita']    = $gestita;
                        $fuori[] = $a;
                    }
                }
                $tutte = $fuori;
            }

            // chi l'ha fatto e chi manca in questo periodo: l'ufficio e il
            // capo, che deve stare dietro alla sua squadra
            if (in_array($ruolo, [\App\Service\Zone\Accesso::UFFICIO, \App\Service\Zone\Accesso::CANTIERE], true)) {
                $stato = (new \App\Service\Zone\ScadenzeModuli($this->conn))->stato($w);
                foreach ($tutte as &$a) {
                    $a['stato'] = $stato[(int)$a['id']] ?? null;
                }
                unset($a);
            }

            $tutte = $this->conChiSuRighe($request, 'modulo', $tutte);
            return $tutte;
        });
    }

    /** Assegna un modulo: a una persona o a un ruolo, una volta o a cadenza. */
    public function salvaAssegnazione(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w    = (int)$request->param('id');
            $body = $this->jsonBody();

            $tpl = $this->templateDelCantiere($request, (int)($body['template_id'] ?? 0));
            // il committente assegna i modelli della sua azienda, non quelli di CSM
            if (!$this->accesso->daUfficio($request->user(), $w)) {
                $cliente = $this->clienteDiChi($request);
                if ($cliente === null || (int)($tpl['client_id'] ?? 0) !== $cliente) {
                    \App\Service\Zone\Accesso::nega('Puoi assegnare solo i moduli creati dalla tua azienda');
                }
            }

            $aUser  = (int)($body['a_user_id'] ?? 0) ?: null;
            // capo e operaio (pagine o app di prima) sono Cantiere
            $aRuolo = is_string($body['a_ruolo'] ?? null)
                ? (new \App\Service\Zone\Accesso($this->conn))->normalizza($body['a_ruolo'])
                : null;
            if (!$aUser && !$aRuolo) throw new \RuntimeException('Scegli a chi tocca compilarlo');

            $frequenza = in_array($body['frequenza'] ?? '', ['una_volta', 'giornaliera', 'settimanale', 'mensile'], true)
                ? $body['frequenza'] : 'una_volta';
            $scadenza = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($body['scadenza'] ?? ''))
                ? $body['scadenza'] : null;
            $this->conn->prepare("
                INSERT INTO bb_zone_form_assegnazioni
                    (worksite_id, template_id, a_ruolo, a_user_id, frequenza, scadenza, created_by)
                VALUES (:w, :t, :r, :u, :f, :s, :by)
            ")->execute([
                ':w' => $w, ':t' => (int)$tpl['id'], ':r' => $aUser ? null : $aRuolo, ':u' => $aUser,
                ':f' => $frequenza, ':s' => $scadenza,
                ':by' => (int)($request->user()->id ?? 0) ?: null,
            ]);
            $id = (int)$this->conn->lastInsertId();
            // chi legge le risposte: i nomi mandati, se no nessuno — un
            // modulo DPI lo legge l'ufficio, e l'ufficio vede tutto
            $this->condivisione->salva(
                $w,
                'modulo',
                $id,
                is_array($body['persone'] ?? null) ? $body['persone'] : [],
                (int)($request->user()->id ?? 0)
            );
            // chi legge le risposte: di base le leggono tutti e tre, come il
            // resto della Zone; l'ufficio stringe per i moduli interni
            $this->condivisione->salvaParti(
                $w, 'modulo', $id,
                is_array($body['parti'] ?? null)
                    ? $body['parti']
                    : (is_array($body['persone'] ?? null) && $body['persone'] ? [] : \App\Service\Zone\Condivisione::PARTI_PREDEFINITE)
            );
            $this->avvisa(fn($a) => $a->modulo($id, (int)($request->user()->id ?? 0)));
            return ['id' => $id];
        });
    }

    /**
     * Avvisa di una segnalazione appena aperta.
     *
     * Passa dal servizio delle segnalazioni, che e' l'unico posto dove sta
     * scritto chi la deve ricevere: ufficio e capi, e il ripiego quando a
     * quel cantiere non e' attaccato nessuno dell'ufficio.
     */
    private function avvisaSegnalazione(int $segnalazioneId): void
    {
        try {
            $notifiche = new \App\Service\Notifications\NotificationService($this->conn, $this->config);
            (new \App\Service\Zone\Segnalazioni($this->conn, new \App\Service\Zone\Messaggi($this->conn)))
                ->avvisaNuova($notifiche, $segnalazioneId);
        } catch (\Throwable $e) {
            // la segnalazione e' salvata: se l'avviso non parte non si
            // perde, si vede aprendo il cantiere
            error_log('[Zone avviso segnalazione] ' . $e->getMessage());
        }
    }

    /** Toglie un "da compilare". Le compilazioni gia' fatte restano. */
    public function disattivaAssegnazione(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);

        $this->jsonResponse(function () use ($request) {
            $w = (int)$request->param('id');
            $a = (int)$request->param('aId');
            // il committente toglie solo le assegnazioni dei moduli della sua
            // azienda: quelle di CSM (DPI della squadra) restano
            if (!$this->accesso->daUfficio($request->user(), $w)) {
                $st = $this->conn->prepare('SELECT t.client_id FROM bb_zone_form_assegnazioni a
                    JOIN bb_zone_form_templates t ON t.id = a.template_id WHERE a.id = :a AND a.worksite_id = :w');
                $st->execute([':a' => $a, ':w' => $w]);
                $cliente = $this->clienteDiChi($request);
                if ($cliente === null || (int)$st->fetchColumn() !== $cliente) {
                    \App\Service\Zone\Accesso::nega('Puoi rimuovere solo le assegnazioni dei moduli della tua azienda');
                }
            }
            $this->conn->prepare('UPDATE bb_zone_form_assegnazioni SET attiva = 0 WHERE id = :a AND worksite_id = :w')
                ->execute([':a' => $a, ':w' => $w]);
            return ['ok' => true];
        });
    }

    /**
     * Manda un avviso della Zone senza poter rompere quello che si e'
     * appena salvato: se il push fallisce, il dato resta.
     *
     * @param callable(\App\Service\Zone\AvvisiZona): void $cosa
     */
    private function avvisa(callable $cosa): void
    {
        try {
            $cosa(new \App\Service\Zone\AvvisiZona(
                $this->conn,
                new \App\Service\Notifications\NotificationService($this->conn, $this->config)
            ));
        } catch (\Throwable $e) {
            error_log('[Zone avviso] ' . $e->getMessage());
        }
    }

    /** L'assegnazione, se e' di questo cantiere e tocca a chi chiede. */
    private function assegnazioneDi(int $worksiteId, int $assegnazioneId): ?array
    {
        if ($assegnazioneId <= 0) {
            return null;
        }
        $st = $this->conn->prepare('SELECT * FROM bb_zone_form_assegnazioni WHERE id = :a AND worksite_id = :w AND attiva = 1');
        $st->execute([':a' => $assegnazioneId, ':w' => $worksiteId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ─── Chi vede cosa ────────────────────────────────────────────────────────
    //
    // Le regole stanno in App\Service\Zone\Accesso; qui si applicano agli
    // oggetti della Zone. Ogni oggetto chiesto per id si controlla che sia
    // DI QUESTO CANTIERE e VISIBILE a chi chiede: senza il primo controllo,
    // chi entra in un cantiere potrebbe toccare le attivita' di un altro
    // cambiando un numero nell'indirizzo.

    /**
     * Il modello di un modulo, se si usa in questo cantiere.
     *
     * Prima si cercava solo per numero: dall'indirizzo di un cantiere
     * qualsiasi si leggeva, si cambiava o si cancellava il modello di un
     * altro cantiere, o uno universale che usano tutti. Bastava il ruolo
     * cliente, che i moduli li puo' gestire.
     *
     * Per leggerlo basta che sia di qui o universale. Per cambiarlo:
     * l'ufficio quelli di qui e gli universali, gli altri solo quelli di
     * qui che hanno fatto loro.
     */
    private function templateDelCantiere(Request $request, int $tplId, bool $modifica = false): array
    {
        $w   = (int)$request->param('id');
        $tpl = $this->formRepo->find($tplId);
        $suo = $tpl && ((int)($tpl['worksite_id'] ?? 0) === $w
            || ($tpl['worksite_id'] === null
                && ($tpl['client_id'] === null || (int)$tpl['client_id'] === $this->clienteDelCantiere($w))));
        if (!$suo) {
            throw new \RuntimeException('Modulo non trovato');
        }
        if (!$modifica || $this->accesso->daUfficio($request->user(), $w)) {
            return $tpl;
        }
        // il committente cambia i modelli della sua azienda, gli altri quelli
        // creati da loro; quelli di CSM per tutti i cantieri restano a CSM
        $cliente = $this->clienteDiChi($request);
        $mio = (int)($tpl['created_by'] ?? 0) === (int)($request->user()->id ?? 0);
        $dellaSuaAzienda = $cliente !== null && (int)($tpl['client_id'] ?? 0) === $cliente;
        if (!$mio && !$dellaSuaAzienda) {
            \App\Service\Zone\Accesso::nega('Questo modulo puo\' essere modificato solo da chi l\'ha creato o da CSM');
        }
        return $tpl;
    }

    /** Il committente che sta guardando, o null se non e' un committente qui. */
    private function clienteDiChi(Request $request): ?int
    {
        if ($this->ruoloQui($request) !== \App\Service\Zone\Accesso::CLIENTE) {
            return null;
        }
        $c = (int)($request->user()->client_id ?? 0);
        return $c > 0 ? $c : null;
    }

    private function clienteDelCantiere(int $w): int
    {
        $st = $this->conn->prepare('SELECT client_id FROM bb_worksites WHERE id = :w');
        $st->execute([':w' => $w]);
        return (int)$st->fetchColumn();
    }

    /**
     * Cancella chi l'ha messo, o l'ufficio. Prima i file, le cartelle e le
     * attivita' le cancellava solo l'ufficio: un cliente che caricava la
     * foto sbagliata se la teneva.
     */
    private function pretendeMio(Request $request, int $autore, string $cosa): void
    {
        if ($this->accesso->daUfficio($request->user(), (int)$request->param('id'))) {
            return;
        }
        if ($autore <= 0 || $autore !== (int)($request->user()->id ?? -1)) {
            \App\Service\Zone\Accesso::nega("Puoi cancellare solo i tuoi $cosa");
        }
    }

    /** Il ruolo di chi chiede su questo cantiere (la guardia e' gia' passata). */
    private function ruoloQui(Request $request): string
    {
        return $this->accesso->ruolo($request->user(), (int)$request->param('id'))
            ?? \App\Service\Zone\Accesso::nega('Non hai accesso a questo cantiere');
    }

    /** L'attivita', se e' di questo cantiere e chi chiede la vede. */
    private function taskVisibile(Request $request, int $taskId): array
    {
        $task = $this->zoneRepo->find($taskId);
        if (!$task || (int)$task['worksite_id'] !== (int)$request->param('id')
            || !$this->condiviso($request, 'attivita', $taskId, (int)($task['created_by'] ?? 0))) {
            Response::json(['ok' => false, 'error' => 'Attivita non trovata'], 404);
        }
        return $task;
    }

    /** La voce di checklist deve essere di quell'attivita'. */
    private function voceDelTask(int $itemId, int $taskId): void
    {
        $voce = $this->zoneRepo->findChecklistItem($itemId);
        if (!$voce || (int)$voce['task_id'] !== $taskId) {
            Response::json(['ok' => false, 'error' => 'Elemento non trovato'], 404);
        }
    }

    /**
     * Chi puo' spostare un'attivita' e dove.
     *
     * - "Verificato" lo mette solo l'ufficio: e' il collaudo, non il "fatto".
     * - Il cliente guarda e commenta, non sposta.
     * - L'operaio sposta solo le attivita' sue o della squadra.
     */
    private function pretendeStato(Request $request, array $task, string $stato): void
    {
        $ruolo = $this->ruoloQui($request);
        if ($ruolo === \App\Service\Zone\Accesso::UFFICIO) {
            return;
        }
        if (!in_array($stato, ['open', 'in_progress', 'complete', 'verified'], true)) {
            throw new \RuntimeException('Stato non valido');
        }
        if ($stato === 'verified' || ($task['status'] ?? '') === 'verified') {
            \App\Service\Zone\Accesso::nega("Verificare, o riaprire un'attivita' verificata, lo fa CSM");
        }
        $this->pretendeChiCiLavora($request, $task);
    }

    /**
     * Porta avanti un'attivita' (stato, checklist) solo chi ce l'ha.
     *
     * Data a una persona: quella persona. Data a un gruppo (la squadra, il
     * cliente, l'ufficio): chi ne fa parte. E sempre CSM e il committente,
     * che dirige il cantiere: portano avanti qualsiasi attivita' che vedono
     * (verificare resta a CSM, vedi pretendeStato). Un altro della squadra
     * no: assegnare vuol dire "la fai tu".
     */
    private function pretendeChiCiLavora(Request $request, array $task): void
    {
        $ruolo = $this->ruoloQui($request);
        if ($ruolo === \App\Service\Zone\Accesso::UFFICIO || $ruolo === \App\Service\Zone\Accesso::CLIENTE) {
            return;
        }
        $io      = (int)($request->user()->id ?? -1);
        $persona = (int)($task['assignee_user_id'] ?? 0);
        $mia = $persona > 0
            ? $persona === $io
            : self::gruppoTask((string)($task['assegnata_a'] ?? 'squadra')) === $ruolo;
        if (!$mia) {
            \App\Service\Zone\Accesso::nega(
                $persona > 0 ? "Questa attivita' e' assegnata a un'altra persona" : "Questa attivita' e' assegnata a un altro gruppo"
            );
        }
    }

    /** Il disegno e' di questo cantiere, e chi chiede lo vede? */
    private function disegnoDelCantiere(Request $request, int $docId): bool
    {
        $st = $this->conn->prepare('SELECT created_by FROM bb_worksite_documents WHERE id = :d AND worksite_id = :w AND is_deleted = 0');
        $st->execute([':d' => $docId, ':w' => (int)$request->param('id')]);
        $creato = $st->fetchColumn();
        return $creato !== false && $this->condiviso($request, 'disegno', $docId, (int)$creato);
    }

    /** I campi che il repository non conosce: per ora il luogo. */
    private function scriviDettagliTask(int $taskId, array $body): void
    {
        if (!array_key_exists('luogo', $body)) {
            return;
        }
        $luogo = mb_substr(trim((string)$body['luogo']), 0, 160);
        try {
            $this->conn->prepare('UPDATE bb_zone_tasks SET luogo = :l WHERE id = :id')
                ->execute([':l' => $luogo !== '' ? $luogo : null, ':id' => $taskId]);
        } catch (\PDOException $e) {
            // colonna non ancora creata (migrazione da lanciare)
        }
    }

    /** Il ruolo a cui tocca un'attivita' assegnata a un gruppo (null = a una persona). */
    private static function gruppoTask(string $assegnataA): ?string
    {
        return match ($assegnataA) {
            'squadra', 'capi', 'cantiere' => \App\Service\Zone\Accesso::CANTIERE,
            'cliente'                     => \App\Service\Zone\Accesso::CLIENTE,
            'ufficio'                     => \App\Service\Zone\Accesso::UFFICIO,
            default                       => null,
        };
    }

    /**
     * Chi vede un'attivita', e a chi tocca.
     *
     * Chi la vede sono nomi, e chi non e' dell'ufficio non puo' darla al
     * cliente: cosa arriva al committente lo decide l'ufficio, come prima
     * faceva il limite sulle etichette.
     */
    private function scriviVisibilitaTask(
        int $worksiteId,
        int $taskId,
        string $ruolo,
        array $body,
        bool $nuova,
        Request $request
    ): void {
        if ($nuova || array_key_exists('persone', $body)) {
            $this->condivisioneNuova($request, $worksiteId, 'attivita', $taskId, $body, $nuova);
        }

        $campi = [];
        if ($nuova || array_key_exists('assegnata_a', $body)) {
            $a = (string)($body['assegnata_a'] ?? '');
            // capi e squadra sono le stesse persone: una parola sola
            if ($a === 'capi' || $a === 'cantiere') {
                $a = 'squadra';
            }
            if (!in_array($a, ['persona', 'squadra', 'ufficio', 'cliente'], true)) {
                // senza scelta: il cliente chiede all'ufficio, gli altri
                // danno lavoro alla squadra
                // e un'attivita' che l'ufficio tiene per se' ("solo ufficio")
                // non si da' alla squadra: a chi tocca, la vede
                $soloUfficio = ($body['parti'] ?? null) === [] && empty($body['persone']);
                $a = !empty($body['assignee_user_id']) ? 'persona'
                   : ($ruolo === \App\Service\Zone\Accesso::CLIENTE || $soloUfficio ? 'ufficio' : 'squadra');
            }
            // Al cliente assegna l'ufficio, o il cliente stesso. All'ufficio
            // puo' assegnare chiunque: prima il cliente che apriva
            // un'attivita' "per l'ufficio" se la vedeva girare alla squadra.
            if ($a === 'cliente' && !in_array($ruolo, [\App\Service\Zone\Accesso::UFFICIO, \App\Service\Zone\Accesso::CLIENTE], true)) {
                $a = 'squadra';
            }
            $campi['assegnata_a'] = $a;
        }
        if (!$campi) {
            $this->condivisione->aggiungiAssegnatari($worksiteId, $taskId);
            return;
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($campi)));
        $par = [':id' => $taskId];
        foreach ($campi as $k => $v) {
            $par[":$k"] = $v;
        }
        $this->conn->prepare("UPDATE bb_zone_tasks SET $set WHERE id = :id")->execute($par);
        $this->condivisione->aggiungiAssegnatari($worksiteId, $taskId);
    }

    /**
     * Chi ha scritto, e se e' una nota interna.
     *
     * "Nota interna" la scrive solo l'ufficio e la legge solo l'ufficio.
     * Tutto il resto lo legge chi vede l'attivita': e' la conversazione
     * del cantiere fra noi, il cliente e la squadra. per_cliente si scrive
     * ancora per le righe vecchie ma non decide piu' niente.
     */
    private function scriviCommento(int $commentId, Request $request, array $body): void
    {
        $ruolo   = $this->ruoloQui($request);
        $ufficio = $ruolo === \App\Service\Zone\Accesso::UFFICIO;
        $cliente = $ruolo === \App\Service\Zone\Accesso::CLIENTE;

        $this->conn->prepare("
            UPDATE bb_zone_task_comments
            SET author_user_id = :u, interna = :i, per_cliente = :c
            WHERE id = :id
        ")->execute([
            ':u'  => (int)($request->user()->id ?? 0) ?: null,
            ':i'  => $ufficio && !empty($body['interna']) ? 1 : 0,
            ':c'  => $cliente || ($ufficio && !empty($body['per_cliente'])) ? 1 : 0,
            ':id' => $commentId,
        ]);
    }

    /**
     * I messaggi (e le foto) di un'attivita' che chi chiede puo' leggere:
     * tutti, tranne le note interne dell'ufficio.
     *
     * Prima ogni parte aveva il suo pezzo di conversazione: il cliente
     * leggeva solo quello che l'ufficio segnava "per il cliente", la
     * squadra non leggeva mai il cliente. Sulla stessa attivita' tre
     * persone parlavano senza sentirsi, e le foto della squadra al cliente
     * non arrivavano. La Zone e' lo spazio comune: chi vede l'attivita'
     * legge il filo. Per parlare a parte ci sono le chat Squadra e Cliente.
     */
    private function filtraCommenti(array $commenti, string $ruolo, int $io): array
    {
        if ($ruolo === \App\Service\Zone\Accesso::UFFICIO) {
            return $commenti;
        }
        return array_values(array_filter(
            $commenti,
            fn(array $c) => empty($c['interna']) || (int)($c['author_user_id'] ?? 0) === $io
        ));
    }

    /**
     * Le cartelle che chi chiede vede, per id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartelleVisibili(Request $request, int $worksiteId): array
    {
        $vede  = $this->filtro($request, 'cartella');
        $tutte = [];
        $fuori = [];
        foreach ($this->fileRepo->folders($worksiteId) as $c) {
            $tutte[(int)$c['id']] = $c;
            if ($vede($c)) {
                $fuori[(int)$c['id']] = $c;
            }
        }
        // una cartella si vede solo se si vedono anche quelle che la
        // contengono: chiudere "Contratti" chiude anche cio' che c'e' dentro
        foreach (array_keys($fuori) as $id) {
            $su = $tutte[$id]['parent_id'] ?? null;
            $giri = 0;
            while ($su !== null && $su !== '' && $giri++ < 20) {
                if (!isset($tutte[(int)$su])) { break; }   // contenitore sparito: resta in cima
                if (!$vede($tutte[(int)$su])) { unset($fuori[$id]); break; }
                $su = $tutte[(int)$su]['parent_id'] ?? null;
            }
        }
        return $fuori;
    }

    /**
     * Chi vede un file.
     *
     * Un file condiviso con qualcuno vale per se'. Un file senza
     * condivisioni segue la cartella — l'ufficio decide una volta per
     * "Contratti" e non file per file — e una cartella che chi chiede non
     * vede nasconde anche quello che c'e' dentro: per questo $cartelle sono
     * quelle VISIBILI, e un file in una cartella che li' non c'e' resta
     * all'ufficio.
     *
     * Nella radice, senza cartella, un file senza condivisioni non lo vede
     * nessuno fuori dall'ufficio. Prima valeva "squadra", cioe' lo vedevano
     * tutti: con i nomi, una cosa che nessuno ha condiviso non e'
     * condivisa.
     *
     * @param array<int, array<string, mixed>> $cartelleVisibili
     */
    private function vedeFile(Request $request, array $file, array $cartelleVisibili): bool
    {
        if ($this->condiviso($request, 'file', (int)$file['id'], (int)($file['uploaded_by'] ?? 0))) {
            return true;
        }
        $cartella = $file['folder_id'] ?? null;
        if ($cartella === null || $cartella === '') {
            return false;
        }
        return isset($cartelleVisibili[(int)$cartella]);
    }

    private function fileVisibile(Request $request, ?array $file): void
    {
        $w = (int)$request->param('id');
        if (!$file || (int)$file['worksite_id'] !== $w
            || !$this->vedeFile($request, $file, $this->cartelleVisibili($request, $w))) {
            Response::json(['ok' => false, 'error' => 'File non trovato'], 404);
        }
    }

    /**
     * Il file di un disegno, per l'app: la pagina web lo prende da
     * /worksites/{id}/disegni/{doc}/view, che va con la sessione del browser
     * e non col token. Stesse regole della Zone: lo apre chi lo vede.
     */
    public function fileDisegno(Request $request): void
    {
        $this->guardia(__FUNCTION__, $request);
        $docId = (int)$request->param('docId');
        $this->documentoVisibile($request, $docId);

        $st = $this->conn->prepare('SELECT file_path, file_name FROM bb_worksite_documents WHERE id = :id AND is_deleted = 0');
        $st->execute([':id' => $docId]);
        $d = $st->fetch(\PDO::FETCH_ASSOC);

        $root = realpath(\CloudPath::getRoot());
        $real = $d ? realpath(\CloudPath::getRoot() . DIRECTORY_SEPARATOR . $d['file_path']) : false;
        if (!$d || $real === false || $root === false || strpos($real, $root) !== 0 || !is_file($real)) {
            http_response_code(404);
            exit('Disegno non trovato');
        }

        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext]
            ?? 'application/octet-stream';
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename((string)$d['file_name']) . '"');
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, no-store');
        readfile($real);
        exit;
    }

    /** Un disegno: di questo cantiere, e visibile a chi chiede. */
    private function documentoVisibile(Request $request, int $docId): void
    {
        $st = $this->conn->prepare('SELECT worksite_id, created_by FROM bb_worksite_documents WHERE id = :id');
        $st->execute([':id' => $docId]);
        $d = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$d || (int)$d['worksite_id'] !== (int)$request->param('id')
            || !$this->condiviso($request, 'disegno', $docId, (int)($d['created_by'] ?? 0))) {
            Response::json(['ok' => false, 'error' => 'Disegno non trovato'], 404);
        }
    }

    private function jsonResponse(callable $fn): void
    {
        // Il controllo sta qui e non ripetuto in ogni metodo: gli endpoint
        // che passano di qua sono trentanove, e ne basta uno dimenticato per
        // lasciare la porta aperta. Chi ne aggiunge un altro domani se lo
        // ritrova gia' protetto senza doverci pensare.
        $this->assertZone();

        // pulisce eventuali buffer ereditati
        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_start();

        try {
            $data = $fn();
            $stray = ob_get_clean();
            if ($stray !== '') {
                error_log('[FieldwireController] stray output before JSON: ' . substr($stray, 0, 500));
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[FieldwireController] ' . $e::class . ': ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'    => false,
                'error' => $e->getMessage(),
                'type'  => $e::class,
                'where' => basename($e->getFile()) . ':' . $e->getLine(),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
