<?php
/**
 * FleetLink Magazyn - Vehicle Management
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
$preClientId = (int)($_GET['client'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'vehicles.php'); }
    $postAction   = sanitize($_POST['action'] ?? '');
    $clientId     = (int)($_POST['client_id'] ?? 0) ?: null;
    $registration = strtoupper(sanitize($_POST['registration'] ?? ''));
    $make         = sanitize($_POST['make'] ?? '');
    $modelName    = sanitize($_POST['model_name'] ?? '');
    $year         = (int)($_POST['year'] ?? 0) ?: null;
    $vin          = strtoupper(sanitize($_POST['vin'] ?? ''));
    $notes        = sanitize($_POST['notes'] ?? '');
    $active       = isset($_POST['active']) ? 1 : 0;

    if ($postAction === 'add') {
        if (empty($registration)) { flashError('Numer rejestracyjny jest wymagany.'); redirect(getBaseUrl() . 'vehicles.php?action=add&client=' . ($clientId ?: '')); }
        $db->prepare("INSERT INTO vehicles (client_id, registration, make, model_name, year, vin, notes, active) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active]);
        flashSuccess("Pojazd $registration dodany.");
        redirect($clientId ? getBaseUrl() . 'clients.php?action=view&id=' . $clientId : getBaseUrl() . 'vehicles.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($registration) || !$editId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'vehicles.php?action=edit&id=' . $editId); }
        $db->prepare("UPDATE vehicles SET client_id=?, registration=?, make=?, model_name=?, year=?, vin=?, notes=?, active=? WHERE id=?")
           ->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active, $editId]);
        flashSuccess('Pojazd zaktualizowany.');
        redirect(getBaseUrl() . 'vehicles.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM vehicles WHERE id=?")->execute([$delId]);
            flashSuccess('Pojazd usunięty.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć pojazdu — posiada powiązane montaże.');
        }
        redirect(getBaseUrl() . 'vehicles.php');
    }
}

$vehicle = null;
if (in_array($action, ['edit','view']) && $id) {
    $stmt = $db->prepare("SELECT v.*, c.contact_name, c.company_name FROM vehicles v LEFT JOIN clients c ON c.id=v.client_id WHERE v.id=?");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch();
    if (!$vehicle) { flashError('Pojazd nie istnieje.'); redirect(getBaseUrl() . 'vehicles.php'); }
}

$clients = $db->query("SELECT id, contact_name, company_name, address, city, postal_code FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();

// Data for install modal
$vehAvailableModels = $db->query("
    SELECT m.id as model_id, m.name as model_name, mf.name as manufacturer_name,
           COUNT(d.id) as available_count
    FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id
    JOIN devices d ON d.model_id=m.id AND d.status IN ('nowy','sprawny')
    GROUP BY m.id HAVING available_count > 0 ORDER BY mf.name, m.name
")->fetchAll();
$vehAvailableDevices = $db->query("
    SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status IN ('nowy','sprawny') ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();
$vehUsers = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();

$vehicles = [];
if ($action === 'list') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT v.*, c.contact_name, c.company_name, 
                   COUNT(DISTINCT i.id) as installation_count
            FROM vehicles v
            LEFT JOIN clients c ON c.id=v.client_id
            LEFT JOIN installations i ON i.vehicle_id=v.id
            WHERE v.active=1";
    $params = [];
    if ($search) { $sql .= " AND (v.registration LIKE ? OR v.make LIKE ? OR v.model_name LIKE ? OR c.contact_name LIKE ?)"; $params = ["%$search%","%$search%","%$search%","%$search%"]; }
    $sql .= " GROUP BY v.id ORDER BY v.registration";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
}

$activePage = 'clients';
$pageTitle = 'Pojazdy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-car me-2 text-primary"></i>Pojazdy</h1>
    <?php if ($action === 'list'): ?>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#vehAddModal"><i class="fas fa-plus me-2"></i>Dodaj pojazd</button>
    <?php else: ?>
    <a href="vehicles.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (rejestracja, marka, klient...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Szukaj</button>
                <a href="vehicles.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Pojazdy (<?= count($vehicles) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Rejestracja</th><th>Marka / Model</th><th>Rok</th><th>Klient</th><th>Montaży</th><th>Akcje</th></tr></thead>
            <tbody>
                <?php foreach ($vehicles as $v): ?>
                <tr>
                    <td class="fw-bold"><?= h($v['registration']) ?></td>
                    <td><?= h(trim($v['make'] . ' ' . $v['model_name'])) ?: '—' ?></td>
                    <td><?= h($v['year'] ?? '—') ?></td>
                    <td><?= $v['contact_name'] ? h($v['company_name'] ?: $v['contact_name']) : '—' ?></td>
                    <td><?= $v['installation_count'] ?></td>
                    <td>
                        <a href="vehicles.php?action=edit&id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action veh-new-inst-btn" title="Nowy montaż" aria-label="Nowy montaż dla pojazdu <?= h($v['registration']) ?>" data-registration="<?= h($v['registration']) ?>"><i class="fas fa-plus"></i></button>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $v['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" data-confirm="Usuń pojazd <?= h($v['registration']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vehicles)): ?><tr><td colspan="6" class="text-center text-muted p-3">Brak pojazdów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:600px">
    <div class="card-header"><i class="fas fa-car me-2"></i><?= $action === 'add' ? 'Dodaj pojazd' : 'Edytuj pojazd' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $vehicle['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-star">Nr rejestracyjny</label>
                    <input type="text" name="registration" class="form-control text-uppercase" required value="<?= h($vehicle['registration'] ?? '') ?>" placeholder="np. WA12345">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Klient</label>
                    <select name="client_id" class="form-select">
                        <option value="">— brak przypisania —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($vehicle['client_id'] ?? $preClientId) == $c['id'] ? 'selected' : '' ?>>
                            <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Marka</label>
                    <input type="text" name="make" class="form-control" value="<?= h($vehicle['make'] ?? '') ?>" placeholder="np. Toyota">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Model pojazdu</label>
                    <input type="text" name="model_name" class="form-control" value="<?= h($vehicle['model_name'] ?? '') ?>" placeholder="np. Corolla">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rok produkcji</label>
                    <input type="number" name="year" class="form-control" value="<?= h($vehicle['year'] ?? '') ?>" min="1990" max="<?= date('Y') + 1 ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">VIN</label>
                    <input type="text" name="vin" class="form-control text-uppercase" value="<?= h($vehicle['vin'] ?? '') ?>" maxlength="17" placeholder="17-znakowy numer VIN">
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="2"><?= h($vehicle['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($vehicle['active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Aktywny</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz' ?></button>
                    <a href="vehicles.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- Modal: Dodaj pojazd -->
<div class="modal fade" id="vehAddModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="vehicles.php" id="vehAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-car me-2 text-primary"></i>Dodaj pojazd</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Nr rejestracyjny</label>
                            <input type="text" name="registration" class="form-control text-uppercase" required placeholder="np. WA12345">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Klient</label>
                            <select name="client_id" class="form-select">
                                <option value="">— brak przypisania —</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Marka</label>
                            <input type="text" name="make" class="form-control" placeholder="np. Toyota">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model pojazdu</label>
                            <input type="text" name="model_name" class="form-control" placeholder="np. Corolla">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Rok produkcji</label>
                            <input type="number" name="year" class="form-control" min="1990" max="<?= date('Y') + 1 ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">VIN</label>
                            <input type="text" name="vin" class="form-control text-uppercase" maxlength="17" placeholder="17-znakowy numer VIN">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="vehAddActive" checked><label class="form-check-label" for="vehAddActive">Aktywny</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Dodaj pojazd</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Nowy montaż (pojazdy) — wielourządzeniowy -->
<div class="modal fade" id="vehInstAddModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="installations.php" id="vehInstAddForm">
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
                            <div id="vehInstDevRowsContainer" class="d-flex flex-column gap-2 mb-2">
                                <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-auto"><span class="row-num badge bg-secondary">1</span></div>
                                        <div class="col-auto">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="vim_auto_0" value="auto" checked>
                                                <label class="btn btn-outline-secondary" for="vim_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="vim_manual_0" value="manual">
                                                <label class="btn btn-outline-primary" for="vim_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny</label>
                                            </div>
                                        </div>
                                        <div class="col col-mode-auto">
                                            <select name="model_id[0]" class="form-select form-select-sm">
                                                <option value="">— wybierz model —</option>
                                                <?php foreach ($vehAvailableModels as $m): ?>
                                                <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col col-mode-manual" style="display:none">
                                            <select name="device_id_manual[0]" class="form-select form-select-sm ts-device-veh">
                                                <option value="">— wybierz urządzenie —</option>
                                                <?php
                                                $vGrp0 = '';
                                                foreach ($vehAvailableDevices as $dev):
                                                    $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                                    if ($grp !== $vGrp0) { if ($vGrp0) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $vGrp0 = $grp; }
                                                ?>
                                                <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' ['.h($dev['imei']).']' : '' ?><?= $dev['sim_number'] ? ' ('.h($dev['sim_number']).')' : '' ?></option>
                                                <?php endforeach; if ($vGrp0) echo '</optgroup>'; ?>
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <input type="text" name="vehicle_registration[0]" id="vehInstReg0" class="form-control form-control-sm" required placeholder="Nr rej. pojazdu" style="text-transform:uppercase;min-width:130px">
                                        </div>
                                        <div class="col-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none" title="Usuń"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="vehInstAddRowBtn" class="btn btn-sm btn-outline-success"><i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie</button>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="vehInstClientSel" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($clients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="vehInstQCBtn" title="Dodaj nowego klienta"><i class="fas fa-user-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adres instalacji</label>
                            <input type="text" name="installation_address" id="vehInstAddrFld" class="form-control" placeholder="Automatycznie z klienta lub wpisz ręcznie">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($vehUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data montażu</label>
                            <input type="date" name="installation_date" id="vehInstDateFld" class="form-control" required value="<?= date('Y-m-d') ?>">
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
                        <div class="col-12"><label class="form-label">Uwagi</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-car me-1"></i>Zarejestruj montaż</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick-add klienta (pojazdy) -->
<div class="modal fade" id="vehInstQCModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="vehInstQCErr"></div>
                <div class="mb-2"><label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label><input type="text" id="vehInstQCName" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Nazwa firmy</label><input type="text" id="vehInstQCCompany" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Telefon</label><input type="text" id="vehInstQCPhone" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">E-mail</label><input type="email" id="vehInstQCEmail" class="form-control form-control-sm"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="vehInstQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<template id="vehInstDevRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center">
            <div class="col-auto"><span class="row-num badge bg-secondary">__NUM__</span></div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="vim_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="vim_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="vim_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="vim_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny</label>
                </div>
            </div>
            <div class="col col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($vehAvailableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm ts-device-veh">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $vTplGrp = '';
                    foreach ($vehAvailableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $vTplGrp) { if ($vTplGrp) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $vTplGrp = $grp; }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' ['.h($dev['imei']).']' : '' ?><?= $dev['sim_number'] ? ' ('.h($dev['sim_number']).')' : '' ?></option>
                    <?php endforeach; if ($vTplGrp) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-auto">
                <input type="text" name="vehicle_registration[__IDX__]" class="form-control form-control-sm" required placeholder="Nr rej. pojazdu" style="text-transform:uppercase;min-width:130px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" title="Usuń"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>

<script>
window.flVehDevices = <?= json_encode(array_values(array_map(function($d) {
    $t = $d['serial_number'];
    if ($d['imei'])       $t .= ' [' . $d['imei'] . ']';
    if ($d['sim_number']) $t .= ' (' . $d['sim_number'] . ')';
    return ['value' => (string)$d['id'], 'text' => $t];
}, $vehAvailableDevices))) ?>;
window.flVehClientAddresses = <?= json_encode(array_reduce($clients, function($c, $cl) {
    $parts = array_filter([$cl['address'] ?? '', trim(($cl['postal_code'] ?? '') . ' ' . ($cl['city'] ?? ''))]);
    $c[(string)$cl['id']] = implode(', ', $parts);
    return $c;
}, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
(function () {
    // Per-row install button: pre-fill registration
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.veh-new-inst-btn');
        if (!btn) return;
        var reg = (btn.dataset.registration || '').toUpperCase();
        var modal = new bootstrap.Modal(document.getElementById('vehInstAddModal'));
        document.getElementById('vehInstAddModal').addEventListener('shown.bs.modal', function setReg() {
            var inp = document.getElementById('vehInstReg0'); if (inp) inp.value = reg;
            document.getElementById('vehInstAddModal').removeEventListener('shown.bs.modal', setReg);
        });
        modal.show();
    });

    var container  = document.getElementById('vehInstDevRowsContainer');
    var addBtn     = document.getElementById('vehInstAddRowBtn');
    var rowCounter = 1;
    if (!container || !addBtn) return;

    function vhSyncDropdowns() {
        var rows = Array.from(container.querySelectorAll('.device-row'));
        var rowVals = new Map();
        rows.forEach(function(row){var sel=row.querySelector('select.ts-device-veh'); if(!sel||!sel.tomselect) return; rowVals.set(row,sel.tomselect.getValue()||'');});
        rows.forEach(function(row){
            var sel=row.querySelector('select.ts-device-veh'); if(!sel||!sel.tomselect) return;
            var ts=sel.tomselect,myVal=rowVals.get(row)||'';
            var taken=new Set(); rowVals.forEach(function(v,r){if(r!==row&&v)taken.add(v);});
            (window.flVehDevices||[]).forEach(function(dev){
                if(taken.has(dev.value)){if(ts.options[dev.value])ts.removeOption(dev.value);}
                else{if(!ts.options[dev.value])ts.addOption({value:dev.value,text:dev.text});}
            });
            ts.refreshOptions(false); if(myVal&&ts.options[myVal])ts.setValue(myVal,true);
        });
    }
    function vhInitTS(row){row.querySelectorAll('select.ts-device-veh').forEach(function(sel){if(sel.tomselect||typeof TomSelect==='undefined')return;new TomSelect(sel,{placeholder:'— szukaj —',allowEmptyOption:true,maxOptions:null,searchField:['text','value']});});}
    function vhDestroyTS(row){row.querySelectorAll('select.ts-device-veh').forEach(function(sel){if(sel.tomselect)sel.tomselect.destroy();});}
    function vhApplyMode(row,mode){
        var ac=row.querySelector('.col-mode-auto'),mc=row.querySelector('.col-mode-manual');
        if(ac)ac.style.display=mode==='auto'?'':'none'; if(mc)mc.style.display=mode==='manual'?'':'none';
        if(mode==='manual'){vhInitTS(row);vhSyncDropdowns();}
    }
    function vhUpdateNums(){
        var rows=container.querySelectorAll('.device-row');
        rows.forEach(function(row,i){var n=row.querySelector('.row-num');if(n)n.textContent=i+1;var b=row.querySelector('.remove-row-btn');if(b)b.style.display=rows.length>1?'':'none';});
    }
    container.addEventListener('change',function(e){
        if(e.target.type==='radio'&&(e.target.name||'').startsWith('device_mode'))vhApplyMode(e.target.closest('.device-row'),e.target.value);
        if(e.target.classList.contains('ts-device-veh')||e.target.closest('select.ts-device-veh'))vhSyncDropdowns();
    });
    container.addEventListener('click',function(e){
        var btn=e.target.closest('.remove-row-btn');
        if(btn){var row=btn.closest('.device-row');vhDestroyTS(row);row.remove();vhUpdateNums();vhSyncDropdowns();}
    });
    addBtn.addEventListener('click',function(){
        var tpl=document.getElementById('vehInstDevRowTemplate');if(!tpl)return;
        var idx=rowCounter++,clone=tpl.content.cloneNode(true);
        clone.querySelectorAll('[name]').forEach(function(el){el.name=el.name.replace(/__IDX__/g,idx);});
        clone.querySelectorAll('[id]').forEach(function(el){el.id=el.id.replace(/__IDX__/g,idx);});
        clone.querySelectorAll('[for]').forEach(function(el){el.htmlFor=el.htmlFor.replace(/__IDX__/g,idx);});
        container.appendChild(clone); vhUpdateNums();
    });
    var modal=document.getElementById('vehInstAddModal');
    if(modal){
        modal.addEventListener('show.bs.modal',function(){
            Array.from(container.querySelectorAll('.device-row')).forEach(function(row,i){if(i>0){vhDestroyTS(row);row.remove();}});
            rowCounter=1;
            var fr=container.querySelector('.device-row');
            if(fr){var ar=fr.querySelector('input[value="auto"]');if(ar){ar.checked=true;vhApplyMode(fr,'auto');}var ms=fr.querySelector('select[name="model_id[0]"]');if(ms)ms.value='';vhDestroyTS(fr);}
            document.getElementById('vehInstClientSel').value='';
            document.getElementById('vehInstAddrFld').value='';
            document.getElementById('vehInstDateFld').value=new Date().toISOString().slice(0,10);
            vhUpdateNums();
        });
    }
    var cliSel=document.getElementById('vehInstClientSel');
    if(cliSel)cliSel.addEventListener('change',function(){
        var v=this.value,addr=document.getElementById('vehInstAddrFld');
        if(addr)addr.value=(v&&window.flVehClientAddresses&&window.flVehClientAddresses[v])?window.flVehClientAddresses[v]:'';
    });
    var qcBtn=document.getElementById('vehInstQCBtn');
    if(qcBtn)qcBtn.addEventListener('click',function(){
        ['vehInstQCName','vehInstQCCompany','vehInstQCPhone','vehInstQCEmail'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
        var err=document.getElementById('vehInstQCErr');if(err)err.classList.add('d-none');
        new bootstrap.Modal(document.getElementById('vehInstQCModal')).show();
    });
    var qcSave=document.getElementById('vehInstQCSaveBtn');
    if(qcSave)qcSave.addEventListener('click',function(){
        var name=(document.getElementById('vehInstQCName').value||'').trim();
        var errEl=document.getElementById('vehInstQCErr');
        if(!name){errEl.textContent='Imię i nazwisko kontaktu jest wymagane.';errEl.classList.remove('d-none');return;}
        errEl.classList.add('d-none');
        var fd=new FormData(); fd.append('action','quick_add_client'); fd.append('csrf_token',document.querySelector('#vehInstAddForm input[name="csrf_token"]').value); fd.append('contact_name',name); fd.append('company_name',document.getElementById('vehInstQCCompany').value); fd.append('phone',document.getElementById('vehInstQCPhone').value); fd.append('email',document.getElementById('vehInstQCEmail').value);
        fetch('installations.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if(data.error){errEl.textContent=data.error;errEl.classList.remove('d-none');return;}
            var sel=document.getElementById('vehInstClientSel'); var opt=document.createElement('option'); opt.value=data.id; opt.textContent=data.label; opt.selected=true; sel.appendChild(opt); sel.dispatchEvent(new Event('change'));
            bootstrap.Modal.getInstance(document.getElementById('vehInstQCModal')).hide();
        }).catch(function(){errEl.textContent='Błąd połączenia z serwerem.';errEl.classList.remove('d-none');});
    });
    container.querySelectorAll('.device-row').forEach(function(row){var c=row.querySelector('.btn-check:checked');if(c)vhApplyMode(row,c.value);});
    vhUpdateNums();
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
