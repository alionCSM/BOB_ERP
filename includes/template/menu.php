<?php
require_once '../../includes/middleware.php';
$isWorker = ($user->type === 'worker');

$isCompanyScopedUser = false;
if (($user->role ?? '') === 'company_viewer' || !empty($user->client_id) || !empty($user->permissions['companies_viewer'])) {
    $isCompanyScopedUser = true;
} else {
    $mapStmtMenu = $connection->query("SHOW TABLES LIKE 'bb_user_company_access'");
    $hasCompanyMapMenu = $mapStmtMenu && $mapStmtMenu->fetch(PDO::FETCH_NUM);
    if ($hasCompanyMapMenu) {
        $cntStmt = $connection->prepare("SELECT COUNT(*) FROM bb_user_company_access WHERE user_id = :uid");
        $cntStmt->execute([':uid' => $user->id]);
        $isCompanyScopedUser = ((int)$cntStmt->fetchColumn() > 0);
    }
}

// --------------------------------------
// NORMALIZE REQUEST PATH
// --------------------------------------
$currentPath = $_SERVER['REQUEST_URI'];
$currentPath = strtok($currentPath, '?'); // Remove query string

if ($currentPath !== '/' && str_ends_with($currentPath, '/')) {
    $currentPath = rtrim($currentPath, '/');
}

$currentFile = basename($currentPath);

// --------------------------------------
// NEW HELPERS (WORK WITH FILE NAMES)
// --------------------------------------
function isActiveFile(string $file, string $currentFile): string {
    return ($currentFile === $file) ? 'side-menu--active' : '';
}

function menuActive(array $files, string $currentFile): string {
    return in_array($currentFile, $files, true) ? 'side-menu--active' : '';
}

function submenuOpen(array $files, string $currentFile): string {
    return in_array($currentFile, $files, true) ? 'side-menu__sub-open' : '';
}

function arrowRotate(array $files, string $currentFile): string {
    return in_array($currentFile, $files, true) ? 'transform rotate-180' : '';
}

?>

