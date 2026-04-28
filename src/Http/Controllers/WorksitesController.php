<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config;
use App\Infrastructure\SqlServerConnection;
use App\Repository\Activity\ActivityRepository;
use App\Repository\AttendanceRepository;
use App\Repository\Billing\BillingRepository;
use App\Repository\Documents\DocumentRepository;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Extra\ExtraRepository;
use App\Repository\Orders\OrderRepository;
use App\Repository\Users\UserRepository;
use App\Repository\Worksites\WorksiteRepository;

final class WorksitesController
{
    private \PDO $conn;
    private WorksiteRepository $worksiteRepo;
    private EquipmentRepository $equipmentRepo;
    private BillingRepository $billingRepo;
    private DocumentRepository $documentRepo;
    private ExtraRepository $extraRepo;
    private ActivityRepository $activityRepo;
    private OrderRepository $orderRepo;
    private AttendanceRepository $attendanceRepo;
    private UserRepository $userRepo;

    public function __construct(
        \PDO $conn,
        WorksiteRepository $worksiteRepo,
        EquipmentRepository $equipmentRepo,
        BillingRepository $billingRepo,
        DocumentRepository $documentRepo,
        ExtraRepository $extraRepo,
        ActivityRepository $activityRepo,
        OrderRepository $orderRepo,
        AttendanceRepository $attendanceRepo,
        UserRepository $userRepo
    ) {
        $this->conn             = $conn;
        $this->worksiteRepo     = $worksiteRepo;
        $this->equipmentRepo    = $equipmentRepo;
        $this->billingRepo      = $billingRepo;
        $this->documentRepo     = $documentRepo;
        $this->extraRepo        = $extraRepo;
        $this->activityRepo     = $activityRepo;
        $this->orderRepo        = $orderRepo;
        $this->attendanceRepo   = $attendanceRepo;
        $this->userRepo         = $userRepo;
    }

    // ── Index ──────────────────────────────────────────────────────────────────

    public function index(Request $request): void
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        $statuses        = ['In corso', 'Completato', 'Sospeso', 'A rischio'];
        $filterStatus    = $request->get('status', 'In corso');
        $statusQueryValue = $filterStatus === '' ? 'all' : $filterStatus;
        $filterYear      = $request->get('year', '');
        $filterSearch    = trim($request->get('q', ''));
        $worksitesLimit  = 200;

        $yearsStmt = $this->conn->query("SELECT DISTINCT YEAR(start_date) AS year FROM bb_worksites ORDER BY year DESC");
        $years     = $yearsStmt->fetchAll(\PDO::FETCH_COLUMN);

        $worksites = $this->worksiteRepo->getAllByCompany(
            $companyId,
            $filterStatus === 'all' ? '' : $filterStatus,
            $filterYear,
            '',
            $filterSearch,
            $worksitesLimit
        );

        $auth              = $GLOBALS['authenticated_user'] ?? [];
        $currentUsername   = $auth['username'] ?? '';
        $usersWithPriceAccess = ['alion', 'laura', 'osman', 'elena', 'ermal'];
        $canSeePrices      = in_array($currentUsername, $usersWithPriceAccess, true);

        $companyFilter = ($companyId != 1) ? "AND w.company_id = " . (int)$companyId : "";
        $contractField = ($companyId == 1) ? 'w.total_offer' : 'w.ext_total_offer';

        $countStmt = $this->conn->query("
            SELECT
                COUNT(DISTINCT CASE
                    WHEN w.status IN ('Attivo','In corso')
                     AND (
                            EXISTS (SELECT 1 FROM bb_presenze p WHERE p.worksite_id = w.id)
                         OR EXISTS (SELECT 1 FROM bb_presenze_consorziate pc WHERE pc.worksite_id = w.id)
                         )
                    THEN w.id END
                ) AS in_corso,
                COUNT(DISTINCT CASE
                    WHEN w.status = 'Completato'
                    THEN w.id END
                ) AS completato,
                COUNT(DISTINCT CASE
                    WHEN w.status = 'Sospeso'
                    THEN w.id END
                ) AS sospeso,
                COUNT(DISTINCT CASE
                    WHEN w.status IN ('Attivo','In corso')
                     AND (
                            EXISTS (SELECT 1 FROM bb_presenze p WHERE p.worksite_id = w.id)
                         OR EXISTS (SELECT 1 FROM bb_presenze_consorziate pc WHERE pc.worksite_id = w.id)
                         )
                     AND (fs.margin < 0 OR ((fs.margin / NULLIF({$contractField},0))*100 <= 30))
                    THEN w.id END
                ) AS a_rischio
            FROM bb_worksites w
            LEFT JOIN bb_worksite_financial_status fs ON fs.worksite_id = w.id
            WHERE w.is_draft = 0 {$companyFilter}
        ");

        $totalStmt = $this->conn->query("
            SELECT COUNT(*) FROM bb_worksites WHERE 1=1 {$companyFilter}
        ");

        $totalWorksites = (int)$totalStmt->fetchColumn();
        $counts         = $countStmt->fetch(\PDO::FETCH_ASSOC);

        $countInCorso    = (int)$counts['in_corso'];
        $countCompletato = (int)$counts['completato'];
        $countSospeso    = (int)$counts['sospeso'];
        $countRisk       = (int)$counts['a_rischio'];

        Response::view('worksites/index.html.twig', $request, compact(
            'companyId', 'statuses', 'filterStatus', 'statusQueryValue',
            'filterYear', 'filterSearch', 'years', 'worksites',
            'canSeePrices', 'currentUsername',
            'countInCorso', 'countCompletato', 'countSospeso', 'countRisk',
            'totalWorksites'
        ));
    }

    // ── My worksites (worker/client) ───────────────────────────────────────────

    public function my(Request $request): void
    {
        $user = $request->user();

        if (!in_array($user->type, ['worker', 'client'], true)) {
            Response::error('Access denied', 403);
        }

        $auth = $GLOBALS['authenticated_user'] ?? [];

        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.worksite_code, w.location
            FROM bb_worksites w
            INNER JOIN bb_worksite_users wu ON wu.worksite_id = w.id
            WHERE wu.user_id = :uid
            ORDER BY w.name
        ");
        $stmt->execute([':uid' => $auth['user_id']]);
        $worksites = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::view('worksites/my.html.twig', $request, compact('worksites'));
    }

    // ── Drafts (admin only) ────────────────────────────────────────────────────

    public function drafts(Request $request): void
    {
        $user = $request->user();

        if ($user->getCompanyId() != 1) {
            Response::error('Access denied', 403);
        }

        $drafts = $this->worksiteRepo->getDrafts();

        Response::view('worksites/drafts.html.twig', $request, compact('drafts'));
    }

    // ── Export Excel Presenze ──────────────────────────────────────────────────

    public function exportPresenze(Request $request): never
    {
        // Delegate entirely to the existing export file (headers + xlsx output)
        require APP_ROOT . '/views/worksites/export_excel_presenze_cantiere.php';
        exit;
    }

    // ── Create form ────────────────────────────────────────────────────────────

    public function create(Request $request): void
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        $clients = [];
        if ($companyId == 1) {
            $stmt = $this->conn->prepare("SELECT id, name FROM bb_clients ORDER BY name ASC");
            $stmt->execute();
            $clients = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        Response::view('worksites/create.html.twig', $request, compact('companyId', 'clients'));
    }

    // ── Store (POST /worksites) ────────────────────────────────────────────────

    public function store(Request $request): void
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        $name         = $_POST['name']         ?? '';
        $location     = $_POST['location']     ?? '';
        $start_date   = $_POST['start_date']   ?? null;
        $offer_number = !empty($_POST['offer_number']) ? $_POST['offer_number'] : null;
        $order_date   = !empty($_POST['order_date'])   ? $_POST['order_date']   : null;
        $order_number = !empty($_POST['order_number']) ? $_POST['order_number'] : null;
        $commessa     = !empty($_POST['commessa'])      ? $_POST['commessa']     : null;

        $total_offer     = $this->toDecimal($_POST['total_offer'] ?? '0');
        $descrizione     = $_POST['descrizione']     ?? '';
        $ext_descrizione = $_POST['ext_descrizione'] ?? '';

        $client_id      = ($companyId == 1) ? ($_POST['client_id'] ?? null) : 1;
        $is_placeholder = ($companyId == 1) ? 0 : 1;

        // Check client on YARD
        $yardId = null;
        $clientNameStmt = $this->conn->prepare("SELECT name FROM bb_clients WHERE id = ?");
        $clientNameStmt->execute([$client_id]);
        $clientName = $clientNameStmt->fetchColumn();

        $sqlSrv     = new SQLServer(new Config());
        $sqlSrvConn = $sqlSrv->connect();
        $yardQuery  = $sqlSrvConn->prepare("SELECT id FROM ANA_anagrafiche WHERE ragione_sociale = ?");
        $yardQuery->execute([$clientName]);

        if ($row = $yardQuery->fetch(\PDO::FETCH_ASSOC)) {
            $yardId = $row['id'];
        }

        $isDraft     = ($yardId === null);
        $clientData  = null;

        if ($isDraft && $client_id) {
            $clientDataStmt = $this->conn->prepare("SELECT * FROM bb_clients WHERE id = ?");
            $clientDataStmt->execute([$client_id]);
            $clientData = $clientDataStmt->fetch(\PDO::FETCH_ASSOC);
        }

        $draftReason      = $isDraft ? 'CLIENTE_NON_PRESENTE_SU_YARD' : null;
        $yard_worksite_id = null;

        // Create on YARD (only if not draft)
        if (!$isDraft) {
            $yardWorksiteData = [
                'name'                  => $name,
                'client_id'             => $client_id,
                'location'              => $location,
                'descrizione'           => $descrizione,
                'ext_descrizione'       => $ext_descrizione,
                'total_offer'           => $total_offer,
                'start_date'            => $start_date,
                'order_number'          => $order_number,
                'commessa'              => $commessa,
                'order_date'            => $order_date,
                'yard_client_id'        => $yardId,
                'company_id'            => $companyId,
                'is_placeholder_client' => $is_placeholder,
            ];

            $yardWorksite     = new YardWorksite($sqlSrvConn);
            $yard_worksite_id = $yardWorksite->createYardWorksite($yardWorksiteData);

            if (!$yard_worksite_id) {
                echo "<div class='alert alert-danger m-4'>Errore durante la creazione del cantiere su YARD.</div>";
                exit;
            }
        }

        // Always create in BOB
        $data = [
            'name'                  => $name,
            'client_id'             => $client_id,
            'location'              => $location,
            'descrizione'           => $descrizione,
            'ext_descrizione'       => $ext_descrizione,
            'start_date'            => $start_date,
            'offer_number'          => $offer_number,
            'yard_client_id'        => $yardId,
            'yard_worksite_id'      => $yard_worksite_id,
            'company_id'            => $companyId,
            'is_placeholder_client' => $is_placeholder,
            'is_draft'              => $isDraft ? 1 : 0,
            'draft_reason'          => $draftReason,
        ];

        if ($companyId == 1) {
            $data['is_consuntivo']  = ((int)($_POST['is_consuntivo'] ?? 0) === 1) ? 1 : 0;
            $data['prezzo_persona'] = ($data['is_consuntivo'] && !empty($_POST['prezzo_persona']))
                ? $this->toDecimal($_POST['prezzo_persona']) : null;
            $data['total_offer']    = $total_offer;
            $data['order_number']   = $order_number;
            $data['commessa']       = $commessa;
            $data['order_date']     = $order_date;
        } else {
            $data['is_consuntivo']    = 0;
            $data['prezzo_persona']   = null;
            $data['ext_total_offer']  = $this->toDecimal($_POST['ext_total_offer'] ?? '0');
            $data['ext_order_number'] = $_POST['ext_order_number'] ?? null;
            $data['ext_order_date']   = $_POST['ext_order_date']   ?? null;
        }

        $draftId = $this->worksiteRepo->create($data);

        if (!$draftId) {
            echo "<div class='alert alert-danger m-4'>Errore durante la creazione del cantiere in BOB.</div>";
            exit;
        }

        // Send draft activation email
        if ($isDraft) {
            try {
                $mailer = new Mailer();
                $mailer->setSender('alerts');
                $mail = $mailer->getMailer();
                $mail->addAddress('amministrazione@consorziosoluzionemontaggi.it');
                $mail->addCC('info@csmontaggi.it');

                $appUrl         = rtrim($_ENV['APP_URL'], '/');
                $activationLink = $appUrl . "/worksites/activate/{$draftId}";

                $mail->Subject = "🚧 Cantiere in bozza – azione richiesta";

                $rowsHtml = '';
                if ($clientData) {
                    foreach ($clientData as $key => $value) {
                        if ($key === 'id') continue;
                        $rowsHtml .= '<tr>
                            <td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-weight:600;">'
                            . htmlspecialchars(ucwords(str_replace('_', ' ', $key)))
                            . '</td>
                            <td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">'
                            . nl2br(htmlspecialchars((string)$value))
                            . '</td>
                        </tr>';
                    }
                }

                $mail->Body = '
<div style="font-family:Segoe UI,sans-serif;font-size:14px;color:#111">
    <div style="text-align:center;margin-bottom:20px;">
        <img src="https://bob.csmontaggi.it/includes/template/dist/images/logo.png" alt="BOB" style="max-width:160px;height:auto;">
    </div>
    <h2 style="text-align:center;">🚧 Cantiere creato in bozza</h2>
    <div style="background:#f3f4f6;border-left:4px solid #2563eb;padding:12px 16px;margin:20px 0;border-radius:6px;">
        <div style="margin-bottom:6px;">
            <strong>Nome cantiere:</strong><br>
            <span style="font-size:16px;color:#1e40af;">' . htmlspecialchars($name) . '</span>
        </div>
        <div>
            <strong>Numero ordine:</strong><br>
            <span style="font-size:15px;color:#374151;">' . (!empty($order_number) ? htmlspecialchars($order_number) : '—') . '</span>
        </div>
    </div>
    <p>Il cantiere indicato sopra è stato salvato in <strong>BOZZA</strong> perché il cliente non è presente su Business (YARD).</p>
    <p><strong>Dati cliente da inserire su Business:</strong></p>
    <table style="border-collapse:collapse;width:100%;margin-top:10px">' . $rowsHtml . '</table>
    <p style="margin-top:20px">Dopo aver inserito il cliente su Business, è possibile procedere con l\'attivazione del cantiere:</p>
    <p style="margin:20px 0;text-align:center;">
        <a href="' . $activationLink . '" style="background:#2563eb;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;">✅ Attiva cantiere</a>
    </p>
    <p style="margin-top:25px;font-size:13px;color:#92400e;background:#fffbeb;padding:10px 14px;border-radius:6px;border:1px solid #fde68a;">
        ⚠️ <strong>Nota importante:</strong><br>
        prima di cliccare su <em>Attiva cantiere</em>, assicurarsi di essere <strong>già autenticati su BOB</strong>.
    </p>
    <p style="font-size:12px;color:#6b7280;text-align:center;margin-top:30px">Mail automatica da BOB – Non rispondere a questa mail.</p>
</div>';

                $mail->send();
            } catch (\Exception $e) {
                \App\Infrastructure\LoggerFactory::mail()->error('[WORKSITE DRAFT MAIL] ' . $e->getMessage());
            }
        }

        if ($isDraft) {
            $_SESSION['info'] = 'Cantiere salvato in bozza, in attesa di conferma da Amministrazione';
            Response::redirect('/worksites');
        } else {
            $_SESSION['success'] = 'Cantiere creato con successo!';
            Response::redirect('/worksites');
        }
    }

