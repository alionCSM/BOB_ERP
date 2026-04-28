<?php
require_once '../../includes/middleware.php'; // Controllo autenticazione
$userId = $authenticated_user['user_id'];

$unreadStmt = $connection->prepare("SELECT COUNT(*) FROM bb_notifications WHERE user_id = :uid AND is_read = 0");
$unreadStmt->execute([':uid' => $userId]);
$unreadCount = $unreadStmt->fetchColumn();
$vapidPublicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? '';

// Check if user has any unread high-priority notifications (server-side)
$hiPriStmt = $connection->prepare("SELECT COUNT(*) FROM bb_notifications WHERE user_id = :uid AND is_read = 0 AND priority = 'high'");
$hiPriStmt->execute([':uid' => $userId]);
$hasHighPriority = (int)$hiPriStmt->fetchColumn() > 0;

if (!isset($isCompanyScopedUser)) {
    $isCompanyScopedUser = ($user->role ?? '') === 'company_viewer' || !empty($user->client_id);
}

$currentUserStmt = $connection->prepare("
SELECT u.first_name, u.last_name, u.username, u.photo,
       COALESCE(c.name, u.company, 'N/D') AS company_name
FROM bb_users u
LEFT JOIN bb_companies c ON c.id = u.company_id
WHERE u.id = :uid
LIMIT 1
");
$currentUserStmt->execute([':uid' => $userId]);
$currentUserData = $currentUserStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$currentUserName = trim((string)(($currentUserData['first_name'] ?? '') . ' ' . ($currentUserData['last_name'] ?? '')));
if ($currentUserName === '') {
    $currentUserName = (string)($currentUserData['username'] ?? 'User');
}

$currentCompanyName = (string)($currentUserData['company_name'] ?? 'N/D');
$currentUserPhoto = (string)($currentUserData['photo'] ?? '');
if ($currentUserPhoto === '') {
    $currentUserPhoto = '/uploads/avatar.jpg';
} elseif (str_starts_with($currentUserPhoto, 'Users/')) {
    $currentUserPhoto = '/users/' . $userId . '/user-photo';
} elseif (!preg_match('#^https?://#i', $currentUserPhoto) && $currentUserPhoto[0] !== '/') {
    $currentUserPhoto = '/' . ltrim($currentUserPhoto, '/');
}

$notifStmt = $connection->prepare("
SELECT n.*, u.first_name, u.last_name, w.photo
FROM bb_notifications n
LEFT JOIN bb_users u ON n.created_by = u.id
LEFT JOIN bb_workers w ON u.worker_id = w.id
WHERE n.user_id = :uid
  AND n.is_read = 0
ORDER BY n.created_at DESC
LIMIT 10
");
$notifStmt->execute([':uid' => $userId]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="top-bar relative">
    <nav aria-label="breadcrumb" class="-intro-x mr-auto hidden sm:flex">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="#">Application</a></li>
            <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
        </ol>
    </nav>

    <?php if (!$isCompanyScopedUser): ?>
    <div class="intro-x dropdown mr-4 sm:mr-6">
        <div class="dropdown-toggle notification cursor-pointer" role="button" aria-expanded="false" data-tw-toggle="dropdown">
            <i data-lucide="settings" class="notification__icon dark:text-slate-500"></i>
        </div>

        <div class="notification-content pt-2 dropdown-menu">
            <div class="notification-content__box dropdown-content w-[420px] max-w-full">
                <div class="notification-content__title">Servizi</div>

                <div class="relative flex items-start mt-5 p-2 hover:bg-slate-100 rounded transition cursor-pointer">
                    <div class="w-12 h-12 flex-none flex items-center justify-center mr-2">
                        <i data-lucide="calculator" class="w-6 h-6 text-slate-500"></i>
                    </div>
                    <div class="flex-1 overflow-hidden">
                        <div class="font-medium">Calcola margini cantiere</div>
                        <div class="text-slate-500 text-sm mt-1">Ricalcolo costi e margini BOB / Yard</div>
                        <div class="mt-1">
                            <a href="#" id="run-recalculate-margin" class="text-blue-600 underline text-sm">Avvia servizio</a>
                            <div id="recalculate-margin-result" class="text-xs mt-1 hidden"></div>
                        </div>
                    </div>
                </div>

                <div class="relative flex items-start mt-5 p-2 hover:bg-slate-100 rounded transition cursor-pointer">
                    <div class="w-12 h-12 flex-none flex items-center justify-center mr-2">
                        <i data-lucide="database" class="w-6 h-6 text-slate-500"></i>
                    </div>
                    <div class="flex-1 overflow-hidden">
                        <div class="font-medium">Stato cantiere su Yard</div>
                        <div class="text-slate-500 text-sm mt-1">Controlla stato su YARD</div>
                        <div class="mt-1">
                            <a href="#" id="run-yard-status" class="text-blue-600 underline text-sm">Avvia verifica</a>
                            <div id="yard-status-result" class="text-xs mt-1 hidden"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="intro-x dropdown mr-auto sm:mr-6">
        <div id="notif-bell-toggle" class="dropdown-toggle notification <?= $unreadCount ? 'notification--bullet' : '' ?> cursor-pointer" role="button" aria-expanded="false" data-tw-toggle="dropdown">
            <i data-lucide="bell" class="notification__icon dark:text-slate-500"></i>
        </div>
        <div class="notification-content pt-2 dropdown-menu">
            <div class="notification-content__box dropdown-content w-[450px] max-w-full" id="notification-box">
                <div class="notification-content__title flex items-center justify-between">
                    <span>Notifiche</span>
                    <button id="open-history" type="button" data-tw-toggle="modal" data-tw-target="#notification-history-modal" class="ml-4 text-xs text-blue-600 underline">Cronologia</button>
                </div>

                <div class="border-t border-slate-200 mt-4 pt-3">
                    <button id="enable-browser-push" type="button" class="btn btn-sm btn-outline-primary w-full">Attiva notifiche browser</button>
                    <div id="push-status" class="text-xs text-slate-500 mt-2"></div>
                </div>

                <div id="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-notif text-slate-500 text-center p-4">Nessuna notifica non letta</div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif):
                            $profilePhoto = !empty($notif['photo']) ? '/' . $notif['photo'] : '/uploads/avatar.jpg';
                            ?>
                            <div class="notification-item relative flex items-start mt-5 p-2 hover:bg-slate-100 rounded transition" data-id="<?= (int)$notif['id'] ?>">
                                <div class="w-12 h-12 flex-none image-fit mr-2">
                                    <img alt="Mittente" class="rounded-full" src="<?= htmlspecialchars($profilePhoto) ?>">
                                    <div class="w-3 h-3 bg-success absolute right-0 bottom-0 rounded-full border-2 border-white dark:border-darkmode-600"></div>
                                </div>

                                <div class="flex-1 overflow-hidden">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium mr-2"><?= htmlspecialchars(trim(($notif['first_name'] ?? '') . ' ' . ($notif['last_name'] ?? ''))) ?></span>
                                        <div class="text-xs text-slate-400 whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></div>
                                    </div>
                                    <div class="text-slate-500 mt-1 whitespace-normal break-words"><?= htmlspecialchars((string)$notif['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-2 flex gap-3 text-xs">
                                        <button type="button" class="notif-mark-read text-emerald-700 underline">Segna come letta</button>
                                        <?php if (!empty($notif['link'])): ?>
                                            <a href="<?= htmlspecialchars($notif['link']) ?>" class="notif-open text-blue-600 underline">Apri</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="intro-x dropdown w-8 h-8">
        <div class="dropdown-toggle w-8 h-8 rounded-full overflow-hidden shadow-lg image-fit zoom-in" role="button" aria-expanded="false" data-tw-toggle="dropdown">
            <img alt="BOB" src="<?= htmlspecialchars($currentUserPhoto) ?>">
        </div>
        <div class="dropdown-menu w-56">
            <ul class="dropdown-content bg-primary text-white">
                <li class="p-2">
                    <div class="font-medium"><?= htmlspecialchars($currentUserName) ?></div>
                    <div class="text-xs text-white/70 mt-0.5 dark:text-slate-500"><?= htmlspecialchars($currentCompanyName) ?></div>
                </li>
                <li><hr class="dropdown-divider border-white/[0.08]"></li>
                <li><a href="/profile" class="dropdown-item hover:bg-white/5"><i data-lucide="user" class="w-4 h-4 mr-2"></i> Profilo</a></li>
                <li><a href="/profile#password" class="dropdown-item hover:bg-white/5"><i data-lucide="lock" class="w-4 h-4 mr-2"></i> Cambia Password</a></li>
                <li><hr class="dropdown-divider border-white/[0.08]"></li>
                <li><a href="/logout" class="dropdown-item hover:bg-white/5"><i data-lucide="toggle-right" class="w-4 h-4 mr-2"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</div>

<div id="notification-history-modal" class="modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="font-medium text-base mr-auto">Cronologia notifiche lette</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-tw-dismiss="modal">Chiudi</button>
            </div>
            <div class="modal-body">
                <div id="history-list" class="max-h-[60vh] overflow-y-auto text-sm text-slate-700">
                    <div class="text-slate-500">Caricamento...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="top-bar-config"
     data-has-high-priority="<?= $hasHighPriority ? '1' : '0' ?>"
     data-vapid-public-key="<?= htmlspecialchars($vapidPublicKey) ?>"
     hidden></div>
<script src="/assets/js/includes/template/top_bar.js"></script>

<!-- ═══ Priority Notification Modal (first login of day) ═══ -->
<div id="priority-notif-modal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,.5); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:18px; width:95%; max-width:560px; max-height:80vh; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,.2); animation: pnm-in .3s ease;">
        <div style="padding:20px 24px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:12px;">
            <div style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#ef4444,#f97316);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            </div>
            <div style="flex:1">
                <h3 style="margin:0;font-size:16px;font-weight:800;color:#0f172a">Notifiche Importanti</h3>
                <p style="margin:2px 0 0;font-size:12px;color:#64748b">Richiedono la tua attenzione</p>
            </div>
            <button onclick="dismissPriorityModal()" style="width:32px;height:32px;border-radius:8px;border:none;background:#f1f5f9;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div id="priority-notif-list" style="padding:16px 24px; overflow-y:auto; max-height:calc(80vh - 140px); display:flex; flex-direction:column; gap:10px;"></div>
        <div style="padding:14px 24px; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end;">
            <button onclick="dismissPriorityModal()" style="height:38px;padding:0 20px;border-radius:10px;border:none;background:linear-gradient(135deg,#312e81,#6366f1);color:#fff;font-size:13px;font-weight:700;cursor:pointer;">
                Ho capito
            </button>
        </div>
    </div>
</div>
<link rel="stylesheet" href="/assets/css/includes/template/top_bar.css">

