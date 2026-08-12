<?php

declare(strict_types=1);

use App\Domain\UserAnalytics;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Share\SharedLinkRepository;

final class DashboardController
{
    public function __construct(private \PDO $conn) {}

    public function index(Request $request): never
    {
        $user   = $request->user();
        $userId = $user->id;

        $stmt = $this->conn->prepare('SELECT username, role, first_name FROM bb_users WHERE id = ?');
        $stmt->execute([$userId]);
        $userInfo  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $username  = $userInfo['username']   ?? '';
        $name      = $userInfo['first_name'] ?? '';
        $role      = $userInfo['role']       ?? '';
        $pageTitle = 'Dashboard';

        $data = compact('username', 'name', 'role', 'pageTitle');

        // Chi arriva qui rimbalzato da una pagina di un'altra societa' deve
        // capire perche', invece di ritrovarsi sulla dashboard senza motivo.
        $data['fuoriSocieta']  = isset($_GET['fuori_societa']);
        $data['societaAttiva'] = ($GLOBALS['currentCompany'] ?? null)?->current()['nome'] ?? '';

        match ($role) {
            'admin'            => $data += $this->dataForAdmin($name),
            'document_manager' => $data += $this->dataForDocuments($userId, $name, $user),
            // tutti gli altri ruoli: dashboard dinamica costruita sui permessi
            default            => $data += $this->dataForDynamic($userId, $name, $user),
        };

        Response::view('dashboard/index.html.twig', $request, $data);
    }