    // ── Show (GET + POST for tasks/comments) ───────────────────────────────────

    public function show(Request $request): void
    {
        $user      = $request->user();
        $auth      = $GLOBALS['authenticated_user'] ?? [];
        $worksite_id = $request->intParam('id');

        if ($worksite_id === 0) {
            Response::error("ID cantiere non valido.", 400);
        }

        // Use repository for the raw load; keep Worksite object for getters used downstream
        $worksiteData = $this->worksiteRepo->findById($worksite_id);
        if (!$worksiteData) {
            Response::error("Cantiere non trovato.", 404);
        }
        $worksite = new Worksite($this->conn, $worksite_id);

        $isWorker = ($user->type === 'worker');

        // POST handlers (tasks / comments)
        if ($request->isPost()) {
            if (isset($_POST['add_task'])) {
                $title       = trim($_POST['task_title']       ?? '');
                $description = trim($_POST['task_description'] ?? '');
                $assignedTo  = trim($_POST['assigned_to']      ?? '');
                $dueDate     = $_POST['due_date'] ?? null;

                if (!empty($title)) {
                    $this->worksiteRepo->addTask($worksite_id, $title, $description, $assignedTo, 'Da fare', $dueDate);

                    $userStmt = $this->conn->prepare("SELECT id FROM bb_users WHERE username = :username LIMIT 1");
                    $userStmt->execute([':username' => $assignedTo]);
                    $userRow = $userStmt->fetch(\PDO::FETCH_ASSOC);

                    if ($userRow) {
                        $userId         = $userRow['id'];
                        $assignedById   = $auth['user_id'];
                        $assignedByName = $auth['username'];
                        $linkToTask     = "/worksites/{$worksite_id}#tasks";

                        $notifStmt = $this->conn->prepare(
                            "INSERT INTO bb_notifications (user_id, title, message, created_by)
                             VALUES (:user_id, :title, :message, :created_by)"
                        );
                        $notifStmt->execute([
                            ':user_id'    => $userId,
                            ':title'      => 'Nuovo Task Assegnato',
                            ':message'    => 'Ti è stato assegnato il task: "' . $title . '" nel cantiere ' . $worksite->getName(),
                            ':created_by' => $assignedById,
                        ]);
                    }
                }

                Response::redirect("/worksites/{$worksite_id}");
            }

            if (isset($_POST['update_task'])) {
                $taskId = intval($_POST['task_id'] ?? 0);
                $status = $_POST['status'] ?? '';
                $this->worksiteRepo->updateTask($worksite_id, $taskId, $status);
                Response::redirect("/worksites/{$worksite_id}");
            }

            if (isset($_POST['delete_task'])) {
                $taskId = intval($_POST['task_id'] ?? 0);
                $this->worksiteRepo->deleteTask($worksite_id, $taskId);
                Response::redirect("/worksites/{$worksite_id}");
            }

            if (isset($_POST['add_comment'])) {
                $taskId  = intval($_POST['task_id']      ?? 0);
                $userId  = $auth['user_id'];
                $comment = trim($_POST['comment_text']   ?? '');

                if (!empty($comment)) {
                    $this->worksiteRepo->addTaskComment($taskId, $userId, $comment);
                }
                Response::redirect("/worksites/{$worksite_id}");
            }
        }

        // Access check for workers/clients
        if (in_array($user->type, ['worker', 'client'], true)) {
            $accessStmt = $this->conn->prepare("
                SELECT 1 FROM bb_worksite_users
                WHERE worksite_id = :wid AND user_id = :uid LIMIT 1
            ");
            $accessStmt->execute([':wid' => $worksite_id, ':uid' => $auth['user_id']]);

            if (!$accessStmt->fetchColumn()) {
                http_response_code(403);
                $custom403 = APP_ROOT . '/bob403.html';
                if (file_exists($custom403)) {
                    require $custom403;
                } else {
                    echo '403 – Accesso non autorizzato';
                }
                exit;
            }
        }

        // Offer link
        $offerId = null;
        if ($worksite->getOfferNumber()) {
            $offerStmt = $this->conn->prepare("SELECT id FROM bb_offers WHERE offer_number = :num LIMIT 1");
            $offerStmt->execute([':num' => $worksite->getOfferNumber()]);
            $offerId = $offerStmt->fetchColumn() ?: null;
        }

        // Presenze
        $presenze     = $this->attendanceRepo->getByWorksiteId($worksite_id);
        $presenzeCons = $this->attendanceRepo->getConsorziateFiltered('', '', $worksite_id, '');
        $allPresenze  = $presenze;

        $stmt = $this->conn->prepare("
            SELECT SUM(CASE turno WHEN 'Intero' THEN 1 WHEN 'Mezzo' THEN 0.5 ELSE 0 END) AS tot_nostri
            FROM bb_presenze WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $worksite_id]);
        $presenzeNostriEq = (float)$stmt->fetchColumn();

        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(quantita),0) FROM bb_presenze_consorziate WHERE worksite_id = :wid
        ");
        $stmt->execute(['wid' => $worksite_id]);
        $presenzeConsEq = (float)$stmt->fetchColumn();

        $presenzeTotaliEq = $presenzeNostriEq + $presenzeConsEq;

        // Lifting equipment
        $mezzi       = $this->equipmentRepo->getByWorksite($worksite_id);
        $mezzi_count = $this->equipmentRepo->getTotalByWorksite($worksite_id);

        // Financial stats
        $statsHandler    = new WorksiteStats($this->conn, $worksite_id);
        $rawSummary      = $statsHandler->getSummary();
        $margin          = $rawSummary['andamento'];

