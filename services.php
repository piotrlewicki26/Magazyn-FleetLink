<?php
/**
 * FleetLink Magazyn - Service Management
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();
$action = sanitize($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'services.php'); }
    $postAction      = sanitize($_POST['action'] ?? '');
    $deviceId        = (int)($_POST['device_id'] ?? 0);
    $installationId  = (int)($_POST['installation_id'] ?? 0) ?: null;
    $technicianId    = (int)($_POST['technician_id'] ?? 0) ?: null;
    $type            = sanitize($_POST['type'] ?? 'przeglad');
    $plannedDate     = sanitize($_POST['planned_date'] ?? '') ?: null;
    $completedDate   = sanitize($_POST['completed_date'] ?? '') ?: null;
    $status          = sanitize($_POST['status'] ?? 'zaplanowany');
    $description     = sanitize($_POST['description'] ?? '');
    $resolution      = sanitize($_POST['resolution'] ?? '');
    $cost            = str_replace(',', '.', $_POST['cost'] ?? '0');
    $currentUser     = getCurrentUser();

    $validTypes     = ['przeglad','naprawa','wymiana','aktualizacja','inne'];
    $validStatuses  = ['zaplanowany','w_trakcie','zakończony','anulowany'];
    if (!in_array($type, $validTypes)) $type = 'przeglad';
    if (!in_array($status, $validStatuses)) $status = 'zaplanowany';
    if (!$technicianId) $technicianId = $currentUser['id'];

    if ($postAction === 'add') {
        if (!$deviceId || empty($plannedDate)) {
            flashError('Urządzenie i data zaplanowanego serwisu są wymagane.');
            redirect(getBaseUrl() . 'services.php?action=add');
        }
        $db->prepare("INSERT INTO services (device_id, installation_id, technician_id, type, planned_date, completed_date, status, description, resolution, cost) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$deviceId, $installationId, $technicianId, $type, $plannedDate, $completedDate, $status, $description, $resolution, $cost]);
        // Update device status if in service
        if ($status === 'w_trakcie') {
            $db->prepare("UPDATE devices SET status='w_serwisie' WHERE id=?")->execute([$deviceId]);
        }
        flashSuccess('Serwis zarejestrowany pomyślnie.');
        redirect(getBaseUrl() . 'services.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE services SET device_id=?, installation_id=?, technician_id=?, type=?, planned_date=?, completed_date=?, status=?, description=?, resolution=?, cost=? WHERE id=?")
           ->execute([$deviceId, $installationId, $technicianId, $type, $plannedDate, $completedDate, $status, $description, $resolution, $cost, $editId]);
        // Update device status
        if ($status === 'w_trakcie') {
            $db->prepare("UPDATE devices SET status='w_serwisie' WHERE id=?")->execute([$deviceId]);
        } elseif ($status === 'zakończony') {
            $db->prepare("UPDATE devices SET status='sprawny' WHERE id=? AND status='w_serwisie'")->execute([$deviceId]);
        }
        flashSuccess('Serwis zaktualizowany.');
        redirect(getBaseUrl() . 'services.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM services WHERE id=?")->execute([$delId]);
        flashSuccess('Serwis usunięty.');
        redirect(getBaseUrl() . 'services.php');
    }
}

if (in_array($action, ['view','edit']) && $id) {
    $stmt = $db->prepare("
        SELECT s.*, d.serial_number, d.imei, m.name as model_name, mf.name as manufacturer_name,
               u.name as technician_name,
               v.registration, v.make
        FROM services s
        JOIN devices d ON d.id=s.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN users u ON u.id=s.technician_id
        LEFT JOIN installations inst ON inst.id=s.installation_id
        LEFT JOIN vehicles v ON v.id=inst.vehicle_id
        WHERE s.id=?
    ");
    $stmt->execute([$id]);
    $service = $stmt->fetch();
    if (!$service) { flashError('Serwis nie istnieje.'); redirect(getBaseUrl() . 'services.php'); }
}

$allDevices = $db->query("
    SELECT d.id, d.serial_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status != 'wycofany'
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

$users = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$activeInstallations = $db->query("
    SELECT i.id, v.registration, d.serial_number
    FROM installations i
    JOIN vehicles v ON v.id=i.vehicle_id
    JOIN devices d ON d.id=i.device_id
    WHERE i.status='aktywna'
    ORDER BY v.registration
")->fetchAll();

$services = [];
if ($action === 'list') {
    $filterStatus = sanitize($_GET['status'] ?? '');
    $filterType   = sanitize($_GET['type'] ?? '');
    $search       = sanitize($_GET['search'] ?? '');
    $dateFrom     = sanitize($_GET['date_from'] ?? '');
    $dateTo       = sanitize($_GET['date_to'] ?? '');

    $sql = "
        SELECT s.id, s.type, s.planned_date, s.completed_date, s.status, s.cost, s.description,
               d.serial_number, m.name as model_name, mf.name as manufacturer_name,
               u.name as technician_name,
               v.registration, v.make
        FROM services s
        JOIN devices d ON d.id=s.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN users u ON u.id=s.technician_id
        LEFT JOIN installations inst ON inst.id=s.installation_id
        LEFT JOIN vehicles v ON v.id=inst.vehicle_id
        WHERE 1=1
    ";
    $params = [];
    if ($filterStatus) { $sql .= " AND s.status=?"; $params[] = $filterStatus; }
    if ($filterType)   { $sql .= " AND s.type=?";   $params[] = $filterType; }
    if ($search) {
        $sql .= " AND (d.serial_number LIKE ? OR m.name LIKE ? OR v.registration LIKE ?)";
        $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
    }
    if ($dateFrom) { $sql .= " AND s.planned_date >= ?"; $params[] = $dateFrom; }
    if ($dateTo)   { $sql .= " AND s.planned_date <= ?"; $params[] = $dateTo; }
    $sql .= " ORDER BY FIELD(s.status,'w_trakcie','zaplanowany','zakończony','anulowany'), s.planned_date";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $services = $stmt->fetchAll();
}

$activePage = 'services';
$pageTitle = 'Serwisy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-wrench me-2 text-primary"></i>Serwisy</h1>
    <?php if ($action === 'list'): ?>
    <a href="services.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowy serwis</a>
    <?php else: ?>
    <a href="services.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-3">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (nr seryjny, rejestracja, model...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <?php foreach (['zaplanowany','w_trakcie','zakończony','anulowany'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">Wszystkie typy</option>
                    <?php foreach (['przeglad','naprawa','wymiana','aktualizacja','inne'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($_GET['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($_GET['date_from'] ?? '') ?>" title="Data od">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($_GET['date_to'] ?? '') ?>" title="Data do">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="services.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Serwisy (<?= count($services) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Zaplanowany</th><th>Typ</th><th>Urządzenie</th><th>Pojazd</th><th>Status</th><th>Koszt</th><th>Technik</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($services as $svc): ?>
                <tr class="<?= $svc['status'] === 'zaplanowany' && $svc['planned_date'] < date('Y-m-d') ? 'table-warning' : '' ?>">
                    <td><?= formatDate($svc['planned_date']) ?></td>
                    <td><span class="badge bg-secondary"><?= h(ucfirst($svc['type'])) ?></span></td>
                    <td>
                        <a href="devices.php?action=view&id=<?= $svc['device_id'] ?? '' ?>"><?= h($svc['serial_number']) ?></a>
                        <br><small class="text-muted"><?= h($svc['manufacturer_name'] . ' ' . $svc['model_name']) ?></small>
                    </td>
                    <td><?= $svc['registration'] ? h($svc['registration'] . ' ' . $svc['make']) : '—' ?></td>
                    <td><?= getStatusBadge($svc['status'], 'service') ?></td>
                    <td><?= $svc['cost'] > 0 ? formatMoney($svc['cost']) : '—' ?></td>
                    <td><?= h($svc['technician_name'] ?? '—') ?></td>
                    <td>
                        <a href="services.php?action=view&id=<?= $svc['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="services.php?action=edit&id=<?= $svc['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń serwis #<?= $svc['id'] ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($services)): ?><tr><td colspan="8" class="text-center text-muted p-3">Brak serwisów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && isset($service)): ?>
<div class="row g-3">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Szczegóły serwisu</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted">Status</th><td><?= getStatusBadge($service['status'], 'service') ?></td></tr>
                    <tr><th class="text-muted">Typ</th><td><?= h(ucfirst($service['type'])) ?></td></tr>
                    <tr><th class="text-muted">Urządzenie</th><td><a href="devices.php?action=view&id=<?= $service['device_id'] ?>"><?= h($service['serial_number']) ?></a><br><small><?= h($service['manufacturer_name'] . ' ' . $service['model_name']) ?></small></td></tr>
                    <?php if ($service['registration']): ?><tr><th class="text-muted">Pojazd</th><td><?= h($service['registration'] . ' ' . $service['make']) ?></td></tr><?php endif; ?>
                    <tr><th class="text-muted">Zaplanowany</th><td><?= formatDate($service['planned_date']) ?></td></tr>
                    <tr><th class="text-muted">Zrealizowany</th><td><?= formatDate($service['completed_date']) ?></td></tr>
                    <tr><th class="text-muted">Technik</th><td><?= h($service['technician_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Koszt</th><td class="fw-bold"><?= $service['cost'] > 0 ? formatMoney($service['cost']) : '—' ?></td></tr>
                </table>
                <?php if ($service['description']): ?>
                <hr><strong class="small">Opis:</strong><p class="small text-muted mt-1"><?= h($service['description']) ?></p>
                <?php endif; ?>
                <?php if ($service['resolution']): ?>
                <strong class="small">Rozwiązanie:</strong><p class="small text-muted mt-1"><?= h($service['resolution']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <a href="services.php?action=edit&id=<?= $service['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <a href="protocols.php?action=add&service=<?= $service['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clipboard me-1"></i>Protokół</a>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:700px">
    <div class="card-header"><i class="fas fa-wrench me-2"></i><?= $action === 'add' ? 'Nowy serwis' : 'Edytuj serwis' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $service['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-star">Urządzenie GPS</label>
                    <input type="text" id="deviceSearch" class="form-control mb-1"
                           placeholder="Wyszukaj urządzenie (nr seryjny, model, producent)…"
                           autocomplete="off">
                    <select name="device_id" id="deviceSelect" class="form-select" required size="4" style="height:auto">
                        <option value="">— wybierz urządzenie —</option>
                        <?php
                        $currentMf = '';
                        foreach ($allDevices as $d):
                            if ($d['manufacturer_name'] !== $currentMf) {
                                if ($currentMf) echo '</optgroup>';
                                echo '<optgroup label="' . h($d['manufacturer_name'] . ' ' . $d['model_name']) . '">';
                                $currentMf = $d['manufacturer_name'];
                            }
                        ?>
                        <option value="<?= $d['id'] ?>"
                                data-search="<?= h(strtolower($d['serial_number'] . ' ' . $d['model_name'] . ' ' . $d['manufacturer_name'])) ?>"
                                <?= ($service['device_id'] ?? (int)($_GET['device'] ?? 0)) == $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['serial_number']) ?> — <?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?>
                        </option>
                        <?php endforeach; if ($currentMf) echo '</optgroup>'; ?>
                    </select>
                    <div class="form-text">Wpisz fragment numeru seryjnego, modelu lub producenta aby przefiltrować listę.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Powiązany montaż</label>
                    <select name="installation_id" class="form-select">
                        <option value="">— brak —</option>
                        <?php foreach ($activeInstallations as $inst): ?>
                        <option value="<?= $inst['id'] ?>"
                                <?= ($service['installation_id'] ?? (int)($_GET['installation'] ?? 0)) == $inst['id'] ? 'selected' : '' ?>>
                            <?= h($inst['registration'] . ' — ' . $inst['serial_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Typ serwisu</label>
                    <select name="type" class="form-select">
                        <option value="przeglad" <?= ($service['type'] ?? 'przeglad') === 'przeglad' ? 'selected' : '' ?>>Przegląd</option>
                        <option value="naprawa" <?= ($service['type'] ?? '') === 'naprawa' ? 'selected' : '' ?>>Naprawa</option>
                        <option value="wymiana" <?= ($service['type'] ?? '') === 'wymiana' ? 'selected' : '' ?>>Wymiana</option>
                        <option value="aktualizacja" <?= ($service['type'] ?? '') === 'aktualizacja' ? 'selected' : '' ?>>Aktualizacja firmware</option>
                        <option value="inne" <?= ($service['type'] ?? '') === 'inne' ? 'selected' : '' ?>>Inne</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="zaplanowany" <?= ($service['status'] ?? 'zaplanowany') === 'zaplanowany' ? 'selected' : '' ?>>Zaplanowany</option>
                        <option value="w_trakcie" <?= ($service['status'] ?? '') === 'w_trakcie' ? 'selected' : '' ?>>W trakcie</option>
                        <option value="zakończony" <?= ($service['status'] ?? '') === 'zakończony' ? 'selected' : '' ?>>Zakończony</option>
                        <option value="anulowany" <?= ($service['status'] ?? '') === 'anulowany' ? 'selected' : '' ?>>Anulowany</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Data zaplanowana</label>
                    <input type="date" name="planned_date" class="form-control" required value="<?= h($service['planned_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Data realizacji</label>
                    <input type="date" name="completed_date" class="form-control" value="<?= h($service['completed_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Technik</label>
                    <select name="technician_id" class="form-select">
                        <option value="">— aktualny użytkownik —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($service['technician_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Koszt</label>
                    <div class="input-group">
                        <input type="number" name="cost" class="form-control" value="<?= h($service['cost'] ?? '0') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Opis / Problem</label>
                    <textarea name="description" class="form-control" rows="3"><?= h($service['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Rozwiązanie / Wynik</label>
                    <textarea name="resolution" class="form-control" rows="3"><?= h($service['resolution'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Zarejestruj serwis' : 'Zapisz zmiany' ?></button>
                    <a href="services.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var searchInput = document.getElementById('deviceSearch');
    var deviceSelect = document.getElementById('deviceSelect');
    if (!searchInput || !deviceSelect) return;

    // Pre-select and show label for current value
    var currentOption = deviceSelect.selectedIndex > 0 ? deviceSelect.options[deviceSelect.selectedIndex] : null;
    if (currentOption && currentOption.value) {
        searchInput.value = currentOption.textContent.trim();
    }

    searchInput.addEventListener('input', function () {
        var term = this.value.toLowerCase().trim();
        var options = deviceSelect.querySelectorAll('option[data-search]');
        options.forEach(function (opt) {
            var match = !term || opt.dataset.search.includes(term);
            opt.style.display = match ? '' : 'none';
            opt.parentElement.style.display = '';
        });
        // Hide empty optgroups
        deviceSelect.querySelectorAll('optgroup').forEach(function (grp) {
            var visible = Array.from(grp.querySelectorAll('option[data-search]')).some(function (o) { return o.style.display !== 'none'; });
            grp.style.display = visible ? '' : 'none';
        });
        // Deselect if text doesn't match selection
        if (deviceSelect.selectedIndex > 0) {
            var sel = deviceSelect.options[deviceSelect.selectedIndex];
            if (sel && sel.style.display === 'none') {
                deviceSelect.value = '';
            }
        }
    });

    // When user selects a device from the list, update the text input
    deviceSelect.addEventListener('change', function () {
        var sel = this.options[this.selectedIndex];
        if (sel && sel.value) {
            searchInput.value = sel.textContent.trim();
        }
    });
}());
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
