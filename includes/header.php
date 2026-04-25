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

// ── Nav modal data (used by quick-action modals in navbar) ──────────
$_navDb = getDb();
$navModels = $navAvailableModels = $navAvailableDevices = [];
$navAllDevices = $navActiveInstallations = [];
$navClients = $navUsers = $navSimOptions = $navSimDevices = [];
try {
    $navModels = $_navDb->query("SELECT m.id, m.name, mf.name as manufacturer_name FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE m.active=1 ORDER BY mf.name, m.name")->fetchAll();
    try { $navSimOptions = $_navDb->query("SELECT phone_number FROM sim_cards WHERE active=1 ORDER BY phone_number")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $_e2) {}
    $navAvailableModels = $_navDb->query("SELECT m.id as model_id, m.name as model_name, mf.name as manufacturer_name, COUNT(d.id) as available_count FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id JOIN devices d ON d.model_id=m.id AND d.status IN ('nowy','sprawny') GROUP BY m.id HAVING available_count > 0 ORDER BY mf.name, m.name")->fetchAll();
    $navAvailableDevices = $_navDb->query("SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.status IN ('nowy','sprawny') ORDER BY mf.name, m.name, d.serial_number")->fetchAll();
    $navAllDevices = $_navDb->query("SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.status NOT IN ('wycofany','sprzedany') ORDER BY mf.name, m.name, d.serial_number")->fetchAll();
    $navActiveInstallations = $_navDb->query("SELECT i.id, v.registration, d.serial_number FROM installations i JOIN vehicles v ON v.id=i.vehicle_id JOIN devices d ON d.id=i.device_id WHERE i.status='aktywna' ORDER BY v.registration")->fetchAll();
    $navClients = $_navDb->query("SELECT id, contact_name, company_name, address, city, postal_code FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
    $navUsers = $_navDb->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();
    $navSimDevices = $_navDb->query("SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.status NOT IN ('wycofany','sprzedany') ORDER BY mf.name, m.name, d.serial_number")->fetchAll();
} catch (Exception $_e) {}
// ── End nav modal data ──────────────────────────────────────────────
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
            <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.png" alt="FleetLink" height="34" style="display:block">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>dashboard.php">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array(($activePage ?? ''), ['manufacturers','models','devices','sim_cards']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-microchip me-1"></i>Urządzenia
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>devices.php"><i class="fas fa-list me-2"></i>Lista urządzeń</a></li>
                        <?php if (isAdmin()): ?>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#navDevAddModal"><i class="fas fa-plus me-2"></i>Dodaj urządzenie</button></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>device_import.php"><i class="fas fa-file-import me-2"></i>Importuj urządzenia</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>sim_cards.php"><i class="fas fa-sim-card me-2"></i>Karty SIM</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#navSimAddModal"><i class="fas fa-plus me-2"></i>Dodaj kartę SIM</button></li>
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
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($activePage ?? '') === 'clients' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-users me-1"></i>Klienci
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>clients.php"><i class="fas fa-list me-2"></i>Lista klientów</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#navClientAddModal"><i class="fas fa-user-plus me-2"></i>Dodaj klienta</button></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($activePage ?? '') === 'installations' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-car me-1"></i>Instalacje
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>installations.php"><i class="fas fa-list me-2"></i>Lista montaży</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#navInstAddModal"><i class="fas fa-plus me-2"></i>Nowy montaż</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>protocols.php?filter=installation"><i class="fas fa-clipboard-check me-2"></i>Protokoły montaży</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($activePage ?? '') === 'services' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-wrench me-1"></i>Serwisy
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>services.php"><i class="fas fa-list me-2"></i>Lista serwisów</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#navSvcAddModal"><i class="fas fa-plus me-2"></i>Nowy serwis</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>protocols.php?filter=service"><i class="fas fa-clipboard-check me-2"></i>Protokoły serwisu</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'calendar' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>calendar.php">
                        <i class="fas fa-calendar-alt me-1"></i>Kalendarz
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array(($activePage ?? ''), ['offers','offer_generator']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-file-invoice-dollar me-1"></i>Oferty
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>offers.php"><i class="fas fa-list me-2"></i>Lista ofert</a></li>
                        <li><a class="dropdown-item" href="<?= getBaseUrl() ?>offer_generator.php" target="_blank"><i class="fas fa-magic me-2"></i>Generator ofert GPS</a></li>
                    </ul>
                </li>
                <?php if (isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($activePage ?? '') === 'statistics' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>statistics.php">
                        <i class="fas fa-chart-bar me-1"></i>Statystyki
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="https://system.le-mar.eu/partner/" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-satellite-dish me-1"></i>Logowanie GPS
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="https://system.le-mar.eu/services/" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-broadcast-tower me-1"></i>Status Urządzenia
                    </a>
                </li>
                <?php
                // Load schema settings for Schematy dropdown
                $allcan300Pass         = 'Pj0;Gm6$.g2rnd9';
                $schemaDbPasses        = [];
                try {
                    $navSchemaStmt = getDb()->prepare("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'schema_%_pass'");
                    $navSchemaStmt->execute();
                    foreach ($navSchemaStmt->fetchAll() as $sr) {
                        $schemaDbPasses[$sr['key']] = $sr['value'];
                    }
                    if (!empty($schemaDbPasses['schema_allcan300_pass'])) {
                        $allcan300Pass = $schemaDbPasses['schema_allcan300_pass'];
                    }
                } catch (Exception $e) {}
                $navSchemas = [
                    ['label' => 'ALL-CAN 300',        'url' => 'https://share.teltonika.lt/index.php/s/rFHo99iWX8BHMaZ/authenticate/showshare',    'pass' => $allcan300Pass],
                    ['label' => 'CAN-CONTROL',        'url' => 'https://share.teltonika.lt/index.php/s/srTkkTW57jczcAT/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_cancontrol_pass'])        ? $schemaDbPasses['schema_cancontrol_pass']        : "3dmU~I{_@;W'OVL"],
                    ['label' => 'CAN-CONTROL 6C IMMO','url' => 'https://share.teltonika.lt/index.php/s/k7CaQNcbjTRmSSL/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_cancontrol6cimmo_pass'])  ? $schemaDbPasses['schema_cancontrol6cimmo_pass']  : "f_n8n}G'sK+j4fx"],
                    ['label' => 'CAN-CONTROL 6C',     'url' => 'https://share.teltonika.lt/index.php/s/Ad6P4Ea93Nptjnn/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_cancontrol6c_pass'])      ? $schemaDbPasses['schema_cancontrol6c_pass']      : 'q1{nGYAfw3-!5y#'],
                    ['label' => 'CAN-CONTROL DTC',    'url' => 'https://share.teltonika.lt/index.php/s/Y2YYfPMi9e7kyTK/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_cancontroldtc_pass'])     ? $schemaDbPasses['schema_cancontroldtc_pass']     : "Hm!oo7jW-#kgxu'"],
                    ['label' => 'CAN-CONTROL IMMO',   'url' => 'https://share.teltonika.lt/index.php/s/jEq22Dcqonq86p9/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_cancontrolimmo_pass'])    ? $schemaDbPasses['schema_cancontrolimmo_pass']    : 'F6evA;eIYTji~f('],
                    ['label' => 'CAN-CONTROL IMMO P1','url' => 'https://share.teltonika.lt/index.php/s/73jkTnDko8PTJCe/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_cancontrolimmop1_pass'])  ? $schemaDbPasses['schema_cancontrolimmop1_pass']  : '#F+B9Q1OJS#uSI@'],
                    ['label' => 'FMB 140 ALL-CAN',    'url' => 'https://share.teltonika.lt/index.php/s/xsxZPknB78S9763/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_fmb140allcan_pass'])      ? $schemaDbPasses['schema_fmb140allcan_pass']      : 'EmNj+l%3g!aaSqQ'],
                    ['label' => 'FMB 140 LV-CAN',     'url' => 'https://share.teltonika.lt/index.php/s/mmrjRCGkicBjAtz/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_fmb140lvcan_pass'])       ? $schemaDbPasses['schema_fmb140lvcan_pass']       : 'IrL@nhJuyvdD=96'],
                    ['label' => 'FMC 150',             'url' => 'https://share.teltonika.lt/index.php/s/w8Xi3txtHLB3B4H/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_fmc150_pass'])            ? $schemaDbPasses['schema_fmc150_pass']            : 'i-evHv6#hu5I(ei'],
                    ['label' => 'LV-CAN200',           'url' => 'https://share.teltonika.lt/index.php/s/F9nGxssycArbkem/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_lvcan200_pass'])          ? $schemaDbPasses['schema_lvcan200_pass']          : ',J8RPt%_EgEFzOY'],
                    ['label' => 'LV-CAN200 DTC',       'url' => 'https://share.teltonika.lt/index.php/s/JnEzJEeTDMQFqbX/authenticate/showshare',    'pass' => !empty($schemaDbPasses['schema_lvcan200dtc_pass'])       ? $schemaDbPasses['schema_lvcan200dtc_pass']       : "W.#2}~MaqY]]w'D"],
                ];
                ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                        <i class="fas fa-sitemap me-1"></i>Schematy
                    </a>
                    <ul class="dropdown-menu">
                        <?php foreach ($navSchemas as $schemaIdx => $schema): $schemaId = 'schemaPass' . $schemaIdx; ?>
                        <li>
                            <a class="dropdown-item" href="<?= h($schema['url']) ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-file-alt me-2"></i><?= h($schema['label']) ?>
                            </a>
                        </li>
                        <li>
                            <span class="dropdown-item d-flex align-items-center gap-2" style="cursor:default">
                                <i class="fas fa-key text-muted"></i>
                                <span class="text-muted small" id="<?= h($schemaId) ?>"><?= h($schema['pass']) ?></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-auto" style="font-size:.75rem"
                                    onclick="(function(btn,id,e){e.stopPropagation();var t=document.getElementById(id).textContent;navigator.clipboard.writeText(t).then(function(){var orig=btn.innerHTML;btn.innerHTML='<i class=\'fas fa-check\'></i>';setTimeout(function(){btn.innerHTML=orig;},1500);}).catch(function(){var orig=btn.innerHTML;btn.innerHTML='<i class=\'fas fa-times\'></i>';setTimeout(function(){btn.innerHTML=orig;},1500);});})(this,'<?= h($schemaId) ?>',event)"
                                    title="Kopiuj hasło">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
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

