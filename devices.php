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
    $notes         = sanitize($_POST['notes'] ?? '');

    $validStatuses = ['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany'];
    if (!in_array($status, $validStatuses)) $status = 'nowy';

    if ($postAction === 'add') {
        if (empty($serialNumber) || !$modelId) {
            flashError('Numer seryjny i model są wymagane.');
            redirect(getBaseUrl() . 'devices.php?action=add');
        }
        try {
            $stmt = $db->prepare("INSERT INTO devices (model_id, serial_number, imei, sim_number, status, purchase_date, purchase_price, notes) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$modelId, $serialNumber, $imei, $simNumber, $status, $purchaseDate ?: null, $purchasePrice, $notes]);
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
        try {
            $stmt = $db->prepare("UPDATE devices SET model_id=?, serial_number=?, imei=?, sim_number=?, status=?, purchase_date=?, purchase_price=?, notes=? WHERE id=?");
            $stmt->execute([$modelId, $serialNumber, $imei, $simNumber, $status, $purchaseDate ?: null, $purchasePrice, $notes, $editId]);
            flashSuccess('Urządzenie zostało zaktualizowane.');
        } catch (PDOException $e) {
            flashError('Numer seryjny już istnieje w systemie.');
        }
        redirect(getBaseUrl() . 'devices.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM devices WHERE id=?")->execute([$delId]);
            flashSuccess('Urządzenie zostało usunięte.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć urządzenia — posiada powiązane rekordy.');
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
        SELECT d.id, d.serial_number, d.imei, d.status, d.purchase_date,
               m.name as model_name, mf.name as manufacturer_name
        FROM devices d
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        WHERE 1=1
    ";
    $params = [];
    if ($search) {
        $sql .= " AND (d.serial_number LIKE ? OR d.imei LIKE ? OR m.name LIKE ? OR mf.name LIKE ?)";
        $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
    }
    if ($filterModel) { $sql .= " AND d.model_id=?"; $params[] = $filterModel; }
    if ($filterStatus) { $sql .= " AND d.status=?"; $params[] = $filterStatus; }
    $sql .= " ORDER BY d.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $devices = $stmt->fetchAll();
}

$activePage = 'devices';
$pageTitle = 'Urządzenia';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-microchip me-2 text-primary"></i>Urządzenia GPS</h1>
    <?php if ($action === 'list'): ?>
    <a href="devices.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj urządzenie</a>
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
                    <?php foreach (['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
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

<div class="card">
    <div class="card-header">Urządzenia (<?= count($devices) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr seryjny</th><th>IMEI</th><th>Producent / Model</th><th>Status</th><th>Data zakupu</th><th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $d): ?>
                <tr>
                    <td class="fw-semibold">
                        <a href="devices.php?action=view&id=<?= $d['id'] ?>"><?= h($d['serial_number']) ?></a>
                    </td>
                    <td><?= h($d['imei'] ?? '—') ?></td>
                    <td><?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?></td>
                    <td><?= getStatusBadge($d['status'], 'device') ?></td>
                    <td><?= formatDate($d['purchase_date']) ?></td>
                    <td>
                        <a href="devices.php?action=view&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-info btn-action" title="Podgląd"><i class="fas fa-eye"></i></a>
                        <a href="devices.php?action=edit&id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary btn-action" title="Edytuj"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń urządzenie <?= h($d['serial_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($devices)): ?>
                <tr><td colspan="6" class="text-center text-muted p-3">Brak urządzeń. <a href="devices.php?action=add">Dodaj pierwsze urządzenie.</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

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
                    <tr><th class="text-muted">Nr karty SIM</th><td><?= h($device['sim_number'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Status</th><td><?= getStatusBadge($device['status'], 'device') ?></td></tr>
                    <tr><th class="text-muted">Data zakupu</th><td><?= formatDate($device['purchase_date']) ?></td></tr>
                    <tr><th class="text-muted">Cena zakupu</th><td><?= $device['purchase_price'] ? formatMoney($device['purchase_price']) : '—' ?></td></tr>
                </table>
                <?php if ($device['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= h($device['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <a href="devices.php?action=edit&id=<?= $device['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <a href="installations.php?action=add&device=<?= $device['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-car me-1"></i>Montaż</a>
                <a href="services.php?action=add&device=<?= $device['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-wrench me-1"></i>Serwis</a>
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
                    <label class="form-label">Numer karty SIM</label>
                    <input type="text" name="sim_number" class="form-control" value="<?= h($device['sim_number'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($device['status'] ?? 'nowy') === $s ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $s)) ?>
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
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
