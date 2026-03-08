<?php
/**
 * FleetLink Magazyn - SIM Card Management
 * Manages SIM phone numbers assigned to devices.
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
$id     = (int)($_GET['id'] ?? 0);   // device id when editing SIM

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }
    $postAction = sanitize($_POST['action'] ?? '');
    $deviceId   = (int)($_POST['device_id'] ?? 0);
    $simNumber  = sanitize($_POST['sim_number'] ?? '');

    if ($postAction === 'assign') {
        // Assign or update a SIM number on an existing device
        if (!$deviceId || empty($simNumber)) {
            flashError('Urządzenie i numer telefonu SIM są wymagane.');
            redirect(getBaseUrl() . 'sim_cards.php?action=add');
        }
        $db->prepare("UPDATE devices SET sim_number=? WHERE id=?")->execute([$simNumber, $deviceId]);
        flashSuccess('Numer SIM został przypisany do urządzenia.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }

    if ($postAction === 'remove') {
        // Clear the SIM number from the device
        if ($deviceId) {
            $db->prepare("UPDATE devices SET sim_number=NULL WHERE id=?")->execute([$deviceId]);
            flashSuccess('Numer SIM został usunięty z urządzenia.');
        }
        redirect(getBaseUrl() . 'sim_cards.php');
    }

    if ($postAction === 'edit_sim') {
        $editId    = (int)($_POST['id'] ?? 0);
        $simNumber = sanitize($_POST['sim_number'] ?? '');
        if ($editId) {
            $db->prepare("UPDATE devices SET sim_number=? WHERE id=?")->execute([$simNumber ?: null, $editId]);
            flashSuccess('Numer SIM zaktualizowany.');
        }
        redirect(getBaseUrl() . 'sim_cards.php');
    }
}

// Fetch device for edit
$editDevice = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT d.id, d.serial_number, d.sim_number, m.name as model_name, mf.name as manufacturer_name
                          FROM devices d
                          JOIN models m ON m.id=d.model_id
                          JOIN manufacturers mf ON mf.id=m.manufacturer_id
                          WHERE d.id=?");
    $stmt->execute([$id]);
    $editDevice = $stmt->fetch();
    if (!$editDevice) {
        flashError('Urządzenie nie istnieje.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }
}

// Devices with SIM numbers (list)
$search = sanitize($_GET['search'] ?? '');
$sqlList = "
    SELECT d.id, d.serial_number, d.imei, d.sim_number, d.status,
           m.name as model_name, mf.name as manufacturer_name,
           v.registration as vehicle_registration,
           c.contact_name, c.company_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    LEFT JOIN installations i ON i.device_id=d.id AND i.status='aktywna'
    LEFT JOIN vehicles v ON v.id=i.vehicle_id
    LEFT JOIN clients c ON c.id=i.client_id
    WHERE d.sim_number IS NOT NULL AND d.sim_number != ''
";
$params = [];
if ($search) {
    $sqlList .= " AND (d.sim_number LIKE ? OR d.serial_number LIKE ? OR m.name LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%"];
}
$sqlList .= " ORDER BY d.sim_number";
$simDevicesStmt = $db->prepare($sqlList);
$simDevicesStmt->execute($params);
$simDevices = $simDevicesStmt->fetchAll();

// All devices without a SIM number (for the "assign" form)
$devicesWithoutSim = $db->query("
    SELECT d.id, d.serial_number, d.imei, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE (d.sim_number IS NULL OR d.sim_number = '')
      AND d.status NOT IN ('wycofany','sprzedany')
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

// All devices (for edit/assign — include already-with-SIM to allow overwriting)
$allDevices = $db->query("
    SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status NOT IN ('wycofany','sprzedany')
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

$activePage = 'sim_cards';
$pageTitle  = 'Karty SIM';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-sim-card me-2 text-primary"></i>Karty SIM</h1>
    <?php if ($action === 'list'): ?>
    <a href="sim_cards.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Przypisz kartę SIM</a>
    <?php else: ?>
    <a href="sim_cards.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj po numerze SIM, nr seryjnym, modelu..."
                       value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Szukaj</button>
                <a href="sim_cards.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Karty SIM przypisane do urządzeń (<?= count($simDevices) ?>)</span>
        <a href="sim_cards.php?action=add" class="btn btn-sm btn-primary">
            <i class="fas fa-plus me-1"></i>Przypisz kartę SIM
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr telefonu SIM</th>
                    <th>Urządzenie</th>
                    <th>Model</th>
                    <th>Status</th>
                    <th>Pojazd</th>
                    <th>Klient</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($simDevices as $d): ?>
                <tr>
                    <td class="fw-semibold"><?= h($d['sim_number']) ?></td>
                    <td>
                        <a href="devices.php?action=view&id=<?= $d['id'] ?>">
                            <?= h($d['serial_number']) ?>
                        </a>
                        <?php if ($d['imei']): ?><br><small class="text-muted"><?= h($d['imei']) ?></small><?php endif; ?>
                    </td>
                    <td><?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?></td>
                    <td><?= getStatusBadge($d['status'], 'device') ?></td>
                    <td><?= $d['vehicle_registration'] ? h($d['vehicle_registration']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= ($d['company_name'] ?: $d['contact_name']) ? h($d['company_name'] ?: $d['contact_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <a href="sim_cards.php?action=edit&id=<?= $d['id'] ?>"
                           class="btn btn-sm btn-outline-primary btn-action" title="Edytuj numer SIM">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Odpiąć kartę SIM od urządzenia <?= h($d['serial_number']) ?>?"
                                    title="Odepnij kartę SIM"><i class="fas fa-unlink"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($simDevices)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted p-3">
                        Brak urządzeń z przypisaną kartą SIM.
                        <a href="sim_cards.php?action=add">Przypisz pierwszą kartę SIM.</a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'add'): ?>

<div class="card" style="max-width:600px">
    <div class="card-header"><i class="fas fa-plus me-2"></i>Przypisz kartę SIM do urządzenia</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="assign">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label required-star">Urządzenie</label>
                    <select name="device_id" class="form-select" id="simDeviceSelect" required>
                        <option value="">— wybierz urządzenie —</option>
                        <?php
                        $grp = '';
                        foreach ($allDevices as $dev):
                            $g = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                            if ($g !== $grp) {
                                if ($grp) echo '</optgroup>';
                                echo '<optgroup label="' . h($g) . '">';
                                $grp = $g;
                            }
                        ?>
                        <option value="<?= $dev['id'] ?>">
                            <?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?>
                            <?= $dev['sim_number'] ? ' — SIM: ' . h($dev['sim_number']) : '' ?>
                        </option>
                        <?php endforeach; if ($grp) echo '</optgroup>'; ?>
                    </select>
                    <div class="form-text">Urządzenia ze statusem wycofany/sprzedany nie są wyświetlane.</div>
                </div>
                <div class="col-12">
                    <label class="form-label required-star">Numer telefonu karty SIM</label>
                    <input type="text" name="sim_number" class="form-control" required
                           placeholder="+48 123 456 789" maxlength="30">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Przypisz SIM</button>
                    <a href="sim_cards.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('simDeviceSelect');
    if (sel && typeof TomSelect !== 'undefined') {
        new TomSelect(sel, { maxOptions: null });
    }
});
</script>

<?php elseif ($action === 'edit' && $editDevice): ?>

<div class="card" style="max-width:500px">
    <div class="card-header"><i class="fas fa-edit me-2"></i>Edytuj numer SIM — <?= h($editDevice['serial_number']) ?></div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Urządzenie: <strong><?= h($editDevice['manufacturer_name'] . ' ' . $editDevice['model_name']) ?></strong><br>
            Nr seryjny: <strong><?= h($editDevice['serial_number']) ?></strong>
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_sim">
            <input type="hidden" name="id" value="<?= $editDevice['id'] ?>">
            <div class="mb-3">
                <label class="form-label">Numer telefonu karty SIM</label>
                <input type="text" name="sim_number" class="form-control"
                       value="<?= h($editDevice['sim_number'] ?? '') ?>"
                       placeholder="+48 123 456 789" maxlength="30">
                <div class="form-text">Pozostaw puste, aby odpiąć kartę SIM.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz</button>
            <a href="sim_cards.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
        </form>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
