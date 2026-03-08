<?php
/**
 * FleetLink Magazyn - HTML Header & Navigation
 * $pageTitle, $activePage should be set before including this file
 */
if (!defined('IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}
$currentUser = getCurrentUser();
$pageTitle = ($pageTitle ?? 'Dashboard') . ' — FleetLink Magazyn';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <!-- Apply saved theme before render to avoid flash -->
    <script>
        (function(){var t=localStorage.getItem('fl-theme')||'light';document.documentElement.setAttribute('data-bs-theme',t);})();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= getBaseUrl() ?>assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= getBaseUrl() ?>dashboard.php">
            <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.svg" alt="FleetLink" height="34" style="display:block">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>dashboard.php">
                        <i class="fas fa-tachometer-alt me-1"></i>Desktop
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array(($activePage ?? ''), ['manufacturers','models','devices','sim_cards']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-microchip me-1"></i>Urządzenia
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>devices.php"><i class="fas fa-list me-2"></i>Lista urządzeń</a></li>
                        <?php if (isAdmin()): ?>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>devices.php?action=add"><i class="fas fa-plus me-2"></i>Dodaj urządzenie</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>device_import.php"><i class="fas fa-file-import me-2"></i>Importuj urządzenia</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>sim_cards.php"><i class="fas fa-sim-card me-2"></i>Karty SIM</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>sim_cards.php?action=add"><i class="fas fa-plus me-2"></i>Dodaj kartę SIM</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>manufacturers.php"><i class="fas fa-industry me-2"></i>Producenci</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>models.php"><i class="fas fa-tags me-2"></i>Modele</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($activePage ?? '') === 'inventory' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-warehouse me-1"></i>Magazyn
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>inventory.php"><i class="fas fa-microchip me-2"></i>Urządzenia</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>inventory.php?action=accessories"><i class="fas fa-toolbox me-2"></i>Akcesoria</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>inventory.php?action=movements"><i class="fas fa-history me-2"></i>Historia ruchów</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'clients' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>clients.php">
                        <i class="fas fa-users me-1"></i>Klienci
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($activePage ?? '') === 'installations' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-car me-1"></i>Instalacje
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>installations.php"><i class="fas fa-list me-2"></i>Lista montaży</a></li>
                        <?php if (!isTechnician()): ?>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>installations.php?action=add"><i class="fas fa-plus me-2"></i>Nowy montaż</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($activePage ?? '') === 'services' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-wrench me-1"></i>Serwisy
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>services.php"><i class="fas fa-list me-2"></i>Lista serwisów</a></li>
                        <?php if (!isTechnician()): ?>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>services.php?action=add"><i class="fas fa-plus me-2"></i>Nowy serwis</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'calendar' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>calendar.php">
                        <i class="fas fa-calendar-alt me-1"></i>Kalendarz
                    </a>
                </li>
                <?php if (isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'statistics' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>statistics.php">
                        <i class="fas fa-chart-bar me-1"></i>Statystyki
                    </a>
                </li>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array(($activePage ?? ''), ['users','settings','email']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-cog me-1"></i>Admin
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>users.php"><i class="fas fa-users-cog me-2"></i>Użytkownicy</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>email.php"><i class="fas fa-envelope me-2"></i>Wyślij e-mail</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>settings.php"><i class="fas fa-sliders-h me-2"></i>Ustawienia</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <!-- Dark mode toggle -->
                <li class="nav-item">
                    <button id="darkModeToggle" title="Tryb ciemny" aria-label="Przełącz tryb kolorów">
                        <i class="fas fa-moon"></i>
                    </button>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i><?= h($currentUser['name'] ?? 'Użytkownik') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= h($currentUser['email'] ?? '') ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>account.php"><i class="fas fa-user me-2"></i>Moje konto</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>logout.php"><i class="fas fa-sign-out-alt me-2"></i>Wyloguj</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container-fluid py-3">
    <?= renderFlash() ?>
