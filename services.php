<?php
/**
 * FleetLink System GPS - Service Management
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
    $replacementDeviceId = (int)($_POST['replacement_device_id'] ?? 0) ?: null;
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
    if ($type !== 'wymiana') $replacementDeviceId = null;

    if ($postAction === 'add') {
        if (!$deviceId || empty($plannedDate)) {
            flashError('Urządzenie i data zaplanowanego serwisu są wymagane.');
            redirect(getBaseUrl() . 'services.php?action=add');
        }
        $db->prepare("INSERT INTO services (device_id, installation_id, technician_id, type, replacement_device_id, planned_date, completed_date, status, description, resolution, cost) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$deviceId, $installationId, $technicianId, $type, $replacementDeviceId, $plannedDate, $completedDate, $status, $description, $resolution, $cost]);
        $newServiceId = (int)$db->lastInsertId();
        // Record device history for "wymiana"
        if ($type === 'wymiana' && $deviceId && $replacementDeviceId) {
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, service_id) VALUES (?,?,?,?)")
               ->execute([$deviceId, 'wymieniono_na', $replacementDeviceId, $newServiceId]);
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, service_id) VALUES (?,?,?,?)")
               ->execute([$replacementDeviceId, 'wymieniono_z', $deviceId, $newServiceId]);
        }
        // Update device status if in service
        if ($status === 'w_trakcie') {
            $db->prepare("UPDATE devices SET status='w_serwisie' WHERE id=?")->execute([$deviceId]);
        }
        // Process accessory pickups submitted with the service form
        $svcAccIds   = array_map('intval', (array)($_POST['svc_acc'] ?? []));
        $svcAccQtys  = array_map('intval', (array)($_POST['svc_acc_qty'] ?? []));
        $svcAccNotes = (array)($_POST['svc_acc_note'] ?? []);
        if (!empty($svcAccIds)) {
            foreach ($svcAccIds as $si => $sacid) {
                $sqty = max(0, (int)($svcAccQtys[$si] ?? 0));
                if (!$sacid || !$sqty) continue;
                $snote = sanitize($svcAccNotes[$si] ?? '');
                try {
                    $db->prepare("INSERT INTO accessory_issues (accessory_id, installation_id, user_id, quantity, notes) VALUES (?,?,?,?,?)")
                       ->execute([$sacid, $installationId ?: null, $currentUser['id'], $sqty, $snote ?: null]);
                } catch (Exception $e) { /* non-fatal */ }
            }
        }
        flashSuccess('Serwis zarejestrowany pomyślnie.');
        // Send notification email to the current user
        if (!empty($currentUser['email'])) {
            try {
                $svcTypeLabels = ['przeglad'=>'Przegląd','naprawa'=>'Naprawa','wymiana'=>'Wymiana','aktualizacja'=>'Aktualizacja firmware','inne'=>'Inne'];
                $svcStatusLabels = ['zaplanowany'=>'Zaplanowany','w_trakcie'=>'W trakcie','zakończony'=>'Zakończony','anulowany'=>'Anulowany'];
                $devLabel = '';
                if ($deviceId) {
                    $dRow = $db->prepare("SELECT d.serial_number, m.name as model_name, mf.name as manufacturer FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.id=?");
                    $dRow->execute([$deviceId]);
                    $dInfo = $dRow->fetch();
                    if ($dInfo) $devLabel = $dInfo['manufacturer'] . ' ' . $dInfo['model_name'] . ' — ' . $dInfo['serial_number'];
                }
                $techName = $currentUser['name'];
                if ($technicianId && $technicianId !== $currentUser['id']) {
                    $tRow = $db->prepare("SELECT name FROM users WHERE id=?");
                    $tRow->execute([$technicianId]);
                    $tInfo = $tRow->fetch();
                    if ($tInfo) $techName = $tInfo['name'];
                }
                $body = getEmailTemplate('service_created', [
                    'SERVICE_TYPE' => $svcTypeLabels[$type] ?? $type,
                    'DEVICE'       => $devLabel ?: '—',
                    'DATE'         => $plannedDate ? date('d.m.Y', strtotime($plannedDate)) : '—',
                    'TECHNICIAN'   => $techName,
                    'STATUS'       => $svcStatusLabels[$status] ?? $status,
                    'DESCRIPTION'  => $description ?: '—',
                    'SENDER_NAME'  => $currentUser['name'],
                ]);
                sendAppEmail($currentUser['email'], $currentUser['name'], 'Nowy serwis — FleetLink System GPS', $body);
            } catch (Exception $emailEx) { /* non-fatal */ }
        }
        redirect(getBaseUrl() . 'services.php?action=view&id=' . $newServiceId);

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        // Fetch old values to compare wymiana state
        $oldSvcStmt = $db->prepare("SELECT device_id, type, replacement_device_id FROM services WHERE id=?");
        $oldSvcStmt->execute([$editId]);
        $oldSvc = $oldSvcStmt->fetch() ?: [];
        $db->prepare("UPDATE services SET device_id=?, installation_id=?, technician_id=?, type=?, replacement_device_id=?, planned_date=?, completed_date=?, status=?, description=?, resolution=?, cost=? WHERE id=?")
           ->execute([$deviceId, $installationId, $technicianId, $type, $replacementDeviceId, $plannedDate, $completedDate, $status, $description, $resolution, $cost, $editId]);
        // Handle device_history for wymiana changes
        $wasWymiana = ($oldSvc['type'] ?? '') === 'wymiana' && ($oldSvc['device_id'] ?? 0) && ($oldSvc['replacement_device_id'] ?? 0);
        $isWymiana  = $type === 'wymiana' && $deviceId && $replacementDeviceId;
        $deviceChanged = ($oldSvc['device_id'] ?? 0) !== $deviceId || ($oldSvc['replacement_device_id'] ?? 0) !== $replacementDeviceId;
        if ($isWymiana && (!$wasWymiana || $deviceChanged)) {
            if ($wasWymiana) {
                $db->prepare("DELETE FROM device_history WHERE service_id=?")->execute([$editId]);
            }
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, service_id) VALUES (?,?,?,?)")
               ->execute([$deviceId, 'wymieniono_na', $replacementDeviceId, $editId]);
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, service_id) VALUES (?,?,?,?)")
               ->execute([$replacementDeviceId, 'wymieniono_z', $deviceId, $editId]);
        } elseif ($wasWymiana && !$isWymiana) {
            $db->prepare("DELETE FROM device_history WHERE service_id=?")->execute([$editId]);
        }
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
        $db->prepare("DELETE FROM device_history WHERE service_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM services WHERE id=?")->execute([$delId]);
        flashSuccess('Serwis usunięty.');
        redirect(getBaseUrl() . 'services.php');
    }
}

