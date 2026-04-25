<?php
/**
 * FleetLink Magazyn - Device Management
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

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'devices.php');
    }
    $postAction    = sanitize($_POST['action'] ?? '');
    $modelId       = (int)($_POST['model_id'] ?? 0);
    $serialNumber  = sanitize($_POST['serial_number'] ?? '');
    $imei          = sanitize($_POST['imei'] ?? '');
    $simNumber     = sanitize($_POST['sim_number'] ?? '');
    $status        = sanitize($_POST['status'] ?? 'nowy');
    $purchaseDate  = sanitize($_POST['purchase_date'] ?? '');
    $purchasePrice = str_replace(',', '.', $_POST['purchase_price'] ?? '0');
    $saleDate      = sanitize($_POST['sale_date'] ?? '');
    $leaseEndDate  = sanitize($_POST['lease_end_date'] ?? '');
    $notes         = sanitize($_POST['notes'] ?? '');

    if ($postAction === 'sim_edit') {
        // Administrator, Technik and Użytkownik may update SIM number (requireLogin enforces auth)
        $simEditId = (int)($_POST['id'] ?? 0);
        if (!$simEditId) {
            flashError('Nieprawidłowe dane.');
            redirect(getBaseUrl() . 'devices.php');
        }
        $newSim = sanitize($_POST['sim_number'] ?? '');
        // Fetch old SIM
        $oldSimRow = $db->prepare("SELECT sim_number FROM devices WHERE id=?");
        $oldSimRow->execute([$simEditId]);
        $oldSimRec = $oldSimRow->fetch();
        $db->prepare("UPDATE devices SET sim_number=? WHERE id=?")->execute([$newSim ?: null, $simEditId]);
        // Sync sim_cards
        if (!empty($newSim)) {
            try {
                $existingSimStmt = $db->prepare("SELECT id FROM sim_cards WHERE device_id=? LIMIT 1");
                $existingSimStmt->execute([$simEditId]);
                $existingSim = $existingSimStmt->fetch();
                if ($existingSim) {
                    $db->prepare("UPDATE sim_cards SET phone_number=? WHERE id=?")->execute([$newSim, $existingSim['id']]);
                } else {
                    $checkSim = $db->prepare("SELECT id FROM sim_cards WHERE phone_number=? LIMIT 1");
                    $checkSim->execute([$newSim]);
                    if (!$checkSim->fetch()) {
                        $db->prepare("INSERT INTO sim_cards (phone_number, device_id) VALUES (?,?)")->execute([$newSim, $simEditId]);
                    } else {
                        $db->prepare("UPDATE sim_cards SET device_id=? WHERE phone_number=? AND device_id IS NULL")->execute([$simEditId, $newSim]);
                    }
                }
            } catch (PDOException $e) { /* sim_cards table may not exist */ }
        } elseif ($oldSimRec && !empty($oldSimRec['sim_number'])) {
            try {
                $db->prepare("UPDATE sim_cards SET device_id=NULL WHERE device_id=?")->execute([$simEditId]);
            } catch (PDOException $e) {}
        }
        flashSuccess('Numer telefonu SIM został zaktualizowany.');
        redirect(getBaseUrl() . 'devices.php');
    }

    $validStatuses = ['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa'];
    if (!in_array($status, $validStatuses)) $status = 'nowy';

    if ($postAction === 'add') {
        if (!isAdmin()) { flashError('Dodawanie urządzeń jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'devices.php'); }
        if (empty($serialNumber) || !$modelId) {
            flashError('Numer seryjny i model są wymagane.');
            redirect(getBaseUrl() . 'devices.php?action=add');
        }
        try {
            $stmt = $db->prepare("INSERT INTO devices (model_id, serial_number, imei, sim_number, status, purchase_date, purchase_price, sale_date, lease_end_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$modelId, $serialNumber, $imei, $simNumber, $status, $purchaseDate ?: null, $purchasePrice, $saleDate ?: null, $leaseEndDate ?: null, $notes]);
            $newDeviceId = (int)$db->lastInsertId();
            // Auto-adjust inventory: new device with status 'nowy' enters stock
            adjustInventoryForStatusChange($db, $modelId, '', $status);
            // Auto-create sim_cards entry when SIM number is provided
            if (!empty($simNumber)) {
                try {
                    // Only insert if no sim_card with this phone_number already exists
                    $checkSim = $db->prepare("SELECT id FROM sim_cards WHERE phone_number=? LIMIT 1");
                    $checkSim->execute([$simNumber]);
                    if (!$checkSim->fetch()) {
                        $db->prepare("INSERT INTO sim_cards (phone_number, device_id) VALUES (?,?)")
                           ->execute([$simNumber, $newDeviceId]);
                    } else {
                        // Link existing sim_card entry to the new device
                        $db->prepare("UPDATE sim_cards SET device_id=? WHERE phone_number=? AND (device_id IS NULL OR device_id=?)")
                           ->execute([$newDeviceId, $simNumber, $newDeviceId]);
                    }
                } catch (PDOException $e) { /* sim_cards table may not exist yet */ }
            }
            flashSuccess("Urządzenie $serialNumber zostało dodane.");
        } catch (PDOException $e) {
            flashError('Numer seryjny już istnieje w systemie.');
        }
        redirect(getBaseUrl() . 'devices.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($serialNumber) || !$modelId || !$editId) {
            flashError('Nieprawidłowe dane.');
            redirect(getBaseUrl() . 'devices.php?action=edit&id=' . $editId);
        }
        // Fetch old status and old sim_number for inventory/SIM sync
        $oldRow = $db->prepare("SELECT model_id, status, sim_number FROM devices WHERE id=?");
        $oldRow->execute([$editId]);
        $oldDevice = $oldRow->fetch();
        try {
            $stmt = $db->prepare("UPDATE devices SET model_id=?, serial_number=?, imei=?, sim_number=?, status=?, purchase_date=?, purchase_price=?, sale_date=?, lease_end_date=?, notes=? WHERE id=?");
            $stmt->execute([$modelId, $serialNumber, $imei, $simNumber, $status, $purchaseDate ?: null, $purchasePrice, $saleDate ?: null, $leaseEndDate ?: null, $notes, $editId]);
            // Auto-adjust inventory on status change
            if ($oldDevice) {
                adjustInventoryForStatusChange($db, $modelId, $oldDevice['status'], $status);
            }
            // Sync sim_cards: if SIM number changed or added
            if (!empty($simNumber)) {
                try {
                    // Upsert: update existing entry for this device, or insert new one
                    $existingSimStmt = $db->prepare("SELECT id FROM sim_cards WHERE device_id=? LIMIT 1");
                    $existingSimStmt->execute([$editId]);
                    $existingSim = $existingSimStmt->fetch();
                    if ($existingSim) {
                        $db->prepare("UPDATE sim_cards SET phone_number=? WHERE id=?")
                           ->execute([$simNumber, $existingSim['id']]);
                    } else {
                        // Only insert if no sim_card with this phone_number already exists
                        $checkSim = $db->prepare("SELECT id FROM sim_cards WHERE phone_number=? LIMIT 1");
                        $checkSim->execute([$simNumber]);
                        if (!$checkSim->fetch()) {
                            $db->prepare("INSERT INTO sim_cards (phone_number, device_id) VALUES (?,?)")
                               ->execute([$simNumber, $editId]);
                        } else {
                            // Link the existing sim_card to this device if it has no device
                            $db->prepare("UPDATE sim_cards SET device_id=? WHERE phone_number=? AND device_id IS NULL")
                               ->execute([$editId, $simNumber]);
                        }
                    }
                } catch (PDOException $e) { /* sim_cards table may not exist yet */ }
            } elseif ($oldDevice && !empty($oldDevice['sim_number'])) {
                // SIM number was cleared — unlink from sim_cards
                try {
                    $db->prepare("UPDATE sim_cards SET device_id=NULL WHERE device_id=?")
                       ->execute([$editId]);
                } catch (PDOException $e) { /* sim_cards table may not exist yet */ }
            }
            flashSuccess('Urządzenie zostało zaktualizowane.');
        } catch (PDOException $e) {
            flashError('Numer seryjny już istnieje w systemie.');
        }
        redirect(getBaseUrl() . 'devices.php');

    } elseif ($postAction === 'delete') {
        if (!isAdmin()) { flashError('Usuwanie urządzeń jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'devices.php'); }
        $delId = (int)($_POST['id'] ?? 0);
        // Fetch device for inventory restore
        $delRow = $db->prepare("SELECT model_id, status FROM devices WHERE id=?");
        $delRow->execute([$delId]);
        $delDevice = $delRow->fetch();
        try {
            $db->prepare("DELETE FROM devices WHERE id=?")->execute([$delId]);
            // If device was in stock ('nowy' or 'sprawny'), reduce inventory
            if ($delDevice) {
                adjustInventoryForStatusChange($db, $delDevice['model_id'], $delDevice['status'], 'wycofany');
            }
            flashSuccess('Urządzenie zostało usunięte.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć urządzenia — posiada powiązane rekordy.');
        }
        redirect(getBaseUrl() . 'devices.php');

    } elseif ($postAction === 'bulk_purchase') {
        if (!isAdmin()) { flashError('Brak uprawnień.'); redirect(getBaseUrl() . 'devices.php'); }
        $bulkIds = array_map('intval', $_POST['device_ids'] ?? []);
        $bulkIds = array_filter($bulkIds); // remove zeros
        if (empty($bulkIds)) {
            flashError('Nie wybrano żadnych urządzeń.');
            redirect(getBaseUrl() . 'devices.php');
        }
        $bulkPrice = trim(str_replace(',', '.', $_POST['bulk_purchase_price'] ?? ''));
        $bulkDate  = sanitize($_POST['bulk_purchase_date'] ?? '');
        $setParts   = [];
        $setParams  = [];
        if ($bulkPrice !== '') {
            $setParts[]  = 'purchase_price=?';
            $setParams[] = (float)$bulkPrice;
        }
        if ($bulkDate !== '') {
            $setParts[]  = 'purchase_date=?';
            $setParams[] = $bulkDate;
        }
        if (empty($setParts)) {
            flashError('Podaj cenę zakupu lub datę zakupu do przypisania.');
            redirect(getBaseUrl() . 'devices.php');
        }
        $placeholders = implode(',', array_fill(0, count($bulkIds), '?'));
        $db->prepare("UPDATE devices SET " . implode(',', $setParts) . " WHERE id IN ($placeholders)")
           ->execute(array_merge($setParams, $bulkIds));
        $n = count($bulkIds);
        if ($n === 1) {
            $label = '1 urządzenie';
        } elseif ($n <= 4) {
            $label = $n . ' urządzenia';
        } else {
            $label = $n . ' urządzeń';
        }
        flashSuccess('Zaktualizowano ' . $label . '.');
        redirect(getBaseUrl() . 'devices.php');

    } elseif ($postAction === 'bulk_delete') {
        if (!isAdmin()) { flashError('Brak uprawnień.'); redirect(getBaseUrl() . 'devices.php'); }
        $bulkDelIds = array_map('intval', $_POST['device_ids'] ?? []);
        $bulkDelIds = array_filter($bulkDelIds);
        if (empty($bulkDelIds)) {
            flashError('Nie wybrano żadnych urządzeń.');
            redirect(getBaseUrl() . 'devices.php');
        }
        $deleted = 0;
        $errors  = 0;
        foreach ($bulkDelIds as $bulkDelId) {
            $delRow = $db->prepare("SELECT model_id, status FROM devices WHERE id=?");
            $delRow->execute([$bulkDelId]);
            $delDevice = $delRow->fetch();
            try {
                $db->prepare("DELETE FROM devices WHERE id=?")->execute([$bulkDelId]);
                if ($delDevice) {
                    adjustInventoryForStatusChange($db, $delDevice['model_id'], $delDevice['status'], 'wycofany');
                }
                $deleted++;
            } catch (PDOException $e) {
                $errors++;
            }
        }
        $n = $deleted;
        if ($n === 1) { $label = '1 urządzenie'; }
        elseif ($n <= 4) { $label = $n . ' urządzenia'; }
        else { $label = $n . ' urządzeń'; }
        if ($deleted > 0) {
            $msg = 'Usunięto ' . $label . '.';
            if ($errors > 0) { $msg .= ' ' . $errors . ' nie udało się usunąć (powiązane rekordy).'; }
            flashSuccess($msg);
        } else {
            flashError('Nie udało się usunąć żadnego urządzenia — posiadają powiązane rekordy.');
        }
        redirect(getBaseUrl() . 'devices.php');
    }
}

if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT d.*, m.name as model_name, mf.name as manufacturer_name FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.id=?");
    $stmt->execute([$id]);
    $device = $stmt->fetch();
    if (!$device) { flashError('Urządzenie nie istnieje.'); redirect(getBaseUrl() . 'devices.php'); }
}

if ($action === 'view' && $id) {
    $stmt = $db->prepare("
        SELECT d.*, m.name as model_name, mf.name as manufacturer_name,
               m.price_purchase, m.price_sale
        FROM devices d
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        WHERE d.id=?
    ");
    $stmt->execute([$id]);
    $device = $stmt->fetch();
    if (!$device) { flashError('Urządzenie nie istnieje.'); redirect(getBaseUrl() . 'devices.php'); }

    // History of installations and services
    $installations = $db->prepare("
        SELECT i.*, v.registration, v.make, v.model_name as vehicle_model,
               c.contact_name, c.company_name, u.name as tech_name
        FROM installations i
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN users u ON u.id=i.technician_id
        WHERE i.device_id=?
        ORDER BY i.installation_date DESC
    ");
    $installations->execute([$id]);
    $deviceInstallations = $installations->fetchAll();

    $services = $db->prepare("
        SELECT s.*, u.name as tech_name
        FROM services s
        LEFT JOIN users u ON u.id=s.technician_id
        WHERE s.device_id=?
        ORDER BY s.created_at DESC
    ");
    $services->execute([$id]);
    $deviceServices = $services->fetchAll();

    // Device history (replacement events from PS protocols)
    $histStmt = $db->prepare("
        SELECT dh.*, dh.event_type,
               p.protocol_number, p.date as protocol_date,
               rd.serial_number as related_serial, rd.imei as related_imei,
               rm.name as related_model, rmf.name as related_manufacturer
        FROM device_history dh
        LEFT JOIN protocols p   ON p.id=dh.protocol_id
        LEFT JOIN devices rd    ON rd.id=dh.related_device_id
        LEFT JOIN models rm     ON rm.id=rd.model_id
        LEFT JOIN manufacturers rmf ON rmf.id=rm.manufacturer_id
        WHERE dh.device_id=?
        ORDER BY dh.created_at DESC
    ");
    $histStmt->execute([$id]);
    $deviceHistory = $histStmt->fetchAll();
}

// Models for select
$models = $db->query("SELECT m.id, m.name, mf.name as manufacturer_name FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE m.active=1 ORDER BY mf.name, m.name")->fetchAll();

// List with filters
$devices = [];
if ($action === 'list') {
    $search = sanitize($_GET['search'] ?? '');
    $filterModel = (int)($_GET['model'] ?? 0);
    $filterStatus = sanitize($_GET['status'] ?? '');

    $sql = "
        SELECT d.id, d.serial_number, d.imei, d.sim_number, d.status, d.purchase_date,
               d.purchase_price,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration as vehicle_registration,
               c.contact_name, c.company_name,
               i.installation_date
        FROM devices d
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        LEFT JOIN installations i ON i.device_id = d.id AND i.status = 'aktywna'
        LEFT JOIN vehicles v ON v.id = i.vehicle_id
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE 1=1
    ";
    $params = [];
    if ($search) {
        $sql .= " AND (d.serial_number LIKE ? OR d.imei LIKE ? OR m.name LIKE ? OR mf.name LIKE ?)";
        $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
    }
    if ($filterModel) { $sql .= " AND d.model_id=?"; $params[] = $filterModel; }
    if ($filterStatus) { $sql .= " AND d.status=?"; $params[] = $filterStatus; }
    $sql .= " ORDER BY d.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $devices = $stmt->fetchAll();
}

// SIM cards list for datalist (available / unassigned) — used in SIM-edit modal
$simCardOptions = [];
if ($action === 'list') {
    try {
        $simCardOptions = $db->query("SELECT phone_number FROM sim_cards WHERE active=1 ORDER BY phone_number")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $simCardOptions = []; }
}

$activePage = 'devices';
$pageTitle = 'Urządzenia';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-microchip me-2 text-primary"></i>Urządzenia GPS</h1>
    <?php if ($action === 'list'): ?>
    <div class="d-flex gap-2">
        <?php if (isAdmin()): ?>
        <a href="devices.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj urządzenie</a>
        <a href="device_import.php" class="btn btn-outline-secondary"><i class="fas fa-file-import me-2"></i>Importuj</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <a href="devices.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (nr seryjny, IMEI, model...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <?php foreach (['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_',' ',$s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="model" class="form-select form-select-sm">
                    <option value="">Wszystkie modele</option>
                    <?php foreach ($models as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($_GET['model'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                        <?= h($m['manufacturer_name'] . ' ' . $m['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Filtruj</button>
                <a href="devices.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>

<?php if (isAdmin()): ?>
<!-- Bulk actions panel (hidden until devices are selected) -->
<form id="bulkPurchaseForm" method="POST">
    <?= csrfField() ?>
    <div id="bulkActionsPanel" class="card border-warning mb-3" style="display:none">
        <div class="card-header d-flex align-items-center gap-2 bg-warning bg-opacity-10">
            <i class="fas fa-tasks text-warning"></i>
            <strong>Akcje masowe</strong>
            <span class="text-muted small ms-1" id="selectedCount"></span>
        </div>
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Cena zakupu (PLN)</label>
                    <input type="number" name="bulk_purchase_price" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="np. 299.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Data zakupu</label>
                    <input type="date" name="bulk_purchase_date" class="form-control form-control-sm">
                </div>
                <div class="col-auto d-flex gap-2 align-items-end">
                    <button type="submit" name="action" value="bulk_purchase" class="btn btn-sm btn-warning">
                        <i class="fas fa-save me-1"></i>Przypisz cenę / datę
                    </button>
                    <button type="submit" name="action" value="bulk_delete" class="btn btn-sm btn-danger"
                            id="bulkDeleteBtn">
                        <i class="fas fa-trash me-1"></i>Usuń zaznaczone
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<div class="card">
    <div class="card-header">Urządzenia (<?= count($devices) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <?php if (isAdmin()): ?>
                    <th style="width:36px"><input type="checkbox" id="checkAll" form="bulkPurchaseForm" title="Zaznacz wszystkie"></th>
                    <?php endif; ?>
                    <th>Nr seryjny</th><th>IMEI</th><th>Producent / Model</th><th>Status</th><th>Rejestracja</th><th>Nr telefonu SIM</th><th>Klient</th><th>Data montażu</th>
                    <?php if (isAdmin()): ?><th>Cena zakupu</th><?php endif; ?>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $d): ?>
                <tr>
                    <?php if (isAdmin()): ?>
                    <td><input type="checkbox" name="device_ids[]" value="<?= $d['id'] ?>" form="bulkPurchaseForm" class="device-checkbox"></td>
                    <?php endif; ?>
                    <td class="fw-semibold">
                        <a href="devices.php?action=view&id=<?= $d['id'] ?>"><?= h($d['serial_number']) ?></a>
                    </td>
                    <td><?= h($d['imei'] ?? '—') ?></td>
                    <td><?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?></td>
                    <td><?= getStatusBadge($d['status'], 'device') ?></td>
                    <td><?= $d['vehicle_registration'] ? h($d['vehicle_registration']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $d['sim_number'] ? h($d['sim_number']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?php $clientLabel = $d['company_name'] ?: ($d['contact_name'] ?: null); echo $clientLabel ? h($clientLabel) : '<span class="text-muted">—</span>'; ?></td>
                    <td>
                        <?php if ($d['installation_date']): ?>
                            <?= formatDate($d['installation_date']) ?>
                        <?php elseif ($d['purchase_date']): ?>
                            <?= formatDate($d['purchase_date']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if (isAdmin()): ?>
                    <td><?= $d['purchase_price'] > 0 ? formatMoney($d['purchase_price']) : '<span class="text-muted">—</span>' ?></td>
                    <?php endif; ?>
                    <td>
                        <a href="devices.php?action=view&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-info btn-action" title="Podgląd"><i class="fas fa-eye"></i></a>
                        <?php if (isAdmin()): ?>
                        <a href="devices.php?action=edit&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary btn-action" title="Edytuj"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń urządzenie <?= h($d['serial_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action" title="Zmień nr SIM"
                                onclick="openSimEdit(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['sim_number'] ?? '')) ?>)">
                            <i class="fas fa-sim-card"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($devices)): ?>
                <tr><td colspan="<?= isAdmin() ? 11 : 9 ?>" class="text-center text-muted p-3">Brak urządzeń. <a href="devices.php?action=add">Dodaj pierwsze urządzenie.</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php // SIM-edit modal (all roles) ?>
<!-- SIM-edit modal -->
<div class="modal fade" id="simEditModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" id="simEditForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sim_edit">
                <input type="hidden" name="id" id="simEditDeviceId" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sim-card me-2 text-primary"></i>Nr telefonu SIM</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Numer telefonu</label>
                    <input type="text" name="sim_number" id="simEditNumber" class="form-control"
                           list="simCardList" placeholder="np. +48 600 000 000" autocomplete="off">
                    <datalist id="simCardList">
                        <?php foreach ($simCardOptions as $sc): ?>
                        <option value="<?= h($sc) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-text">Wpisz ręcznie lub wybierz z listy kart SIM.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openSimEdit(deviceId, currentSim) {
    document.getElementById('simEditDeviceId').value = deviceId;
    document.getElementById('simEditNumber').value = currentSim || '';
    var modal = new bootstrap.Modal(document.getElementById('simEditModal'));
    modal.show();
}
<?php if (isAdmin()): ?>
(function () {
    var checkAll   = document.getElementById('checkAll');
    var panel      = document.getElementById('bulkActionsPanel');
    var countEl    = document.getElementById('selectedCount');
    var deleteBtn  = document.getElementById('bulkDeleteBtn');

    function countChecked() {
        var all = document.querySelectorAll('.device-checkbox');
        var n = 0;
        all.forEach(function (c) { if (c.checked) n++; });
        return n;
    }

    function pluralLabel(n) {
        if (n === 1) return '1 urządzenie';
        if (n <= 4)  return n + ' urządzenia';
        return n + ' urządzeń';
    }

    function syncPanel() {
        var n = countChecked();
        if (n > 0) {
            panel.style.display = '';
            countEl.textContent = '(' + pluralLabel(n) + ' zaznaczonych)';
        } else {
            panel.style.display = 'none';
            countEl.textContent = '';
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.device-checkbox').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
            syncPanel();
        });
    }

    document.querySelectorAll('.device-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var all = document.querySelectorAll('.device-checkbox');
            var n = countChecked();
            checkAll.checked = n === all.length;
            checkAll.indeterminate = n > 0 && n < all.length;
            syncPanel();
        });
    });

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function (e) {
            var n = countChecked();
            if (!confirm('Czy na pewno chcesz usunąć ' + pluralLabel(n) + '? Tej operacji nie można cofnąć.')) {
                e.preventDefault();
            }
        });
    }
})();
<?php endif; ?>
</script>

<?php elseif ($action === 'view' && isset($device)): ?>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Szczegóły urządzenia</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:40%">Producent</th><td><?= h($device['manufacturer_name']) ?></td></tr>
                    <tr><th class="text-muted">Model</th><td><?= h($device['model_name']) ?></td></tr>
                    <tr><th class="text-muted">Nr seryjny</th><td class="fw-bold"><?= h($device['serial_number']) ?></td></tr>
                    <tr><th class="text-muted">IMEI</th><td><?= h($device['imei'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Nr telefonu SIM</th><td><?= h($device['sim_number'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Status</th><td><?= getStatusBadge($device['status'], 'device') ?></td></tr>
                    <tr><th class="text-muted">Data zakupu</th><td><?= formatDate($device['purchase_date']) ?></td></tr>
                    <tr><th class="text-muted">Cena zakupu</th><td><?= $device['purchase_price'] ? formatMoney($device['purchase_price']) : '—' ?></td></tr>
                    <?php if ($device['status'] === 'sprzedany'): ?>
                    <tr><th class="text-muted">Data sprzedaży</th><td><?= formatDate($device['sale_date'] ?? '') ?></td></tr>
                    <?php if (!empty($device['sale_date'])): ?>
                    <?php
                        $saleDateTime = new DateTime($device['sale_date']);
                        $warrantyEnd  = (clone $saleDateTime)->modify('+24 months');
                        $now          = new DateTime();
                        $warrantyExpired = $warrantyEnd < $now;
                        // Remaining whole months (using exact day comparison)
                        $remainingMonths = 0;
                        if (!$warrantyExpired) {
                            $diff = $now->diff($warrantyEnd);
                            $remainingMonths = $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0);
                        }
                    ?>
                    <tr>
                        <th class="text-muted">Gwarancja do</th>
                        <td>
                            <?= h($warrantyEnd->format('d.m.Y')) ?>
                            <?php if ($warrantyExpired): ?>
                            <span class="badge bg-secondary ms-1">Wygasła</span>
                            <?php else: ?>
                            <span class="badge bg-success ms-1">Aktywna (<?= $remainingMonths ?> mies.)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php elseif ($device['status'] === 'dzierżawa'): ?>
                    <tr><th class="text-muted">Dzierżawa do</th>
                        <td>
                            <?= formatDate($device['lease_end_date'] ?? '') ?>
                            <?php if (!empty($device['lease_end_date'])): ?>
                                <?php $leaseEnd = new DateTime($device['lease_end_date']); ?>
                                <?php if ($leaseEnd < new DateTime()): ?>
                                <span class="badge bg-danger ms-1">Wygasła</span>
                                <?php else: ?>
                                <span class="badge bg-info ms-1">Aktywna</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php if ($device['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= h($device['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <?php if (!isTechnician()): ?>
                <a href="devices.php?action=edit&id=<?= $device['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <a href="installations.php?action=add&device=<?= $device['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-car me-1"></i>Montaż</a>
                <a href="services.php?action=add&device=<?= $device['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-wrench me-1"></i>Serwis</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-car me-2 text-success"></i>Historia montaży</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Pojazd</th><th>Klient</th><th>Montaż</th><th>Demontaż</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($deviceInstallations as $inst): ?>
                        <tr>
                            <td><?= h($inst['registration'] . ' ' . $inst['make']) ?></td>
                            <td><?= h($inst['company_name'] ?: $inst['contact_name'] ?? '—') ?></td>
                            <td><?= formatDate($inst['installation_date']) ?></td>
                            <td><?= formatDate($inst['uninstallation_date']) ?></td>
                            <td><?= getStatusBadge($inst['status'], 'installation') ?></td>
                            <td><a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="btn btn-xs btn-link p-0"><i class="fas fa-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($deviceInstallations)): ?>
                        <tr><td colspan="6" class="text-muted text-center">Brak montaży</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-wrench me-2 text-warning"></i>Historia serwisów</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Typ</th><th>Zaplanowany</th><th>Zrealizowany</th><th>Status</th><th>Koszt</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($deviceServices as $svc): ?>
                        <tr>
                            <td><?= h(ucfirst($svc['type'])) ?></td>
                            <td><?= formatDate($svc['planned_date']) ?></td>
                            <td><?= formatDate($svc['completed_date']) ?></td>
                            <td><?= getStatusBadge($svc['status'], 'service') ?></td>
                            <td><?= $svc['cost'] > 0 ? formatMoney($svc['cost']) : '—' ?></td>
                            <td><a href="services.php?action=view&id=<?= $svc['id'] ?>" class="btn btn-xs btn-link p-0"><i class="fas fa-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($deviceServices)): ?>
                        <tr><td colspan="6" class="text-muted text-center">Brak serwisów</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (!empty($deviceHistory)): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fas fa-exchange-alt me-2 text-danger"></i>Historia wymian</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Zdarzenie</th><th>Powiązane urządzenie</th><th>Protokół</th><th>Data</th></tr></thead>
                    <tbody>
                        <?php
                        $histLabels = ['wymieniono_na' => '🔄 Wymieniono na', 'wymieniono_z' => '🔄 Wymieniono z', 'serwis' => '🔧 Serwis'];
                        foreach ($deviceHistory as $h_row):
                        ?>
                        <tr>
                            <td><?= h($histLabels[$h_row['event_type']] ?? $h_row['event_type']) ?></td>
                            <td>
                                <?php if ($h_row['related_serial']): ?>
                                <?= h(trim(($h_row['related_manufacturer'] ?? '') . ' ' . ($h_row['related_model'] ?? ''))) ?><br>
                                <small class="text-muted"><?= h($h_row['related_serial']) ?><?= $h_row['related_imei'] ? ' [' . h($h_row['related_imei']) . ']' : '' ?></small>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($h_row['protocol_number']): ?>
                                <a href="protocols.php?action=view&id=<?= (int)$h_row['protocol_id'] ?>"><?= h($h_row['protocol_number']) ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?= formatDate($h_row['protocol_date'] ?? $h_row['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:700px">
    <div class="card-header">
        <i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i>
        <?= $action === 'add' ? 'Dodaj urządzenie' : 'Edytuj: ' . h($device['serial_number'] ?? '') ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $device['id'] ?>"><?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-star">Model urządzenia</label>
                    <select name="model_id" class="form-select" required>
                        <option value="">— wybierz model —</option>
                        <?php
                        $currentMf = '';
                        foreach ($models as $m):
                            if ($m['manufacturer_name'] !== $currentMf) {
                                if ($currentMf) echo '</optgroup>';
                                echo '<optgroup label="' . h($m['manufacturer_name']) . '">';
                                $currentMf = $m['manufacturer_name'];
                            }
                        ?>
                        <option value="<?= $m['id'] ?>" <?= ($device['model_id'] ?? (int)($_GET['model'] ?? 0)) == $m['id'] ? 'selected' : '' ?>>
                            <?= h($m['name']) ?>
                        </option>
                        <?php endforeach; if ($currentMf) echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Numer seryjny</label>
                    <input type="text" name="serial_number" class="form-control" required value="<?= h($device['serial_number'] ?? '') ?>" placeholder="np. 1234567890">
                </div>
                <div class="col-md-6">
                    <label class="form-label">IMEI</label>
                    <input type="text" name="imei" class="form-control" value="<?= h($device['imei'] ?? '') ?>" placeholder="15-cyfrowy numer IMEI">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nr telefonu SIM</label>
                    <input type="text" name="sim_number" class="form-control" value="<?= h($device['sim_number'] ?? '') ?>" placeholder="np. +48 600 000 000">
                    <div class="form-text">Po zapisaniu urządzenia z wypełnionym numerem SIM, karta pojawi się automatycznie w zakładce <a href="sim_cards.php" target="_blank">Karty SIM</a>.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" id="deviceStatus" class="form-select">
                        <?php foreach (['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($device['status'] ?? 'nowy') === $s ? 'selected' : '' ?>>
                            <?= h(ucfirst(str_replace('_',' ',$s))) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Data zakupu</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?= h($device['purchase_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Cena zakupu</label>
                    <div class="input-group">
                        <input type="number" name="purchase_price" class="form-control" value="<?= h($device['purchase_price'] ?? '0') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>

                <!-- Sale date – shown when status = sprzedany -->
                <div class="col-md-6" id="fieldSaleDate" style="display:none">
                    <label class="form-label">Data sprzedaży</label>
                    <input type="date" name="sale_date" class="form-control" value="<?= h($device['sale_date'] ?? '') ?>">
                    <div class="form-text">Gwarancja naliczana 24 miesiące od tej daty.</div>
                </div>

                <!-- Lease end date – shown when status = dzierżawa -->
                <div class="col-md-6" id="fieldLeaseEnd" style="display:none">
                    <label class="form-label">Data końca dzierżawy</label>
                    <input type="date" name="lease_end_date" class="form-control" value="<?= h($device['lease_end_date'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="3"><?= h($device['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz' ?></button>
                    <a href="devices.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function toggleStatusFields() {
    var status = document.getElementById('deviceStatus').value;
    document.getElementById('fieldSaleDate').style.display  = (status === 'sprzedany')  ? '' : 'none';
    document.getElementById('fieldLeaseEnd').style.display  = (status === 'dzierżawa')  ? '' : 'none';
}
document.getElementById('deviceStatus').addEventListener('change', toggleStatusFields);
toggleStatusFields(); // run on page load
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