        $yardStatsStmt = $this->conn->prepare("
            SELECT * FROM bb_cantiere_stats_2025
            WHERE cantiere_id_sqlsrv = (
                SELECT yard_worksite_id FROM bb_worksites WHERE id = :wid LIMIT 1
            )
        ");
        $yardStatsStmt->execute(['wid' => $worksite_id]);
        $yardStatsRow = $yardStatsStmt->fetch(\PDO::FETCH_ASSOC);
        if ($yardStatsRow) {
            $margin -= (float)$yardStatsRow['totale_complessivo'];
        }

        // Build flat statsSummary for Twig (replaces inline stats.php computations)
        $lavoratori     = $statsHandler->getTotaleLavoratori();
        $pasti          = $statsHandler->getTotalePasti();
        $hotel          = $statsHandler->getTotaleHotel();
        $giorniLavorati = $statsHandler->getGiorniLavorati();
        $costi          = $rawSummary['costi'];
        $ricavi         = $rawSummary['ricavi'];

        // Merge yard historical data into BOB stats (same logic as legacy stats.php)
        if ($yardStatsRow) {
            $costi['nostri']      += (float)$yardStatsRow['presenze_consorzio_costo'];
            $costi['ordini']      += (float)$yardStatsRow['correct_costo_consorziate'];
            $costi['mezzi']       += (float)$yardStatsRow['mezzi_costo'];
            $costi['hotel']       += (float)$yardStatsRow['hotel_costo'];
            $costi['pasti_nostri'] += (float)$yardStatsRow['pasti_costo'];
            $costi['tot_costi']   +=
                (float)$yardStatsRow['presenze_consorzio_costo'] +
                (float)$yardStatsRow['correct_costo_consorziate'] +
                (float)$yardStatsRow['mezzi_costo'] +
                (float)$yardStatsRow['hotel_costo'] +
                (float)$yardStatsRow['pasti_costo'];

            $lavoratori['nostri']      += (int)$yardStatsRow['presenze_consorzio_qta'];
            $lavoratori['consorziate'] += (int)$yardStatsRow['presenze_consorziate_qta'];
            $lavoratori['totale']       = $lavoratori['nostri'] + $lavoratori['consorziate'];

            $pasti['noi']['qta']      += (int)$yardStatsRow['pasti_qta'];
            $pasti['noi']['euro']     += (float)$yardStatsRow['pasti_costo'];
            $pasti['totale']['qta']   += (int)$yardStatsRow['pasti_qta'];
            $pasti['totale']['euro']  += (float)$yardStatsRow['pasti_costo'];

            $hotel['nostri']['qta']   += (int)$yardStatsRow['presenze_consorzio_qta'];
            $hotel['nostri']['euro']  += (float)$yardStatsRow['hotel_costo'];
            $hotel['totale']['qta']   += (int)$yardStatsRow['presenze_consorzio_qta'] + (int)$yardStatsRow['presenze_consorziate_qta'];
            $hotel['totale']['euro']  += (float)$yardStatsRow['hotel_costo'];
        }

        $statsSummary   = [
            'giorni_lavorati'         => $giorniLavorati,
            'fatturato'               => $ricavi['tot_ricavi'],
            'is_consuntivo'           => $ricavi['is_consuntivo'],
            'prezzo_persona'          => $ricavi['prezzo_persona'],
            'ricavo_stimato'          => $ricavi['ricavo_stimato'],
            'costo_totale'            => $costi['tot_costi'],
            'lavoratori_nostri'       => $lavoratori['nostri'],
            'lavoratori_consorziate'  => $lavoratori['consorziate'],
            'lavoratori_totale'       => $lavoratori['totale'],
            'pasti_noi_qta'           => $pasti['noi']['qta'],
            'pasti_noi_euro'          => $pasti['noi']['euro'],
            'pasti_loro_qta'          => $pasti['loro']['qta'],
            'pasti_loro_euro'         => $pasti['loro']['euro'],
            'pasti_totale_qta'        => $pasti['totale']['qta'],
            'pasti_totale_euro'       => $pasti['totale']['euro'],
            'hotel_nostri_qta'        => $hotel['nostri']['qta'],
            'hotel_nostri_euro'       => $hotel['nostri']['euro'],
            'hotel_consorziate_qta'   => $hotel['consorziate']['qta'],
            'hotel_consorziate_euro'  => $hotel['consorziate']['euro'],
            'hotel_totale_qta'        => $hotel['totale']['qta'],
            'hotel_totale_euro'       => $hotel['totale']['euro'],
            'costi_breakdown'         => [
                ['voce' => 'Presenze Nostri', 'importo' => $costi['nostri']],
                ['voce' => 'Consorziate',     'importo' => $costi['ordini']],
                ['voce' => 'Pasti',           'importo' => $costi['pasti_nostri'] + $costi['pasti_cons']],
                ['voce' => 'Hotel',           'importo' => $costi['hotel']],
                ['voce' => 'Mezzi sollev.',   'importo' => $costi['mezzi']],
            ],
        ];

        // Orders / extras / billing
        $ordini = $this->orderRepo->getByWorksiteId($worksite_id);
        $extra  = $this->extraRepo->getByWorksiteId($worksite_id);

        // Attività
        $attivita           = $this->activityRepo->getByWorksiteId($worksite_id);
        $totaleGiornateUomo = $this->activityRepo->getTotaleGiornateUomo($worksite_id);
        $attivitaPhotos     = $this->activityRepo->getPhotosGroupedByWorksite($worksite_id);
        // Inject serve_url so Twig/JS can render thumbnails without knowing the file path
        foreach ($attivitaPhotos as &$_photos) {
            foreach ($_photos as &$_p) {
                $_p['serve_url'] = "/worksites/{$worksite_id}/attivita/photos/{$_p['id']}/serve";
            }
        }
        unset($_photos, $_p);

        $articles = $this->billingRepo->getAllArticles();
        $vatCodes = $this->billingRepo->getVatCodes();
        $fatture  = $this->billingRepo->getByWorksiteId($worksite_id);

        $yardBilling = new YardWorksiteBilling(new SqlServerConnection(new Config()));
        foreach ($fatture as &$f) {
            $f['emessa_reale'] = !empty($f['yard_id']) ? $yardBilling->isEmessa((int)$f['yard_id']) : false;
        }
        unset($f);

        // Presenze date filters
        $dateFilter = $request->get('filter_date');
        if ($dateFilter) {
            $presenze = $this->attendanceRepo->getByWorksiteIdAndDate($worksite_id, $dateFilter);
        }
        $dateList     = $this->attendanceRepo->getDatesByWorksite($worksite_id);
        $dateListCons = $this->attendanceRepo->getDatesConsByWorksite($worksite_id);

        // Price access
        $currentUsername      = $auth['username'] ?? '';
        $usersWithPriceAccess = ['alion', 'laura', 'osman', 'elena', 'ermal'];
        $canSeePrices         = in_array($currentUsername, $usersWithPriceAccess, true);

        // Users
        $allUsers      = $this->userRepo->getAssignableUsers();
        $assignedUsers = $this->userRepo->getUsersByWorksite($worksite_id);

        // Documents / drawings
        $disegniStmt = $this->conn->prepare("
            SELECT d.*, u.first_name AS uploader_first, u.last_name AS uploader_last
            FROM bb_worksite_documents d
            LEFT JOIN bb_users u ON u.id = d.created_by
            WHERE d.worksite_id = :wid AND d.is_deleted = 0
              AND d.file_path LIKE '%/Disegni/%'
            ORDER BY d.created_at DESC
        ");
        $disegniStmt->execute([':wid' => $worksite_id]);
        $disegni = $disegniStmt->fetchAll(\PDO::FETCH_ASSOC);

        $documents = $this->documentRepo->getByWorksite($worksite_id);

        $isWorkerOrClient = in_array($user->type, ['worker', 'client'], true);
        if ($isWorkerOrClient) {
            $sharedDocIds = $this->documentRepo->getSharedDocIdsByUser($auth['user_id'], $worksite_id);
            $disegni      = array_filter($disegni, fn($d) => in_array((int)$d['id'], $sharedDocIds));
        }

        $disegniByCategory = [];
        foreach ($disegni as $d) {
            $category = $d['subcategory'] ?? null;
            if (!$category && preg_match('~/Disegni/([^/]+)/~', $d['file_path'], $m)) {
                $category = $m[1];
            }
            $disegniByCategory[$category ?: 'altri'][] = $d;
        }

        $allDisegniIds = array_column($disegni, 'id');
        $sharedMap     = $this->documentRepo->getSharedUsersBulk($allDisegniIds);

        $wuStmt = $this->conn->prepare("
            SELECT wu.user_id, u.first_name, u.last_name, u.username
            FROM bb_worksite_users wu
            JOIN bb_users u ON u.id = wu.user_id
            WHERE wu.worksite_id = :wid
            ORDER BY u.first_name
        ");
        $wuStmt->execute([':wid' => $worksite_id]);
        $worksiteUsers = $wuStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Compute view-level values that were previously calculated inline in view.php/billing.php
        $orderDateFormatted = '-';
        $rawOrderDate = $worksite->getOrderDate();
        if (!empty($rawOrderDate)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawOrderDate)) {
                [$y, $m, $d] = explode('-', $rawOrderDate);
                $orderDateFormatted = "$d/$m/$y";
            } elseif (preg_match('/^\d{8}$/', $rawOrderDate)) {
                $orderDateFormatted = substr($rawOrderDate, 6, 2) . '/' . substr($rawOrderDate, 4, 2) . '/' . substr($rawOrderDate, 0, 4);
            } else {
                $orderDateFormatted = $rawOrderDate;
            }
        }
        $clientName = $worksite->getClientName();
        $clientId   = $worksite->getClientId();

        // Billing modal defaults (from billing.php)
        $billingDefaultDescr = sprintf(
            'ORDINE %s - CANTIERE %s',
            $worksite->getOrderNumber(),
            $worksite->getName()
        );
        $billingRemaining = $worksite->getRemainingToBill();

        // For consuntivo: override remaining-to-bill using estimated revenue
        if ($worksite->isConsuntivo() && !empty($ricavi['ricavo_stimato'])) {
            $grossConsuntivo  = $ricavi['ricavo_stimato'] + $ricavi['extra'];
            $billingRemaining = max(0.0, $grossConsuntivo - $worksite->getTotalBilled());
        }

        $userObj = $user; // alias expected by view.html.twig (userObj.type)

        Response::view('worksites/view.html.twig', $request, compact(
            'worksite_id', 'worksite', 'isWorker',
            'offerId', 'presenze', 'presenzeCons', 'allPresenze',
            'presenzeNostriEq', 'presenzeConsEq', 'presenzeTotaliEq',
            'mezzi', 'mezzi_count',
            'statsSummary', 'yardStatsRow', 'margin',
            'ordini', 'extra', 'attivita', 'totaleGiornateUomo', 'attivitaPhotos', 'articles', 'vatCodes', 'fatture', 'yardBilling',
            'dateFilter', 'dateList', 'dateListCons',
            'canSeePrices', 'currentUsername', 'usersWithPriceAccess',
            'userObj', 'allUsers', 'assignedUsers',
            'disegni', 'disegniByCategory', 'sharedMap', 'worksiteUsers',
            'documents', 'isWorkerOrClient',
            'orderDateFormatted', 'clientName', 'clientId',
            'billingDefaultDescr', 'billingRemaining'
        ));
    }

    // ── Edit form ──────────────────────────────────────────────────────────────

    public function edit(Request $request): void
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        $worksite_id = $request->intParam('id');
        if (!$worksite_id) {
            Response::error("ID cantiere non valido.", 400);
        }