if (in_array($action, ['view','edit','print']) && $id) {
    $stmt = $db->prepare("
        SELECT s.*, d.serial_number, d.imei, m.name as model_name, mf.name as manufacturer_name,
               u.name as technician_name,
               v.registration, v.make,
               c.contact_name, c.company_name, c.phone as client_phone,
               c.address as client_address, c.city as client_city, c.postal_code as client_postal_code
        FROM services s
        JOIN devices d ON d.id=s.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN users u ON u.id=s.technician_id
        LEFT JOIN installations inst ON inst.id=s.installation_id
        LEFT JOIN vehicles v ON v.id=inst.vehicle_id
        LEFT JOIN clients c ON c.id=inst.client_id
        WHERE s.id=?
    ");
    $stmt->execute([$id]);
    $service = $stmt->fetch();
    if (!$service) { flashError('Serwis nie istnieje.'); redirect(getBaseUrl() . 'services.php'); }
}

$allDevices = $db->query("
    SELECT d.id, d.serial_number, m.name as model_name, mf.name as manufacturer_name,
           COALESCE(i.client_id, 0) as client_id
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    LEFT JOIN installations i ON i.device_id=d.id AND i.status='aktywna'
    WHERE d.status != 'wycofany'
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

$svcClients = $db->query("SELECT id, contact_name, company_name FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();

$users = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$activeInstallations = $db->query("
    SELECT i.id, v.registration, d.serial_number
    FROM installations i
    JOIN vehicles v ON v.id=i.vehicle_id
    JOIN devices d ON d.id=i.device_id
    WHERE i.status='aktywna'
    ORDER BY v.registration
")->fetchAll();

// Available accessories for add/edit forms
$svcAvailableAccessories = [];
try {
    $aaS = $db->query("
        SELECT a.id, a.name,
               (a.quantity_initial - COALESCE((SELECT SUM(ai2.quantity) FROM accessory_issues ai2 WHERE ai2.accessory_id = a.id),0)) AS remaining
        FROM accessories a WHERE a.active = 1 ORDER BY a.name
    ");
    $svcAvailableAccessories = $aaS->fetchAll();
} catch (Exception $e) { $svcAvailableAccessories = []; }

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

// Fetch service protocols for the Protocols tab
$serviceProtocols = [];
if ($action === 'list') {
    try {
        $serviceProtocols = $db->query("
            SELECT p.id, p.type, p.protocol_number, p.date,
                   u.name as technician_name, d.serial_number, m.name as model_name
            FROM protocols p
            LEFT JOIN users u ON u.id=p.technician_id
            LEFT JOIN services s ON s.id=p.service_id
            LEFT JOIN devices d ON d.id=s.device_id
            LEFT JOIN models m ON m.id=d.model_id
            WHERE p.type='PS'
            ORDER BY p.date DESC, p.id DESC
        ")->fetchAll();
    } catch (Exception $e) { $serviceProtocols = []; }
}

$activePage = 'services';
$pageTitle = $action === 'print' ? 'Zlecenie serwisowe' : 'Serwisy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-wrench me-2 text-primary"></i>Serwisy</h1>
    <?php if ($action === 'list'): ?>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#svcListAddModal"><i class="fas fa-plus me-2"></i>Nowy serwis</button>
    <?php elseif ($action !== 'print'): ?>
    <a href="services.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<ul class="nav nav-tabs mb-3" id="serviceTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-serwisy" data-bs-toggle="tab" data-bs-target="#pane-serwisy" type="button" role="tab">
            <i class="fas fa-wrench me-1"></i>Serwisy
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-protokoly-s" data-bs-toggle="tab" data-bs-target="#pane-protokoly-s" type="button" role="tab">
            <i class="fas fa-clipboard-check me-1"></i>Protokoły serwisu
        </button>
    </li>
</ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="pane-serwisy" role="tabpanel">
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
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showServicePreview(<?= htmlspecialchars(json_encode([
                                    'id'               => $svc['id'],
                                    'type'             => $svc['type'],
                                    'status'           => $svc['status'],
                                    'serial_number'    => $svc['serial_number'] ?? '',
                                    'manufacturer_name'=> $svc['manufacturer_name'] ?? '',
                                    'model_name'       => $svc['model_name'] ?? '',
                                    'device_id'        => $svc['device_id'] ?? null,
                                    'registration'     => $svc['registration'] ?? '',
                                    'make'             => $svc['make'] ?? '',
                                    'planned_date'     => $svc['planned_date'] ?? '',
                                    'completed_date'   => $svc['completed_date'] ?? '',
                                    'cost'             => $svc['cost'] ?? 0,
                                    'technician_name'  => $svc['technician_name'] ?? '',
                                ]), ENT_QUOTES) ?>)"
                                title="Podgląd"><i class="fas fa-eye"></i></button>
                        <a href="services.php?action=edit&id=<?= $svc['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <a href="services.php?action=print&id=<?= $svc['id'] ?>" class="btn btn-sm btn-outline-dark btn-action" title="Drukuj zlecenie serwisowe"><i class="fas fa-print"></i></a>
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

</div><!-- /pane-serwisy -->
<div class="tab-pane fade" id="pane-protokoly-s" role="tabpanel">
<div class="card">
    <div class="card-header">Protokoły serwisowe (<?= count($serviceProtocols) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Nr protokołu</th><th>Data</th><th>Urządzenie</th><th>Technik</th><th>Akcje</th></tr></thead>
            <tbody>
                <?php foreach ($serviceProtocols as $sp): ?>
                <tr>
                    <td class="fw-bold"><a href="protocols.php?action=view&id=<?= $sp['id'] ?>"><?= h($sp['protocol_number']) ?></a></td>
                    <td><?= formatDate($sp['date']) ?></td>
                    <td><?= h($sp['serial_number'] ?? '—') ?><br><small class="text-muted"><?= h($sp['model_name'] ?? '') ?></small></td>
                    <td><?= h($sp['technician_name'] ?? '—') ?></td>
                    <td>
                        <a href="protocols.php?action=view&id=<?= $sp['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="protocols.php?action=print&id=<?= $sp['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary btn-action"><i class="fas fa-print"></i></a>
                        <?php if (isAdmin()): ?>
                        <form method="POST" action="protocols.php" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $sp['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń protokół <?= h($sp['protocol_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($serviceProtocols)): ?>
                <tr><td colspan="5" class="text-center text-muted p-3">Brak protokołów serwisowych.<br>
                    <a href="protocols.php?action=add&type=PS" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-plus me-1"></i>Dodaj protokół serwisowy</a>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /pane-protokoly-s -->
</div><!-- /tab-content -->

<!-- Service Preview Modal -->
<div class="modal fade" id="servicePreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="servicePreviewTitle"><i class="fas fa-wrench me-2 text-warning"></i>Podgląd serwisu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="servicePreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
                <a id="servicePreviewPrintBtn" href="#" target="_blank" class="btn btn-outline-dark btn-sm"><i class="fas fa-print me-1"></i>Drukuj</a>
                <a id="servicePreviewViewBtn" href="#" class="btn btn-info btn-sm text-white"><i class="fas fa-eye me-1"></i>Otwórz pełny widok</a>
            </div>
        </div>
    </div>
</div>
<script>
function showServicePreview(data) {
    var statusMap = {
        'zaplanowany': '<span class="badge bg-warning text-dark">Zaplanowany</span>',
        'w_trakcie':   '<span class="badge bg-primary">W trakcie</span>',
        'zakończony':  '<span class="badge bg-success">Zakończony</span>',
        'anulowany':   '<span class="badge bg-secondary">Anulowany</span>'
    };
    var statusBadge = statusMap[data.status] || ('<span class="badge bg-secondary">' + data.status + '</span>');
    var formatDate = function(d) { return d ? d.split('-').reverse().join('.') : '—'; };
    var costStr = data.cost > 0 ? parseFloat(data.cost).toFixed(2).replace('.', ',') + ' zł' : '—';

    document.getElementById('servicePreviewTitle').innerHTML = '<i class="fas fa-wrench me-2 text-warning"></i>Serwis #' + data.id;
    document.getElementById('servicePreviewBody').innerHTML =
        '<table class="table table-sm table-borderless mb-0">' +
        '<tr><th class="text-muted" style="width:40%">Status</th><td>' + statusBadge + '</td></tr>' +
        '<tr><th class="text-muted">Typ</th><td><span class="badge bg-secondary">' + data.type.charAt(0).toUpperCase() + data.type.slice(1) + '</span></td></tr>' +
        '<tr><th class="text-muted">Urządzenie</th><td><strong>' + (data.serial_number || '—') + '</strong><br><small class="text-muted">' + data.manufacturer_name + ' ' + data.model_name + '</small></td></tr>' +
        '<tr><th class="text-muted">Pojazd</th><td>' + (data.registration ? data.registration + ' ' + data.make : '—') + '</td></tr>' +
        '<tr><th class="text-muted">Data zaplanowana</th><td>' + formatDate(data.planned_date) + '</td></tr>' +
        '<tr><th class="text-muted">Data realizacji</th><td>' + formatDate(data.completed_date) + '</td></tr>' +
        '<tr><th class="text-muted">Technik</th><td>' + (data.technician_name || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Koszt</th><td class="fw-bold">' + costStr + '</td></tr>' +
        '</table>';

    document.getElementById('servicePreviewViewBtn').href  = 'services.php?action=view&id=' + data.id;
    document.getElementById('servicePreviewPrintBtn').href = 'services.php?action=print&id=' + data.id;
    var modal = new bootstrap.Modal(document.getElementById('servicePreviewModal'));
    modal.show();
}
</script>

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
                <a href="services.php?action=print&id=<?= $service['id'] ?>" class="btn btn-sm btn-outline-dark"><i class="fas fa-print me-1"></i>Drukuj zlecenie</a>
                <a href="protocols.php?action=add&service=<?= $service['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clipboard me-1"></i>Protokół</a>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:<?= ($action === 'add' && !empty($svcAvailableAccessories)) ? '1400px' : '700px' ?>">
    <div class="card-header"><i class="fas fa-wrench me-2"></i><?= $action === 'add' ? 'Nowy serwis' : 'Edytuj serwis' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $service['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <?php if ($action === 'add' && !empty($svcAvailableAccessories)): ?><div class="col-lg-8"><div class="row g-3"><?php endif; ?>
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
                    <select name="type" id="svcTypeSelect" class="form-select">
                        <option value="przeglad" <?= ($service['type'] ?? 'przeglad') === 'przeglad' ? 'selected' : '' ?>>Przegląd</option>
                        <option value="naprawa" <?= ($service['type'] ?? '') === 'naprawa' ? 'selected' : '' ?>>Naprawa</option>
                        <option value="wymiana" <?= ($service['type'] ?? '') === 'wymiana' ? 'selected' : '' ?>>Wymiana</option>
                        <option value="aktualizacja" <?= ($service['type'] ?? '') === 'aktualizacja' ? 'selected' : '' ?>>Aktualizacja firmware</option>
                        <option value="inne" <?= ($service['type'] ?? '') === 'inne' ? 'selected' : '' ?>>Inne</option>
                    </select>
                </div>
                <!-- Replacement device (wymiana only) -->
                <div id="svcReplacementWrapper" class="col-12" <?= ($service['type'] ?? '') !== 'wymiana' ? 'style="display:none"' : '' ?>>
                    <label class="form-label fw-semibold text-danger"><i class="fas fa-exchange-alt me-1"></i>Urządzenie zastępcze (wymiana na)</label>
                    <select name="replacement_device_id" class="form-select">
                        <option value="">— wybierz urządzenie zastępcze —</option>
                        <?php foreach ($allDevices as $repDev): ?>
                        <option value="<?= $repDev['id'] ?>" <?= ($service['replacement_device_id'] ?? 0) == $repDev['id'] ? 'selected' : '' ?>>
                            <?= h($repDev['serial_number']) ?> — <?= h($repDev['manufacturer_name'] . ' ' . $repDev['model_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-danger"><i class="fas fa-info-circle me-1"></i>Historia "wymieniono na/z" zostanie zapisana w obu urządzeniach.</div>
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
                <?php if ($action === 'add' && !empty($svcAvailableAccessories)): ?>
                </div></div><!-- /inner row g-3 + /col-lg-8 -->
                <div class="col-lg-4">
                    <div class="card bg-light border-0 h-100">
                        <div class="card-header bg-warning bg-opacity-25 py-2 d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-toolbox me-2 text-warning"></i>Akcesoria do pobrania z magazynu (opcjonalnie)</span>
                            <button type="button" class="btn btn-outline-warning btn-sm" id="svcAddAccRow">
                                <i class="fas fa-plus me-1"></i>Dodaj pozycję
                            </button>
                        </div>
                        <div class="card-body pb-1" id="svcAccContainer">
                            <div class="svc-acc-row row g-2 align-items-center mb-2">
                                <div class="col-md-5">
                                    <select name="svc_acc[]" class="form-select form-select-sm">
                                        <option value="">— nie pobieraj —</option>
                                        <?php foreach ($svcAvailableAccessories as $sa): $srem = (int)$sa['remaining']; ?>
                                        <option value="<?= $sa['id'] ?>" <?= $srem <= 0 ? 'disabled' : '' ?>>
                                            <?= h($sa['name']) ?> (dost.: <?= max(0,$srem) ?> szt.)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="svc_acc_qty[]" class="form-control form-control-sm" min="1" value="1" placeholder="Ilość">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="svc_acc_note[]" class="form-control form-control-sm" placeholder="Uwagi do pobrania">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-outline-danger btn-sm svc-acc-remove" disabled><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /col-lg-4 accessories -->
                <?php endif; ?>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Zarejestruj serwis' : 'Zapisz zmiany' ?></button>
                    <a href="services.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php if ($action === 'add'): ?>
<script>
(function() {
    var svcAccOpts = <?= json_encode(array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'rem' => max(0,(int)$a['remaining'])], $svcAvailableAccessories ?? [])) ?>;
    function buildSvcAccOpts() {
        var html = '<option value="">— nie pobieraj —</option>';
        svcAccOpts.forEach(function(a) {
            html += '<option value="' + a.id + '"' + (a.rem <= 0 ? ' disabled' : '') + '>' + a.name.replace(/</g,'&lt;') + ' (dost.: ' + a.rem + ' szt.)</option>';
        });
        return html;
    }
    var addBtn = document.getElementById('svcAddAccRow');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            var container = document.getElementById('svcAccContainer');
            var div = document.createElement('div');
            div.className = 'svc-acc-row row g-2 align-items-center mb-2';
            div.innerHTML = '<div class="col-md-5"><select name="svc_acc[]" class="form-select form-select-sm">' + buildSvcAccOpts() + '</select></div>' +
                '<div class="col-md-2"><input type="number" name="svc_acc_qty[]" class="form-control form-control-sm" min="1" value="1" placeholder="Ilość"></div>' +
                '<div class="col-md-4"><input type="text" name="svc_acc_note[]" class="form-control form-control-sm" placeholder="Uwagi do pobrania"></div>' +
                '<div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm svc-acc-remove"><i class="fas fa-times"></i></button></div>';
            container.appendChild(div);
            updateSvcRemoveBtns();
        });
    }
    document.addEventListener('click', function(e) {
        if (e.target.closest('.svc-acc-remove')) {
            e.target.closest('.svc-acc-row').remove();
            updateSvcRemoveBtns();
        }
    });
    function updateSvcRemoveBtns() {
        var rows = document.querySelectorAll('#svcAccContainer .svc-acc-row');
        rows.forEach(function(r) { var b = r.querySelector('.svc-acc-remove'); if(b) b.disabled = rows.length <= 1; });
    }
})();
</script>
<?php endif; ?>
<script>
(function () {
    var svcTypeSelect = document.getElementById('svcTypeSelect');
    var svcRepWrapper = document.getElementById('svcReplacementWrapper');
    if (svcTypeSelect && svcRepWrapper) {
        svcTypeSelect.addEventListener('change', function () {
            svcRepWrapper.style.display = (this.value === 'wymiana') ? '' : 'none';
        });
    }
}());
</script>
<?php endif; ?>

<?php if ($action === 'print' && isset($service)): ?>
<?php
$svcClientLabel = $service['company_name'] ?: ($service['contact_name'] ?: '—');
$svcTechName    = $service['technician_name'] ?? '—';
$svcDate        = $service['planned_date'] ?? date('Y-m-d');
$svcOrderNum    = sprintf('ZS/%s/%04d', date('Y', strtotime(!empty($svcDate) ? $svcDate : 'now')), $service['id']);
$svcClientAddr  = trim(($service['client_address'] ?? '') . ', ' . ($service['client_postal_code'] ?? '') . ' ' . ($service['client_city'] ?? ''), ', ');
$svcCompanyName = '';
$svcCompanyAddr = '';
$svcCompanyPhone= '';
try {
    $svcSettings = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('company_name','company_address','company_city','company_postal_code','company_phone')")->fetchAll();
    $sCfg = [];
    foreach ($svcSettings as $s) { $sCfg[$s['key']] = $s['value']; }
    $svcCompanyName  = $sCfg['company_name'] ?? '';
    $svcCompanyAddr  = trim(($sCfg['company_address'] ?? '') . ', ' . ($sCfg['company_postal_code'] ?? '') . ' ' . ($sCfg['company_city'] ?? ''), ', ');
    $svcCompanyPhone = $sCfg['company_phone'] ?? '';
} catch (Exception $e) {}
$typeLabels = ['przeglad'=>'Przegląd','naprawa'=>'Naprawa','wymiana'=>'Wymiana','aktualizacja'=>'Aktualizacja firmware','inne'=>'Inne'];
?>
<style>
.svc-print-doc { background:#fff; color:#1a1a2e; font-family:'DM Sans','Segoe UI',system-ui,sans-serif; max-width:900px; margin:0 auto; }
.svc-print-header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:18px; margin-bottom:22px; border-bottom:3px solid #f97316; }
.svc-print-logo { font-size:1.5rem; font-weight:800; color:#1a1a2e; letter-spacing:-0.5px; }
.svc-print-logo span { color:#f97316; }
.svc-print-title { font-size:1.25rem; font-weight:700; color:#f97316; letter-spacing:1px; text-transform:uppercase; }
.svc-print-meta { font-size:.83rem; color:#666; margin-top:2px; }
.svc-section-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#f97316; margin-bottom:6px; display:flex; align-items:center; gap:6px; }
.svc-section-label::after { content:''; flex:1; height:1px; background:#fde8d0; }
.svc-info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:24px; }
.svc-info-box { background:#fff8f3; border:1px solid #fde8d0; border-radius:8px; padding:12px 14px; }
.svc-info-box .label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#f97316; margin-bottom:4px; }
.svc-info-box .value { font-size:.9rem; font-weight:600; color:#1a1a2e; }
.svc-info-box .sub   { font-size:.78rem; color:#666; margin-top:2px; }
.svc-detail-table { width:100%; border-collapse:collapse; margin-bottom:24px; font-size:.88rem; }
.svc-detail-table th { text-align:left; padding:8px 12px; width:38%; font-weight:700; font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; color:#888; border-bottom:1px solid #fde8d0; }
.svc-detail-table td { padding:8px 12px; border-bottom:1px solid #fde8d0; color:#1a1a2e; }
.svc-sig-row { display:flex; gap:32px; margin-top:40px; }
.svc-sig-box { flex:1; text-align:center; }
.svc-sig-line { border-top:2px solid #1a1a2e; padding-top:6px; margin-top:52px; font-size:.78rem; color:#444; }
.svc-print-footer { text-align:center; font-size:.72rem; color:#999; margin-top:30px; padding-top:12px; border-top:1px solid #fde8d0; }
@media print {
    .no-print { display:none !important; }
    body { background:#fff !important; }
    .navbar, footer { display:none !important; }
    .container-fluid { padding:0 !important; }
    .svc-print-doc { max-width:100%; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h5 class="mb-0"><i class="fas fa-file-alt me-2 text-warning"></i>Zlecenie serwisowe — podgląd wydruku</h5>
    <div>
        <button type="button" class="btn btn-warning me-2" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Drukuj / PDF
        </button>
        <a href="services.php?action=view&id=<?= $service['id'] ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Powrót
        </a>
    </div>
</div>

<div class="svc-print-doc p-4 card">
    <!-- ── Header ─────────────────────────── -->
    <div class="svc-print-header">
        <div>
            <?php if ($svcCompanyName): ?>
            <div class="svc-print-logo"><?= h($svcCompanyName) ?></div>
            <?php if ($svcCompanyAddr): ?><div style="font-size:.82rem;color:#666;margin-top:3px"><?= h($svcCompanyAddr) ?></div><?php endif; ?>
            <?php if ($svcCompanyPhone): ?><div style="font-size:.82rem;color:#666">Tel: <?= h($svcCompanyPhone) ?></div><?php endif; ?>
            <?php else: ?>
            <div class="svc-print-logo">Fleet<span>Link</span></div>
            <div style="font-size:.82rem;color:#666">System zarządzania urządzeniami GPS</div>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div class="svc-print-title">Zlecenie serwisowe</div>
            <div class="svc-print-meta">Nr zlecenia: <strong><?= h($svcOrderNum) ?></strong></div>
            <div class="svc-print-meta">Data: <strong><?= formatDate($svcDate) ?></strong></div>
            <div class="svc-print-meta">Typ: <strong><?= h($typeLabels[$service['type']] ?? ucfirst($service['type'])) ?></strong></div>
        </div>
    </div>

    <!-- ── Info grid ──────────────────────── -->
    <div class="svc-info-grid">
        <div class="svc-info-box">
            <div class="label">Klient</div>
            <div class="value"><?= h($svcClientLabel) ?></div>
            <?php if ($service['client_phone'] ?? ''): ?>
            <div class="sub"><i class="fas fa-phone me-1" style="color:#f97316;font-size:.7rem"></i><?= h($service['client_phone']) ?></div>
            <?php endif; ?>
            <?php if ($svcClientAddr): ?>
            <div class="sub"><i class="fas fa-map-marker-alt me-1" style="color:#f97316;font-size:.7rem"></i><?= h($svcClientAddr) ?></div>
            <?php endif; ?>
        </div>
        <div class="svc-info-box">
            <div class="label">Pojazd</div>
            <div class="value"><?= $service['registration'] ? h($service['registration']) : '<span style="color:#999">—</span>' ?></div>
            <?php if ($service['make'] ?? ''): ?>
            <div class="sub"><?= h($service['make']) ?></div>
            <?php endif; ?>
        </div>
        <div class="svc-info-box">
            <div class="label">Technik</div>
            <div class="value"><?= h($svcTechName) ?></div>
            <?php if ($service['completed_date']): ?>
            <div class="sub">Zrealizowano: <?= formatDate($service['completed_date']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Device details ─────────────────── -->
    <div class="svc-section-label">Urządzenie GPS</div>
    <table class="svc-detail-table" style="margin-bottom:20px">
        <tr>
            <th>Producent / Model</th>
            <td><strong><?= h($service['manufacturer_name'] . ' ' . $service['model_name']) ?></strong></td>
            <th>Nr seryjny</th>
            <td><?= h($service['serial_number']) ?></td>
        </tr>
        <tr>
            <th>IMEI</th>
            <td><?= h($service['imei'] ?? '—') ?></td>
            <th>Status serwisu</th>
            <td><?= getStatusBadge($service['status'], 'service') ?></td>
        </tr>
    </table>

    <!-- ── Description / Resolution ──────── -->
    <?php if ($service['description']): ?>
    <div class="svc-section-label">Opis / Problem</div>
    <div style="font-size:.87rem;color:#333;margin-bottom:18px;padding:10px 14px;background:#fff8f3;border-radius:6px;border:1px solid #fde8d0">
        <?= h($service['description']) ?>
    </div>
    <?php endif; ?>

    <?php if ($service['resolution']): ?>
    <div class="svc-section-label">Rozwiązanie / Wynik prac</div>
    <div style="font-size:.87rem;color:#333;margin-bottom:18px;padding:10px 14px;background:#fff8f3;border-radius:6px;border:1px solid #fde8d0">
        <?= h($service['resolution']) ?>
    </div>
    <?php endif; ?>

    <?php if ($service['cost'] > 0): ?>
    <div style="text-align:right;font-size:1rem;font-weight:700;margin-bottom:20px">
        Koszt serwisu: <span style="color:#f97316"><?= formatMoney($service['cost']) ?></span>
    </div>
    <?php endif; ?>

    <!-- ── Signatures ─────────────────────── -->
    <div class="svc-sig-row">
        <div class="svc-sig-box">
            <div class="svc-sig-line">Podpis technika<br><strong><?= h($svcTechName) ?></strong></div>
        </div>
        <div class="svc-sig-box">
            <div class="svc-sig-line">Podpis klienta / odbiór<br><strong><?= h($svcClientLabel) ?></strong></div>
        </div>
    </div>

    <div class="svc-print-footer">
        Dokument wygenerowany przez <?= $svcCompanyName ? h($svcCompanyName) : 'FleetLink System GPS' ?> &mdash; <?= date('d.m.Y H:i') ?> &mdash; <a href="https://www.fleetlink.pl" style="color:inherit;text-decoration:none">www.fleetlink.pl</a>
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

<?php if ($action === 'list'): ?>
<!-- Modal: Nowy serwis (lista serwisów) -->
<div class="modal fade" id="svcListAddModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="services.php" id="svcListAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wrench me-2 text-warning"></i>Nowy serwis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Klient (filtr urządzeń GPS)</label>
                            <select id="svcListClientFilter" class="form-select">
                                <option value="">— wszystkie urządzenia —</option>
                                <?php foreach ($svcClients as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Urządzenie GPS</label>
                            <input type="text" id="svcListDevSearch" class="form-control form-control-sm mb-1"
                                   placeholder="Szukaj urządzenia (nr seryjny, model…)" autocomplete="off">
                            <select name="device_id" id="svcListDevSelect" class="form-select" required size="4" style="height:auto">
                                <option value="">— wybierz urządzenie —</option>
                                <?php
                                $slGroup = '';
                                foreach ($allDevices as $d):
                                    $grp = $d['manufacturer_name'] . ' ' . $d['model_name'];
                                    if ($grp !== $slGroup) {
                                        if ($slGroup) echo '</optgroup>';
                                        echo '<optgroup label="' . h($grp) . '">';
                                        $slGroup = $grp;
                                    }
                                ?>
                                <option value="<?= $d['id'] ?>"
                                        data-client="<?= (int)$d['client_id'] ?>"
                                        data-search="<?= h(strtolower($d['serial_number'] . ' ' . $d['model_name'] . ' ' . $d['manufacturer_name'])) ?>">
                                    <?= h($d['serial_number']) ?> — <?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?>
                                </option>
                                <?php endforeach; if ($slGroup) echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Powiązany montaż (aktywny)</label>
                            <select name="installation_id" class="form-select">
                                <option value="">— brak —</option>
                                <?php foreach ($activeInstallations as $inst): ?>
                                <option value="<?= $inst['id'] ?>"><?= h($inst['registration'] . ' — ' . $inst['serial_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Typ serwisu</label>
                            <select name="type" id="svcListTypeSelect" class="form-select">
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
                            <input type="date" name="planned_date" id="svcListPlannedDate" class="form-control" required>
                        </div>
                        <?php if (!empty($users)): ?>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($users as $u): ?>
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
(function () {
    var modal = document.getElementById('svcListAddModal');
    if (!modal) return;
    function filterSvcDevices() {
        var q = (document.getElementById('svcListDevSearch').value || '').toLowerCase().trim();
        var clientId = document.getElementById('svcListClientFilter').value;
        document.querySelectorAll('#svcListDevSelect option').forEach(function (o) {
            if (!o.value) { o.style.display = ''; return; }
            var matchSearch = !q || (o.dataset.search || '').includes(q);
            var matchClient = !clientId || String(o.dataset.client || '0') === clientId;
            o.style.display = (matchSearch && matchClient) ? '' : 'none';
        });
    }
    modal.addEventListener('show.bs.modal', function () {
        document.getElementById('svcListDevSearch').value = '';
        document.getElementById('svcListDevSelect').value = '';
        document.getElementById('svcListClientFilter').value = '';
        document.querySelectorAll('#svcListDevSelect option').forEach(function (o) { o.style.display = ''; });
        document.getElementById('svcListPlannedDate').value = new Date().toISOString().slice(0, 10);
    });
    var search = document.getElementById('svcListDevSearch');
    if (search) { search.addEventListener('input', filterSvcDevices); }
    var clientFilter = document.getElementById('svcListClientFilter');
    if (clientFilter) { clientFilter.addEventListener('change', filterSvcDevices); }
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
