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
    // $locationInVehicle is a single string used for both 'add' (shared across all devices in batch) and 'edit'.
    $locationInVehicle  = is_array($_POST['location_in_vehicle'] ?? null) ? '' : sanitize($_POST['location_in_vehicle'] ?? '');
    $notes              = sanitize($_POST['notes'] ?? '');
    $currentUser        = getCurrentUser();

    if (!$technicianId) $technicianId = $currentUser['id'];

    // AJAX: quick-create client
    if ($postAction === 'quick_add_client') {
        header('Content-Type: application/json');
        $contactName = sanitize($_POST['contact_name'] ?? '');
        $companyName = sanitize($_POST['company_name'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $email       = sanitize($_POST['email'] ?? '');
        if (empty($contactName)) { echo json_encode(['error' => 'Imię i nazwisko kontaktu jest wymagane.']); exit; }
        $db->prepare("INSERT INTO clients (contact_name, company_name, phone, email) VALUES (?,?,?,?)")
           ->execute([$contactName, $companyName, $phone, $email]);
        $newClientId = $db->lastInsertId();
        echo json_encode(['id' => $newClientId, 'label' => ($companyName ? $companyName . ' — ' : '') . $contactName]);
        exit;
    }

    if ($postAction === 'add') {
        // New multi-device add flow
        $vehicleRegistration = strtoupper(trim(sanitize($_POST['vehicle_registration'] ?? '')));

        if (empty($vehicleRegistration) || empty($installationDate)) {
            flashError('Numer rejestracyjny pojazdu i data montażu są wymagane.');
            redirect(getBaseUrl() . 'installations.php?action=add');
        }

        // Arrays of per-device row data
        // device_mode[] values are validated to 'auto'/'manual' in the loop; model/device IDs are cast to int.
        $deviceModes     = is_array($_POST['device_mode'] ?? null)      ? $_POST['device_mode']      : ['auto'];
        $modelIds        = is_array($_POST['model_id'] ?? null)         ? $_POST['model_id']         : [0];
        $deviceIdsManual = is_array($_POST['device_id_manual'] ?? null) ? $_POST['device_id_manual'] : [0];
        // location_in_vehicle is a single shared field (next to Status) applied to all devices in this batch

        if (empty($deviceModes)) {
            flashError('Wybierz co najmniej jedno urządzenie.');
            redirect(getBaseUrl() . 'installations.php?action=add');
        }

        // Find or auto-create vehicle by registration plate
        $vStmt = $db->prepare("SELECT id FROM vehicles WHERE registration=? LIMIT 1");
        $vStmt->execute([$vehicleRegistration]);
        $vRow = $vStmt->fetch();
        if ($vRow) {
            $vehicleId = $vRow['id'];
        } else {
            $db->prepare("INSERT INTO vehicles (registration, client_id) VALUES (?,?)")
               ->execute([$vehicleRegistration, $clientId]);
            $vehicleId = $db->lastInsertId();
        }

        $allocatedDeviceIds = []; // track devices allocated in this batch
        $db->beginTransaction();
        try {
            foreach ($deviceModes as $idx => $mode) {
                $mode = ($mode === 'manual') ? 'manual' : 'auto';

                if ($mode === 'manual') {
                    $dId = (int)($deviceIdsManual[$idx] ?? 0);
                    if (!$dId) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': nie wybrano urządzenia.');
                    }
                    $devStmt = $db->prepare("SELECT id, model_id, status FROM devices WHERE id=? AND status IN ('nowy','sprawny') LIMIT 1");
                    $devStmt->execute([$dId]);
                    $devRow = $devStmt->fetch();
                    if (!$devRow) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': wybrane urządzenie jest niedostępne lub zmieniło status.');
                    }
                    if (in_array($dId, $allocatedDeviceIds)) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': to urządzenie zostało już wybrane w tym montażu.');
                    }
                } else {
                    $mId = (int)($modelIds[$idx] ?? 0);
                    if (!$mId) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': nie wybrano modelu urządzenia.');
                    }
                    if (!empty($allocatedDeviceIds)) {
                        $placeholders = implode(',', array_fill(0, count($allocatedDeviceIds), '?'));
                        $devStmt = $db->prepare("SELECT id, model_id, status FROM devices WHERE model_id=? AND status IN ('nowy','sprawny') AND id NOT IN ($placeholders) LIMIT 1");
                        $devStmt->execute(array_merge([$mId], $allocatedDeviceIds));
                    } else {
                        $devStmt = $db->prepare("SELECT id, model_id, status FROM devices WHERE model_id=? AND status IN ('nowy','sprawny') LIMIT 1");
                        $devStmt->execute([$mId]);
                    }
                    $devRow = $devStmt->fetch();
                    if (!$devRow) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': brak dostępnych urządzeń dla wybranego modelu.');
                    }
                    $dId = $devRow['id'];
                }

                // Check not already actively installed
                $checkStmt = $db->prepare("SELECT id FROM installations WHERE device_id=? AND status='aktywna' LIMIT 1");
                $checkStmt->execute([$dId]);
                if ($checkStmt->fetch()) {
                    throw new Exception('Wiersz ' . ($idx + 1) . ': urządzenie jest już aktywnie zamontowane.');
                }

                // Create installation record ($locationInVehicle is shared across all devices in this batch)
                $db->prepare("INSERT INTO installations (device_id, vehicle_id, client_id, technician_id, installation_date, uninstallation_date, status, location_in_vehicle, notes) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$dId, $vehicleId, $clientId, $technicianId, $installationDate, $uninstallationDate, $status, $locationInVehicle, $notes]);

                // Update device status + auto inventory adjust
                $oldStatus = $devRow['status'];
                $db->prepare("UPDATE devices SET status='zamontowany' WHERE id=?")->execute([$dId]);
                adjustInventoryForStatusChange($db, $devRow['model_id'], $oldStatus, 'zamontowany');

                $allocatedDeviceIds[] = $dId;
            }
            $db->commit();
            $n = count($allocatedDeviceIds);
            flashSuccess('Zarejestrowano ' . $n . ' montaż' . ($n === 1 ? '' : 'e') . ' pomyślnie.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
            redirect(getBaseUrl() . 'installations.php?action=add');
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
// Available device models (for the add form — model selector)
$availableModels = $db->query("
    SELECT m.id as model_id, m.name as model_name, mf.name as manufacturer_name,
           COUNT(d.id) as available_count
    FROM models m
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    JOIN devices d ON d.model_id=m.id AND d.status IN ('nowy','sprawny')
    GROUP BY m.id
    HAVING available_count > 0
    ORDER BY mf.name, m.name
")->fetchAll();

// Individual available devices (for manual selection in the add form)
$availableDevices = $db->query("
    SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status IN ('nowy','sprawny')
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

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
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                            <input type="hidden" name="device_id" value="<?= $inst['device_id'] ?? 0 ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń montaż #<?= $inst['id'] ?>? Urządzenie zostanie oznaczone jako sprawne."><i class="fas fa-trash"></i></button>
                        </form>
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
<div class="card" style="max-width:1050px">
    <div class="card-header">
        <i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i>
        <?= $action === 'add' ? 'Nowy montaż' : 'Edytuj montaż' ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $installation['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $installation['vehicle_id'] ?? 0 ?>"><?php endif; ?>
            <div class="row g-3">
                <?php if ($action === 'add'): ?>
                <!-- Multi-device selection rows -->
                <div class="col-12">
                    <label class="form-label required-star">Urządzenia GPS do montażu</label>

                    <!-- Vehicle registration shared for entire batch -->
                    <div class="mb-3">
                        <label class="form-label required-star" for="vehicle_registration_input">Numer rejestracyjny pojazdu</label>
                        <input type="text" id="vehicle_registration_input" name="vehicle_registration" class="form-control"
                               required placeholder="np. KR 12345"
                               value="<?= h($_POST['vehicle_registration'] ?? '') ?>"
                               style="text-transform:uppercase;max-width:250px">
                        <div class="form-text">Pojazd zostanie automatycznie zarejestrowany jeśli nie istnieje.</div>
                    </div>

                    <?php if (empty($availableModels) && empty($availableDevices)): ?>
                    <div class="alert alert-warning py-2 mb-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>Brak dostępnych urządzeń w magazynie.
                        <a href="devices.php?action=add">Dodaj urządzenia</a> lub sprawdź stan magazynu.
                    </div>
                    <?php endif; ?>

                    <div id="deviceRowsContainer" class="d-flex flex-column gap-2 mb-2">
                        <!-- First device row (index 0), pre-populated from ?device=X if provided -->
                        <?php
                        $preDeviceId = (int)($_GET['device'] ?? 0);
                        $preMode     = ($preDeviceId > 0) ? 'manual' : 'auto';
                        ?>
                        <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                            <div class="row g-2 align-items-center">
                                <div class="col-auto">
                                    <span class="row-num badge bg-secondary">1</span>
                                </div>
                                <div class="col-auto">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="device_mode[0]" id="dm_auto_0" value="auto"
                                               <?= $preMode === 'auto' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-secondary" for="dm_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                        <input type="radio" class="btn-check" name="device_mode[0]" id="dm_manual_0" value="manual"
                                               <?= $preMode === 'manual' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-primary" for="dm_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                                    </div>
                                </div>
                                <div class="col col-mode-auto" <?= $preMode === 'manual' ? 'style="display:none"' : '' ?>>
                                    <select name="model_id[0]" class="form-select form-select-sm">
                                        <option value="">— wybierz model —</option>
                                        <?php foreach ($availableModels as $m): ?>
                                        <option value="<?= $m['model_id'] ?>">
                                            <?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col col-mode-manual" <?= $preMode === 'auto' ? 'style="display:none"' : '' ?>>
                                    <select name="device_id_manual[0]" class="form-select form-select-sm">
                                        <option value="">— wybierz urządzenie —</option>
                                        <?php
                                        $currentGroup = '';
                                        foreach ($availableDevices as $dev):
                                            $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                            if ($grp !== $currentGroup) {
                                                if ($currentGroup) echo '</optgroup>';
                                                echo '<optgroup label="' . h($grp) . '">';
                                                $currentGroup = $grp;
                                            }
                                        ?>
                                        <option value="<?= $dev['id'] ?>" <?= $preDeviceId === $dev['id'] ? 'selected' : '' ?>>
                                            <?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?>
                                        </option>
                                        <?php endforeach; if ($currentGroup) echo '</optgroup>'; ?>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none"
                                            title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div><!-- #deviceRowsContainer -->

                    <button type="button" id="addDeviceRowBtn" class="btn btn-sm btn-outline-success"
                            <?= (empty($availableModels) && empty($availableDevices)) ? 'disabled' : '' ?>>
                        <i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie
                    </button>
                </div><!-- .col-12 device section -->
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label">Klient</label>
                    <div class="input-group">
                        <select name="client_id" id="clientSelect" class="form-select">
                            <option value="">— brak przypisania —</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($installation['client_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($action === 'add'): ?>
                        <button type="button" class="btn btn-outline-success" id="quickAddClientBtn"
                                title="Dodaj nowego klienta" data-bs-toggle="tooltip">
                            <i class="fas fa-user-plus"></i>
                        </button>
                        <?php endif; ?>
                    </div>
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

<!-- Quick Add Client Modal -->
<div class="modal fade" id="quickClientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2 text-success"></i>Nowy klient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="quickClientError" class="alert alert-danger d-none"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-star">Imię i nazwisko kontaktu</label>
                        <input type="text" id="qc_contact_name" class="form-control" placeholder="Jan Kowalski">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nazwa firmy</label>
                        <input type="text" id="qc_company_name" class="form-control" placeholder="Firma Sp. z o.o.">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefon</label>
                        <input type="text" id="qc_phone" class="form-control" placeholder="+48 123 456 789">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input type="email" id="qc_email" class="form-control" placeholder="kontakt@firma.pl">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success" id="quickClientSave">
                    <i class="fas fa-save me-2"></i>Zapisz klienta
                </button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('quickAddClientBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        new bootstrap.Modal(document.getElementById('quickClientModal')).show();
    });
    document.getElementById('quickClientSave').addEventListener('click', function () {
        var contactName = document.getElementById('qc_contact_name').value.trim();
        var companyName = document.getElementById('qc_company_name').value.trim();
        var phone       = document.getElementById('qc_phone').value.trim();
        var email       = document.getElementById('qc_email').value.trim();
        var errEl       = document.getElementById('quickClientError');
        if (!contactName) { errEl.textContent = 'Imię i nazwisko kontaktu jest wymagane.'; errEl.classList.remove('d-none'); return; }
        errEl.classList.add('d-none');
        var fd = new FormData();
        fd.append('action', 'quick_add_client');
        var csrfEl = document.querySelector('input[name="csrf_token"]');
        if (!csrfEl) { errEl.textContent = 'Błąd sesji. Odśwież stronę i spróbuj ponownie.'; errEl.classList.remove('d-none'); return; }
        fd.append('csrf_token', csrfEl.value);
        fd.append('contact_name', contactName);
        fd.append('company_name', companyName);
        fd.append('phone', phone);
        fd.append('email', email);
        fetch('installations.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
                var sel = document.getElementById('clientSelect');
                var opt = new Option(data.label, data.id, true, true);
                sel.appendChild(opt);
                bootstrap.Modal.getInstance(document.getElementById('quickClientModal')).hide();
                // Reset modal fields
                ['qc_contact_name','qc_company_name','qc_phone','qc_email'].forEach(function (id) { document.getElementById(id).value = ''; });
            })
            .catch(function () { errEl.textContent = 'Błąd połączenia z serwerem.'; errEl.classList.remove('d-none'); });
    });
}());
</script>

<!-- Hidden template for new device rows — cloned by JavaScript -->
<?php if ($action === 'add'): ?>
<template id="deviceRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <span class="row-num badge bg-secondary">__NUM__</span>
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="dm_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="dm_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="dm_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="dm_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                </div>
            </div>
            <div class="col col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($availableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $tplGroup = '';
                    foreach ($availableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $tplGroup) {
                            if ($tplGroup) echo '</optgroup>';
                            echo '<optgroup label="' . h($grp) . '">';
                            $tplGroup = $grp;
                        }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?></option>
                    <?php endforeach; if ($tplGroup) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn"
                        title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    var container = document.getElementById('deviceRowsContainer');
    var addBtn    = document.getElementById('addDeviceRowBtn');
    if (!container || !addBtn) return;

    var rowCounter = 1; // Row 0 is already rendered by PHP

    function updateRowNumbers() {
        var rows = container.querySelectorAll('.device-row');
        rows.forEach(function (row, i) {
            var numEl = row.querySelector('.row-num');
            if (numEl) numEl.textContent = i + 1;
            var removeBtn = row.querySelector('.remove-row-btn');
            if (removeBtn) removeBtn.style.display = rows.length > 1 ? '' : 'none';
        });
    }

    function applyModeToRow(row, mode) {
        var autoCol   = row.querySelector('.col-mode-auto');
        var manualCol = row.querySelector('.col-mode-manual');
        if (autoCol)   autoCol.style.display   = (mode === 'auto')   ? '' : 'none';
        if (manualCol) manualCol.style.display = (mode === 'manual') ? '' : 'none';
    }

    // Event delegation – mode toggle
    container.addEventListener('change', function (e) {
        if (e.target.type === 'radio' && e.target.name && e.target.name.startsWith('device_mode')) {
            applyModeToRow(e.target.closest('.device-row'), e.target.value);
        }
    });

    // Event delegation – remove row
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-row-btn');
        if (btn) {
            btn.closest('.device-row').remove();
            updateRowNumbers();
        }
    });

    // Add new device row
    addBtn.addEventListener('click', function () {
        var tpl = document.getElementById('deviceRowTemplate');
        if (!tpl) return;
        var idx   = rowCounter++;
        var clone = tpl.content.cloneNode(true);
        // Replace __IDX__ and __NUM__ in all relevant attributes
        clone.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/__IDX__/g, idx);
        });
        clone.querySelectorAll('[id]').forEach(function (el) {
            el.id = el.id.replace(/__IDX__/g, idx);
        });
        clone.querySelectorAll('[for]').forEach(function (el) {
            el.htmlFor = el.htmlFor.replace(/__IDX__/g, idx);
        });
        container.appendChild(clone);
        updateRowNumbers();
    });

    // Init: apply mode to first row and update remove-button visibility
    container.querySelectorAll('.device-row').forEach(function (row) {
        var checked = row.querySelector('.btn-check:checked');
        if (checked) applyModeToRow(row, checked.value);
    });
    updateRowNumbers();
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