    /**
     * Contatori delle autocarrate della societa' attiva.
     *
     * Sta in un metodo suo perche' lo usano sia la dashboard dinamica sia
     * quella dell'admin, che dentro una societa' diversa dal Consorzio
     * sostituisce con questi le proprie card.
     */
    private function statsAutocarrate(): array
    {
        $cid   = ($GLOBALS['currentCompany'] ?? new \App\Service\CurrentCompany($this->conn))->id();
        $oggi  = date('Y-m-d');

        try {
            $s = $this->conn->prepare("
                SELECT COUNT(*) FROM pn_autocarrate
                WHERE group_company_id = :cid AND stato = 'attiva'
            ");
            $s->execute([':cid' => $cid]);
            $totali = (int)$s->fetchColumn();

            $s = $this->conn->prepare("
                SELECT COUNT(DISTINCT autocarrata_id) FROM pn_prenotazioni
                WHERE group_company_id = :cid AND stato <> 'annullata'
                  AND data_inizio <= :d1 AND data_fine >= :d2
            ");
            $s->execute([':cid' => $cid, ':d1' => $oggi, ':d2' => $oggi]);
            $impegnate = (int)$s->fetchColumn();
        } catch (\Throwable $e) {
            // migration non ancora applicata: meglio nessuna card che una
            // pagina che non si apre
            error_log('[Dashboard] autocarrate: ' . $e->getMessage());
            return [];
        }

        return [
            ['num' => max(0, $totali - $impegnate), 'label' => 'Autocarrate libere', 'sub' => 'oggi',
             'color' => '#16a34a', 'bg' => '#f0fdf4', 'href' => '/autocarrate',
             'icon' => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1'],
            ['num' => $impegnate, 'label' => 'Autocarrate impegnate', 'sub' => 'oggi',
             'color' => '#0369a1', 'bg' => '#f0f9ff', 'href' => '/autocarrate/prenotazioni',
             'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
        ];
    }

    /**
     * True se la societa' attiva ha un elenco di moduli, cioe' non e' il
     * Consorzio che li ha tutti. Serve a capire se le dashboard fisse
     * hanno ancora senso.
     */
    private function societaLimitata(): bool
    {
        $service = $GLOBALS['currentCompany'] ?? new \App\Service\CurrentCompany($this->conn);
        $dati    = $service->current();
        return $dati !== null && !empty($dati['moduli']);
    }

    // ──────────────────────────────────────────────────────────────
    // Admin dashboard data
    // ──────────────────────────────────────────────────────────────

    private function dataForAdmin(string $name): array
    {
        $conn = $this->conn;

        /* ── User analytics ── */
        $analytics   = new UserAnalytics($conn);
        $onlineCount = $analytics->countOnlineUsers();
        $onlineUsers = $analytics->getOnlineUsers();
        $topUsers    = $analytics->getTopUsers('today');
        $recentActions = $analytics->getRecentActions(15);

        /* ── System counters ── */
        $totalUsers  = $conn->query("SELECT COUNT(*) FROM bb_users")->fetchColumn();
        $activeUsers = $conn->query("SELECT COUNT(*) FROM bb_users WHERE ACTIVE = '1'")->fetchColumn();

        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT w.id)
            FROM bb_worksites w
            WHERE w.status IN ('Attivo','In corso')
              AND (
                    EXISTS (SELECT 1 FROM bb_presenze p WHERE p.worksite_id = w.id)
                 OR EXISTS (SELECT 1 FROM bb_presenze_consorziate pc WHERE pc.worksite_id = w.id)
              )
        ");
        $stmt->execute();
        $activeWorksites = $stmt->fetchColumn();
        $totalWorksites  = $conn->query("SELECT COUNT(*) FROM bb_worksites")->fetchColumn();

        $stmt = $conn->prepare("SELECT COUNT(*) FROM bb_presenze WHERE data = CURDATE()");
        $stmt->execute();
        $todayNostri = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT COALESCE(SUM(quantita),0) FROM bb_presenze_consorziate WHERE data_presenza = CURDATE()");
        $stmt->execute();
        $todayCons = (int)$stmt->fetchColumn();

        $todayAttendance = $todayNostri + $todayCons;

        $expiringDocs = $conn->query("
            SELECT COUNT(*)
            FROM bb_worker_documents d
            JOIN bb_workers w ON w.id = d.worker_id
            WHERE w.active = 'Y'
              AND d.scadenza != ''
              AND d.scadenza != 'INDETERMINATO'
              AND STR_TO_DATE(d.scadenza, '%d/%m/%Y')
                  BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
        ")->fetchColumn();

        $expiredDocs = $conn->query("
            SELECT COUNT(*)
            FROM bb_worker_documents d
            JOIN bb_workers w ON w.id = d.worker_id
            WHERE w.active = 'Y'
              AND d.scadenza != ''
              AND d.scadenza != 'INDETERMINATO'
              AND STR_TO_DATE(d.scadenza, '%d/%m/%Y') < CURDATE()
        ")->fetchColumn();

        /* ── System status ── */
        $dbStatus = 'Online';
        try { $conn->query("SELECT 1"); } catch (\Exception $e) { $dbStatus = 'Offline'; }

        $mailStatus = 'Non configurato';
        if (!empty($_ENV['MAIL_HOST']) && !empty($_ENV['MAIL_PORT'])) {
            $mailStatus = 'Operativo';
            $sock = @fsockopen($_ENV['MAIL_HOST'], (int)$_ENV['MAIL_PORT'], $errno, $errstr, 2);
            if (!$sock) {
                $mailStatus = 'Non raggiungibile';
            } else {
                fclose($sock);
            }
        }

        /* ── NFS cloud storage ── */
        $storagePath    = $_ENV['CLOUD_ROOT'] ?? null;
        $storageStatus  = 'N/D';
        $storageLatency = null;
        $cloudPercent   = null;
        $cloudUsedGB    = null;
        $cloudTotalGB   = null;

        if ($storagePath && is_dir($storagePath)) {
            $start = microtime(true);
            $files = @scandir($storagePath);
            $latency = (int)round((microtime(true) - $start) * 1000);

            if ($files === false) {
                $storageStatus = 'NFS non accessibile';
            } else {
                $testFile = $storagePath . '/.healthcheck';
                $writeOk  = @file_put_contents($testFile, 'test') !== false;
                if ($writeOk) {
                    @unlink($testFile);
                }
                $storageStatus  = $writeOk ? 'Online' : 'Sola lettura';
                $storageLatency = $latency;

                $cloudTotal = @disk_total_space($storagePath);
                $cloudFree  = @disk_free_space($storagePath);
                if ($cloudTotal > 0) {
                    $cloudUsed    = $cloudTotal - $cloudFree;
                    $cloudPercent = (int)round(($cloudUsed / $cloudTotal) * 100);
                    $cloudUsedGB  = round($cloudUsed  / 1073741824, 1);
                    $cloudTotalGB = round($cloudTotal / 1073741824, 1);
                }
            }
        }

        /* ── Server resources ── */
        $diskTotal   = disk_total_space(__DIR__);
        $diskFree    = disk_free_space(__DIR__);
        $diskUsed    = $diskTotal - $diskFree;
        $diskPercent = (int)round(($diskUsed / $diskTotal) * 100);
        $diskUsedGB  = round($diskUsed  / 1073741824, 1);
        $diskTotalGB = round($diskTotal / 1073741824, 1);

        $phpMemoryLimit = ini_get('memory_limit');
        $phpMemoryUsage = round(memory_get_usage(true) / 1024 / 1024, 1) . ' MB';

        $load    = sys_getloadavg();
        $cpuLoad = round($load[0], 2);

        $memInfo  = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+)/',     $memInfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $avail);
        $memTotal   = isset($total[1]) ? (int)$total[1] * 1024 : 0;
        $memAvail   = isset($avail[1]) ? (int)$avail[1] * 1024 : 0;
        $ramPercent = $memTotal > 0 ? (int)round((($memTotal - $memAvail) / $memTotal) * 100) : 0;
        $ramUsedGB  = round(($memTotal - $memAvail) / 1073741824, 1);
        $ramTotalGB = round($memTotal / 1073741824, 1);

        /* ── Greeting ── */
        $hour = (int)date('H');
        if ($hour < 12)      { $greeting = 'Buongiorno'; }
        elseif ($hour < 18)  { $greeting = 'Buon pomeriggio'; }
        else                  { $greeting = 'Buonasera'; }

        @setlocale(LC_TIME, 'it_IT.UTF-8', 'it_IT', 'italian');
        $today = @strftime('%A %d %B %Y') ?: date('d/m/Y');

        // Stato del sistema e risorse del server valgono per tutte le societa':
        // sono la macchina, non i dati di una azienda. Le quattro card in alto
        // invece sono del Consorzio, e dentro un'altra societa' lasciano il
        // posto ai contatori di quella societa'.
        $statsSocieta = $this->societaLimitata() ? $this->statsAutocarrate() : null;

        return compact(
            'statsSocieta',
            'name', 'greeting', 'today',
            'totalUsers', 'activeUsers',
            'activeWorksites', 'totalWorksites',
            'todayNostri', 'todayCons', 'todayAttendance',
            'expiringDocs', 'expiredDocs',
            'dbStatus', 'mailStatus',
            'storageStatus', 'storageLatency',
            'cloudPercent', 'cloudUsedGB', 'cloudTotalGB',
            'diskPercent', 'diskUsedGB', 'diskTotalGB',
            'phpMemoryLimit', 'phpMemoryUsage',
            'cpuLoad',
            'ramPercent', 'ramUsedGB', 'ramTotalGB',
            'onlineCount', 'onlineUsers', 'topUsers', 'recentActions'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Document manager dashboard data
    // ──────────────────────────────────────────────────────────────

    private function dataForDocuments(int $userId, string $name, \App\Domain\User $user): array
    {
        $conn     = $this->conn;
        $linkRepo = new SharedLinkRepository($conn);

        /* ── Scaduti / in scadenza — STESSA fonte delle pagine
              /documents/expired e /documents/expiring, cosi' i numeri
              delle card coincidono sempre con le liste.
              NB: "in 30gg" INCLUDE anche quelli entro 7gg (finestra 0-30). ── */
        $docCtrl    = new \App\Service\Documents\WorkerDocumentController($conn);
        $expired    = $docCtrl->getExpiredDocuments($user);
        $expiring30 = $docCtrl->getExpiringDocuments($user, 30);
        $expiring7  = $docCtrl->getExpiringDocuments($user, 7);

        $expiredWorkerCount  = count($expired['workerDocs']);
        $expiredCompanyCount = count($expired['companyDocs']);
        $expiredTotal        = $expiredWorkerCount + $expiredCompanyCount;

        $expiring7Total  = count($expiring7['workerDocs'])  + count($expiring7['companyDocs']);
        $expiring30Total = count($expiring30['workerDocs']) + count($expiring30['companyDocs']);

        /* ── Shared links ── */
        $allLinks        = $linkRepo->getAllLinks();
        $activeLinks     = array_filter($allLinks, fn($l) => $l['is_active']);
        $totalLinks      = count($allLinks);
        $totalActiveLinks = count($activeLinks);

        /* ── Recent downloads ── */
        $recentDownloads  = 0;
        $recentDownloads7 = 0;
        try {
            $recentDownloads = (int)$conn->query("
                SELECT COUNT(*) FROM bb_shared_downloads
                WHERE downloaded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ")->fetchColumn();
            $recentDownloads7 = (int)$conn->query("
                SELECT COUNT(*) FROM bb_shared_downloads
                WHERE downloaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetchColumn();
        } catch (\PDOException $e) { /* table may not exist yet */ }

        /* ── Top companies with expired docs (dagli stessi dati delle card) ── */
        $topCompaniesExpired = [];
        foreach ($expired['workerDocs'] as $d) {
            $cn = trim((string)($d['company_name'] ?? ''));
            if ($cn === '') continue;
            $topCompaniesExpired[$cn] = ($topCompaniesExpired[$cn] ?? 0) + 1;
        }
        arsort($topCompaniesExpired);
        $topCompaniesExpired = array_slice($topCompaniesExpired, 0, 6, true);
        $maxCompanyExpired   = !empty($topCompaniesExpired) ? max($topCompaniesExpired) : 1;

        /* ── Urgent expirations (next 7 days — detail rows, stessi dati) ── */
        $urgentDocs = [];
        foreach ($expiring7['workerDocs'] as $d) {
            $urgentDocs[] = [
                'tipo_documento' => $d['tipo_documento'],
                'entity_name'    => $d['worker_name'] ?? '—',
                'doc_type'       => 'operaio',
                'scadenza_date'  => (string)$d['scadenza_norm'],
            ];
        }
        foreach ($expiring7['companyDocs'] as $d) {
            $urgentDocs[] = [
                'tipo_documento' => $d['tipo_documento'],
                'entity_name'    => $d['company_name'] ?? '—',
                'doc_type'       => 'azienda',
                'scadenza_date'  => (string)$d['scadenza_norm'],
            ];
        }
        usort($urgentDocs, fn($a, $b) => $a['scadenza_date'] <=> $b['scadenza_date']);
        $urgentDocs = array_slice($urgentDocs, 0, 8);

        // Pre-compute days_left so the template doesn't need DateTime logic
        $today = new \DateTime();
        foreach ($urgentDocs as &$doc) {
            $expDate          = new \DateTime($doc['scadenza_date']);
            $doc['days_left'] = (int)$today->diff($expDate)->format('%r%a');
        }
        unset($doc);

        /* ── Recent shared links (last 5) ── */
        usort($allLinks, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        $recentLinks = array_slice($allLinks, 0, 5);

        /* ── Recent notifications (last 10) ── */
        $recentNotifications = [];
        try {
            $notifStmt = $conn->prepare("
                SELECT n.id, n.title, n.message, n.link, n.created_at, n.is_read,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.username, 'Sistema') AS created_by_name
                FROM bb_notifications n
                LEFT JOIN bb_users u ON n.created_by = u.id
                WHERE n.user_id = :uid
                ORDER BY n.created_at DESC
                LIMIT 10
            ");
            $notifStmt->execute([':uid' => $userId]);
            $rawNotifs = $notifStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $rawNotifs = [];
        }

        // Pre-compute time_ago for each notification
        $now = new \DateTime();
        foreach ($rawNotifs as $notif) {
            if (!empty($notif['created_at'])) {
                $diff = $now->diff(new \DateTime($notif['created_at']));
                if ($diff->days === 0) {
                    if ($diff->h > 0)      { $timeAgo = $diff->h . 'h fa'; }
                    elseif ($diff->i > 0)  { $timeAgo = $diff->i . 'min fa'; }
                    else                    { $timeAgo = 'Adesso'; }
                } elseif ($diff->days === 1) {
                    $timeAgo = 'Ieri';
                } elseif ($diff->days < 7) {
                    $timeAgo = $diff->days . 'gg fa';
                } else {
                    $timeAgo = !empty($notif['created_at'])
                        ? (new \DateTime($notif['created_at']))->format('d/m/Y H:i')
                        : '—';
                }
            } else {
                $timeAgo = '';
            }
            $notif['time_ago']          = $timeAgo;
            $recentNotifications[]      = $notif;
        }

        /* ── Greeting ── */
        $hour = (int)date('H');
        if ($hour < 12)     { $greeting = 'Buongiorno'; }
        elseif ($hour < 18) { $greeting = 'Buon pomeriggio'; }
        else                 { $greeting = 'Buonasera'; }

        @setlocale(LC_TIME, 'it_IT.UTF-8', 'it_IT', 'italian');
        $todayStr = @strftime('%A %d %B %Y') ?: date('d/m/Y');

        return compact(
            'name', 'greeting', 'todayStr',
            'expiredWorkerCount', 'expiredCompanyCount', 'expiredTotal',
            'expiring7Total', 'expiring30Total',
            'totalLinks', 'totalActiveLinks',
            'recentDownloads', 'recentDownloads7',
            'topCompaniesExpired', 'maxCompanyExpired',
            'urgentDocs',
            'recentLinks',
            'recentNotifications'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Dynamic dashboard (tutti gli altri ruoli)
    // Scorciatoie e contatori costruiti sui permessi dell'utente
    // (bb_user_permissions), stesso look della dashboard documenti.
    // ──────────────────────────────────────────────────────────────

    private function dataForDynamic(int $userId, string $name, \App\Domain\User $user): array
    {
        $conn = $this->conn;

        $stmt = $conn->prepare("SELECT module FROM bb_user_permissions WHERE user_id = :uid AND allowed = 1");
        $stmt->execute([':uid' => $userId]);
        $mods = array_fill_keys($stmt->fetchAll(\PDO::FETCH_COLUMN), true);

        // Le scorciatoie seguono la societa' in cui si sta lavorando: dentro
        // Poti non devono comparire quelle del Consorzio. canAccess() applica
        // gia' lo stesso filtro, qui i permessi si leggono direttamente.
        $mods = array_filter(
            $mods,
            static fn(string $m): bool => $user->canAccess($m),
            ARRAY_FILTER_USE_KEY
        );

        $has  = static fn(string ...$m): bool => (bool)array_intersect($m, array_keys($mods));

        /* ── Scorciatoie: solo i moduli che l'utente puo' usare ── */
        // [perms richiesti, label, sottotitolo, url, colore, sfondo icona, path svg]
        $catalog = [
            [['worksites'],                'Cantieri',            'Elenco e gestione cantieri',        '/worksites',          '#ea580c', '#fff7ed', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1'],
            [['worksites_drafts'],         'Cantieri in Bozza',   'Bozze da completare e attivare',    '/worksites/drafts',   '#dc2626', '#fef2f2', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            [['attendance', 'presenze'],   'Presenze',            'Cerca e inserisci presenze',        '/attendance',         '#0ea5e9', '#f0f9ff', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            [['attendance', 'presenze'],   'Ferie e Permessi',    'Registra le assenze',               '/attendance/leaves',  '#38bdf8', '#f0f9ff', 'M12 7v5l3 3M12 21a9 9 0 100-18 9 9 0 000 18z'],
            [['pianificazione'],           'Squadre',             'Pianificazione squadre',            '/pianificazione',     '#3b82f6', '#eff6ff', 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            [['programmazione'],           'Programmazione',      'Programma settimanale',             '/programmazione',     '#f59e0b', '#fffbeb', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            [['equipment'],                'Noleggio Mezzi',      'Mezzi di sollevamento a noleggio',  '/equipment/rentals',  '#b45309', '#fffbeb', 'M3 21h18M6 21V8l12-5v18M10 12h4'],
            [['fleet_view', 'fleet_manage'], 'Flotta',            'Auto aziendali e assegnazioni',     '/fleet',              '#0369a1', '#f0f9ff', 'M9 17a2 2 0 11-4 0 2 2 0 014 0zm0 0h6m4 0a2 2 0 11-4 0 2 2 0 014 0zM7 9h10l2 4H5l2-4z'],
            [['bookings'],                 'Prenotazioni',        'Alloggi e strutture',               '/bookings',           '#0d9488', '#f0fdfa', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            [['billing'],                  'Fatturazione',        'Bozze e fatture cantieri',          '/billing',            '#16a34a', '#f0fdf4', 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
            [['offers'],                   'Offerte',             'Preventivi e revisioni',            '/offers',             '#059669', '#ecfdf5', 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
            [['clients'],                  'Clienti',             'Anagrafica committenti',            '/clients',            '#0891b2', '#f0f9ff', 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
            [['companies'],                'Aziende',             'Aziende e consorziate',             '/companies',          '#7c3aed', '#f5f3ff', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5'],
            [['ordini'],                   'Ordini Consorziate',  'Ordini verso consorziate',          '/ordini',             '#1d4ed8', '#eff6ff', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2m-6 9l2 2 4-4'],
            [['ordini_aziende'],           'Ordini Aziende',      'Ordini verso aziende',              '/ordini-aziende',     '#0d9488', '#f0fdfa', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
            [['tickets'],                  'Bigliettini Pasto',   'Buoni pasto operai',                '/tickets',            '#059669', '#ecfdf5', 'M15 5H9V3h6v2zm4 4H5a2 2 0 00-2 2v1h18v-1a2 2 0 00-2-2zM3 14v5a2 2 0 002 2h14a2 2 0 002-2v-5H3z'],
            [['documents'],                'Documenti Scaduti',   'Documenti operai e aziende',        '/documents/expired',  '#dc2626', '#fef2f2', 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M12 18v-6M12 9h.01'],
            [['share'],                    'Doc Condivisi',       'Link di condivisione documenti',    '/share',              '#2563eb', '#eff6ff', 'M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71'],
            [['ai_chat'],                  'BOB AI',              'Chiedi ai dati in linguaggio naturale', '/ai/chat',        '#6366f1', '#f5f3ff', 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z'],
            [['pn_autocarrate'],           'Autocarrate',         'Disponibilita\' e prenotazioni',     '/autocarrate',        '#0369a1', '#f0f9ff', 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6 0a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0'],
        ];

        $shortcuts = [];
        foreach ($catalog as [$perms, $label, $sub, $href, $color, $bg, $icon]) {
            if ($has(...$perms)) {
                $shortcuts[] = compact('label', 'sub', 'href', 'color', 'bg', 'icon');
            }
        }

        /* ── Contatori: solo per i moduli permessi ── */
        $stats = [];
        $today = date('Y-m-d');

        if ($has('worksites')) {
            $n = (int)$conn->query("SELECT COUNT(*) FROM bb_worksites WHERE status = 'In corso' AND is_draft = 0")->fetchColumn();
            $stats[] = ['num' => $n, 'label' => 'Cantieri attivi', 'sub' => 'in corso', 'color' => '#ea580c', 'bg' => '#fff7ed',
                        'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5', 'href' => '/worksites'];
        }

        if ($has('attendance', 'presenze')) {
            $s = $conn->prepare("
                SELECT (SELECT COUNT(*) FROM bb_presenze WHERE data = :d1)
                     + (SELECT COALESCE(SUM(quantita),0) FROM bb_presenze_consorziate WHERE data_presenza = :d2)
            ");
            $s->execute([':d1' => $today, ':d2' => $today]);
            $stats[] = ['num' => (int)$s->fetchColumn(), 'label' => 'Presenze oggi', 'sub' => 'nostri + consorziate', 'color' => '#0ea5e9', 'bg' => '#f0f9ff',
                        'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'href' => '/attendance'];

            $s = $conn->prepare("SELECT COUNT(*) FROM bb_ferie_permessi WHERE data_inizio <= :d1 AND data_fine >= :d2");
            $s->execute([':d1' => $today, ':d2' => $today]);
            $stats[] = ['num' => (int)$s->fetchColumn(), 'label' => 'Assenti oggi', 'sub' => 'ferie e permessi', 'color' => '#b45309', 'bg' => '#fffbeb',
                        'icon' => 'M12 7v5l3 3M12 21a9 9 0 100-18 9 9 0 000 18z', 'href' => '/attendance/leaves'];
        }

        if ($has('equipment')) {
            $n = (int)$conn->query("
                SELECT COALESCE(SUM(quantita),0) FROM bb_worksite_lifting
                WHERE stato IN ('Attivo','attivo') AND tipo_noleggio <> 'Una Tantum'
            ")->fetchColumn();
            $stats[] = ['num' => $n, 'label' => 'Mezzi a noleggio', 'sub' => 'attivi ora', 'color' => '#b45309', 'bg' => '#fffbeb',
                        'icon' => 'M3 21h18M6 21V8l12-5v18', 'href' => '/equipment/rentals'];
        }

        if ($has('pn_autocarrate')) {
            $stats = array_merge($stats, $this->statsAutocarrate());
        }

        if ($has('documents', 'document_alerts')) {
            $docCtrl    = new \App\Service\Documents\WorkerDocumentController($conn);
            $expired    = $docCtrl->getExpiredDocuments($user);
            $expiring30 = $docCtrl->getExpiringDocuments($user, 30);
            $stats[] = ['num' => count($expired['workerDocs']) + count($expired['companyDocs']), 'label' => 'Documenti scaduti', 'sub' => 'operai + aziende', 'color' => '#dc2626', 'bg' => '#fef2f2',
                        'icon' => 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M12 18v-6M12 9h.01', 'href' => '/documents/expired'];
            $stats[] = ['num' => count($expiring30['workerDocs']) + count($expiring30['companyDocs']), 'label' => 'Scadono in 30gg', 'sub' => 'da monitorare', 'color' => '#d97706', 'bg' => '#fffbeb',
                        'icon' => 'M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01', 'href' => '/documents/expiring'];
        }

        if ($has('billing') && $user->canSeePrices()) {
            $row = $conn->query("
                SELECT COUNT(*) AS n, COALESCE(SUM(totale_imponibile),0) AS tot
                FROM bb_billing WHERE emessa = 0
            ")->fetch(\PDO::FETCH_ASSOC) ?: [];
            $stats[] = ['num' => (int)($row['n'] ?? 0), 'label' => 'Fatture da emettere', 'sub' => '€ ' . number_format((float)($row['tot'] ?? 0), 0, ',', '.'), 'color' => '#16a34a', 'bg' => '#f0fdf4',
                        'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z', 'href' => '/billing'];
        }

        /* ── Notifiche recenti ── */
        $recentNotifications = [];
        try {
            $notifStmt = $conn->prepare("
                SELECT n.id, n.title, n.message, n.link, n.created_at, n.is_read,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.username, 'Sistema') AS created_by_name
                FROM bb_notifications n
                LEFT JOIN bb_users u ON n.created_by = u.id
                WHERE n.user_id = :uid
                ORDER BY n.created_at DESC
                LIMIT 10
            ");
            $notifStmt->execute([':uid' => $userId]);
            $now = new \DateTime();
            foreach ($notifStmt->fetchAll(\PDO::FETCH_ASSOC) as $notif) {
                $timeAgo = '';
                try {
                    $created = new \DateTime($notif['created_at']);
                    $diff    = $now->diff($created);
                    if ($diff->days === 0) {
                        if ($diff->h > 0)     { $timeAgo = $diff->h . 'h fa'; }
                        elseif ($diff->i > 0) { $timeAgo = $diff->i . 'min fa'; }
                        else                   { $timeAgo = 'Adesso'; }
                    } elseif ($diff->days === 1) {
                        $timeAgo = 'Ieri';
                    } else {
                        $timeAgo = $diff->days . ' giorni fa';
                    }
                } catch (\Exception $e) {}
                $notif['time_ago']     = $timeAgo;
                $recentNotifications[] = $notif;
            }
        } catch (\PDOException $e) { /* tabella assente */ }

        /* ── Greeting ── */
        $hour = (int)date('H');
        if ($hour < 12)     { $greeting = 'Buongiorno'; }
        elseif ($hour < 18) { $greeting = 'Buon pomeriggio'; }
        else                 { $greeting = 'Buonasera'; }

        @setlocale(LC_TIME, 'it_IT.UTF-8', 'it_IT', 'italian');
        $todayStr = @strftime('%A %d %B %Y') ?: date('d/m/Y');

        return compact('name', 'greeting', 'todayStr', 'shortcuts', 'stats', 'recentNotifications');
    }
}