<!-- ================================================================ -->
<!-- NAV QUICK-ACTION MODALS                                          -->
<!-- ================================================================ -->

<?php if (isAdmin()): ?>
<!-- Modal: Dodaj urządzenie (nav) -->
<div class="modal fade" id="navDevAddModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="<?= getBaseUrl() ?>devices.php" id="navDevAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_add_devices">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2 text-primary"></i>Dodaj urządzenie / urządzenia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3 pb-3 border-bottom">
                        <div class="col-md-4">
                            <label class="form-label required-star">Model urządzenia (wspólny)</label>
                            <select name="model_id" id="navDevAddModel" class="form-select" required>
                                <option value="">— wybierz model —</option>
                                <?php
                                $navDevMf = '';
                                foreach ($navModels as $m):
                                    if ($m['manufacturer_name'] !== $navDevMf) {
                                        if ($navDevMf) echo '</optgroup>';
                                        echo '<optgroup label="' . h($m['manufacturer_name']) . '">';
                                        $navDevMf = $m['manufacturer_name'];
                                    }
                                ?>
                                <option value="<?= $m['id'] ?>"><?= h($m['name']) ?></option>
                                <?php endforeach; if ($navDevMf) echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status (wspólny)</label>
                            <select name="status" class="form-select">
                                <?php foreach (['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa'] as $s): ?>
                                <option value="<?= $s ?>" <?= $s === 'nowy' ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_',' ',$s))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Data zakupu (wspólna)</label>
                            <input type="date" name="purchase_date" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cena zakupu (wspólna)</label>
                            <div class="input-group">
                                <input type="number" name="purchase_price" class="form-control" min="0" step="0.01" value="0">
                                <span class="input-group-text">zł</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold text-muted small">Urządzenia do dodania</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="navDevAddRow()"><i class="fas fa-plus me-1"></i>Dodaj wiersz</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:30px">#</th>
                                    <th>Nr seryjny <span class="text-danger">*</span></th>
                                    <th>IMEI</th>
                                    <th>Nr telefonu SIM</th>
                                    <th>Uwagi</th>
                                    <th style="width:42px"></th>
                                </tr>
                            </thead>
                            <tbody id="navDevicesBody"></tbody>
                        </table>
                    </div>
                    <div class="mt-2 text-muted small" id="navDevicesCount">0 urządzeń do dodania</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Zapisz urządzenia</button>
                </div>
            </form>
        </div>
    </div>