<nav class="side-nav">

    <!-- LOGO -->
    <a href="/dashboard" class="intro-x flex items-center pl-5 pt-4">
        <img alt="Bob Logo" class="w-10" src="<?= $_tplBase ?>/includes/template/dist/images/logo.png">
        <span class="hidden xl:block text-white text-lg ml-3"> BOB </span>
    </a>

    <div class="side-nav__devider my-6"></div>

    <ul>

        <?php if ($isCompanyScopedUser): ?>

            <!-- ── AZIENDE ── -->
            <?php $companiesViewerActive = str_starts_with($currentPath, '/companies'); ?>
            <li>
                <a href="/companies/my" class="side-menu <?= $companiesViewerActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="building"></i> </div>
                    <div class="side-menu__title">Le Mie Aziende</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>

            <!-- ── OPERAI ── -->
            <?php $usersActive = str_starts_with($currentPath, '/users'); ?>
            <li>
                <a href="/users/workers" class="side-menu <?= ($usersActive && $currentPath !== '/users/create') ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="users"></i> </div>
                    <div class="side-menu__title">Operai</div>
                </a>
            </li>

            <li>
                <a href="/users/create" class="side-menu <?= $currentPath === '/users/create' ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="user-plus"></i> </div>
                    <div class="side-menu__title">Nuovo Operaio</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>

            <!-- ── SCADENZE ── -->
            <li>
                <a href="/documents/expired-cv" class="side-menu <?= $currentPath === '/documents/expired-cv' ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="alert-triangle"></i> </div>
                    <div class="side-menu__title">Scadenze</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>

        <?php else: ?>

        <!-- HOME (only for non-company-scoped users) -->
        <?php $homeActive = ($currentPath === '/' || $currentPath === '/dashboard'); ?>
        <li>
            <a href="javascript:;" class="side-menu <?= $homeActive ? 'side-menu--active' : '' ?>">
                <div class="side-menu__icon"> <i data-lucide="home"></i> </div>
                <div class="side-menu__title">
                    Home
                    <div class="side-menu__sub-icon <?= $homeActive ? 'transform rotate-180' : '' ?>">
                        <i data-lucide="chevron-down"></i>
                    </div>
                </div>
            </a>
            <ul class="<?= $homeActive ? 'side-menu__sub-open' : '' ?>">
                <li>
                    <a href="/dashboard" class="side-menu <?= $homeActive ? 'side-menu--active' : '' ?>">
                        <div class="side-menu__icon"> <i data-lucide="monitor"></i> </div>
                        <div class="side-menu__title">Dashboard</div>
                    </a>
                </li>
            </ul>
        </li>

        <li class="side-nav__devider my-6"></li>

        <?php endif; ?>

        <!-- OFFERTE -->
        <?php if ($user->canAccess('offers')): ?>
            <?php $offersActive = str_starts_with($currentPath, '/offers'); ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $offersActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="file-check"></i> </div>
                    <div class="side-menu__title">
                        Offerte
                        <div class="side-menu__sub-icon <?= $offersActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $offersActive ? 'side-menu__sub-open' : '' ?>">
                    <li>
                        <a href="/offers/create" class="side-menu <?= $currentPath === '/offers/create' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="plus"></i> </div>
                            <div class="side-menu__title">Crea Offerta</div>
                        </a>
                    </li>

                    <li>
                        <a href="/offers" class="side-menu <?= $currentPath === '/offers' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="list"></i> </div>
                            <div class="side-menu__title">Lista Offerte</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- CLIENTI -->
        <?php if ($user->canAccess('clients')): ?>
            <?php $clientsActive = str_starts_with($currentPath, '/clients'); ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $clientsActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="users"></i> </div>
                    <div class="side-menu__title">
                        Clienti
                        <div class="side-menu__sub-icon <?= $clientsActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $clientsActive ? 'side-menu__sub-open' : '' ?>">
                    <li>
                        <a href="/clients/create" class="side-menu <?= $currentPath === '/clients/create' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="user-plus"></i> </div>
                            <div class="side-menu__title">Nuovo Cliente</div>
                        </a>
                    </li>

                    <li>
                        <a href="/clients" class="side-menu <?= $currentPath === '/clients' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="contact"></i> </div>
                            <div class="side-menu__title">Lista Clienti</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- CANTIERI -->
        <?php
        $worksitesPathActive = str_starts_with($currentPath, '/worksites');
        ?>
        <?php if ($isWorker): ?>

            <li>
                <a href="/worksites/my"
                   class="side-menu <?= $worksitesPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon">
                        <i data-lucide="hard-hat"></i>
                    </div>
                    <div class="side-menu__title">
                        I miei cantieri
                    </div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>

        <?php elseif ($user->canAccess('worksites')): ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $worksitesPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="file-check"></i> </div>
                    <div class="side-menu__title">
                        Cantieri
                        <div class="side-menu__sub-icon <?= $worksitesPathActive ? 'rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $worksitesPathActive ? 'side-menu__sub-open' : '' ?>">
                    <li>
                        <a href="/worksites/create" class="side-menu <?= $currentPath === '/worksites/create' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="plus"></i> </div>
                            <div class="side-menu__title">Crea Cantiere</div>
                        </a>
                    </li>

                    <li>
                        <a href="/worksites" class="side-menu <?= $currentPath === '/worksites' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="list"></i> </div>
                            <div class="side-menu__title">Lista Cantieri</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- FATTURAZIONE -->
        <?php if ($user->canAccess('billing')): ?>
            <?php $billingPathActive = str_starts_with($currentPath, '/billing'); ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $billingPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="euro"></i> </div>
                    <div class="side-menu__title">
                        Fatturazione
                        <div class="side-menu__sub-icon <?= $billingPathActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $billingPathActive ? 'side-menu__sub-open' : '' ?>">
                    <li>
                        <a href="/billing" class="side-menu <?= $currentPath === '/billing' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="activity"></i> </div>
                            <div class="side-menu__title">Cantieri Movimentati</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- PRESENZE -->
        <?php if ($user->canAccess('attendance')): ?>
            <?php $attendancePathActive = str_starts_with($currentPath, '/attendance'); ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $attendancePathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="calendar"></i> </div>
                    <div class="side-menu__title">
                        Presenze
                        <div class="side-menu__sub-icon <?= $attendancePathActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $attendancePathActive ? 'side-menu__sub-open' : '' ?>">

                    <li>
                        <a href="/attendance/create" class="side-menu <?= $currentPath === '/attendance/create' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="plus"></i> </div>
                            <div class="side-menu__title">Inserisci Presenze</div>
                        </a>
                    </li>

                    <li>
                        <a href="/attendance" class="side-menu <?= $currentPath === '/attendance' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="search"></i> </div>
                            <div class="side-menu__title">Cerca</div>
                        </a>
                    </li>

                    <li>
                        <a href="/attendance/advances" class="side-menu <?= $currentPath === '/attendance/advances' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="banknote"></i> </div>
                            <div class="side-menu__title">Anticipi</div>
                        </a>
                    </li>

                    <li>
                        <a href="/attendance/refunds" class="side-menu <?= $currentPath === '/attendance/refunds' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="wallet"></i> </div>
                            <div class="side-menu__title">Rimborsi</div>
                        </a>
                    </li>

                    <li>
                        <a href="/attendance/fines" class="side-menu <?= $currentPath === '/attendance/fines' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="camera"></i> </div>
                            <div class="side-menu__title">Multe</div>
                        </a>
                    </li>

                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- MEZZI SOLLEVAMENTO -->
        <?php if ($user->canAccess('equipment')): ?>
            <?php $equipmentPathActive = str_starts_with($currentPath, '/equipment'); ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $equipmentPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="truck"></i> </div>
                    <div class="side-menu__title">
                        Mezzi Sollevamento
                        <div class="side-menu__sub-icon <?= $equipmentPathActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $equipmentPathActive ? 'side-menu__sub-open' : '' ?>">
                    <li>
                        <a href="/equipment/assign" class="side-menu <?= $currentPath === '/equipment/assign' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="plus-circle"></i> </div>
                            <div class="side-menu__title">Inserisci Mezzi</div>
                        </a>
                    </li>

                    <li>
                        <a href="/equipment/rentals" class="side-menu <?= $currentPath === '/equipment/rentals' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="list-checks"></i> </div>
                            <div class="side-menu__title">Noleggi</div>
                        </a>
                    </li>

                    <li>
                        <a href="/equipment/manage" class="side-menu <?= $currentPath === '/equipment/manage' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="clipboard"></i> </div>
                            <div class="side-menu__title">Mezzi Sollevamento</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- PRENOTAZIONI -->
        <?php if ($user->canAccess('bookings')): ?>
            <?php $bookingsPathActive = str_starts_with($currentPath, '/bookings'); ?>
            <li>
                <a href="/bookings" class="side-menu <?= $bookingsPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="bookmark"></i> </div>
                    <div class="side-menu__title">Prenotazioni</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>

        <!-- PIANIFICAZIONE SQUADRE -->
        <?php if ($user->canAccess('pianificazione')): ?>
            <li>
                <a href="/pianificazione" class="side-menu <?= $currentPath === '/pianificazione' ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="clipboard-list"></i> </div>
                    <div class="side-menu__title">Squadre</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>

        <!-- PROGRAMMAZIONE MEZZI -->
        <?php if ($user->canAccess('programmazione')): ?>
            <?php $progPathActive = str_starts_with($currentPath, '/programmazione'); ?>
            <li>
                <a href="/programmazione" class="side-menu <?= $progPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="truck"></i> </div>
                    <div class="side-menu__title">Programmazione</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>

        <!-- BIGLIETTINI PASTO (MOP) -->
        <?php if ($user->canAccess('tickets')): ?>
            <?php $ticketsPathActive = str_starts_with($currentPath, '/tickets'); ?>
            <li>
                <a href="/tickets" class="side-menu <?= $ticketsPathActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="ticket"></i> </div>
                    <div class="side-menu__title">Bigliettini</div>
                </a>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>

        <!-- COMPLIANCE -->
        <?php if ($user->canAccess('documents')): ?>
            <?php
            $complianceFiles   = [];
            $usersPathActive   = str_starts_with($currentPath, '/users');
            $companiesActive   = str_starts_with($currentPath, '/companies');
            $complianceActive  = $currentPath === '/documents/expired' || $currentPath === '/documents/expired-cv' || $usersPathActive || $companiesActive;
            ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $complianceActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon"> <i data-lucide="shield-check"></i> </div>
                    <div class="side-menu__title">
                        Compliance
                        <div class="side-menu__sub-icon <?= $complianceActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $complianceActive ? 'side-menu__sub-open' : '' ?>">

                    <li>
                        <a href="/users" class="side-menu <?= ($usersPathActive && $currentPath !== '/users/create') ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="users"></i> </div>
                            <div class="side-menu__title">Operai</div>
                        </a>
                    </li>

                    <li>
                        <a href="/companies" class="side-menu <?= ($companiesActive && $currentPath !== '/companies/my') ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="file-text"></i> </div>
                            <div class="side-menu__title">Aziende</div>
                        </a>
                    </li>

                    <li>
                        <a href="/documents/expired" class="side-menu <?= $currentPath === '/documents/expired' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="alert-triangle"></i> </div>
                            <div class="side-menu__title">Documenti Scaduti</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="side-nav__devider my-6"></li>
        <?php endif; ?>


        <!-- DOC CONDIVISI -->
        <?php if ($user->canAccess('share')): ?>
            <?php $shareActive = ($currentPath === '/share' || str_starts_with($currentPath, '/share/')); ?>
            <li>
                <a href="javascript:;" class="side-menu <?= $shareActive ? 'side-menu--active' : '' ?>">
                    <div class="side-menu__icon">
                        <i data-lucide="cloud"></i>
                    </div>
                    <div class="side-menu__title">
                        Doc Condivisi
                        <div class="side-menu__sub-icon <?= $shareActive ? 'transform rotate-180' : '' ?>">
                            <i data-lucide="chevron-down"></i>
                        </div>
                    </div>
                </a>

                <ul class="<?= $shareActive ? 'side-menu__sub-open' : '' ?>">

                    <li>
                        <a href="/share/create" class="side-menu <?= $currentPath === '/share/create' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="plus"></i> </div>
                            <div class="side-menu__title">Crea Link</div>
                        </a>
                    </li>

                    <li>
                        <a href="/share" class="side-menu <?= $currentPath === '/share' ? 'side-menu--active' : '' ?>">
                            <div class="side-menu__icon"> <i data-lucide="list"></i> </div>
                            <div class="side-menu__title">Lista Link</div>
                        </a>
                    </li>

                </ul>

            </li>
        <?php endif; ?>

    </ul>

    <?php
    require_once __DIR__ . '/../version.php';
    $bobVer = getBobVersion();
    ?>
    <div style="padding:16px 20px 20px;margin-top:auto;border-top:1px solid rgba(255,255,255,.06);">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:10px;font-weight:800;letter-spacing:.08em;color:rgba(255,255,255,.25);text-transform:uppercase;">BOB</span>
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:5px;background:rgba(99,102,241,.15);color:rgba(139,92,246,.8);"><?= htmlspecialchars($bobVer['version']) ?><?php if (!empty($bobVer['commits'])): ?><span style="opacity:.4;">+<?= $bobVer['commits'] ?></span><?php endif; ?></span>
        </div>
    </div>

</nav>
