<?php
/**
 * FleetLink Magazyn - SIM Card Management
 * Manages SIM cards — both standalone entries and those linked to devices.
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
$id     = (int)($_GET['id'] ?? 0);   // sim_card id

// Check if sim_cards table exists; if not, show a notice and skip all queries
$simCardsTableExists = false;
try {
    $db->query("SELECT 1 FROM sim_cards LIMIT 1");
    $simCardsTableExists = true;
} catch (PDOException $e) {
    // Table not yet created — migration needs to be run
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }
    $postAction = sanitize($_POST['action'] ?? '');
    $simId      = (int)($_POST['sim_id'] ?? 0);
    $deviceIdRaw = (int)($_POST['device_id'] ?? 0);
    $deviceId   = $deviceIdRaw > 0 ? $deviceIdRaw : null;
    $simNumber  = sanitize($_POST['phone_number'] ?? $_POST['sim_number'] ?? '');
    $operator   = sanitize($_POST['operator'] ?? '');
    $iccid      = sanitize($_POST['iccid'] ?? '');
    $notes      = sanitize($_POST['notes'] ?? '');

    if ($postAction === 'add') {
        if (!isAdmin()) { flashError('Dodawanie kart SIM jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'sim_cards.php'); }
        // Create a new SIM card entry (device optional)
        if (empty($simNumber)) {
            flashError('Numer telefonu SIM jest wymagany.');
            redirect(getBaseUrl() . 'sim_cards.php?action=add');
        }
        $db->prepare("INSERT INTO sim_cards (phone_number, device_id, operator, iccid, notes) VALUES (?,?,?,?,?)")
           ->execute([$simNumber, $deviceId, $operator ?: null, $iccid ?: null, $notes ?: null]);
        // Sync with devices.sim_number if device was selected
        if ($deviceId) {
            $db->prepare("UPDATE devices SET sim_number=? WHERE id=?")->execute([$simNumber, $deviceId]);
        }
        flashSuccess('Karta SIM została dodana.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }

    if ($postAction === 'bulk_add_sims') {
        if (!isAdmin()) { flashError('Dodawanie kart SIM jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'sim_cards.php'); }

        $sharedOperator = sanitize($_POST['operator'] ?? '');

        $phoneNumbers = $_POST['phone_numbers'] ?? [];
        $iccids       = $_POST['iccids'] ?? [];
        $deviceIds    = $_POST['device_ids_sim'] ?? [];
        $notesList    = $_POST['notes_list'] ?? [];

        $added  = 0;
        $errors = [];
        foreach ($phoneNumbers as $i => $rawPhone) {
            $phone = sanitize($rawPhone);
            if (empty($phone)) continue;
            $rowIccid    = sanitize($iccids[$i]    ?? '');
            $rowDeviceId = (int)($deviceIds[$i]    ?? 0) ?: null;
            $rowNotes    = sanitize($notesList[$i] ?? '');
            try {
                $db->prepare("INSERT INTO sim_cards (phone_number, device_id, operator, iccid, notes) VALUES (?,?,?,?,?)")
                   ->execute([$phone, $rowDeviceId, $sharedOperator ?: null, $rowIccid ?: null, $rowNotes ?: null]);
                if ($rowDeviceId) {
                    $db->prepare("UPDATE devices SET sim_number=? WHERE id=?")->execute([$phone, $rowDeviceId]);
                }
                $added++;
            } catch (PDOException $e) {
                $errors[] = $phone;
            }
        }

        if ($added > 0) {
            $n = $added;
            if ($n === 1) $label = '1 karta SIM';
            elseif ($n <= 4) $label = $n . ' karty SIM';
            else $label = $n . ' kart SIM';
            $msg = 'Dodano ' . $label . '.';
            if (!empty($errors)) $msg .= ' Błąd dla: ' . implode(', ', $errors) . ' (duplikat numeru?).';
            flashSuccess($msg);
        } else {
            flashError('Nie dodano żadnej karty SIM. Sprawdź czy numery nie są duplikatami.');
        }
        redirect(getBaseUrl() . 'sim_cards.php');
    }

    if ($postAction === 'edit') {
        if (empty($simNumber)) {
            flashError('Numer telefonu SIM jest wymagany.');
            redirect(getBaseUrl() . 'sim_cards.php?action=edit&id=' . $simId);
        }
        // Fetch old device_id to handle device sync
        $old = $db->prepare("SELECT device_id, phone_number FROM sim_cards WHERE id=?");
        $old->execute([$simId]);
        $oldRow = $old->fetch();
        // Update sim_card
        $db->prepare("UPDATE sim_cards SET phone_number=?, device_id=?, operator=?, iccid=?, notes=? WHERE id=?")
           ->execute([$simNumber, $deviceId, $operator ?: null, $iccid ?: null, $notes ?: null, $simId]);
        // Sync devices.sim_number: clear old device link
        if ($oldRow && $oldRow['device_id'] && $oldRow['device_id'] !== $deviceId) {
            $db->prepare("UPDATE devices SET sim_number=NULL WHERE id=? AND sim_number=?")
               ->execute([$oldRow['device_id'], $oldRow['phone_number']]);
        }
        // Set new device link
        if ($deviceId) {
            $db->prepare("UPDATE devices SET sim_number=? WHERE id=?")->execute([$simNumber, $deviceId]);
        }
        flashSuccess('Karta SIM zaktualizowana.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }

    if ($postAction === 'delete') {
        $delId = (int)($_POST['sim_id'] ?? 0);
        $delRow = $db->prepare("SELECT device_id, phone_number FROM sim_cards WHERE id=?");
        $delRow->execute([$delId]);
        $delData = $delRow->fetch();
        $db->prepare("DELETE FROM sim_cards WHERE id=?")->execute([$delId]);
        // Clear devices.sim_number for the linked device
        if ($delData && $delData['device_id']) {
            $db->prepare("UPDATE devices SET sim_number=NULL WHERE id=? AND sim_number=?")
               ->execute([$delData['device_id'], $delData['phone_number']]);
        }
        flashSuccess('Karta SIM została usunięta.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }

    // Legacy: handle old-style device-based remove (from devices still linked without sim_card entry)
    if ($postAction === 'remove_device_sim') {
        $devId = (int)($_POST['device_id'] ?? 0);
        if ($devId) {
            $db->prepare("UPDATE devices SET sim_number=NULL WHERE id=?")->execute([$devId]);
            flashSuccess('Numer SIM został usunięty z urządzenia.');
        }
        redirect(getBaseUrl() . 'sim_cards.php');
    }
}

// Fetch SIM card for edit
$editSim = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT sc.*, d.serial_number, m.name as model_name, mf.name as manufacturer_name
                          FROM sim_cards sc
                          LEFT JOIN devices d ON d.id=sc.device_id
                          LEFT JOIN models m ON m.id=d.model_id
                          LEFT JOIN manufacturers mf ON mf.id=m.manufacturer_id
                          WHERE sc.id=?");
    $stmt->execute([$id]);
    $editSim = $stmt->fetch();
    if (!$editSim) {
        flashError('Karta SIM nie istnieje.');
        redirect(getBaseUrl() . 'sim_cards.php');
    }
}

// ── List query ─────────────────────────────────────────────────────────────────
// Show sim_cards table entries + devices with sim_number not yet in sim_cards
$search = sanitize($_GET['search'] ?? '');

$params = [];
// Main list: sim_cards table
$simCardsRows = [];
$legacyRows   = [];
$params = [];

if ($simCardsTableExists) {
    $sqlMain = "
        SELECT sc.id as sim_id, sc.phone_number as sim_number, sc.operator, sc.iccid,
               sc.device_id,
               d.id as device_table_id, d.serial_number, d.imei, d.status as device_status,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration as vehicle_registration,
               c.contact_name, c.company_name,
               'sim_card' as row_source
        FROM sim_cards sc
        LEFT JOIN devices d ON d.id=sc.device_id
        LEFT JOIN models m ON m.id=d.model_id
        LEFT JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN installations i ON i.device_id=d.id AND i.status='aktywna'
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        WHERE sc.active=1
    ";
    if ($search) {
        $sqlMain .= " AND (sc.phone_number LIKE ? OR sc.operator LIKE ? OR d.serial_number LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }

    // Legacy: devices with sim_number that are NOT yet in sim_cards
    $paramsLegacy = [];
    $sqlLegacy = "
        SELECT NULL as sim_id, d.sim_number as sim_number, NULL as operator, NULL as iccid,
               d.id as device_id,
               d.id as device_table_id, d.serial_number, d.imei, d.status as device_status,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration as vehicle_registration,
               c.contact_name, c.company_name,
               'device' as row_source
        FROM devices d
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN installations i ON i.device_id=d.id AND i.status='aktywna'
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        WHERE d.sim_number IS NOT NULL AND d.sim_number != ''
          AND d.id NOT IN (SELECT device_id FROM sim_cards WHERE device_id IS NOT NULL)
    ";
    if ($search) {
        $sqlLegacy .= " AND (d.sim_number LIKE ? OR d.serial_number LIKE ? OR m.name LIKE ?)";
        $paramsLegacy = ["%$search%", "%$search%", "%$search%"];
    }

    $simStmt = $db->prepare($sqlMain . " ORDER BY sc.created_at DESC");
    $simStmt->execute($params);
    $simCardsRows = $simStmt->fetchAll();

    $legacyStmt = $db->prepare($sqlLegacy . " ORDER BY d.sim_number");
    $legacyStmt->execute($paramsLegacy);
    $legacyRows = $legacyStmt->fetchAll();
} else {
    // sim_cards table not yet created — show only legacy device SIM numbers
    $paramsLegacy = [];
    $sqlLegacy = "
        SELECT NULL as sim_id, d.sim_number as sim_number, NULL as operator, NULL as iccid,
               d.id as device_id,
               d.id as device_table_id, d.serial_number, d.imei, d.status as device_status,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration as vehicle_registration,
               c.contact_name, c.company_name,
               'device' as row_source
        FROM devices d
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN installations i ON i.device_id=d.id AND i.status='aktywna'
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        WHERE d.sim_number IS NOT NULL AND d.sim_number != ''
    ";
    if ($search) {
        $sqlLegacy .= " AND (d.sim_number LIKE ? OR d.serial_number LIKE ? OR m.name LIKE ?)";
        $paramsLegacy = ["%$search%", "%$search%", "%$search%"];
    }
    $legacyStmt = $db->prepare($sqlLegacy . " ORDER BY d.sim_number");
    $legacyStmt->execute($paramsLegacy);
    $legacyRows = $legacyStmt->fetchAll();
}

$allSimRows = array_merge($simCardsRows, $legacyRows);

// All devices (for add/edit selects)
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
    <?php if (isAdmin()): ?>
    <a href="sim_cards.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj kartę SIM</a>
    <?php endif; ?>
    <?php else: ?>
    <a href="sim_cards.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>

<?php if (!$simCardsTableExists): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Tabela kart SIM nie istnieje.</strong>
    Uruchom skrypt <code>includes/migrate_v2.sql</code> na bazie danych, aby utworzyć tabelę <code>sim_cards</code> i odblokować pełną funkcjonalność zarządzania kartami SIM.
    Poniżej widoczne są tylko numery SIM przypisane bezpośrednio do urządzeń.
</div>
<?php endif; ?>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj po numerze SIM, operatorze, nr seryjnym..."
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
        <span>Karty SIM (<?= count($allSimRows) ?>)</span>
        <?php if (isAdmin()): ?>
        <a href="sim_cards.php?action=add" class="btn btn-sm btn-primary">
            <i class="fas fa-plus me-1"></i>Dodaj kartę SIM
        </a>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr telefonu SIM</th>
                    <th>Operator</th>
                    <th>Urządzenie</th>
                    <th>Model</th>
                    <th>Status urząd.</th>
                    <th>Pojazd</th>
                    <th>Klient</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allSimRows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= h($row['sim_number']) ?></td>
                    <td><?= $row['operator'] ? h($row['operator']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($row['serial_number']): ?>
                        <a href="devices.php?action=view&id=<?= $row['device_id'] ?>">
                            <?= h($row['serial_number']) ?>
                        </a>
                        <?php if ($row['imei']): ?><br><small class="text-muted"><?= h($row['imei']) ?></small><?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">— nie przypisano —</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ($row['manufacturer_name'] && $row['model_name']) ? h($row['manufacturer_name'] . ' ' . $row['model_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $row['device_status'] ? getStatusBadge($row['device_status'], 'device') : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $row['vehicle_registration'] ? h($row['vehicle_registration']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= ($row['company_name'] ?: $row['contact_name']) ? h($row['company_name'] ?: $row['contact_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showSimPreview(<?= htmlspecialchars(json_encode([
                                    'sim_id'               => $row['sim_id'] ?? null,
                                    'sim_number'           => $row['sim_number'],
                                    'operator'             => $row['operator'] ?? '',
                                    'iccid'                => $row['iccid'] ?? '',
                                    'serial_number'        => $row['serial_number'] ?? '',
                                    'imei'                 => $row['imei'] ?? '',
                                    'manufacturer_name'    => $row['manufacturer_name'] ?? '',
                                    'model_name'           => $row['model_name'] ?? '',
                                    'device_status'        => $row['device_status'] ?? '',
                                    'vehicle_registration' => $row['vehicle_registration'] ?? '',
                                    'client'               => $row['company_name'] ?: ($row['contact_name'] ?? ''),
                                    'device_id'            => $row['device_id'] ?? null,
                                ]), ENT_QUOTES) ?>)"
                                title="Podgląd"><i class="fas fa-eye"></i></button>
                        <?php if ($row['row_source'] === 'sim_card'): ?>
                        <a href="sim_cards.php?action=edit&id=<?= $row['sim_id'] ?>"
                           class="btn btn-sm btn-outline-primary btn-action" title="Edytuj kartę SIM">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="sim_id" value="<?= $row['sim_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń kartę SIM <?= h($row['sim_number']) ?>?"
                                    title="Usuń kartę SIM"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php else: ?>
                        <!-- Legacy device SIM — show remove only -->
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove_device_sim">
                            <input type="hidden" name="device_id" value="<?= $row['device_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Odpiąć kartę SIM od urządzenia <?= h($row['serial_number']) ?>?"
                                    title="Odepnij kartę SIM"><i class="fas fa-unlink"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($allSimRows)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted p-3">
                        Brak kart SIM w systemie.
                        <a href="sim_cards.php?action=add">Dodaj pierwszą kartę SIM.</a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SIM Preview Modal -->
<div class="modal fade" id="simPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="simPreviewTitle"><i class="fas fa-sim-card me-2 text-primary"></i>Podgląd karty SIM</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="simPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
                <a id="simPreviewDeviceBtn" href="#" class="btn btn-info btn-sm text-white d-none"><i class="fas fa-microchip me-1"></i>Otwórz urządzenie</a>
                <a id="simPreviewEditBtn" href="#" class="btn btn-primary btn-sm d-none"><i class="fas fa-edit me-1"></i>Edytuj kartę SIM</a>
            </div>
        </div>
    </div>
</div>
<script>
function showSimPreview(data) {
    var devStatusMap = {
        'nowy':       '<span class="badge bg-primary">Nowy</span>',
        'sprawny':    '<span class="badge bg-success">Sprawny</span>',
        'zamontowany':'<span class="badge bg-info text-dark">Zamontowany</span>',
        'w_serwisie': '<span class="badge bg-warning text-dark">W serwisie</span>',
        'uszkodzony': '<span class="badge bg-danger">Uszkodzony</span>',
        'wycofany':   '<span class="badge bg-secondary">Wycofany</span>',
        'sprzedany':  '<span class="badge bg-dark">Sprzedany</span>'
    };
    var devBadge = data.device_status
        ? (devStatusMap[data.device_status] || ('<span class="badge bg-secondary">' + data.device_status + '</span>'))
        : '—';

    document.getElementById('simPreviewTitle').innerHTML = '<i class="fas fa-sim-card me-2 text-primary"></i>Karta SIM: ' + data.sim_number;
    document.getElementById('simPreviewBody').innerHTML =
        '<table class="table table-sm table-borderless mb-0">' +
        '<tr><th class="text-muted" style="width:40%">Nr telefonu SIM</th><td class="fw-bold">' + data.sim_number + '</td></tr>' +
        '<tr><th class="text-muted">Operator</th><td>' + (data.operator || '—') + '</td></tr>' +
        '<tr><th class="text-muted">ICCID</th><td>' + (data.iccid || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Urządzenie (nr seryjny)</th><td>' + (data.serial_number || '— nie przypisano —') + '</td></tr>' +
        '<tr><th class="text-muted">IMEI</th><td>' + (data.imei || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Model</th><td>' + (data.manufacturer_name ? data.manufacturer_name + ' ' + data.model_name : '—') + '</td></tr>' +
        '<tr><th class="text-muted">Status urządzenia</th><td>' + devBadge + '</td></tr>' +
        '<tr><th class="text-muted">Pojazd</th><td>' + (data.vehicle_registration || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Klient</th><td>' + (data.client || '—') + '</td></tr>' +
        '</table>';

    var deviceBtn = document.getElementById('simPreviewDeviceBtn');
    var editBtn   = document.getElementById('simPreviewEditBtn');
    if (data.device_id) {
        deviceBtn.href = 'devices.php?action=view&id=' + data.device_id;
        deviceBtn.classList.remove('d-none');
    } else {
        deviceBtn.classList.add('d-none');
    }
    if (data.sim_id) {
        editBtn.href = 'sim_cards.php?action=edit&id=' + data.sim_id;
        editBtn.classList.remove('d-none');
    } else {
        editBtn.classList.add('d-none');
    }
    var modal = new bootstrap.Modal(document.getElementById('simPreviewModal'));
    modal.show();
}
</script>

<?php elseif ($action === 'add'): ?>

<?php if (!$simCardsTableExists): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Funkcja niedostępna.</strong>
    Tabela <code>sim_cards</code> nie istnieje. Uruchom skrypt migracji <code>includes/migrate_v2.sql</code> na bazie danych.
    <a href="sim_cards.php" class="btn btn-sm btn-outline-secondary ms-2">Powrót</a>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header"><i class="fas fa-plus me-2"></i>Dodaj karty SIM</div>
    <div class="card-body">
        <form method="POST" id="bulkSimAddForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_add_sims">
            <div class="row g-3 mb-3 pb-3 border-bottom">
                <div class="col-md-4">
                    <label class="form-label">Operator <span class="text-muted">(wspólny dla wszystkich)</span></label>
                    <input type="text" name="operator" class="form-control"
                           placeholder="np. Play, Orange, T-Mobile" maxlength="50">
                </div>
            </div>
            <div class="mb-2 d-flex align-items-center justify-content-between">
                <span class="fw-semibold text-muted small">Karty SIM do dodania</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="simBulkAddRow()"><i class="fas fa-plus me-1"></i>Dodaj wiersz</button>
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
                    <tbody id="simBulkBody"></tbody>
                </table>
            </div>
            <div class="mt-2 text-muted small" id="simBulkCount">0 kart do dodania</div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz karty SIM</button>
                <a href="sim_cards.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
            </div>
        </form>
    </div>
</div>

<!-- Datalist z urządzeniami dla autocomplete wierszy -->
<datalist id="simBulkDeviceList">
    <?php foreach ($allDevices as $dev): ?>
    <option value="<?= $dev['id'] ?>" data-label="<?= h($dev['serial_number'] . ($dev['imei'] ? ' [' . $dev['imei'] . ']' : '') . ($dev['sim_number'] ? ' — SIM: ' . $dev['sim_number'] : '')) ?>">
        <?= h($dev['serial_number'] . ($dev['imei'] ? ' [' . $dev['imei'] . ']' : '')) ?>
    </option>
    <?php endforeach; ?>
</datalist>

<script>
var simBulkDevices = <?= json_encode(array_values(array_map(function($d) {
    return [
        'id'    => (string)$d['id'],
        'label' => $d['serial_number']
                   . ($d['imei']       ? ' [' . $d['imei'] . ']' : '')
                   . ($d['sim_number'] ? ' — SIM: ' . $d['sim_number'] : '')
                   . ' (' . $d['manufacturer_name'] . ' ' . $d['model_name'] . ')',
    ];
}, $allDevices))) ?>;

var simBulkRowCount = 0;

function simBulkBuildDeviceSelect(name) {
    var opts = '<option value="">— brak —</option>';
    simBulkDevices.forEach(function(d) {
        opts += '<option value="' + d.id + '">' + d.label.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') + '</option>';
    });
    return '<select name="' + name + '" class="form-select form-select-sm">' + opts + '</select>';
}

function simBulkAddRow() {
    simBulkRowCount++;
    var n = simBulkRowCount;
    var tbody = document.getElementById('simBulkBody');
    var tr = document.createElement('tr');
    tr.id = 'sim-bulk-row-' + n;
    tr.innerHTML =
        '<td class="text-muted text-center align-middle">' + n + '</td>' +
        '<td><input type="text" name="phone_numbers[]" class="form-control form-control-sm" placeholder="+48 123 456 789" maxlength="30" required></td>' +
        '<td><input type="text" name="iccids[]" class="form-control form-control-sm" placeholder="20-cyfrowy ICCID" maxlength="25"></td>' +
        '<td>' + simBulkBuildDeviceSelect('device_ids_sim[]') + '</td>' +
        '<td><input type="text" name="notes_list[]" class="form-control form-control-sm" placeholder="Opcjonalne"></td>' +
        '<td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="simBulkRemoveRow(' + n + ')" title="Usuń"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    // Init TomSelect on the new device select
    var sel = tr.querySelector('select');
    if (sel && typeof TomSelect !== 'undefined') {
        new TomSelect(sel, { maxOptions: null });
    }
    tr.querySelector('input[name="phone_numbers[]"]').focus();
    simBulkUpdateCount();
}

function simBulkRemoveRow(n) {
    var row = document.getElementById('sim-bulk-row-' + n);
    if (row) {
        var sel = row.querySelector('select');
        if (sel && sel.tomselect) sel.tomselect.destroy();
        row.remove();
        simBulkUpdateCount();
    }
}

function simBulkUpdateCount() {
    var rows = document.querySelectorAll('#simBulkBody tr').length;
    var el = document.getElementById('simBulkCount');
    if (!el) return;
    if (rows === 0) el.textContent = '0 kart do dodania';
    else if (rows === 1) el.textContent = '1 karta do dodania';
    else if (rows <= 4) el.textContent = rows + ' karty do dodania';
    else el.textContent = rows + ' kart do dodania';
}

document.addEventListener('DOMContentLoaded', function () {
    // Start with one empty row
    simBulkAddRow();
});
</script>
<?php endif; // end simCardsTableExists check for add form ?>

<?php elseif ($action === 'edit' && $editSim): ?>

<div class="card" style="max-width:620px">
    <div class="card-header"><i class="fas fa-edit me-2"></i>Edytuj kartę SIM</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="sim_id" value="<?= $editSim['id'] ?>">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label required-star">Numer telefonu karty SIM</label>
                    <input type="text" name="phone_number" class="form-control" required
                           value="<?= h($editSim['phone_number']) ?>"
                           placeholder="+48 123 456 789" maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Operator</label>
                    <input type="text" name="operator" class="form-control"
                           value="<?= h($editSim['operator'] ?? '') ?>"
                           placeholder="np. Play, Orange, T-Mobile" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label">ICCID / nr karty</label>
                    <input type="text" name="iccid" class="form-control"
                           value="<?= h($editSim['iccid'] ?? '') ?>"
                           placeholder="20-cyfrowy numer ICCID" maxlength="25">
                </div>
                <div class="col-12">
                    <label class="form-label">Przypisz do urządzenia <span class="text-muted">(opcjonalnie)</span></label>
                    <select name="device_id" class="form-select" id="simDeviceEditSelect">
                        <option value="">— brak przypisania —</option>
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
                        <option value="<?= $dev['id'] ?>" <?= $editSim['device_id'] == $dev['id'] ? 'selected' : '' ?>>
                            <?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?>
                            <?= $dev['sim_number'] ? ' — SIM: ' . h($dev['sim_number']) : '' ?>
                        </option>
                        <?php endforeach; if ($grp) echo '</optgroup>'; ?>
                    </select>
                    <div class="form-text">Pozostaw puste, aby odpiąć kartę SIM od urządzenia.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="2"><?= h($editSim['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz</button>
                    <a href="sim_cards.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('simDeviceEditSelect');
    if (sel && typeof TomSelect !== 'undefined') {
        new TomSelect(sel, { maxOptions: null });
    }
});
</script>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