</div>
<datalist id="navSimListAdd">
    <?php foreach ($navSimOptions as $sc): ?>
    <option value="<?= h($sc) ?>">
    <?php endforeach; ?>
</datalist>
<?php endif; ?>

<!-- Modal: Dodaj kartę SIM (nav) — bulk, jak przy urządzeniach -->
<div class="modal fade" id="navSimAddModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="<?= getBaseUrl() ?>sim_cards.php" id="navSimAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_add_sims">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sim-card me-2 text-primary"></i>Dodaj karty SIM</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3 pb-3 border-bottom">
                        <div class="col-md-4">
                            <label class="form-label">Operator <span class="text-muted">(wspólny)</span></label>
                            <input type="text" name="operator" class="form-control"
                                   placeholder="np. Play, Orange, T-Mobile" maxlength="50">
                        </div>
                    </div>
                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold text-muted small">Karty SIM do dodania</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="navSimBulkAddRow()"><i class="fas fa-plus me-1"></i>Dodaj wiersz</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:30px">#</th>
                                    <th>Nr telefonu SIM <span class="text-danger">*</span></th>
                                    <th>ICCID / nr karty</th>
                                    <th>Przypisz do urządzenia</th>
                                    <th>Uwagi</th>
                                    <th style="width:42px"></th>
                                </tr>
                            </thead>
                            <tbody id="navSimBulkBody"></tbody>
                        </table>
                    </div>
                    <div class="mt-2 text-muted small" id="navSimBulkCount">0 kart do dodania</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Zapisz karty SIM</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Dodaj klienta (nav) -->
