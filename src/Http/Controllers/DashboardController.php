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

        match ($role) {
            'admin'            => $data += $this->dataForAdmin($name),
            'document_manager' => $data += $this->dataForDocuments($userId, $name, $user),
            'offerte'          => $data += ['name' => $name],
            default            => $data += ['name' => $name],
        };

        Response::view('dashboard/index.html.twig', $request, $data);
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

        return compact(
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
}
