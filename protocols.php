<?php
/**
 * FleetLink Magazyn - Protocols Management (PP=Przekazanie, PU=Uruchomienie, PS=Serwisowy)
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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'protocols.php'); }
    $postAction     = sanitize($_POST['action'] ?? '');
    $installationId = (int)($_POST['installation_id'] ?? 0) ?: null;
    $serviceId      = (int)($_POST['service_id'] ?? 0) ?: null;
    $type           = sanitize($_POST['type'] ?? 'PP');
    $date           = sanitize($_POST['date'] ?? date('Y-m-d'));
    $technicianId   = (int)($_POST['technician_id'] ?? 0) ?: getCurrentUser()['id'];
    $notes          = sanitize($_POST['notes'] ?? '');
    $clientSignature = sanitize($_POST['client_signature'] ?? '');

    $validTypes = ['PP','PU','PS'];
    if (!in_array($type, $validTypes)) $type = 'PP';

    if ($postAction === 'add') {
        $protocolNum = generateProtocolNumber($type);
        $db->prepare("INSERT INTO protocols (installation_id, service_id, type, protocol_number, date, technician_id, client_signature, notes) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$installationId, $serviceId, $type, $protocolNum, $date, $technicianId, $clientSignature, $notes]);
        $newId = $db->lastInsertId();
        flashSuccess("Protokół $protocolNum został utworzony.");
        redirect(getBaseUrl() . 'protocols.php?action=view&id=' . $newId);

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE protocols SET installation_id=?, service_id=?, date=?, technician_id=?, client_signature=?, notes=? WHERE id=?")
           ->execute([$installationId, $serviceId, $date, $technicianId, $clientSignature, $notes, $editId]);
        flashSuccess('Protokół zaktualizowany.');
        redirect(getBaseUrl() . 'protocols.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM protocols WHERE id=?")->execute([$delId]);
        flashSuccess('Protokół usunięty.');
        redirect(getBaseUrl() . 'protocols.php');
    }
}

$protocol = null;
$protocolData = [];
if (in_array($action, ['view','edit','print']) && $id) {
    $stmt = $db->prepare("
        SELECT p.*,
               u.name as technician_name,
               i.installation_date, i.location_in_vehicle,
               d.serial_number, d.imei,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make, v.model_name as vehicle_model, v.vin,
               c.contact_name, c.company_name, c.nip
        FROM protocols p
        LEFT JOIN users u ON u.id=p.technician_id
        LEFT JOIN installations i ON i.id=p.installation_id
        LEFT JOIN devices d ON d.id=i.device_id
        LEFT JOIN models m ON m.id=d.model_id
        LEFT JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=v.client_id
        WHERE p.id=?
    ");
    $stmt->execute([$id]);
    $protocol = $stmt->fetch();
    if (!$protocol) { flashError('Protokół nie istnieje.'); redirect(getBaseUrl() . 'protocols.php'); }
}

$users = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$activeInstallations = $db->query("
    SELECT i.id, v.registration, d.serial_number
    FROM installations i
    JOIN vehicles v ON v.id=i.vehicle_id
    JOIN devices d ON d.id=i.device_id
    WHERE i.status IN ('aktywna','zakonczona')
    ORDER BY i.installation_date DESC
    LIMIT 50
")->fetchAll();
$recentServices = $db->query("
    SELECT s.id, d.serial_number, s.planned_date, s.type
    FROM services s
    JOIN devices d ON d.id=s.device_id
    ORDER BY s.created_at DESC
    LIMIT 50
")->fetchAll();

$settings = [];
foreach ($db->query("SELECT `key`, `value` FROM settings")->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

if ($action === 'print' && $protocol) {
    include __DIR__ . '/includes/protocol_print.php';
    exit;
}

$preType = sanitize($_GET['type'] ?? 'PP');
if (!in_array($preType, ['PP','PU','PS'])) $preType = 'PP';

$protocols = [];
if ($action === 'list') {
    $protocols = $db->query("
        SELECT p.*, u.name as technician_name, v.registration, d.serial_number
        FROM protocols p
        LEFT JOIN users u ON u.id=p.technician_id
        LEFT JOIN installations i ON i.id=p.installation_id
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN devices d ON d.id=i.device_id
        ORDER BY p.date DESC, p.id DESC
    ")->fetchAll();
}

$activePage = 'offers';
$pageTitle = 'Protokoły';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-clipboard-check me-2 text-primary"></i>Protokoły</h1>
    <?php if ($action === 'list'): ?>
    <a href="protocols.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowy protokół</a>
    <?php else: ?>
    <a href="protocols.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">Protokoły (<?= count($protocols) ?>)</div>
            <div class="col-auto small text-muted">PP = Przekazania &nbsp;|&nbsp; PU = Uruchomienia &nbsp;|&nbsp; PS = Serwisowy</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Nr protokołu</th><th>Typ</th><th>Data</th><th>Pojazd</th><th>Urządzenie</th><th>Technik</th><th>Akcje</th></tr></thead>
            <tbody>
                <?php foreach ($protocols as $p): ?>
                <tr>
                    <td class="fw-bold"><a href="protocols.php?action=view&id=<?= $p['id'] ?>"><?= h($p['protocol_number']) ?></a></td>
                    <td>
                        <?php
                        $typeLabel = ['PP' => 'Przekazania', 'PU' => 'Uruchomienia', 'PS' => 'Serwisowy'];
                        $typeColor = ['PP' => 'primary', 'PU' => 'success', 'PS' => 'warning'];
                        ?>
                        <span class="badge bg-<?= $typeColor[$p['type']] ?? 'secondary' ?>"><?= $typeLabel[$p['type']] ?? h($p['type']) ?></span>
                    </td>
                    <td><?= formatDate($p['date']) ?></td>
                    <td><?= h($p['registration'] ?? '—') ?></td>
                    <td><?= h($p['serial_number'] ?? '—') ?></td>
                    <td><?= h($p['technician_name'] ?? '—') ?></td>
                    <td>
                        <a href="protocols.php?action=view&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="protocols.php?action=print&id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary btn-action"><i class="fas fa-print"></i></a>
                        <a href="protocols.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń protokół <?= h($p['protocol_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($protocols)): ?><tr><td colspan="7" class="text-center text-muted p-3">Brak protokołów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && $protocol): ?>
<div class="row g-3">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Protokół <?= h($protocol['protocol_number']) ?></div>
            <div class="card-body">
                <?php
                $typeLabel = ['PP' => '📋 Przekazania', 'PU' => '✅ Uruchomienia', 'PS' => '🔧 Serwisowy'];
                ?>
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted">Typ</th><td><strong><?= $typeLabel[$protocol['type']] ?? h($protocol['type']) ?></strong></td></tr>
                    <tr><th class="text-muted">Nr protokołu</th><td><?= h($protocol['protocol_number']) ?></td></tr>
                    <tr><th class="text-muted">Data</th><td><?= formatDate($protocol['date']) ?></td></tr>
                    <tr><th class="text-muted">Technik</th><td><?= h($protocol['technician_name'] ?? '—') ?></td></tr>
                    <?php if ($protocol['registration']): ?>
                    <tr><th class="text-muted">Pojazd</th><td><?= h($protocol['registration'] . ' ' . $protocol['make'] . ' ' . $protocol['vehicle_model']) ?></td></tr>
                    <tr><th class="text-muted">VIN</th><td><?= h($protocol['vin'] ?? '—') ?></td></tr>
                    <?php endif; ?>
                    <?php if ($protocol['serial_number']): ?>
                    <tr><th class="text-muted">Urządzenie</th><td><?= h($protocol['manufacturer_name'] . ' ' . $protocol['model_name']) ?><br><small><?= h($protocol['serial_number']) ?></small></td></tr>
                    <tr><th class="text-muted">IMEI</th><td><?= h($protocol['imei'] ?? '—') ?></td></tr>
                    <?php endif; ?>
                    <?php if ($protocol['contact_name']): ?>
                    <tr><th class="text-muted">Klient</th><td><?= h($protocol['company_name'] ?: $protocol['contact_name']) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php if ($protocol['notes']): ?>
                <hr><strong class="small">Uwagi:</strong><p class="small text-muted mt-1"><?= h($protocol['notes']) ?></p>
                <?php endif; ?>
                <?php if ($protocol['client_signature']): ?>
                <hr><strong class="small">Podpis klienta:</strong><p class="small mt-1"><?= h($protocol['client_signature']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <a href="protocols.php?action=print&id=<?= $protocol['id'] ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print me-1"></i>Drukuj</a>
                <a href="protocols.php?action=edit&id=<?= $protocol['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <?php if (isAdmin()): ?>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $protocol['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            data-confirm="Usuń protokół <?= h($protocol['protocol_number']) ?>? Tej operacji nie można cofnąć.">
                        <i class="fas fa-trash me-1"></i>Usuń
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:600px">
    <div class="card-header"><i class="fas fa-clipboard me-2"></i><?= $action === 'add' ? 'Nowy protokół' : 'Edytuj protokół: ' . h($protocol['protocol_number'] ?? '') ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $protocol['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <?php if ($action === 'add'): ?>
                <div class="col-md-6">
                    <label class="form-label required-star">Typ protokołu</label>
                    <select name="type" class="form-select" required>
                        <option value="PP" <?= $preType === 'PP' ? 'selected' : '' ?>>PP — Protokół Przekazania</option>
                        <option value="PU" <?= $preType === 'PU' ? 'selected' : '' ?>>PU — Protokół Uruchomienia</option>
                        <option value="PS" <?= $preType === 'PS' ? 'selected' : '' ?>>PS — Protokół Serwisowy</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label required-star">Data</label>
                    <input type="date" name="date" class="form-control" required value="<?= h($protocol['date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Powiązany montaż</label>
                    <select name="installation_id" class="form-select">
                        <option value="">— brak —</option>
                        <?php foreach ($activeInstallations as $inst): ?>
                        <option value="<?= $inst['id'] ?>"
                                <?= ($protocol['installation_id'] ?? (int)($_GET['installation'] ?? 0)) == $inst['id'] ? 'selected' : '' ?>>
                            <?= h($inst['registration'] . ' — ' . $inst['serial_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Powiązany serwis</label>
                    <select name="service_id" class="form-select">
                        <option value="">— brak —</option>
                        <?php foreach ($recentServices as $svc): ?>
                        <option value="<?= $svc['id'] ?>"
                                <?= ($protocol['service_id'] ?? (int)($_GET['service'] ?? 0)) == $svc['id'] ? 'selected' : '' ?>>
                            <?= h($svc['serial_number'] . ' — ' . ucfirst($svc['type']) . ' ' . formatDate($svc['planned_date'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Technik</label>
                    <select name="technician_id" class="form-select">
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($protocol['technician_id'] ?? getCurrentUser()['id']) == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi / Zakres prac</label>
                    <textarea name="notes" class="form-control" rows="4"><?= h($protocol['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Podpis klienta (imię i nazwisko)</label>
                    <input type="text" name="client_signature" class="form-control" value="<?= h($protocol['client_signature'] ?? '') ?>" placeholder="np. Jan Kowalski">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Utwórz protokół' : 'Zapisz zmiany' ?></button>
                    <a href="protocols.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