<div class="modal fade" id="navClientAddModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= getBaseUrl() ?>clients.php" id="navClientAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="active" value="1">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2 text-secondary"></i>Nowy klient</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nazwa firmy</label>
                            <input type="text" name="company_name" class="form-control" placeholder="Opcjonalne">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Imię i nazwisko kontaktu</label>
                            <input type="text" name="contact_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Adres</label>
                            <input type="text" name="address" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kod pocztowy</label>
                            <input type="text" name="postal_code" class="form-control" placeholder="00-000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Miasto</label>
                            <input type="text" name="city" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">NIP</label>
                            <input type="text" name="nip" class="form-control" placeholder="000-000-00-00">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save me-1"></i>Dodaj klienta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Nowy montaż (nav) — wielourządzeniowy z TomSelect -->
<div class="modal fade" id="navInstAddModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="<?= getBaseUrl() ?>installations.php" id="navInstAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-car me-2 text-success"></i>Nowy montaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required-star">Urządzenia GPS do montażu</label>
                            <?php if (empty($navAvailableModels) && empty($navAvailableDevices)): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <i class="fas fa-exclamation-triangle me-2"></i>Brak dostępnych urządzeń w magazynie.
                                <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#navDevAddModal">Dodaj urządzenia</button>
                            </div>
                            <?php endif; ?>
                            <div id="navInstDevRowsContainer" class="d-flex flex-column gap-2 mb-2">
                                <!-- Pierwszy wiersz (index 0) -->
                                <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-auto">
                                            <span class="row-num badge bg-secondary">1</span>
                                        </div>
                                        <div class="col-auto">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="nim_auto_0" value="auto" checked>
                                                <label class="btn btn-outline-secondary" for="nim_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="nim_manual_0" value="manual">
                                                <label class="btn btn-outline-primary" for="nim_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                                            </div>
                                        </div>
                                        <div class="col col-mode-auto">
                                            <select name="model_id[0]" class="form-select form-select-sm">
                                                <option value="">— wybierz model —</option>
                                                <?php foreach ($navAvailableModels as $m): ?>
                                                <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col col-mode-manual" style="display:none">
                                            <select name="device_id_manual[0]" class="form-select form-select-sm ts-device-nav">
                                                <option value="">— wybierz urządzenie —</option>
                                                <?php
                                                $nimGrp0 = '';
                                                foreach ($navAvailableDevices as $dev):
                                                    $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                                    if ($grp !== $nimGrp0) {
                                                        if ($nimGrp0) echo '</optgroup>';
                                                        echo '<optgroup label="' . h($grp) . '">';
                                                        $nimGrp0 = $grp;
                                                    }
                                                ?>
                                                <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?></option>
                                                <?php endforeach; if ($nimGrp0) echo '</optgroup>'; ?>
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <input type="text" name="vehicle_registration[0]" class="form-control form-control-sm"
                                                   required placeholder="Nr rej. pojazdu"
                                                   style="text-transform:uppercase;min-width:130px">
                                        </div>
                                        <div class="col-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none"
                                                    title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="navInstAddDevRowBtn" class="btn btn-sm btn-outline-success"
                                    <?= (empty($navAvailableModels) && empty($navAvailableDevices)) ? 'disabled' : '' ?>>
                                <i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie
                            </button>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="navInstClientSelect" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($navClients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="navInstQuickClientBtn" title="Dodaj nowego klienta">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adres instalacji</label>
                            <input type="text" name="installation_address" id="navInstAddrField" class="form-control"
                                   placeholder="Automatycznie z klienta lub wpisz ręcznie">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($navUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data montażu</label>
                            <input type="date" name="installation_date" id="navInstDateField" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="aktywna" selected>Aktywna</option>
                                <option value="zakonczona">Zakończona</option>
                                <option value="anulowana">Anulowana</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Miejsce montażu w pojeździe</label>
                            <input type="text" name="location_in_vehicle" class="form-control" placeholder="np. pod deską rozdzielczą">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success btn-sm" <?= (empty($navAvailableModels) && empty($navAvailableDevices)) ? 'disabled' : '' ?>>
                        <i class="fas fa-car me-1"></i>Zarejestruj montaż
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick-add klienta (wewnątrz modalu montażu — nav) -->
<div class="modal fade" id="navInstQCModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="navInstQCErr"></div>
                <div class="mb-2">
                    <label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label>
                    <input type="text" id="navInstQCName" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Nazwa firmy</label>
                    <input type="text" id="navInstQCCompany" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Telefon</label>
                    <input type="text" id="navInstQCPhone" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">E-mail</label>
                    <input type="email" id="navInstQCEmail" class="form-control form-control-sm">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="navInstQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<!-- Template dla nowych wierszy urządzeń w montażu (nav) -->
<template id="navInstDevRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <span class="row-num badge bg-secondary">__NUM__</span>
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="nim_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="nim_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="nim_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="nim_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                </div>
            </div>
            <div class="col col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($navAvailableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm ts-device-nav">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $nimTplGrp = '';
                    foreach ($navAvailableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $nimTplGrp) {
                            if ($nimTplGrp) echo '</optgroup>';
                            echo '<optgroup label="' . h($grp) . '">';
                            $nimTplGrp = $grp;
                        }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?></option>
                    <?php endforeach; if ($nimTplGrp) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-auto">
                <input type="text" name="vehicle_registration[__IDX__]" class="form-control form-control-sm"
                       required placeholder="Nr rej. pojazdu"
                       style="text-transform:uppercase;min-width:130px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn"
                        title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>

<!-- Modal: Nowy serwis (nav) -->
<div class="modal fade" id="navSvcAddModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= getBaseUrl() ?>services.php" id="navSvcAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wrench me-2 text-warning"></i>Nowy serwis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Urządzenie GPS</label>
                            <input type="text" id="navSvcDevSearch" class="form-control form-control-sm mb-1"
                                   placeholder="Szukaj urządzenia (nr seryjny, model…)" autocomplete="off">
                            <select name="device_id" id="navSvcDevSelect" class="form-select" required size="4" style="height:auto">
                                <option value="">— wybierz urządzenie —</option>
                                <?php foreach ($navAllDevices as $dd): ?>
                                <option value="<?= $dd['id'] ?>"
                                        data-search="<?= h(strtolower($dd['serial_number'] . ' ' . $dd['model_name'] . ' ' . $dd['manufacturer_name'])) ?>">
                                    <?= h($dd['serial_number']) ?> — <?= h($dd['manufacturer_name'] . ' ' . $dd['model_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Powiązany montaż (aktywny)</label>
                            <select name="installation_id" class="form-select">
                                <option value="">— brak —</option>
                                <?php foreach ($navActiveInstallations as $ainst): ?>
                                <option value="<?= $ainst['id'] ?>">
                                    <?= h($ainst['registration'] . ' — ' . $ainst['serial_number']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Typ serwisu</label>
                            <select name="type" class="form-select">
                                <option value="przeglad" selected>Przegląd</option>
                                <option value="naprawa">Naprawa</option>
                                <option value="wymiana">Wymiana</option>
                                <option value="aktualizacja">Aktualizacja firmware</option>
                                <option value="inne">Inne</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="zaplanowany" selected>Zaplanowany</option>
                                <option value="w_trakcie">W trakcie</option>
                                <option value="zakończony">Zakończony</option>
                                <option value="anulowany">Anulowany</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data zaplanowana</label>
                            <input type="date" name="planned_date" id="navSvcPlannedDate" class="form-control" required>
                        </div>
                        <?php if (!empty($navUsers)): ?>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($navUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Opis / Problem</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-warning btn-sm text-white"><i class="fas fa-save me-1"></i>Zarejestruj serwis</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Nav modals JS ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // TomSelect for SIM device select in nav SIM add modal
    var navSimDevSel = document.getElementById('navSimDeviceSelect');
    if (navSimDevSel && typeof TomSelect !== 'undefined') {
        new TomSelect(navSimDevSel, { maxOptions: null });
    }

    // TomSelect for client select in nav install modal
    var navInstClientSel = document.getElementById('navInstClientSelect');
    var navInstClientTS = null;
    if (navInstClientSel && typeof TomSelect !== 'undefined') {
        navInstClientTS = new TomSelect(navInstClientSel, { maxOptions: null });
    }

    // Client address auto-fill
    var navInstClientAddresses = <?= json_encode(array_reduce($navClients, function($carry, $c) {
        $parts = array_filter([$c['address'] ?? '', trim(($c['postal_code'] ?? '') . ' ' . ($c['city'] ?? ''))]);
        $carry[(string)$c['id']] = implode(', ', $parts);
        return $carry;
    }, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    if (navInstClientSel) {
        navInstClientSel.addEventListener('change', function () {
            var addr = navInstClientAddresses[this.value] || '';
            var fld = document.getElementById('navInstAddrField');
            if (fld && (!fld.value || fld.dataset.autoSet === '1')) {
                fld.value = addr;
                fld.dataset.autoSet = addr ? '1' : '0';
            }
        });
        if (navInstClientTS) {
            navInstClientTS.on('change', function (val) {
                var addr = navInstClientAddresses[val] || '';
                var fld = document.getElementById('navInstAddrField');
                if (fld && (!fld.value || fld.dataset.autoSet === '1')) {
                    fld.value = addr;
                    fld.dataset.autoSet = addr ? '1' : '0';
                }
            });
        }
    }

    // Device search in nav service modal
    var navSvcSearch = document.getElementById('navSvcDevSearch');
    var navSvcSelect = document.getElementById('navSvcDevSelect');
    if (navSvcSearch && navSvcSelect) {
        navSvcSearch.addEventListener('input', function () {
            var q = this.value.toLowerCase();
            Array.from(navSvcSelect.options).forEach(function (opt) {
                if (!opt.value) { opt.style.display = ''; return; }
                opt.style.display = (opt.dataset.search || '').indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    // Set today's date on service modal open
    var navSvcModal = document.getElementById('navSvcAddModal');
    if (navSvcModal) {
        navSvcModal.addEventListener('show.bs.modal', function () {
            var d = document.getElementById('navSvcPlannedDate');
            if (d && !d.value) d.value = new Date().toISOString().slice(0, 10);
        });
    }

    // Quick-add client button inside install modal
    var navInstQCBtn = document.getElementById('navInstQuickClientBtn');
    if (navInstQCBtn) {
        navInstQCBtn.addEventListener('click', function () {
            new bootstrap.Modal(document.getElementById('navInstQCModal')).show();
        });
    }

    // Quick-add client save
    var navInstQCSaveBtn = document.getElementById('navInstQCSaveBtn');
    if (navInstQCSaveBtn) {
        navInstQCSaveBtn.addEventListener('click', function () {
            var name = document.getElementById('navInstQCName').value.trim();
            var company = document.getElementById('navInstQCCompany').value.trim();
            var phone = document.getElementById('navInstQCPhone').value.trim();
            var email = document.getElementById('navInstQCEmail').value.trim();
            var errEl = document.getElementById('navInstQCErr');
            if (!name) { errEl.textContent = 'Imię i nazwisko jest wymagane.'; errEl.classList.remove('d-none'); return; }
            errEl.classList.add('d-none');
            var fd = new FormData();
            fd.append('action', 'add');
            fd.append('contact_name', name);
            fd.append('company_name', company);
            fd.append('phone', phone);
            fd.append('email', email);
            fd.append('active', '1');
            var csrfEl = document.querySelector('#navInstAddForm [name="csrf_token"]');
            if (csrfEl) fd.append('csrf_token', csrfEl.value);
            fetch('<?= getBaseUrl() ?>clients.php', { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function () {
                    // Reload client options via page reload is too heavy — just close and reload
                    bootstrap.Modal.getInstance(document.getElementById('navInstQCModal')).hide();
                    window.location.reload();
                })
                .catch(function () {
                    errEl.textContent = 'Błąd sieci. Spróbuj ponownie.';
                    errEl.classList.remove('d-none');
                });
        });
    }

    // Add device modal: init first row on open
    var navDevModal = document.getElementById('navDevAddModal');
    if (navDevModal) {
        navDevModal.addEventListener('show.bs.modal', function () {
            if (document.getElementById('navDevicesBody').rows.length === 0) {
                navDevAddRow();
            }
        });
    }

    // Install modal: add device row button
    var navInstAddDevBtn = document.getElementById('navInstAddDevRowBtn');
    if (navInstAddDevBtn) {
        navInstAddDevBtn.addEventListener('click', function () { navInstDevAddRow(); });
    }

    // Install modal: reset on open
    var navInstModal = document.getElementById('navInstAddModal');
    if (navInstModal) {
        navInstModal.addEventListener('show.bs.modal', function () {
            var container = document.getElementById('navInstDevRowsContainer');
            // Remove extra rows
            Array.from(container.querySelectorAll('.device-row')).forEach(function (row, idx) {
                if (idx > 0) { nimDestroyTomSelect(row); row.remove(); }
            });
            navInstRowCounter = 1;
            // Reset first row
            var firstRow = container.querySelector('.device-row');
            if (firstRow) {
                var reg = firstRow.querySelector('input[name="vehicle_registration[0]"]');
                if (reg) reg.value = '';
                var autoR = firstRow.querySelector('input[value="auto"]');
                if (autoR) { autoR.checked = true; nimApplyMode(firstRow, 'auto'); }
                var mSel = firstRow.querySelector('select[name="model_id[0]"]');
                if (mSel) mSel.value = '';
                nimDestroyTomSelect(firstRow);
                firstRow.querySelectorAll('.remove-row-btn').forEach(function (b) { b.style.display = 'none'; });
            }
            // Reset address auto-set
            var addrFld = document.getElementById('navInstAddrField');
            if (addrFld) { addrFld.value = ''; addrFld.dataset.autoSet = '0'; }
            if (navInstClientTS) navInstClientTS.setValue('', true);
            // Set today
            var dFld = document.getElementById('navInstDateField');
            if (dFld) dFld.value = new Date().toISOString().slice(0, 10);
        });
    }
});

// ── Nav: Add device modal rows ────────────────────────────────────────
var navDevRowCount = 0;
function navDevAddRow() {
    navDevRowCount++;
    var n = navDevRowCount;
    var tbody = document.getElementById('navDevicesBody');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.id = 'nav-dev-row-' + n;
    tr.innerHTML =
        '<td class="text-muted text-center align-middle">' + n + '</td>' +
        '<td><input type="text" name="serial_numbers[]" class="form-control form-control-sm" placeholder="np. SN123456" required></td>' +
        '<td><input type="text" name="imeis[]" class="form-control form-control-sm" placeholder="15 cyfr" maxlength="20"></td>' +
        '<td><input type="text" name="sim_numbers[]" class="form-control form-control-sm" placeholder="np. +48 600 000 000" list="navSimListAdd"></td>' +
        '<td><input type="text" name="notes_list[]" class="form-control form-control-sm" placeholder="Opcjonalne"></td>' +
        '<td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="navDevRemoveRow(' + n + ')" title="Usuń"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input[name="serial_numbers[]"]').focus();
    navUpdateDevCount();
}
function navDevRemoveRow(n) {
    var row = document.getElementById('nav-dev-row-' + n);
    if (row) { row.remove(); navUpdateDevCount(); }
}
function navUpdateDevCount() {
    var rows = document.querySelectorAll('#navDevicesBody tr').length;
    var el = document.getElementById('navDevicesCount');
    if (!el) return;
    if (rows === 0) el.textContent = '0 urządzeń do dodania';
    else if (rows === 1) el.textContent = '1 urządzenie do dodania';
    else if (rows <= 4) el.textContent = rows + ' urządzenia do dodania';
    else el.textContent = rows + ' urządzeń do dodania';
}

// ── Nav: Bulk SIM modal rows ──────────────────────────────────────────
var navSimBulkRowCount = 0;
var navSimDevicesJson = <?= json_encode(array_values(array_map(function($d) {
    return [
        'id'    => (string)$d['id'],
        'label' => $d['serial_number']
                   . ($d['imei']       ? ' [' . $d['imei'] . ']' : '')
                   . ($d['sim_number'] ? ' — SIM: ' . $d['sim_number'] : '')
                   . ' (' . $d['manufacturer_name'] . ' ' . $d['model_name'] . ')',
    ];
}, $navSimDevices))) ?>;

function navSimBulkBuildDeviceSelect() {
    var opts = '<option value="">— brak —</option>';
    navSimDevicesJson.forEach(function(d) {
        opts += '<option value="' + d.id + '">' + d.label.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') + '</option>';
    });
    return '<select name="device_ids_sim[]" class="form-select form-select-sm nav-sim-dev-sel">' + opts + '</select>';
}
function navSimBulkAddRow() {
    navSimBulkRowCount++;
    var n = navSimBulkRowCount;
    var tbody = document.getElementById('navSimBulkBody');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.id = 'nav-sim-row-' + n;
    tr.innerHTML =
        '<td class="text-muted text-center align-middle">' + n + '</td>' +
        '<td><input type="text" name="phone_numbers[]" class="form-control form-control-sm" placeholder="+48 123 456 789" maxlength="30" required></td>' +
        '<td><input type="text" name="iccids[]" class="form-control form-control-sm" placeholder="20-cyfrowy ICCID" maxlength="25"></td>' +
        '<td>' + navSimBulkBuildDeviceSelect() + '</td>' +
        '<td><input type="text" name="notes_list[]" class="form-control form-control-sm" placeholder="Opcjonalne"></td>' +
        '<td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="navSimBulkRemoveRow(' + n + ')" title="Usuń"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    var sel = tr.querySelector('.nav-sim-dev-sel');
    if (sel && typeof TomSelect !== 'undefined') {
        new TomSelect(sel, { maxOptions: null });
    }
    tr.querySelector('input[name="phone_numbers[]"]').focus();
    navSimBulkUpdateCount();
}
function navSimBulkRemoveRow(n) {
    var row = document.getElementById('nav-sim-row-' + n);
    if (row) {
        var sel = row.querySelector('.nav-sim-dev-sel');
        if (sel && sel.tomselect) sel.tomselect.destroy();
        row.remove();
        navSimBulkUpdateCount();
    }
}
function navSimBulkUpdateCount() {
    var rows = document.querySelectorAll('#navSimBulkBody tr').length;
    var el = document.getElementById('navSimBulkCount');
    if (!el) return;
    if (rows === 0) el.textContent = '0 kart do dodania';
    else if (rows === 1) el.textContent = '1 karta do dodania';
    else if (rows <= 4) el.textContent = rows + ' karty do dodania';
    else el.textContent = rows + ' kart do dodania';
}
// Init first row when modal opens
document.addEventListener('DOMContentLoaded', function () {
    var navSimModal = document.getElementById('navSimAddModal');
    if (navSimModal) {
        navSimModal.addEventListener('show.bs.modal', function () {
            if (document.getElementById('navSimBulkBody').rows.length === 0) {
                navSimBulkAddRow();
            }
        });
        // Reset on hide so next open is fresh
        navSimModal.addEventListener('hidden.bs.modal', function () {
            var tbody = document.getElementById('navSimBulkBody');
            if (tbody) {
                Array.from(tbody.querySelectorAll('.nav-sim-dev-sel')).forEach(function(sel) {
                    if (sel.tomselect) sel.tomselect.destroy();
                });
                tbody.innerHTML = '';
            }
            navSimBulkRowCount = 0;
            navSimBulkUpdateCount();
        });
    }
});

// ── Nav: Install modal multi-device rows ──────────────────────────────
var navInstRowCounter = 1;
function nimDestroyTomSelect(row) {
    var sel = row.querySelector('.ts-device-nav');
    if (sel && sel.tomselect) { sel.tomselect.destroy(); }
}
function nimInitTomSelect(row) {
    var sel = row.querySelector('.ts-device-nav');
    if (sel && typeof TomSelect !== 'undefined' && !sel.tomselect) {
        new TomSelect(sel, { maxOptions: null });
    }
}
function nimApplyMode(row, mode) {
    var autoCol = row.querySelector('.col-mode-auto');
    var manualCol = row.querySelector('.col-mode-manual');
    if (!autoCol || !manualCol) return;
    if (mode === 'manual') {
        autoCol.style.display = 'none';
        manualCol.style.display = '';
        nimInitTomSelect(row);
    } else {
        autoCol.style.display = '';
        manualCol.style.display = 'none';
        nimDestroyTomSelect(row);
    }
}
function navInstDevAddRow() {
    var tpl = document.getElementById('navInstDevRowTemplate');
    if (!tpl) return;
    navInstRowCounter++;
    var idx = navInstRowCounter - 1;
    var html = tpl.innerHTML
        .replace(/__IDX__/g, idx)
        .replace(/__NUM__/g, navInstRowCounter);
    var container = document.getElementById('navInstDevRowsContainer');
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var newRow = tmp.firstElementChild;
    container.appendChild(newRow);
    // Show all remove buttons when >1 row
    container.querySelectorAll('.remove-row-btn').forEach(function (b) { b.style.display = ''; });
    // Wire radio buttons
    newRow.querySelectorAll('input[type="radio"]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (this.checked) nimApplyMode(newRow, this.value);
        });
    });
    // Wire remove button
    var removeBtn = newRow.querySelector('.remove-row-btn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            nimDestroyTomSelect(newRow);
            newRow.remove();
            var remaining = container.querySelectorAll('.device-row');
            if (remaining.length === 1) remaining[0].querySelector('.remove-row-btn').style.display = 'none';
            // Renumber
            remaining = container.querySelectorAll('.device-row');
            remaining.forEach(function (r, i) {
                var badge = r.querySelector('.row-num');
                if (badge) badge.textContent = i + 1;
            });
            navInstRowCounter = remaining.length;
        });
    }
    newRow.querySelector('input[name="vehicle_registration[' + idx + ']"]').focus();
}
// Wire radio buttons on the first (static) install row
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('navInstDevRowsContainer');
    if (!container) return;
    var firstRow = container.querySelector('.device-row');
    if (firstRow) {
        firstRow.querySelectorAll('input[type="radio"]').forEach(function (r) {
            r.addEventListener('change', function () {
                if (this.checked) nimApplyMode(firstRow, this.value);
            });
        });
    }
});
</script>

