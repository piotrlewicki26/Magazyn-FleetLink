<?php
/**
 * FleetLink Magazyn - Installation Management (Montaż/Demontaż)
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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'installations.php'); }
    $postAction         = sanitize($_POST['action'] ?? '');
    $deviceId           = (int)($_POST['device_id'] ?? 0);
    $vehicleId          = (int)($_POST['vehicle_id'] ?? 0);
    $clientId           = (int)($_POST['client_id'] ?? 0) ?: null;
    $technicianId       = (int)($_POST['technician_id'] ?? 0) ?: null;
    $installationDate   = sanitize($_POST['installation_date'] ?? '');
    $uninstallationDate = sanitize($_POST['uninstallation_date'] ?? '') ?: null;
    $status             = sanitize($_POST['status'] ?? 'aktywna');
    $locationInVehicle  = sanitize($_POST['location_in_vehicle'] ?? '');
    $notes              = sanitize($_POST['notes'] ?? '');
    $currentUser        = getCurrentUser();

    if (!$technicianId) $technicianId = $currentUser['id'];

    if ($postAction === 'add') {
        if (!$deviceId || !$vehicleId || empty($installationDate)) {
            flashError('Urządzenie, pojazd i data montażu są wymagane.');
            redirect(getBaseUrl() . 'installations.php?action=add');
        }
        // Check device not already installed
        $checkStmt = $db->prepare("SELECT id FROM installations WHERE device_id=? AND status='aktywna' LIMIT 1");
        $checkStmt->execute([$deviceId]);
        if ($checkStmt->fetch()) {
            flashError('To urządzenie jest już zamontowane w innym pojeździe (aktywny montaż).');
            redirect(getBaseUrl() . 'installations.php?action=add');
        }
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO installations (device_id, vehicle_id, client_id, technician_id, installation_date, uninstallation_date, status, location_in_vehicle, notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$deviceId, $vehicleId, $clientId, $technicianId, $installationDate, $uninstallationDate, $status, $locationInVehicle, $notes]);
            $installId = $db->lastInsertId();
            $db->prepare("UPDATE devices SET status='zamontowany' WHERE id=?")->execute([$deviceId]);
            $db->commit();
            flashSuccess('Montaż zarejestrowany pomyślnie.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd podczas zapisu: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php');

    } elseif ($postAction === 'uninstall') {
        $instId = (int)($_POST['id'] ?? 0);
        $uninstDate = sanitize($_POST['uninstallation_date'] ?? date('Y-m-d'));
        $devId = (int)($_POST['device_id'] ?? 0);
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE installations SET status='zakonczona', uninstallation_date=? WHERE id=?")->execute([$uninstDate, $instId]);
            $db->prepare("UPDATE devices SET status='sprawny' WHERE id=?")->execute([$devId]);
            $db->commit();
            flashSuccess('Demontaż zarejestrowany.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php?action=view&id=' . $instId);

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE installations SET vehicle_id=?, client_id=?, technician_id=?, installation_date=?, uninstallation_date=?, status=?, location_in_vehicle=?, notes=? WHERE id=?")
           ->execute([$vehicleId, $clientId, $technicianId, $installationDate, $uninstallationDate, $status, $locationInVehicle, $notes, $editId]);
        flashSuccess('Montaż zaktualizowany.');
        redirect(getBaseUrl() . 'installations.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $devId = (int)($_POST['device_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM installations WHERE id=?")->execute([$delId]);
            if ($devId) $db->prepare("UPDATE devices SET status='sprawny' WHERE id=? AND status='zamontowany'")->execute([$devId]);
            flashSuccess('Montaż usunięty.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć — powiązane rekordy istnieją.');
        }
        redirect(getBaseUrl() . 'installations.php');
    }
}

if ($action === 'view' && $id) {
    $stmt = $db->prepare("
        SELECT i.*,
               d.serial_number, d.imei,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make, v.model_name as vehicle_model,
               c.contact_name, c.company_name, c.phone as client_phone, c.email as client_email,
               u.name as technician_name
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN users u ON u.id=i.technician_id
        WHERE i.id=?
    ");
    $stmt->execute([$id]);
    $installation = $stmt->fetch();
    if (!$installation) { flashError('Montaż nie istnieje.'); redirect(getBaseUrl() . 'installations.php'); }

    $installServices = $db->prepare("
        SELECT s.*, u.name as tech_name FROM services s LEFT JOIN users u ON u.id=s.technician_id
        WHERE s.installation_id=? ORDER BY s.created_at DESC
    ");
    $installServices->execute([$id]);
    $services = $installServices->fetchAll();
}

if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM installations WHERE id=?");
    $stmt->execute([$id]);
    $installation = $stmt->fetch();
    if (!$installation) { flashError('Montaż nie istnieje.'); redirect(getBaseUrl() . 'installations.php'); }
}

// Data for selects
$availableDevices = $db->query("
    SELECT d.id, d.serial_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status IN ('nowy','sprawny')
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

$vehicles = $db->query("SELECT v.id, v.registration, v.make, v.model_name, c.contact_name, c.company_name FROM vehicles v LEFT JOIN clients c ON c.id=v.client_id WHERE v.active=1 ORDER BY v.registration")->fetchAll();
$clients  = $db->query("SELECT id, contact_name, company_name FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
$users    = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();

$installations = [];
if ($action === 'list') {
    $filterStatus = sanitize($_GET['status'] ?? '');
    $search = sanitize($_GET['search'] ?? '');
    $sql = "
        SELECT i.id, i.installation_date, i.uninstallation_date, i.status,
               d.serial_number, m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make,
               c.contact_name, c.company_name,
               u.name as technician_name
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN users u ON u.id=i.technician_id
        WHERE 1=1
    ";
    $params = [];
    if ($filterStatus) { $sql .= " AND i.status=?"; $params[] = $filterStatus; }
    if ($search) {
        $sql .= " AND (d.serial_number LIKE ? OR v.registration LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
        $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
    }
    $sql .= " ORDER BY i.installation_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $installations = $stmt->fetchAll();
}

$activePage = 'installations';
$pageTitle = 'Montaże';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-car me-2 text-primary"></i>Montaże / Demontaże</h1>
    <?php if ($action === 'list'): ?>
    <a href="installations.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowy montaż</a>
    <?php else: ?>
    <a href="installations.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (nr seryjny, rejestracja, klient...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <option value="aktywna" <?= ($_GET['status'] ?? '') === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
                    <option value="zakonczona" <?= ($_GET['status'] ?? '') === 'zakonczona' ? 'selected' : '' ?>>Zakończona</option>
                    <option value="anulowana" <?= ($_GET['status'] ?? '') === 'anulowana' ? 'selected' : '' ?>>Anulowana</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="installations.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Montaże (<?= count($installations) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Data montażu</th><th>Urządzenie</th><th>Pojazd</th><th>Klient</th><th>Technik</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($installations as $inst): ?>
                <tr>
                    <td><?= formatDate($inst['installation_date']) ?></td>
                    <td>
                        <a href="devices.php?action=view&id=<?= $inst['device_id'] ?? '' ?>"><?= h($inst['serial_number']) ?></a>
                        <br><small class="text-muted"><?= h($inst['manufacturer_name'] . ' ' . $inst['model_name']) ?></small>
                    </td>
                    <td><?= h($inst['registration']) ?><br><small class="text-muted"><?= h($inst['make']) ?></small></td>
                    <td><?= h($inst['company_name'] ?: $inst['contact_name'] ?? '—') ?></td>
                    <td><?= h($inst['technician_name'] ?? '—') ?></td>
                    <td><?= getStatusBadge($inst['status'], 'installation') ?></td>
                    <td>
                        <a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <?php if ($inst['status'] === 'aktywna'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-action"
                                onclick="showUninstallModal(<?= $inst['id'] ?>, <?= $inst['device_id'] ?? 0 ?>, '<?= h($inst['serial_number']) ?>')">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($installations)): ?><tr><td colspan="7" class="text-center text-muted p-3">Brak montaży.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && isset($installation)): ?>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Szczegóły montażu</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted">Status</th><td><?= getStatusBadge($installation['status'], 'installation') ?></td></tr>
                    <tr><th class="text-muted">Data montażu</th><td><?= formatDate($installation['installation_date']) ?></td></tr>
                    <tr><th class="text-muted">Data demontażu</th><td><?= formatDate($installation['uninstallation_date']) ?></td></tr>
                    <tr><th class="text-muted">Pojazd</th><td><a href="vehicles.php"><?= h($installation['registration']) ?></a><br><?= h($installation['make'] . ' ' . $installation['vehicle_model']) ?></td></tr>
                    <tr><th class="text-muted">Klient</th><td><?= $installation['contact_name'] ? h($installation['company_name'] ?: $installation['contact_name']) : '—' ?></td></tr>
                    <tr><th class="text-muted">Urządzenie</th><td><a href="devices.php?action=view&id=<?= $installation['device_id'] ?>"><?= h($installation['serial_number']) ?></a><br><small><?= h($installation['manufacturer_name'] . ' ' . $installation['model_name']) ?></small></td></tr>
                    <tr><th class="text-muted">IMEI</th><td><?= h($installation['imei'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Technik</th><td><?= h($installation['technician_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Miejsce montażu</th><td><?= h($installation['location_in_vehicle'] ?? '—') ?></td></tr>
                </table>
                <?php if ($installation['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= h($installation['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <a href="installations.php?action=edit&id=<?= $installation['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <?php if ($installation['status'] === 'aktywna'): ?>
                <button onclick="showUninstallModal(<?= $installation['id'] ?>, <?= $installation['device_id'] ?>, '<?= h($installation['serial_number']) ?>')" class="btn btn-sm btn-warning"><i class="fas fa-minus-circle me-1"></i>Demontaż</button>
                <?php endif; ?>
                <a href="services.php?action=add&installation=<?= $installation['id'] ?>&device=<?= $installation['device_id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-wrench me-1"></i>Serwis</a>
                <a href="protocols.php?action=add&installation=<?= $installation['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clipboard me-1"></i>Protokół</a>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-wrench me-2 text-warning"></i>Serwisy tego montażu</span>
                <a href="services.php?action=add&installation=<?= $installation['id'] ?>&device=<?= $installation['device_id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-plus me-1"></i>Nowy serwis</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Typ</th><th>Zaplanowany</th><th>Zrealizowany</th><th>Status</th><th>Koszt</th><th>Technik</th></tr></thead>
                    <tbody>
                        <?php foreach ($services as $svc): ?>
                        <tr>
                            <td><?= h(ucfirst($svc['type'])) ?></td>
                            <td><?= formatDate($svc['planned_date']) ?></td>
                            <td><?= formatDate($svc['completed_date']) ?></td>
                            <td><?= getStatusBadge($svc['status'], 'service') ?></td>
                            <td><?= $svc['cost'] > 0 ? formatMoney($svc['cost']) : '—' ?></td>
                            <td><?= h($svc['tech_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($services)): ?><tr><td colspan="6" class="text-muted text-center">Brak serwisów</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:700px">
    <div class="card-header">
        <i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i>
        <?= $action === 'add' ? 'Nowy montaż' : 'Edytuj montaż' ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $installation['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <?php if ($action === 'add'): ?>
                <div class="col-md-6">
                    <label class="form-label required-star">Urządzenie GPS</label>
                    <select name="device_id" class="form-select" required>
                        <option value="">— wybierz urządzenie —</option>
                        <?php
                        $currentMf = '';
                        foreach ($availableDevices as $d):
                            if ($d['manufacturer_name'] !== $currentMf) {
                                if ($currentMf) echo '</optgroup>';
                                echo '<optgroup label="' . h($d['manufacturer_name'] . ' ' . $d['model_name']) . '">';
                                $currentMf = $d['manufacturer_name'];
                            }
                        ?>
                        <option value="<?= $d['id'] ?>" <?= (int)($_GET['device'] ?? 0) === $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['serial_number']) ?>
                        </option>
                        <?php endforeach; if ($currentMf) echo '</optgroup>'; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label required-star">Pojazd</label>
                    <select name="vehicle_id" class="form-select" required>
                        <option value="">— wybierz pojazd —</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"
                                <?= ($installation['vehicle_id'] ?? (int)($_GET['vehicle'] ?? 0)) == $v['id'] ? 'selected' : '' ?>>
                            <?= h($v['registration'] . ' — ' . $v['make'] . ' ' . $v['model_name'] . ($v['contact_name'] ? ' (' . ($v['company_name'] ?: $v['contact_name']) . ')' : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Klient</label>
                    <select name="client_id" class="form-select">
                        <option value="">— brak przypisania —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($installation['client_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                            <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Technik</label>
                    <select name="technician_id" class="form-select">
                        <option value="">— aktualny użytkownik —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($installation['technician_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Data montażu</label>
                    <input type="date" name="installation_date" class="form-control" required value="<?= h($installation['installation_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Data demontażu</label>
                    <input type="date" name="uninstallation_date" class="form-control" value="<?= h($installation['uninstallation_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="aktywna" <?= ($installation['status'] ?? 'aktywna') === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
                        <option value="zakonczona" <?= ($installation['status'] ?? '') === 'zakonczona' ? 'selected' : '' ?>>Zakończona</option>
                        <option value="anulowana" <?= ($installation['status'] ?? '') === 'anulowana' ? 'selected' : '' ?>>Anulowana</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Miejsce montażu w pojeździe</label>
                    <input type="text" name="location_in_vehicle" class="form-control" value="<?= h($installation['location_in_vehicle'] ?? '') ?>" placeholder="np. pod deską rozdzielczą">
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="3"><?= h($installation['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Zarejestruj montaż' : 'Zapisz zmiany' ?></button>
                    <a href="installations.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Uninstall Modal -->
<div class="modal fade" id="uninstallModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="uninstall">
                <input type="hidden" name="id" id="uninstallId">
                <input type="hidden" name="device_id" id="uninstallDeviceId">
                <div class="modal-header">
                    <h5 class="modal-title text-warning"><i class="fas fa-minus-circle me-2"></i>Demontaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Rejestracja demontażu urządzenia: <strong id="uninstallSerial"></strong></p>
                    <label class="form-label">Data demontażu</label>
                    <input type="date" name="uninstallation_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check me-1"></i>Zatwierdź demontaż</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function showUninstallModal(id, deviceId, serial) {
    document.getElementById('uninstallId').value = id;
    document.getElementById('uninstallDeviceId').value = deviceId;
    document.getElementById('uninstallSerial').textContent = serial;
    new bootstrap.Modal(document.getElementById('uninstallModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
