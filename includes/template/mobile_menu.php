<?php
require_once '../../includes/middleware.php';

$currentPath = $_SERVER['REQUEST_URI'];
$currentPath = strtok($currentPath, '?');
$currentFile = basename($currentPath);

function isActiveMobile($segment, $path, $file = '') {
    if (str_contains($path, $segment)) return 'menu--active';
    if ($file !== '' && basename($path) === $file) return 'menu--active';
    return '';
}

function isOpenMobile($segment, $path, $file = '') {
    if (str_contains($path, $segment)) return 'menu__sub-open';
    if ($file !== '' && basename($path) === $file) return 'menu__sub-open';
    return '';
}
?>

<div class="mobile-menu md:hidden">

    <div class="mobile-menu-bar">
        <a href="" class="flex mr-auto">
            <img alt="Logo" class="w-6" src="<?= $_tplBase ?>/includes/template/dist/images/logo.png">
        </a>
        <a href="javascript:;" class="mobile-menu-toggler">
            <i data-lucide="bar-chart-2" class="w-8 h-8 text-white transform -rotate-90"></i>
        </a>
    </div>

    <div class="scrollable">
        <a href="javascript:;" class="mobile-menu-toggler">
            <i data-lucide="x-circle" class="w-8 h-8 text-white transform -rotate-90"></i>
        </a>

        <ul class="scrollable__content py-2">

            <!-- HOME -->
            <li>
                <a href="javascript:;" class="menu <?= isActiveMobile('/dashboard/', $currentPath, 'dashboard.php') ?>">
                    <div class="menu__icon"><i data-lucide="home"></i></div>
                    <div class="menu__title">Home
                        <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                    </div>
                </a>

                <ul class="<?= isOpenMobile('/dashboard/', $currentPath, 'dashboard.php') ?>">
                    <li>
                        <a href="/views/dashboard/dashboard.php"
                           class="menu <?= $currentFile == 'dashboard.php' ? 'menu--active' : '' ?>">
                            <div class="menu__icon"><i data-lucide="monitor"></i></div>
                            <div class="menu__title">Dashboard</div>
                        </a>
                    </li>
                </ul>
            </li>

            <!-- OFFERTE -->
            <?php if ($user->canAccess('offers')): ?>
                <li>
                    <a href="javascript:;" class="menu <?= isActiveMobile('/offers/', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="file-check"></i></div>
                        <div class="menu__title">Offerte
                            <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                        </div>
                    </a>

                    <ul class="<?= isOpenMobile('/offers/', $currentPath) ?>">
                        <li>
                            <a href="/views/offers/create_offer.php"
                               class="menu <?= $currentFile == 'create_offer.php' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="plus"></i></div>
                                <div class="menu__title">Crea Offerta</div>
                            </a>
                        </li>
                        <li>
                            <a href="/views/offers/offer_list.php"
                               class="menu <?= $currentFile == 'offer_list.php' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="list"></i></div>
                                <div class="menu__title">Lista Offerte</div>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- CLIENTI -->
            <?php if ($user->canAccess('clients')): ?>
                <li>
                    <a href="javascript:;" class="menu <?= isActiveMobile('/clients/', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="users"></i></div>
                        <div class="menu__title">Clienti
                            <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                        </div>
                    </a>

                    <ul class="<?= isOpenMobile('/clients/', $currentPath) ?>">
                        <li>
                            <a href="/clients/create"
                               class="menu <?= $currentPath === '/clients/create' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="user-plus"></i></div>
                                <div class="menu__title">Nuovo Cliente</div>
                            </a>
                        </li>
                        <li>
                            <a href="/clients"
                               class="menu <?= $currentPath === '/clients' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="contact"></i></div>
                                <div class="menu__title">Lista Clienti</div>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- CANTIERI -->
            <?php if ($user->canAccess('worksites')): ?>
                <li>
                    <a href="javascript:;"
                       class="menu <?= isActiveMobile('/worksites/', $currentPath, 'worksite_list.php') ?>">
                        <div class="menu__icon"><i data-lucide="file-check"></i></div>
                        <div class="menu__title">Cantieri
                            <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                        </div>
                    </a>

                    <ul class="<?= isOpenMobile('/worksites/', $currentPath, 'worksite_list.php') ?>">
                        <li>
                            <a href="/views/worksites/create_worksite.php"
                               class="menu <?= $currentFile == 'create_worksite.php' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="plus"></i></div>
                                <div class="menu__title">Crea Cantiere</div>
                            </a>
                        </li>

                        <li>
                            <a href="/views/worksites/worksite_list.php"
                               class="menu <?= str_contains($currentPath, 'worksite_list.php') ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="list"></i></div>
                                <div class="menu__title">Lista Cantieri</div>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- PRESENZE -->
            <?php if ($user->canAccess('attendance')): ?>
                <li>
                    <a href="javascript:;" class="menu <?= isActiveMobile('/attendance/', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="calendar"></i></div>
                        <div class="menu__title">Presenze
                            <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                        </div>
                    </a>

                    <ul class="<?= isOpenMobile('/attendance', $currentPath) ?>">
                        <li>
                            <a href="/attendance/create"
                               class="menu <?= $currentPath === '/attendance/create' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="plus"></i></div>
                                <div class="menu__title">Inserisci</div>
                            </a>
                        </li>

                        <li>
                            <a href="/attendance"
                               class="menu <?= $currentPath === '/attendance' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="search"></i></div>
                                <div class="menu__title">Cerca</div>
                            </a>
                        </li>

                        <li>
                            <a href="/attendance/advances"
                               class="menu <?= $currentPath === '/attendance/advances' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="banknote"></i></div>
                                <div class="menu__title">Anticipi</div>
                            </a>
                        </li>

                        <li>
                            <a href="/attendance/refunds"
                               class="menu <?= $currentPath === '/attendance/refunds' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="wallet"></i></div>
                                <div class="menu__title">Rimborsi</div>
                            </a>
                        </li>

                        <li>
                            <a href="/attendance/fines"
                               class="menu <?= $currentPath === '/attendance/fines' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="camera"></i></div>
                                <div class="menu__title">Multe</div>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- DOCUMENTI -->
            <?php if ($user->canAccess('documents')): ?>
                <li>
                    <a href="javascript:;" class="menu <?= isActiveMobile('/users/', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="files"></i></div>
                        <div class="menu__title">Documenti
                            <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                        </div>
                    </a>

                    <ul class="<?= isOpenMobile('/users/', $currentPath) ?>">
                        <li>
                            <a href="/views/users/list.php"
                               class="menu <?= $currentFile == 'list.php' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="users"></i></div>
                                <div class="menu__title">Operai</div>
                            </a>
                        </li>

                        <li>
                            <a href="/views/dashboard/dashboard.php"
                               class="menu <?= $currentFile == 'dashboard.php' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="alert-triangle"></i></div>
                                <div class="menu__title">Documenti Scaduti</div>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <!-- PRENOTAZIONI -->
            <?php if ($user->canAccess('bookings')): ?>
                <li>
                    <a href="/bookings" class="menu <?= isActiveMobile('/bookings', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="bookmark"></i></div>
                        <div class="menu__title">Prenotazioni</div>
                    </a>
                </li>
            <?php endif; ?>

            <!-- PIANIFICAZIONE -->
            <?php if ($user->canAccess('pianificazione')): ?>
                <li>
                    <a href="/pianificazione" class="menu <?= isActiveMobile('/pianificazione/', $currentPath, 'pianificazione.php') ?>">
                        <div class="menu__icon"><i data-lucide="clipboard-list"></i></div>
                        <div class="menu__title">Squadre</div>
                    </a>
                </li>
            <?php endif; ?>

            <!-- PROGRAMMAZIONE MEZZI -->
            <?php if ($user->canAccess('programmazione')): ?>
                <li>
                    <a href="/programmazione" class="menu <?= isActiveMobile('/programmazione', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="truck"></i></div>
                        <div class="menu__title">Programmazione</div>
                    </a>
                </li>
            <?php endif; ?>

            <!-- BIGLIETTINI PASTO -->
            <?php if ($user->canAccess('tickets')): ?>
                <li>
                    <a href="/tickets" class="menu <?= isActiveMobile('/tickets', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="ticket"></i></div>
                        <div class="menu__title">Bigliettini</div>
                    </a>
                </li>
            <?php endif; ?>
            <!-- DOC CONDIVISI -->
            <?php if ($user->canAccess('share')): ?>
                <li>
                    <a href="javascript:;" class="menu <?= isActiveMobile('/share/', $currentPath) ?>">
                        <div class="menu__icon"><i data-lucide="cloud"></i></div>
                        <div class="menu__title">Doc Condivisi
                            <i data-lucide="chevron-down" class="menu__sub-icon"></i>
                        </div>
                    </a>

                    <ul class="<?= isOpenMobile('/share/', $currentPath) ?>">
                        <li>
                            <a href="/share/create"
                               class="menu <?= $currentPath === '/share/create' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="plus"></i></div>
                                <div class="menu__title">Crea Link</div>
                            </a>
                        </li>

                        <li>
                            <a href="/share"
                               class="menu <?= $currentPath === '/share' ? 'menu--active' : '' ?>">
                                <div class="menu__icon"><i data-lucide="list"></i></div>
                                <div class="menu__title">Lista Link</div>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

        </ul>
    </div>
</div>