        // Store return URL
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $_SESSION['return_to'] = $_SERVER['HTTP_REFERER'];
        }

        $stmt = $this->conn->prepare("SELECT * FROM bb_worksites WHERE id = ?");
        $stmt->execute([$worksite_id]);
        $worksite = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$worksite) {
            Response::error("Cantiere non trovato.", 404);
        }

        $clients = [];
        $clientStmt = $this->conn->prepare("SELECT id, name FROM bb_clients ORDER BY name ASC");
        $clientStmt->execute();
        $clients = $clientStmt->fetchAll(\PDO::FETCH_ASSOC);

        $returnTo = $_SESSION['return_to'] ?? '';
        Response::view('worksites/edit.html.twig', $request, compact('worksite_id', 'worksite', 'companyId', 'clients', 'returnTo'));
    }

    // ── Update (POST /worksites/{id}/edit) ────────────────────────────────────

    public function update(Request $request): void
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        $worksite_id = $request->intParam('id');

        $stmt = $this->conn->prepare("SELECT * FROM bb_worksites WHERE id = ?");
        $stmt->execute([$worksite_id]);
        $worksite = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$worksite) {
            Response::error("Cantiere non trovato.", 404);
        }

        $end_date = $_POST['end_date'] ?? null;
        $end_date = empty($end_date) ? null : $end_date;

        $data = [
            'name'            => $_POST['name']     ?? '',
            'client_id'       => ($companyId == 1 && isset($_POST['client_id'])) ? $_POST['client_id'] : $worksite['client_id'],
            'location'        => $_POST['location']  ?? '',
            'descrizione'     => $_POST['descrizione']     ?? '',
            'ext_descrizione' => $_POST['ext_descrizione'] ?? '',
            'start_date'      => $_POST['start_date'] ?? null,
            'offer_number'    => isset($_POST['offer_number']) && trim($_POST['offer_number']) !== '' ? $_POST['offer_number'] : null,
            'status'          => $_POST['status']   ?? 'In Corso',
            'end_date'        => $end_date,
            'company_id'      => $companyId,
        ];

        $data['is_consuntivo']  = (($_POST['is_consuntivo'] ?? '0') === '1') ? 1 : 0;
        $data['prezzo_persona'] = ($data['is_consuntivo'] && !empty($_POST['prezzo_persona']))
            ? $this->toDecimal($_POST['prezzo_persona']) : null;

        if ($companyId == 1) {
            $data['total_offer']  = $this->toDecimal($_POST['total_offer'] ?? '0');
            $data['commessa']     = $_POST['commessa']     ?? '';
            $data['order_number'] = $_POST['order_number'] ?? '';
            $data['order_date']   = empty($_POST['order_date']) ? null : $_POST['order_date'];
        } else {
            $data['ext_total_offer']  = $this->toDecimal($_POST['ext_total_offer'] ?? '0');
            $data['ext_order_number'] = $_POST['ext_order_number'] ?? '';
            $data['ext_order_date']   = empty($_POST['ext_order_date']) ? null : $_POST['ext_order_date'];
        }

        if ($this->worksiteRepo->update($worksite_id, $data)) {
            // Resolve yard_client_id for the new client_id (if it changed)
            $yardClientId = $worksite['yard_client_id']; // default to old value
            if ($data['client_id'] != $worksite['client_id'] && $data['client_id']) {
                $clientNameStmt = $this->conn->prepare("SELECT name FROM bb_clients WHERE id = ?");
                $clientNameStmt->execute([$data['client_id']]);
                $clientName = $clientNameStmt->fetchColumn();
                if ($clientName) {
                    $sqlSrv     = new SQLServer(new Config());
                    $sqlSrvConn = $sqlSrv->connect();
                    $yardQuery  = $sqlSrvConn->prepare("SELECT id FROM ANA_anagrafiche WHERE ragione_sociale = ?");
                    $yardQuery->execute([$clientName]);
                    if ($row = $yardQuery->fetch(\PDO::FETCH_ASSOC)) {
                        $yardClientId = $row['id'];
                        // Also update yard_client_id in bb_worksites
                        $updateYardClientStmt = $this->conn->prepare("UPDATE bb_worksites SET yard_client_id = ? WHERE id = ?");
                        $updateYardClientStmt->execute([$yardClientId, $worksite_id]);
                    }
                }
            }

            $yardWorksiteData = [
                'name'           => $data['name'],
                'descrizione'    => $data['descrizione'],
                'order_number'   => $data['order_number'] ?? null,
                'order_date'     => $data['order_date']   ?? null,
                'commessa'       => $data['commessa']     ?? null,
                'start_date'     => $data['start_date'],
                'total_offer'    => $data['total_offer']  ?? 0,
                'yard_client_id' => $yardClientId,
                'location'       => $data['location'],
            ];

            $sqlSrv      = new SQLServer(new Config());
            $sqlSrvConn  = $sqlSrv->connect();
            $yardWorksite = new YardWorksite($sqlSrvConn);

            if ($yardWorksite->updateYardWorksite($worksite['yard_worksite_id'], $yardWorksiteData)) {
                $returnTo = $_POST['return_to'] ?? '/worksites';
                $_SESSION['success'] = 'Cantiere modificato con successo!';
                Response::redirect($returnTo);
            } else {
                echo "<div class='alert alert-danger m-4'>Errore durante l'aggiornamento del cantiere su YARD.</div>";
            }
        } else {
            echo "<div class='alert alert-danger m-4'>Errore durante l'aggiornamento del cantiere in MySQL.</div>";
        }
        exit;
    }

    // ── Disegni ───────────────────────────────────────────────────────────────

    public function uploadDisegno(Request $request): never
    {
        $user       = $request->user();
        $auth       = $GLOBALS['authenticated_user'] ?? [];
        $worksiteId = $request->intParam('id');

        if ($worksiteId <= 0 || empty($_FILES['file'])) {
            Response::error('Dati mancanti', 400);
        }
        if (in_array($user->type, ['worker', 'client'], true)) {
            Response::error('Accesso negato', 403);
        }

        $note      = trim($_POST['note'] ?? '');
        $replaceId = (int)($_POST['replace_id'] ?? 0);
        $category  = trim($_POST['folder'] ?? '') ?: 'altri';

        $worksite = $this->worksiteRepo->findById($worksiteId);
        if (!$worksite) {
            Response::error('Cantiere non trovato', 404);
        }

        $worksiteObj = new \Worksite($this->conn, $worksiteId);
        $targetDir   = \CloudPath::ensureDisegniPath($worksiteObj->toCloudArray(), $category);

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Errore upload file', 500);
        }

        // Validate file size (20 MB max)
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            Response::error('Il file supera la dimensione massima consentita (20 MB).', 422);
        }

        $originalName = $file['name'];
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'dwg', 'png', 'jpg', 'jpeg'], true)) {
            Response::error('Formato file non consentito', 422);
        }

        // Validate MIME type server-side
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file((string)$file['tmp_name']);
        $allowedMimes = [
            'application/pdf', 'image/png', 'image/jpeg',
            'image/vnd.dwg', 'application/acad', 'application/x-acad',
            'application/autocad_dwg', 'image/x-dwg', 'application/dwg',
            'application/x-dwg', 'application/octet-stream',
        ];
        if (!in_array($mimeType, $allowedMimes, true)) {
            Response::error('Tipo di file non consentito.', 422);
        }

        $baseName = trim(preg_replace('/[^a-zA-Z0-9 _\-]/', '', pathinfo($originalName, PATHINFO_FILENAME)));
        $filename = $baseName . '_' . date('Ymd_His') . '.' . $extension;
        $target   = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            Response::error('Impossibile salvare il file', 500);
        }

        $relativePath = \CloudPath::relativeToRoot($target);

        if ($replaceId > 0) {
            $existing = $this->documentRepo->getById($replaceId);
            if ($existing && (int)$existing['worksite_id'] === $worksiteId) {
                $this->documentRepo->archiveVersion($replaceId);
                $this->documentRepo->updateDocument($replaceId, [
                    'file_name'  => $filename,
                    'file_path'  => $relativePath,
                    'file_type'  => $extension,
                    'created_by' => $auth['user_id'],
                    'note'       => $note !== '' ? $note : null,
                ]);
            }
        } else {
            $this->documentRepo->create([
                'worksite_id' => $worksiteId,
                'file_name'   => $filename,
                'file_path'   => $relativePath,
                'file_type'   => $extension,
                'category'    => 'disegni',
                'created_by'  => $auth['user_id'],
                'note'        => $note !== '' ? $note : null,
                'subcategory' => $category,
            ]);
        }

        Response::redirect("/worksites/{$worksiteId}?tab=disegni");
    }

    public function viewDisegno(Request $request): never
    {
        $user       = $request->user();
        $auth       = $GLOBALS['authenticated_user'] ?? [];
        $docId      = $request->intParam('docId');
        $versionId  = (int)$request->get('version_id', 0);

        if ($docId <= 0) {
            Response::error('ID non valido', 400);
        }

        $worksiteId = 0;
        $filePath   = '';
        $fileName   = '';

        if ($versionId > 0) {
            $version = $this->documentRepo->getVersionById($versionId);
            if (!$version) {
                Response::error('Versione non trovata', 404);
            }
            $worksiteId = (int)$version['worksite_id'];
            $filePath   = $version['file_path'];
            $fileName   = $version['file_name'];
            $docId      = (int)$version['document_id'];
        } else {
            $stmt = $this->conn->prepare("
                SELECT d.*, w.id AS worksite_id
                FROM bb_worksite_documents d
                JOIN bb_worksites w ON w.id = d.worksite_id
                WHERE d.id = :id AND d.is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([':id' => $docId]);
            $disegno = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$disegno) {
                Response::error('Disegno non trovato', 404);
            }
            $worksiteId = (int)$disegno['worksite_id'];
            $filePath   = $disegno['file_path'];
            $fileName   = $disegno['file_name'];
        }

        if (in_array($user->type, ['worker', 'client'], true)) {
            $accessStmt = $this->conn->prepare("
                SELECT 1 FROM bb_worksite_users
                WHERE worksite_id = :wid AND user_id = :uid LIMIT 1
            ");
            $accessStmt->execute([':wid' => $worksiteId, ':uid' => $auth['user_id']]);
            if (!$accessStmt->fetchColumn()) {
                Response::error('Accesso non autorizzato', 403);
            }
            $sharedIds = $this->documentRepo->getSharedDocIdsByUser((int)$auth['user_id'], $worksiteId);
            if (!in_array($docId, $sharedIds, true)) {
                Response::error('Disegno non condiviso con te', 403);
            }
        }

        $absolutePath = \CloudPath::getRoot() . DIRECTORY_SEPARATOR . $filePath;
        if (!is_file($absolutePath)) {
            Response::error('File non trovato su disco', 404);
        }

        $ext     = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mimeMap = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

        if (isset($mimeMap[$ext])) {
            header('Content-Type: ' . $mimeMap[$ext]);
            header('Content-Disposition: inline; filename="' . basename($fileName ?: $absolutePath) . '"');
            header('Content-Length: ' . filesize($absolutePath));
            header('X-Frame-Options: SAMEORIGIN');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($fileName ?: $absolutePath) . '"');
            header('Content-Length: ' . filesize($absolutePath));
        }
        readfile($absolutePath);
        exit;
    }

    public function getVersions(Request $request): never
    {
        $docId = $request->intParam('docId');
        if ($docId <= 0) {
            Response::json([], 400);
        }
        $versions = $this->documentRepo->getVersions($docId);
        Response::json($versions);
    }

    public function deleteDisegno(Request $request): never
    {
        $user  = $request->user();
        if (in_array($user->type, ['worker', 'client'], true)) {
            Response::error('Accesso negato', 403);
        }
        $docId = $request->intParam('docId');
        if ($docId <= 0) {
            Response::error('ID non valido', 400);
        }

        // Get disegno label before deletion
        $labelStmt = $this->conn->prepare('SELECT file_name FROM bb_worksite_documents WHERE id = :id LIMIT 1');
        $labelStmt->execute([':id' => $docId]);
        $labelRow = $labelStmt->fetch(\PDO::FETCH_ASSOC);
        $disegnoLabel = $labelRow ? $labelRow['file_name'] : "Disegno #{$docId}";

        $this->documentRepo->softDelete($docId);
        AuditLogger::log($this->conn, $user, 'disegno_delete', 'disegno', $docId, $disegnoLabel);
        http_response_code(204);
        exit;
    }

    public function shareDisegno(Request $request): never
    {
        $user = $request->user();
        $auth = $GLOBALS['authenticated_user'] ?? [];
        if (in_array($user->type, ['worker', 'client'], true)) {
            Response::json(['error' => 'Non autorizzato'], 403);
        }

        $documentIds = array_map('intval', $_POST['document_ids'] ?? []);
        $userIds     = array_map('intval', $_POST['user_ids'] ?? []);
        $worksiteId  = (int)($_POST['worksite_id'] ?? 0);
        $category    = trim((string)($_POST['category'] ?? ''));

        if ($category !== '' && $worksiteId > 0) {
            $stmt = $this->conn->prepare("
                SELECT id FROM bb_worksite_documents
                WHERE worksite_id = :wid AND is_deleted = 0 AND subcategory = :cat
            ");
            $stmt->execute([':wid' => $worksiteId, ':cat' => $category]);
            $documentIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        if (empty($documentIds) || empty($userIds)) {
            Response::json(['error' => 'Seleziona almeno un disegno e un utente'], 400);
        }

        if (!empty($documentIds)) {
            $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
            $existingStmt = $this->conn->prepare("SELECT document_id, user_id FROM bb_worksite_document_shares WHERE document_id IN ($placeholders)");
            $existingStmt->execute(array_values($documentIds));
            foreach ($existingStmt->fetchAll(\PDO::FETCH_ASSOC) as $share) {
                if (!in_array((int)$share['user_id'], $userIds, true)) {
                    $this->documentRepo->unshare((int)$share['document_id'], (int)$share['user_id']);
                }
            }
        }

        $newShares = $this->documentRepo->shareWith($documentIds, $userIds, (int)$auth['user_id']);

        if ($newShares > 0) {
            $worksiteName = '';
            if ($worksiteId > 0) {
                $wsStmt = $this->conn->prepare("SELECT name FROM bb_worksites WHERE id = :id LIMIT 1");
                $wsStmt->execute([':id' => $worksiteId]);
                $worksiteName = $wsStmt->fetchColumn() ?: '';
            } elseif (!empty($documentIds)) {
                $wsStmt = $this->conn->prepare("SELECT w.name FROM bb_worksite_documents d JOIN bb_worksites w ON w.id = d.worksite_id WHERE d.id = :id LIMIT 1");
                $wsStmt->execute([':id' => $documentIds[0]]);
                $worksiteName = $wsStmt->fetchColumn() ?: '';
                $wsIdStmt = $this->conn->prepare("SELECT worksite_id FROM bb_worksite_documents WHERE id = :id LIMIT 1");
                $wsIdStmt->execute([':id' => $documentIds[0]]);
                $worksiteId = (int)$wsIdStmt->fetchColumn();
            }

            $notifStmt = $this->conn->prepare("
                INSERT INTO bb_notifications (user_id, title, message, link, created_by, is_read, created_at)
                VALUES (:uid, :title, :message, :link, :created_by, 0, NOW())
            ");
            $docCount = count($documentIds);
            $message  = $docCount === 1
                ? "Un disegno è stato condiviso con te per il cantiere {$worksiteName}"
                : "{$docCount} disegni sono stati condivisi con te per il cantiere {$worksiteName}";
            $link = $worksiteId > 0 ? "/worksites/{$worksiteId}?tab=disegni" : null;
            foreach ($userIds as $uid) {
                $notifStmt->execute([
                    ':uid'        => $uid,
                    ':title'      => 'Nuovi disegni condivisi',
                    ':message'    => $message,
                    ':link'       => $link,
                    ':created_by' => (int)$auth['user_id'],
                ]);
            }
        }

        Response::json(['success' => true, 'shared' => $newShares, 'total' => count($documentIds) * count($userIds)]);
    }

    // ── Billing ───────────────────────────────────────────────────────────────

    public function saveBilling(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $yardBilling = new \App\Domain\YardWorksiteBilling(new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()));

        $id          = $_POST['id']           ?? '';
        $data        = $_POST['data']         ?? '';
        $articolo_id = $_POST['articolo_id']  ?? '';
        $descrizione = trim($_POST['descrizione'] ?? '');
        $iva_id      = $_POST['iva_id']       ?? '';

        $rawTot      = str_replace(['.', ','], ['', '.'], trim($_POST['totale'] ?? '0'));
        $totale      = (float)$rawTot;

        if (!$worksite_id || !$data || !$articolo_id || !$iva_id) {
            $_SESSION['error'] = 'Campi obbligatori mancanti.';
            Response::redirect("/worksites/{$worksite_id}#billing");
        }

        $aliquota_iva = $this->billingRepo->getVatPercentageById((int)$iva_id);
        if ($aliquota_iva === null) {
            $_SESSION['error'] = 'IVA non trovata.';
            Response::redirect("/worksites/{$worksite_id}#billing");
        }

        $ws = $this->worksiteRepo->findById($worksite_id);
        if (!$ws) {
            $_SESSION['error'] = 'Cantiere non trovato.';
            Response::redirect("/worksites/{$worksite_id}#billing");
        }

        $yardArticleId = $this->billingRepo->getArticleYardId((int)$articolo_id);
        if (!$yardArticleId) {
            $_SESSION['error'] = 'Articolo non mappato su YARD.';
            Response::redirect("/worksites/{$worksite_id}#billing");
        }

        $dataMy = [
            'worksite_id'       => $worksite_id,
            'nome_cantiere'     => $ws['name'],
            'nome_cliente'      => $ws['client_name'],
            'data'              => $data,
            'descrizione'       => $descrizione,
            'totale_imponibile' => $totale,
            'aliquota_iva'      => $aliquota_iva,
            'articolo_id'       => $articolo_id,
            'iva_id'            => $iva_id,
            'attivita_id'       => null,
        ];

        try {
            if ($id) {
                $this->billingRepo->update((int)$id, $dataMy);

                $row     = $this->billingRepo->findById((int)$id);
                $yard_id = $row['yard_id'] ?? null;
                if ($yard_id) {
                    $dataY = [
                        'nome_cantiere'     => $ws['name'],
                        'nome_cliente'      => $ws['client_name'],
                        'data'              => $data,
                        'descrizione'       => $descrizione,
                        'aliquota_iva'      => $aliquota_iva,
                        'totale_imponibile' => $totale,
                        'articolo_id'       => $yardArticleId,
                        'cantiere_id'       => $ws['yard_worksite_id'],
                        'iva_id'            => $iva_id,
                        'attivita_id'       => null,
                    ];
                    $yardBilling->updateBrogliaccio($yard_id, $dataY);
                }

                $_SESSION['success'] = 'Fatturazione aggiornata.';
                Response::redirect("/worksites/{$worksite_id}#billing");
            } else {
                $newId = $this->billingRepo->create($dataMy);

                $dataY = [
                    'nome_cantiere'     => $ws['name'],
                    'nome_cliente'      => $ws['client_name'],
                    'data'              => $data,
                    'descrizione'       => $descrizione,
                    'aliquota_iva'      => $aliquota_iva,
                    'totale_imponibile' => $totale,
                    'articolo_id'       => $yardArticleId,
                    'cantiere_id'       => $ws['yard_worksite_id'],
                    'iva_id'            => $iva_id,
                    'attivita_id'       => null,
                ];
                $yardId = $yardBilling->insertToBrogliaccio($dataY);

                $this->billingRepo->setYardId($newId, (int)$yardId);

                $_SESSION['success'] = 'Fatturazione salvata.';
                Response::redirect("/worksites/{$worksite_id}#billing");
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#billing");
        }
    }

    public function destroyBilling(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $billingId   = $request->intParam('billingId');

        $id = $billingId ?: ($_POST['id'] ?? '');

        if (empty($id) || !$worksite_id) {
            $_SESSION['error'] = 'ID fatturazione mancante.';
            Response::redirect("/worksites/{$worksite_id}#billing");
        }

        try {
            $row = $this->billingRepo->findById((int)$id);

            if (!$row) {
                throw new \Exception("Record non trovato in bb_billing.");
            }

            if ((int)$row['worksite_id'] !== (int)$worksite_id) {
                Response::error('Access denied', 403);
            }

            $yard_id = $row['yard_id'];

            if (!empty($yard_id)) {
                $yardBilling = new \App\Domain\YardWorksiteBilling(new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()));
                $yardBilling->softDeleteBrogliaccio((int)$yard_id);
            }

            $this->billingRepo->delete((int)$id);
            AuditLogger::log($this->conn, $request->user(), 'billing_delete', 'billing', $id, "Billing #{$id}");

            $_SESSION['success'] = 'Fatturazione eliminata.';
            Response::redirect("/worksites/{$worksite_id}#billing");

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#billing");
        }
    }

    // ── Extra ─────────────────────────────────────────────────────────────────

    public function saveExtra(Request $request): never
    {
        $worksite_id = $request->intParam('id');

        $conn = $this->conn;

        $id          = $_POST['id']          ?? '';
        $data        = $_POST['data']        ?? '';
        $ordineRaw   = trim($_POST['ordine'] ?? '');
        $ordine      = $ordineRaw !== '' ? $ordineRaw : null;
        $descrizione = trim($_POST['descrizione'] ?? '');
        $totale      = $_POST['totale'] ?? '0';

        if (!$worksite_id || empty($data)) {
            $_SESSION['error'] = 'Campi obbligatori mancanti.';
            Response::redirect("/worksites/{$worksite_id}#extra");
        }

        try {
            $dataArr = [
                'worksite_id' => $worksite_id,
                'data'        => $data,
                'ordine'      => $ordine,
                'descrizione' => $descrizione,
                'totale'      => $totale,
            ];

            if (!empty($id)) {
                $extraRow = $this->extraRepo->getById((int)$id);

                if (!$extraRow) {
                    throw new \Exception("Record non trovato in bb_extra.");
                }

                $yard_id = $extraRow['yard_id'] ?? null;
                if ($yard_id !== null && $yard_id !== '') {
                    $sqlServer = new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config());
                    $yardExtra = new \YardWorksiteExtra($sqlServer);
                    $yardExtra->updateInYard((string)$yard_id, [
                        'descrizione' => $descrizione,
                        'totale'      => $totale,
                        'data'        => $data,
                        'ordine'      => $ordine,
                    ]);
                }

                $this->extraRepo->update((int)$id, $dataArr);

                $_SESSION['success'] = 'Extra aggiornato.';
                Response::redirect("/worksites/{$worksite_id}#extra");
            } else {
                $worksiteRow = $this->worksiteRepo->findById($worksite_id);

                if (!$worksiteRow) {
                    throw new \Exception("Cantiere non trovato in bb_worksites.");
                }

                $yard_worksite_id = $worksiteRow['yard_worksite_id'] ?? null;
                $yardExtraId      = null;

                if ($yard_worksite_id !== null && $yard_worksite_id !== '') {
                    $sqlServer   = new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config());
                    $yardExtra   = new \YardWorksiteExtra($sqlServer);
                    $yardExtraId = $yardExtra->insertToYard([
                        'descrizione'      => $descrizione,
                        'totale'           => $totale,
                        'yard_worksite_id' => $yard_worksite_id,
                        'data'             => $data,
                        'ordine'           => $ordine,
                    ]);
                }

                $extraId = $this->extraRepo->create($dataArr);

                if ($yardExtraId !== null && $yardExtraId !== '') {
                    $updateStmt = $this->conn->prepare("UPDATE bb_extra SET yard_id = :yard_id WHERE id = :id");
                    $updateStmt->execute([':yard_id' => $yardExtraId, ':id' => $extraId]);
                }

                $_SESSION['success'] = 'Extra creato.';
                Response::redirect("/worksites/{$worksite_id}#extra");
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#extra");
        }
    }

    public function destroyExtra(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $extraId     = $request->intParam('extraId');

        $id = $extraId ?: ($_POST['id'] ?? '');

        if (empty($id) || !$worksite_id) {
            $_SESSION['error'] = 'ID extra mancante.';
            Response::redirect("/worksites/{$worksite_id}#extra");
        }

        try {
            $extraRow = $this->extraRepo->getById((int)$id);

            if (!$extraRow) {
                throw new \Exception("Record non trovato in bb_extra.");
            }

            if ((int)$extraRow['worksite_id'] !== (int)$worksite_id) {
                Response::error('Access denied', 403);
            }

            $yard_id = $extraRow['yard_id'] ?? null;
            if ($yard_id !== null && $yard_id !== '') {
                $sqlServer = new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config());
                $yardExtra = new \YardWorksiteExtra($sqlServer);
                $yardExtra->softDeleteInYard((string)$yard_id);
            }

            $this->extraRepo->delete((int)$id);
            AuditLogger::log($this->conn, $request->user(), 'extra_delete', 'extra', $id, "Extra #{$id}");

            $_SESSION['success'] = 'Extra eliminato.';
            Response::redirect("/worksites/{$worksite_id}#extra");

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#extra");
        }
    }

    // ── Attività ──────────────────────────────────────────────────────────────

    public function saveAttivita(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $userId      = (int)(($GLOBALS['authenticated_user'] ?? [])['user_id'] ?? 0);

        $conn = $this->conn;

        $id           = $_POST['id']                     ?? '';
        $data         = $_POST['data']                   ?? '';
        $attivita     = $_POST['attivita']               ?? '';
        $persone      = $_POST['persone_impiegate']      ?? '';
        $tempo_ore    = $_POST['tempo_ore']              ?? '';
        $quantita     = $_POST['quantita']               ?? '';
        $giornata     = $_POST['giornata_uomo']          ?? '';
        $attrezzature = $_POST['attrezzature_impiegate'] ?? '';
        $problemi     = $_POST['problemi']               ?? '';
        $soluzioni    = $_POST['soluzioni']              ?? '';
        $note         = $_POST['note']                   ?? '';

        if (!$worksite_id) {
            $_SESSION['error'] = 'Campi obbligatori mancanti.';
            Response::redirect("/worksites/{$worksite_id}#attivita");
        }

        try {
            $dataArr = [
                'worksite_id'            => $worksite_id,
                'data'                   => $data,
                'attivita'               => $attivita,
                'persone_impiegate'      => $persone,
                'tempo_ore'              => $tempo_ore,
                'quantita'               => $quantita,
                'giornata_uomo'          => $giornata,
                'attrezzature_impiegate' => $attrezzature,
                'problemi'               => $problemi,
                'soluzioni'              => $soluzioni,
                'note'                   => $note,
                'created_by'             => $userId ?: null,
            ];

            if (!empty($id)) {
                $this->activityRepo->updateActivity((int)$id, $dataArr);
                $attivitaId = (int)$id;
            } else {
                $attivitaId = $this->activityRepo->createActivity($dataArr);
            }

            // Handle photo uploads — three separate inputs, one per category
            $allowedExt       = ['jpg','jpeg','png','gif','webp','heic'];
            $allowedPhotoMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic'];
            $baseDir          = dirname(APP_ROOT) . "/cloud/worksites/{$worksite_id}/attivita/{$attivitaId}";

            foreach (['problemi', 'soluzioni', 'info'] as $categoria) {
                $raw = $_FILES["photos_{$categoria}"] ?? [];
                if (empty($raw['name'])) { continue; }
                if (!is_array($raw['name'])) {
                    foreach ($raw as $k => $v) { $raw[$k] = [$v]; }
                }
                if (!is_dir($baseDir)) { mkdir($baseDir, 0775, true); }

                foreach ($raw['name'] as $i => $origName) {
                    if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
                    // Validate file size (5 MB max per photo)
                    if (($raw['size'][$i] ?? 0) > 5 * 1024 * 1024) { continue; }
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) { continue; }
                    // Validate MIME type server-side
                    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file((string)$raw['tmp_name'][$i]);
                    if (!in_array($mimeType, $allowedPhotoMimes, true)) { continue; }
                    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
                    $filename = time() . '_' . $i . '_' . $safeName;
                    $filePath = "$baseDir/$filename";
                    if (!move_uploaded_file($raw['tmp_name'][$i], $filePath)) { continue; }
                    $this->activityRepo->savePhoto([
                        'attivita_id' => $attivitaId,
                        'worksite_id' => $worksite_id,
                        'file_name'   => basename((string)$origName),
                        'file_path'   => $filePath,
                        'categoria'   => $categoria,
                        'created_by'  => $userId ?: null,
                    ]);
                }
            }

            $_SESSION['success'] = 'Attività salvata.';
            Response::redirect("/worksites/{$worksite_id}#attivita");

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#attivita");
        }
    }

    public function destroyAttivita(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $attivitaId  = $request->intParam('attivitaId');

        $id = $attivitaId ?: ($_POST['id'] ?? '');

        if (empty($id) || !$worksite_id) {
            $_SESSION['error'] = 'ID attività mancante.';
            Response::redirect("/worksites/{$worksite_id}#attivita");
        }

        try {
            $row = $this->activityRepo->getActivityById((int)$id);
            if (!$row || (int)$row['worksite_id'] !== (int)$worksite_id) {
                Response::error('Access denied', 403);
            }

            $this->activityRepo->deleteActivity((int)$id);
            AuditLogger::log($this->conn, $request->user(), 'attivita_delete', 'attivita', $id, "Attività #{$id}");

            $_SESSION['success'] = 'Attività eliminata.';
            Response::redirect("/worksites/{$worksite_id}#attivita");

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#attivita");
        }
    }

    // ── POST /worksites/{id}/attivita/{attivitaId}/photos/upload ─────────────

    public function uploadAttivitaPhoto(Request $request): never
    {
        $worksiteId = $request->intParam('id');
        $attivitaId = $request->intParam('attivitaId');
        $userId     = (int)(($GLOBALS['authenticated_user'] ?? [])['user_id'] ?? 0);
        $categoria  = $_POST['categoria'] ?? 'info';

        if (!in_array($categoria, ['info', 'problemi', 'soluzioni'], true)) {
            $categoria = 'info';
        }

        // Verify the attività belongs to this worksite
        $chk = $this->conn->prepare(
            'SELECT id FROM bb_attivita WHERE id = :id AND worksite_id = :wid LIMIT 1'
        );
        $chk->execute([':id' => $attivitaId, ':wid' => $worksiteId]);
        if (!$chk->fetch()) {
            Response::json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $allowedExt        = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'];
        $allowedPhotoMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic'];

        // Normalise $_FILES['photos'] so name/tmp_name/error are always arrays
        $raw = $_FILES['photos'] ?? [];
        if (empty($raw['name'])) {
            Response::json(['ok' => false, 'error' => 'No files uploaded'], 400);
        }
        if (!is_array($raw['name'])) {
            foreach ($raw as $k => $v) { $raw[$k] = [$v]; }
        }

        $baseDir = dirname(APP_ROOT) . "/cloud/worksites/{$worksiteId}/attivita/{$attivitaId}";
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $saved = [];

        foreach ($raw['name'] as $i => $origName) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            // Validate file size (5 MB max per photo)
            if (($raw['size'][$i] ?? 0) > 5 * 1024 * 1024) {
                continue;
            }
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }
            // Validate MIME type server-side
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file((string)$raw['tmp_name'][$i]);
            if (!in_array($mimeType, $allowedPhotoMimes, true)) {
                continue;
            }
            $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
            $filename = time() . '_' . $i . '_' . $safeName;
            $filePath = "$baseDir/$filename";

            if (!move_uploaded_file($raw['tmp_name'][$i], $filePath)) {
                continue;
            }

            $photoId = $this->activityRepo->savePhoto([
                'attivita_id' => $attivitaId,
                'worksite_id' => $worksiteId,
                'file_name'   => $origName,
                'file_path'   => $filePath,
                'categoria'   => $categoria,
                'created_by'  => $userId,
            ]);

            $saved[] = [
                'id'        => $photoId,
                'file_name' => $origName,
                'categoria' => $categoria,
                'serve_url' => "/worksites/{$worksiteId}/attivita/photos/{$photoId}/serve",
            ];
        }

        Response::json(['ok' => true, 'photos' => $saved]);
    }

    // ── POST /worksites/{id}/attivita/{attivitaId}/photos/{photoId}/delete ───

    public function destroyAttivitaPhoto(Request $request): never
    {
        $worksiteId = $request->intParam('id');
        $attivitaId = $request->intParam('attivitaId');
        $photoId    = $request->intParam('photoId');

        $photo = $this->activityRepo->findPhotoById($photoId);

        if (!$photo
            || (int)$photo['worksite_id'] !== $worksiteId
            || (int)$photo['attivita_id'] !== $attivitaId) {
            Response::json(['ok' => false, 'error' => 'Not found'], 404);
        }

        if (file_exists($photo['file_path'])) {
            unlink($photo['file_path']);
        }
        $this->activityRepo->deletePhoto($photoId);

        Response::json(['ok' => true]);
    }

    // ── GET /worksites/{id}/attivita/photos/{photoId}/serve ──────────────────

    public function serveAttivitaPhoto(Request $request): never
    {
        $worksiteId = $request->intParam('id');
        $photoId    = $request->intParam('photoId');

        $photo = $this->activityRepo->findPhotoById($photoId);

        if (!$photo || (int)$photo['worksite_id'] !== $worksiteId) {
            Response::error('Not found', 404);
        }

        $filePath = $photo['file_path'];
        if (!file_exists($filePath)) {
            Response::error('File not found', 404);
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'gif'  => 'image/gif',
            'webp' => 'image/webp', 'heic' => 'image/heic',
        ];

        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }

    // ── Ordini ────────────────────────────────────────────────────────────────

    public function saveOrdine(Request $request): never
    {
        $worksite_id = $request->intParam('id');

        $conn = $this->conn;

        $id           = $_POST['id']         ?? '';
        $order_date   = $_POST['order_date'] ?? '';
        $company_name = $_POST['company_id'] ?? '';
        $note         = $_POST['note']       ?? '';

        if (empty($company_name)) {
            $_SESSION['error'] = 'Azienda obbligatoria.';
            Response::redirect("/worksites/{$worksite_id}#ordini");
        }

        $stmt = $conn->prepare("SELECT id FROM bb_companies WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $company_name]);
        $companyRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$companyRow) {
            $_SESSION['error'] = 'Azienda non trovata.';
            Response::redirect("/worksites/{$worksite_id}#ordini");
        }

        $company_id = $companyRow['id'];
        $total      = $_POST['total'] ?? '';

        if (!$worksite_id || empty($order_date) || empty($company_id) || $total === '') {
            $_SESSION['error'] = 'Campi obbligatori mancanti.';
            Response::redirect("/worksites/{$worksite_id}#ordini");
        }

        try {
            $data = [
                'worksite_id' => $worksite_id,
                'order_date'  => $order_date,
                'company_id'  => $company_id,
                'total'       => $total,
                'note'        => $note,
            ];

            if (empty($id)) {
                $this->orderRepo->create($data);
                $_SESSION['success'] = 'Ordine creato.';
                Response::redirect("/worksites/{$worksite_id}#ordini");
            } else {
                $this->orderRepo->update((int)$id, $data);
                $_SESSION['success'] = 'Ordine aggiornato.';
                Response::redirect("/worksites/{$worksite_id}#ordini");
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#ordini");
        }
    }

    public function destroyOrdine(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $ordineId    = $request->intParam('ordineId');

        $id = $ordineId ?: ($_POST['id'] ?? '');

        if (empty($id) || !$worksite_id) {
            $_SESSION['error'] = 'ID ordine mancante.';
            Response::redirect("/worksites/{$worksite_id}#ordini");
        }

        try {
            $row = $this->orderRepo->getById((int)$id);
            if (!$row || (int)$row['worksite_id'] !== (int)$worksite_id) {
                Response::error('Access denied', 403);
            }

            $this->orderRepo->delete((int)$id);
            AuditLogger::log($this->conn, $request->user(), 'ordine_delete', 'ordine', $id, "Ordine #{$id}");

            $_SESSION['success'] = 'Ordine eliminato.';
            Response::redirect("/worksites/{$worksite_id}#ordini");

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#ordini");
        }
    }

    // ── Presenze ──────────────────────────────────────────────────────────────

    public function destroyPresenza(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $presenzaId  = $request->intParam('presenzaId');

        $id = $presenzaId ?: ($_POST['id'] ?? '');

        if (empty($id) || !$worksite_id) {
            $_SESSION['error'] = 'Dati mancanti.';
            Response::redirect("/worksites/{$worksite_id}#presenze");
        }

        try {
            $success = $this->attendanceRepo->deleteInternalById((int)$id);

            if ($success) {
                AuditLogger::log($this->conn, $request->user(), 'presenza_delete', 'presenza', $id, "Presenza #{$id}");
                $_SESSION['success'] = 'Presenza eliminata.';
                Response::redirect("/worksites/{$worksite_id}#presenze");
            } else {
                $_SESSION['error'] = 'Eliminazione non riuscita.';
                Response::redirect("/worksites/{$worksite_id}#presenze");
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#presenze");
        }
    }

    public function destroyPresenzaConsorziata(Request $request): never
    {
        $worksite_id = $request->intParam('id');
        $presenzaId  = $request->intParam('presenzaId');

        $id = $presenzaId ?: ($_POST['id'] ?? '');

        if (empty($id) || !$worksite_id) {
            $_SESSION['error'] = 'Dati mancanti.';
            Response::redirect("/worksites/{$worksite_id}#presenze_cons");
        }

        try {
            $success = $this->attendanceRepo->deleteConsorziataById((int)$id);

            if ($success) {
                AuditLogger::log($this->conn, $request->user(), 'presenza_consorziata_delete', 'presenza', $id, "Presenza consorziata #{$id}");
                $_SESSION['success'] = 'Presenza consorziata eliminata.';
                Response::redirect("/worksites/{$worksite_id}#presenze_cons");
            } else {
                $_SESSION['error'] = 'Eliminazione non riuscita.';
                Response::redirect("/worksites/{$worksite_id}#presenze_cons");
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}#presenze_cons");
        }
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public function assignUser(Request $request): never
    {
        $worksiteId = $request->intParam('id');

        $conn = $this->conn;

        $user          = $GLOBALS['user'];
        $authorization = new \AuthorizationService(new \AccessProfileResolver());

        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        if ($worksiteId <= 0 || $userId <= 0) {
            Response::error('Dati non validi.', 422);
        }

        if (!$authorization->canAccessModule($user, 'worksites')) {
            Response::error('Non autorizzato.', 403);
        }

        $checkUser = $conn->prepare("
            SELECT id
            FROM bb_users
            WHERE id = :uid
              AND type IN ('worker','client')
              AND active = 'Y'
              AND removed = 'N'
            LIMIT 1
        ");
        $checkUser->execute([':uid' => $userId]);

        if (!$checkUser->fetchColumn()) {
            Response::error('Utente non valido.', 400);
        }

        $stmt = $conn->prepare("
            INSERT IGNORE INTO bb_worksite_users (worksite_id, user_id)
            VALUES (:wid, :uid)
        ");
        $stmt->execute([
            ':wid' => $worksiteId,
            ':uid' => $userId,
        ]);

        Response::redirect("/worksites/{$worksiteId}");
    }

    public function removeUser(Request $request): never
    {
        $worksiteId = $request->intParam('id');

        $conn = $this->conn;

        $user          = $GLOBALS['user'];
        $authorization = new \AuthorizationService(new \AccessProfileResolver());

        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        if ($worksiteId <= 0 || $userId <= 0) {
            Response::error('Dati non validi.', 422);
        }

        if (!$authorization->canAccessModule($user, 'worksites')) {
            Response::error('Non autorizzato.', 403);
        }

        $stmt = $conn->prepare("
            DELETE FROM bb_worksite_users
            WHERE worksite_id = :wid
              AND user_id = :uid
        ");
        $stmt->execute([
            ':wid' => $worksiteId,
            ':uid' => $userId,
        ]);

        Response::redirect("/worksites/{$worksiteId}");
    }

    // ── Delete worksite ───────────────────────────────────────────────────────

    public function destroy(Request $request): never
    {
        $worksite_id = $request->intParam('id');

        if ($worksite_id <= 0) {
            $_SESSION['error'] = 'ID cantiere mancante.';
            Response::redirect("/worksites");
        }

        try {
            $worksiteRow = $this->worksiteRepo->findById($worksite_id);

            if (!$worksiteRow) {
                throw new \Exception("Cantiere non trovato.");
            }

            $worksiteLabel  = trim(($worksiteRow['name'] ?? '') . ' ' . ($worksiteRow['worksite_code'] ?? ''))
                ?: "Cantiere #{$worksite_id}";
            $yardWorksiteId = $worksiteRow['yard_worksite_id'] ?? null;

            if (!empty($yardWorksiteId)) {
                $yardConn     = (new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()))->connect();
                $yardWorksite = new \YardWorksite($yardConn);
                $yardWorksite->softDeleteWorksite((int)$yardWorksiteId);
            }

            $this->worksiteRepo->delete($worksite_id);

            AuditLogger::log($this->conn, $request->user(), 'worksite_delete', 'worksite', $worksite_id, $worksiteLabel);

            $_SESSION['success'] = 'Cantiere eliminato.';
            Response::redirect("/worksites");

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            Response::redirect("/worksites/{$worksite_id}");
        }
    }

    // ── Activate draft ────────────────────────────────────────────────────────

    public function activateDraft(Request $request): never
    {
        $user      = $request->user();
        $companyId = $user->getCompanyId();

        if ($companyId != 1) {
            Response::error('Accesso non autorizzato', 403);
        }

        $draftId = $request->intParam('id');
        if ($draftId <= 0) {
            Response::error('ID non valido', 400);
        }

        $conn = $this->conn;

        $stmt = $conn->prepare("
            SELECT *
            FROM bb_worksites
            WHERE id = :id
              AND is_draft = 1
        ");
        $stmt->execute([':id' => $draftId]);

        $draft = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$draft) {
            Response::error('Cantiere non trovato o già attivato', 404);
        }

        $sqlSrvConn = (new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()))->connect();

        $clientNameStmt = $conn->prepare("
            SELECT name
            FROM bb_clients
            WHERE id = ?
        ");
        $clientNameStmt->execute([$draft['client_id']]);
        $clientName = $clientNameStmt->fetchColumn();

        $yardClientStmt = $sqlSrvConn->prepare("
            SELECT id
            FROM ANA_anagrafiche
            WHERE ragione_sociale = ?
        ");
        $yardClientStmt->execute([$clientName]);
        $yardClientId = $yardClientStmt->fetchColumn();

        if (!$yardClientId) {
            $_SESSION['error'] = 'Cliente non ancora presente su Business.';
            Response::redirect("/worksites");
        }

        $yardWorksiteData = [
            'name'           => $draft['name'],
            'descrizione'    => $draft['descrizione'],
            'order_number'   => $draft['order_number'],
            'start_date'     => $draft['start_date'],
            'total_offer'    => $draft['total_offer'],
            'yard_client_id' => $yardClientId,
            'location'       => $draft['location'],
        ];

        $yardWorksite   = new \YardWorksite($sqlSrvConn);
        $yardWorksiteId = $yardWorksite->createYardWorksite($yardWorksiteData);

        if (!$yardWorksiteId) {
            $_SESSION['error'] = 'Errore durante la creazione su YARD.';
            Response::redirect("/worksites");
        }

        $this->worksiteRepo->activateDraft($draftId, $yardWorksiteId);

        $_SESSION['success'] = 'Cantiere attivato correttamente.';
        Response::redirect("/worksites");
    }

    // ── Recalculate margin ────────────────────────────────────────────────────

    public function recalculateMargin(Request $request): never
    {
        $user = $request->user();

        if (($user->role ?? '') === 'company_viewer' || !empty($user->client_id) || !$user->canAccess('worksites')) {
            Response::json(['status' => 'error', 'message' => 'Accesso negato'], 403);
        }

        $script = APP_ROOT . '/includes/services/recalculate_worksite_stats.php';
        $cmd    = 'php ' . escapeshellarg($script) . ' 2>&1';
        $output = shell_exec($cmd);

        if ($output === null) {
            Response::json(['status' => 'error', 'message' => 'Impossibile eseguire il ricalcolo'], 500);
        }

        Response::json([
            'status'  => 'ok',
            'message' => 'Ricalcolo completato',
            'output'  => $output,
        ]);
    }

    // ── Yard status ───────────────────────────────────────────────────────────

    public function updateYardStatus(Request $request): never
    {
        try {
            $service = new \YardWorksiteStatusService(
                $this->conn,
                (new \App\Infrastructure\SqlServerConnection(new \App\Infrastructure\Config()))->connect()
            );

            $service->run();

            Response::json([
                'status'  => 'ok',
                'message' => 'Verifica completata correttamente.',
            ]);

        } catch (\Throwable $e) {
            Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ── GET /worksites/load-companies ────────────────────────────────────────

    public function loadCompanies(Request $request): never
    {
        $q    = (string)($request->get('q') ?? '');
        $stmt = $this->conn->prepare("SELECT id, name FROM bb_companies WHERE name LIKE :q ORDER BY name ASC LIMIT 40");
        $stmt->execute([':q' => '%' . $q . '%']);

        $results = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $results[] = ['value' => $row['name'], 'text' => $row['name']];
        }

        Response::json($results);
    }

    // ── GET /worksites/documents/open ─────────────────────────────────────────

    public function openDocument(Request $request): void
    {
        $docId = (int)($request->get('id') ?? 0);
        if (!$docId) {
            Response::error('Documento non valido', 400);
        }

        $auth   = $GLOBALS['authenticated_user'] ?? [];
        $userId = (int)($auth['user_id'] ?? 0);

        $stmt = $this->conn->prepare("
            SELECT *
            FROM bb_worksite_documents
            WHERE id = :id AND is_deleted = 0
        ");
        $stmt->execute([':id' => $docId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            Response::error('Documento non trovato', 404);
        }

        $fileUrl     = "https://bob.csmontaggi.it/worksites/documents/{$docId}/download";
        $callbackUrl = "https://bob.csmontaggi.it/worksites/documents/callback";

        $only        = new \App\Service\OnlyOfficeService();
        $editorConfig = $only->buildConfig([
            'fileType'    => $doc['file_type'],
            'key'         => 'worksite_doc_' . $doc['id'],
            'title'       => $doc['file_name'],
            'url'         => $fileUrl,
            'callbackUrl' => $callbackUrl,
            'userId'      => $userId,
            'userName'    => $auth['username'] ?? '',
        ]);

        Response::view('worksites/documents/open.html.twig', $request, compact('editorConfig'));
    }

    // ── POST /worksites/ask-ai ────────────────────────────────────────────────

    public function askAi(Request $request): never
    {
        header('Content-Type: application/json; charset=utf-8');

        $auth      = $GLOBALS['authenticated_user'] ?? [];
        $user      = $request->user();

        $worksiteId = (int)($_POST['worksite_id'] ?? 0);
        $question   = trim((string)($_POST['question'] ?? ''));
        $historyRaw = trim((string)($_POST['history'] ?? '[]'));

        if ($worksiteId <= 0 || $question === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Parametri non validi']);
            exit;
        }

        // Access check for workers and clients
        if (in_array($user->type, ['worker', 'client'], true)) {
            $stmt = $this->conn->prepare("
                SELECT 1
                FROM bb_worksite_users
                WHERE worksite_id = :wid AND user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([':wid' => $worksiteId, ':uid' => (int)$auth['user_id']]);
            if (!$stmt->fetchColumn()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Accesso non autorizzato']);
                exit;
            }
        }

        $usersWithPriceAccess = ['alion', 'laura', 'osman', 'elena', 'ermal'];
        $currentUsername      = (string)($auth['username'] ?? '');
        $canSeePrices         = in_array($currentUsername, $usersWithPriceAccess, true);

        // Rate limit
        $limiter = new \RateLimiter($this->conn, 20, 10);
        $rl      = $limiter->allow((int)$auth['user_id']);
        if (!$rl['ok']) {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_ai_worksite_logs (user_id, worksite_id, question, status, blocked_reason)
                VALUES (:uid, :wid, :q, 'BLOCKED', :r)
            ");
            $stmt->execute([
                ':uid' => (int)$auth['user_id'],
                ':wid' => $worksiteId,
                ':q'   => $question,
                ':r'   => $rl['reason'] ?? 'rate_limit',
            ]);
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'Troppe richieste, riprova tra poco']);
            exit;
        }

        // Build context
        $builder = new \WorksiteContextBuilder($this->conn);
        $context = $builder->build($worksiteId, $canSeePrices);

        if (isset($context['error'])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Cantiere non trovato']);
            exit;
        }

        // Parse conversation history
        $conversationHistory = json_decode($historyRaw, true);
        if (!is_array($conversationHistory)) {
            $conversationHistory = [];
        }
        $conversationHistory = array_filter($conversationHistory, function ($msg) {
            return is_array($msg)
                && isset($msg['role'], $msg['content'])
                && in_array($msg['role'], ['user', 'assistant'], true)
                && is_string($msg['content'])
                && mb_strlen($msg['content']) <= 2000;
        });
        $conversationHistory = array_slice(array_values($conversationHistory), -10);

        $ollamaUrl = getenv('OLLAMA_URL') ?: 'http://192.168.1.10:8000/v1/chat/completions';
        $model     = getenv('MODEL')      ?: 'Qwen3-30B-A3B-Q4_K_M.gguf';

        $client = new \OllamaClient($ollamaUrl, $model);
        $svc    = new \WorksiteAIService($client);
        $res    = $svc->answer($context, $question, $conversationHistory);

        if (!$res['ok']) {
            $stmt = $this->conn->prepare("
                INSERT INTO bb_ai_worksite_logs (user_id, worksite_id, question, response, status, blocked_reason, latency_ms)
                VALUES (:uid, :wid, :q, :resp, 'ERROR', :reason, :lat)
            ");
            $stmt->execute([
                ':uid'    => (int)$auth['user_id'],
                ':wid'    => $worksiteId,
                ':q'      => $question,
                ':resp'   => null,
                ':reason' => (string)($res['error'] ?? 'ollama_error'),
                ':lat'    => (int)($res['latency_ms'] ?? 0),
            ]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Errore AI']);
            exit;
        }

        $answer = trim((string)$res['response']);

        $stmt = $this->conn->prepare("
            INSERT INTO bb_ai_worksite_logs (user_id, worksite_id, question, response, status, latency_ms)
            VALUES (:uid, :wid, :q, :resp, 'OK', :lat)
        ");
        $stmt->execute([
            ':uid'  => (int)$auth['user_id'],
            ':wid'  => $worksiteId,
            ':q'    => $question,
            ':resp' => $answer,
            ':lat'  => (int)($res['latency_ms'] ?? 0),
        ]);

        echo json_encode(['ok' => true, 'answer' => $answer]);
        exit;
    }

    // ── POST /worksites/documents/upload ──────────────────────────────────────

    public function uploadDocument(Request $request): never
    {
        $worksiteId = (int)($_POST['worksite_id'] ?? 0);
        $userId     = (int)(($GLOBALS['authenticated_user'] ?? [])['user_id'] ?? 0);

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Nessun file caricato', 400);
        }

        // Validate file size (20 MB max)
        if (($_FILES['file']['size'] ?? 0) > 20 * 1024 * 1024) {
            Response::error('Il file supera la dimensione massima consentita (20 MB).', 422);
        }

        $allowed = ['docx', 'xlsx', 'pptx', 'pdf'];
        $ext     = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            Response::error('Tipo file non consentito', 400);
        }

        // Validate MIME type server-side
        $finfo         = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType      = $finfo->file((string)$_FILES['file']['tmp_name']);
        $allowedMimes  = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
        ];
        if (!in_array($mimeType, $allowedMimes, true)) {
            Response::error('Tipo di file non consentito.', 422);
        }

        $baseDir = dirname(APP_ROOT) . "/cloud/worksites/$worksiteId";
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $filename = time() . '_' . $safeName;
        $path     = "$baseDir/$filename";

        move_uploaded_file($_FILES['file']['tmp_name'], $path);

        $stmt = $this->conn->prepare("
            INSERT INTO bb_worksite_documents
            (worksite_id, file_name, file_path, file_type, created_by)
            VALUES (:wid, :name, :path, :type, :uid)
        ");
        $stmt->execute([
            ':wid'  => $worksiteId,
            ':name' => $_FILES['file']['name'],
            ':path' => $path,
            ':type' => $ext,
            ':uid'  => $userId,
        ]);

        Response::redirect("/worksites/{$worksiteId}#documents");
    }

    // ── GET /worksites/documents/{id}/download  (no auth — OnlyOffice fetch) ──

    public function downloadDocument(Request $request): never
    {
        $docId = $request->intParam('id');
        if (!$docId) {
            Response::error('ID non valido', 400);
        }

        $stmt = $this->conn->prepare("SELECT * FROM bb_worksite_documents WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $docId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            Response::error('Documento non trovato', 404);
        }

        $filePath = $doc['file_path'];
        if (!$filePath || !file_exists($filePath)) {
            Response::error('File non trovato', 404);
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . addslashes(basename($doc['file_name'])) . '"');
        header('Cache-Control: private, max-age=60');
        readfile($filePath);
        exit;
    }

    // ── POST /worksites/documents/callback  (no auth — OnlyOffice webhook) ────

    public function documentCallback(Request $request): never
    {
        $secret = $_ENV['ONLYOFFICE_JWT_SECRET'] ?? '';
        if (empty($secret)) {
            Response::error('Missing JWT secret', 500);
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            Response::json(['error' => 1], 403);
        }

        try {
            \Firebase\JWT\JWT::decode($m[1], new \Firebase\JWT\Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            Response::json(['error' => 1], 403);
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['status']) || $data['status'] != 2) {
            Response::json(['error' => 0]);
        }

        $docKey  = (string)($data['key'] ?? '');
        $fileUrl = (string)($data['url'] ?? '');

        $trustedHost = parse_url($_ENV['ONLYOFFICE_SERVER_URL'] ?? '', PHP_URL_HOST);
        $fileHost    = parse_url($fileUrl, PHP_URL_HOST);

        if (!$trustedHost || !$fileHost || $fileHost !== $trustedHost) {
            Response::json(['error' => 1], 403);
        }

        $stmt = $this->conn->prepare("
            SELECT * FROM bb_worksite_documents
            WHERE SHA1(CONCAT(file_path, UNIX_TIMESTAMP(created_at))) = :k
            LIMIT 1
        ");
        $stmt->execute(['k' => $docKey]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            Response::json(['error' => 0]);
        }

        $content = file_get_contents($fileUrl);
        if ($content === false) {
            Response::json(['error' => 1], 500);
        }

        file_put_contents($doc['file_path'], $content);

        $this->conn->prepare("UPDATE bb_worksite_documents SET updated_at = NOW() WHERE id = :id")
                   ->execute(['id' => $doc['id']]);

        Response::json(['error' => 0]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function toDecimal(string $value): float
    {
        $value = trim($value);
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }
        return (float)$value;
    }
}
