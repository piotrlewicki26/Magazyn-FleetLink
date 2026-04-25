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

    // PP batch group – when a batch is selected the form sends batch_ref=batch:N
    $batchRef       = sanitize($_POST['batch_ref'] ?? '');
    $batchId        = null;
    $batchPrefix    = 'batch:';
    $instPrefix     = 'inst:';
    if ($batchRef !== '' && strpos($batchRef, $batchPrefix) === 0) {
        $batchId        = (int)substr($batchRef, strlen($batchPrefix)) ?: null;
        $installationId = null; // batch supersedes single installation
    } elseif ($batchRef !== '' && strpos($batchRef, $instPrefix) === 0) {
        $installationId = (int)substr($batchRef, strlen($instPrefix)) ?: null;
        $batchId        = null;
    }

    // PS-specific fields
    $serviceDeviceId    = (int)($_POST['service_device_id'] ?? 0) ?: null;
    $serviceType        = sanitize($_POST['service_type'] ?? '');
    $replacementDeviceId = (int)($_POST['replacement_device_id'] ?? 0) ?: null;
    $validServiceTypes  = ['przeglad','naprawa','wymiana','aktualizacja','inne'];
    if (!in_array($serviceType, $validServiceTypes)) $serviceType = null;

    $validTypes = ['PP','PU','PS'];
    if (!in_array($type, $validTypes)) $type = 'PP';

    // Clear PS fields for non-PS protocols
    if ($type !== 'PS') {
        $serviceDeviceId    = null;
        $serviceType        = null;
        $replacementDeviceId = null;
    }

    // For 'wymiana' both device and replacement must make sense; clear replacement if no device
    if ($serviceType !== 'wymiana') $replacementDeviceId = null;

    if ($postAction === 'add') {
        $protocolNum = generateProtocolNumber($type);
        $db->prepare("INSERT INTO protocols (installation_id, service_id, service_device_id, service_type, replacement_device_id, batch_id, type, protocol_number, date, technician_id, client_signature, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$installationId, $serviceId, $serviceDeviceId, $serviceType, $replacementDeviceId, $batchId, $type, $protocolNum, $date, $technicianId, $clientSignature, $notes]);
        $newId = $db->lastInsertId();

        // Record device history for "wymiana"
        if ($type === 'PS' && $serviceType === 'wymiana' && $serviceDeviceId && $replacementDeviceId) {
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, protocol_id) VALUES (?,?,?,?)")
               ->execute([$serviceDeviceId, 'wymieniono_na', $replacementDeviceId, $newId]);
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, protocol_id) VALUES (?,?,?,?)")
               ->execute([$replacementDeviceId, 'wymieniono_z', $serviceDeviceId, $newId]);
        }

        flashSuccess("Protokół $protocolNum został utworzony.");
        redirect(getBaseUrl() . 'protocols.php?action=view&id=' . $newId);

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);

        // Fetch old values to compare wymiana state
        $oldP = $db->prepare("SELECT type, service_type, service_device_id, replacement_device_id FROM protocols WHERE id=?");
        $oldP->execute([$editId]);
        $oldProtocol = $oldP->fetch();

        $db->prepare("UPDATE protocols SET installation_id=?, service_id=?, service_device_id=?, service_type=?, replacement_device_id=?, batch_id=?, date=?, technician_id=?, client_signature=?, notes=? WHERE id=?")
           ->execute([$installationId, $serviceId, $serviceDeviceId, $serviceType, $replacementDeviceId, $batchId, $date, $technicianId, $clientSignature, $notes, $editId]);

        // Re-record device history for "wymiana" if relevant fields changed
        $wasWymiana = ($oldProtocol['service_type'] === 'wymiana' && $oldProtocol['service_device_id'] && $oldProtocol['replacement_device_id']);
        $isWymiana  = ($serviceType === 'wymiana' && $serviceDeviceId && $replacementDeviceId);

        $deviceChanged = ($oldProtocol['service_device_id'] != $serviceDeviceId)
                      || ($oldProtocol['replacement_device_id'] != $replacementDeviceId)
                      || (!$wasWymiana && $isWymiana);

        if ($isWymiana && $deviceChanged) {
            // Remove old history entries for this protocol
            if ($wasWymiana) {
                $db->prepare("DELETE FROM device_history WHERE protocol_id=?")->execute([$editId]);
            }
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, protocol_id) VALUES (?,?,?,?)")
               ->execute([$serviceDeviceId, 'wymieniono_na', $replacementDeviceId, $editId]);
            $db->prepare("INSERT INTO device_history (device_id, event_type, related_device_id, protocol_id) VALUES (?,?,?,?)")
               ->execute([$replacementDeviceId, 'wymieniono_z', $serviceDeviceId, $editId]);
        } elseif ($wasWymiana && !$isWymiana) {
            // wymiana removed – delete old history rows
            $db->prepare("DELETE FROM device_history WHERE protocol_id=?")->execute([$editId]);
        }

        flashSuccess('Protokół zaktualizowany.');
        redirect(getBaseUrl() . 'protocols.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM device_history WHERE protocol_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM protocols WHERE id=?")->execute([$delId]);
        flashSuccess('Protokół usunięty.');
        redirect(getBaseUrl() . 'protocols.php');

    } elseif ($postAction === 'send_email') {
        $protoId  = (int)($_POST['id'] ?? 0);
        $emailTo  = sanitize($_POST['email_to'] ?? '');
        $emailName= sanitize($_POST['email_to_name'] ?? '');
        $emailMsg = sanitize($_POST['email_message'] ?? '');

        if (!$protoId || !$emailTo || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
            flashError('Podaj poprawny adres e-mail odbiorcy.');
        } else {
            // Fetch protocol summary
            $pStmt = $db->prepare("
                SELECT p.protocol_number, p.type, p.date,
                       u.name as technician_name,
                       v.registration, v.make,
                       d.serial_number, m.name as model_name, mf.name as manufacturer_name
                FROM protocols p
                LEFT JOIN users u ON u.id=p.technician_id
                LEFT JOIN installations i ON i.id=p.installation_id
                LEFT JOIN vehicles v ON v.id=i.vehicle_id
                LEFT JOIN devices d ON d.id=i.device_id
                LEFT JOIN models m ON m.id=d.model_id
                LEFT JOIN manufacturers mf ON mf.id=m.manufacturer_id
                WHERE p.id=?
            ");
            $pStmt->execute([$protoId]);
            $pData = $pStmt->fetch();

            if ($pData) {
                $typeLabel = ['PP' => 'Protokół Przekazania', 'PU' => 'Protokół Uruchomienia', 'PS' => 'Protokół Serwisowy'];
                $subject = h($typeLabel[$pData['type']] ?? 'Protokół') . ' nr ' . h($pData['protocol_number']);
                $msgHtml = nl2br(h($emailMsg));
                $body = getEmailTemplate('general', [
                    'MESSAGE'     => "<strong>Dotyczy: {$subject}</strong><br>Data: " . formatDate($pData['date']) . "<br><br>{$msgHtml}",
                    'SENDER_NAME' => getCurrentUser()['name'],
                    'DATE'        => date('d.m.Y'),
                ]);
                if (sendAppEmail($emailTo, $emailName, $subject, $body)) {
                    flashSuccess("Wiadomość została wysłana na adres {$emailTo}.");
                } else {
                    flashError('Nie udało się wysłać wiadomości. Sprawdź konfigurację e-mail w ustawieniach.');
                }
            }
        }
        redirect(getBaseUrl() . 'protocols.php' . ($protoId ? '?action=view&id=' . $protoId : ''));
    }
}

$protocol = null;
$protocolData = [];
$batchInstallations = []; // filled below when protocol has batch_id
if (in_array($action, ['view','edit','print']) && $id) {
    $stmt = $db->prepare("
        SELECT p.*,
               u.name as technician_name,
               i.installation_date, i.location_in_vehicle,
               d.serial_number, d.imei,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make, v.model_name as vehicle_model, v.vin,
               c.contact_name, c.company_name, c.nip, c.email as client_email,
               sd.serial_number  as svc_serial,  sd.imei  as svc_imei,
               sm.name           as svc_model,   smf.name as svc_manufacturer,
               rd.serial_number  as rep_serial,  rd.imei  as rep_imei,
               rm.name           as rep_model,   rmf.name as rep_manufacturer
        FROM protocols p
        LEFT JOIN users u ON u.id=p.technician_id
        LEFT JOIN installations i ON i.id=p.installation_id
        LEFT JOIN devices d ON d.id=i.device_id
        LEFT JOIN models m ON m.id=d.model_id
        LEFT JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=v.client_id
        LEFT JOIN devices sd  ON sd.id=p.service_device_id
        LEFT JOIN models sm   ON sm.id=sd.model_id
        LEFT JOIN manufacturers smf ON smf.id=sm.manufacturer_id
        LEFT JOIN devices rd  ON rd.id=p.replacement_device_id
        LEFT JOIN models rm   ON rm.id=rd.model_id
        LEFT JOIN manufacturers rmf ON rmf.id=rm.manufacturer_id
        WHERE p.id=?
    ");
    $stmt->execute([$id]);
    $protocol = $stmt->fetch();
    if (!$protocol) { flashError('Protokół nie istnieje.'); redirect(getBaseUrl() . 'protocols.php'); }

    // Fetch all installations of a batch (PP protocols with batch_id)
    if (!empty($protocol['batch_id'])) {
        $batchStmt = $db->prepare("
            SELECT i.id, i.installation_date, i.location_in_vehicle, i.notes,
                   d.serial_number, d.imei, d.sim_number,
                   m.name as model_name, mf.name as manufacturer_name,
                   v.registration, v.make, v.model_name as vehicle_model, v.vin,
                   c.contact_name, c.company_name, c.nip, c.phone as client_phone,
                   c.address as client_address, c.city as client_city,
                   u.name as technician_name
            FROM installations i
            JOIN devices d ON d.id=i.device_id
            JOIN models m ON m.id=d.model_id
            JOIN manufacturers mf ON mf.id=m.manufacturer_id
            JOIN vehicles v ON v.id=i.vehicle_id
            LEFT JOIN clients c ON c.id=i.client_id
            LEFT JOIN users u ON u.id=i.technician_id
            WHERE i.batch_id=?
            ORDER BY i.id
        ");
        $batchStmt->execute([$protocol['batch_id']]);
        $batchInstallations = $batchStmt->fetchAll();
    }
}

$users = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();

// Fetch individual installations (no batch) and batch groups separately for the dropdown
$activeInstallationsSingle = $db->query("
    SELECT i.id, v.registration, d.serial_number, NULL as batch_id
    FROM installations i
    JOIN vehicles v ON v.id=i.vehicle_id
    JOIN devices d ON d.id=i.device_id
    WHERE i.status IN ('aktywna','zakonczona')
      AND (i.batch_id IS NULL)
    ORDER BY i.installation_date DESC
    LIMIT 50
")->fetchAll();

// Batch groups: one row per batch_id with device count and first vehicle registration
$activeInstallationBatches = [];
try {
    $batchRows = $db->query("
        SELECT i.batch_id,
               MIN(i.id) as first_id,
               COUNT(i.id) as device_count,
               GROUP_CONCAT(DISTINCT v.registration ORDER BY v.registration SEPARATOR ', ') as registrations,
               MIN(i.installation_date) as installation_date
        FROM installations i
        JOIN vehicles v ON v.id=i.vehicle_id
        WHERE i.status IN ('aktywna','zakonczona')
          AND i.batch_id IS NOT NULL
        GROUP BY i.batch_id
        ORDER BY MIN(i.installation_date) DESC
        LIMIT 30
    ")->fetchAll();
    $activeInstallationBatches = $batchRows;
} catch (PDOException $e) { /* batch_id column not yet available */ }

$recentServices = $db->query("
    SELECT s.id, d.serial_number, s.planned_date, s.type
    FROM services s
    JOIN devices d ON d.id=s.device_id
    ORDER BY s.created_at DESC
    LIMIT 50
")->fetchAll();
// All devices for PS device pickers (id + label)
$allDevices = $db->query("
    SELECT d.id, d.serial_number, d.imei, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

$settings = [];
foreach ($db->query("SELECT `key`, `value` FROM settings")->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

// Service type labels used in form, view and print template
$psServiceTypeLabels = ['przeglad'=>'Przegląd','naprawa'=>'Naprawa','wymiana'=>'Wymiana','aktualizacja'=>'Aktualizacja firmware','inne'=>'Inne'];

if ($action === 'print' && $protocol) {
    include __DIR__ . '/includes/protocol_print.php';
    exit;
}

$preType = sanitize($_GET['type'] ?? 'PP');
if (!in_array($preType, ['PP','PU','PS'])) $preType = 'PP';

$filterContext = sanitize($_GET['filter'] ?? '');
$protocols = [];
if ($action === 'list') {
    $filterSql = '';
    if ($filterContext === 'installation') {
        $filterSql = 'WHERE p.installation_id IS NOT NULL';
    } elseif ($filterContext === 'service') {
        $filterSql = "WHERE p.type = 'PS'";
    }
    $stmt = $db->prepare("
        SELECT p.*, u.name as technician_name, v.registration, d.serial_number
        FROM protocols p
        LEFT JOIN users u ON u.id=p.technician_id
        LEFT JOIN installations i ON i.id=p.installation_id
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN devices d ON d.id=i.device_id
        $filterSql
        ORDER BY p.date DESC, p.id DESC
    ");
    $stmt->execute([]);
    $protocols = $stmt->fetchAll();
}

$activePage = $filterContext === 'installation' ? 'installations' : ($filterContext === 'service' ? 'services' : 'offers');
$contextTitles = ['installation' => 'Protokoły montaży', 'service' => 'Protokoły serwisu'];
$pageTitle = $contextTitles[$filterContext] ?? 'Protokoły';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-clipboard-check me-2 text-primary"></i><?= h($pageTitle) ?></h1>
    <?php if ($action === 'list'): ?>
    <a href="protocols.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowy protokół</a>
    <?php else: ?>
    <a href="protocols.php<?= $filterContext ? '?filter=' . h($filterContext) : '' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
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
                <?php
                $typeLabel = ['PP' => 'Przekazania', 'PU' => 'Uruchomienia', 'PS' => 'Serwisowy'];
                $typeColor = ['PP' => 'primary', 'PU' => 'success', 'PS' => 'warning'];
                foreach ($protocols as $p): ?>
                <tr>
                    <td class="fw-bold">
                        <a href="#" onclick="showProtocolPreview(<?= htmlspecialchars(json_encode([
                            'id'             => $p['id'],
                            'protocol_number'=> $p['protocol_number'],
                            'type'           => $p['type'],
                            'date'           => $p['date'],
                            'registration'   => $p['registration'] ?? '',
                            'serial_number'  => $p['serial_number'] ?? '',
                            'technician_name'=> $p['technician_name'] ?? '',
                        ]), ENT_QUOTES) ?>); return false;"><?= h($p['protocol_number']) ?></a>
                    </td>
                    <td><span class="badge bg-<?= $typeColor[$p['type']] ?? 'secondary' ?>"><?= $typeLabel[$p['type']] ?? h($p['type']) ?></span></td>
                    <td><?= formatDate($p['date']) ?></td>
                    <td><?= h($p['registration'] ?? '—') ?></td>
                    <td><?= h($p['serial_number'] ?? '—') ?></td>
                    <td><?= h($p['technician_name'] ?? '—') ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showProtocolPreview(<?= htmlspecialchars(json_encode([
                                    'id'             => $p['id'],
                                    'protocol_number'=> $p['protocol_number'],
                                    'type'           => $p['type'],
                                    'date'           => $p['date'],
                                    'registration'   => $p['registration'] ?? '',
                                    'serial_number'  => $p['serial_number'] ?? '',
                                    'technician_name'=> $p['technician_name'] ?? '',
                                ]), ENT_QUOTES) ?>)"
                                title="Podgląd"><i class="fas fa-eye"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action"
                                onclick="openProtocolSendModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['protocol_number']), ENT_QUOTES) ?>)"
                                title="Wyślij e-mailem"><i class="fas fa-envelope"></i></button>
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

<!-- Protocol Preview Modal -->
<div class="modal fade" id="protocolPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="protocolPreviewTitle"><i class="fas fa-clipboard-check me-2 text-primary"></i>Podgląd protokołu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="protocolPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
                <a id="protocolPreviewPrintBtn" href="#" target="_blank" class="btn btn-outline-dark btn-sm"><i class="fas fa-print me-1"></i>Drukuj</a>
                <button type="button" id="protocolPreviewSendBtn" class="btn btn-success btn-sm"><i class="fas fa-envelope me-1"></i>Wyślij</button>
                <a id="protocolPreviewViewBtn" href="#" class="btn btn-info btn-sm text-white"><i class="fas fa-eye me-1"></i>Otwórz pełny widok</a>
            </div>
        </div>
    </div>
</div>

<!-- Protocol Send Email Modal (from list) -->
<div class="modal fade" id="protocolListSendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_email">
                <input type="hidden" name="id" id="plsmProtoId" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-envelope me-2 text-success"></i>Wyślij protokół do klienta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Protokół: <strong id="plsmProtoNumber"></strong></p>
                    <div class="mb-3">
                        <label class="form-label required-star">Adres e-mail odbiorcy</label>
                        <input type="email" name="email_to" class="form-control" required placeholder="adres@email.pl">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Imię i nazwisko odbiorcy</label>
                        <input type="text" name="email_to_name" class="form-control" placeholder="Jan Kowalski">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-star">Treść wiadomości</label>
                        <textarea name="email_message" class="form-control" rows="5" required
                                  placeholder="Treść wiadomości do klienta...">W załączeniu przesyłamy protokół do podpisania. Prosimy o odesłanie podpisanego dokumentu.</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-paper-plane me-1"></i>Wyślij</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function showProtocolPreview(data) {
    var typeMap = {'PP': '<span class="badge bg-primary">Przekazania</span>', 'PU': '<span class="badge bg-success">Uruchomienia</span>', 'PS': '<span class="badge bg-warning text-dark">Serwisowy</span>'};
    var typeBadge = typeMap[data.type] || ('<span class="badge bg-secondary">' + data.type + '</span>');
    var formatDate = function(d) { return d ? d.split('-').reverse().join('.') : '—'; };

    document.getElementById('protocolPreviewTitle').innerHTML = '<i class="fas fa-clipboard-check me-2 text-primary"></i>Protokół: ' + data.protocol_number;
    document.getElementById('protocolPreviewBody').innerHTML =
        '<table class="table table-sm table-borderless mb-0">' +
        '<tr><th class="text-muted" style="width:40%">Nr protokołu</th><td class="fw-bold">' + data.protocol_number + '</td></tr>' +
        '<tr><th class="text-muted">Typ</th><td>' + typeBadge + '</td></tr>' +
        '<tr><th class="text-muted">Data</th><td>' + formatDate(data.date) + '</td></tr>' +
        '<tr><th class="text-muted">Pojazd</th><td>' + (data.registration || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Urządzenie</th><td>' + (data.serial_number || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Technik</th><td>' + (data.technician_name || '—') + '</td></tr>' +
        '</table>';

    document.getElementById('protocolPreviewViewBtn').href  = 'protocols.php?action=view&id=' + data.id;
    document.getElementById('protocolPreviewPrintBtn').href = 'protocols.php?action=print&id=' + data.id;
    document.getElementById('protocolPreviewSendBtn').onclick = function() {
        var previewModal = bootstrap.Modal.getInstance(document.getElementById('protocolPreviewModal'));
        if (previewModal) previewModal.hide();
        setTimeout(function() { openProtocolSendModal(data.id, data.protocol_number); }, 350);
    };
    var modal = new bootstrap.Modal(document.getElementById('protocolPreviewModal'));
    modal.show();
}
function openProtocolSendModal(protoId, protoNumber) {
    document.getElementById('plsmProtoId').value   = protoId;
    document.getElementById('plsmProtoNumber').textContent = protoNumber;
    var modal = new bootstrap.Modal(document.getElementById('protocolListSendModal'));
    modal.show();
}
</script>

<?php elseif ($action === 'view' && $protocol): ?>
<div class="row g-3">
    <div class="col-md-<?= !empty($batchInstallations) ? '7' : '5' ?>">
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
                    <?php if (!empty($batchInstallations)): ?>
                    <tr><th class="text-muted">Montaż grupowy</th><td><span class="badge bg-primary"><?= count($batchInstallations) ?> urządzenia/ń</span></td></tr>
                    <?php elseif ($protocol['registration']): ?>
                    <tr><th class="text-muted">Pojazd</th><td><?= h($protocol['registration'] . ' ' . $protocol['make'] . ' ' . $protocol['vehicle_model']) ?></td></tr>
                    <tr><th class="text-muted">VIN</th><td><?= h($protocol['vin'] ?? '—') ?></td></tr>
                    <?php endif; ?>
                    <?php if (empty($batchInstallations) && $protocol['serial_number']): ?>
                    <tr><th class="text-muted">Urządzenie</th><td><?= h($protocol['manufacturer_name'] . ' ' . $protocol['model_name']) ?><br><small><?= h($protocol['serial_number']) ?></small></td></tr>
                    <tr><th class="text-muted">IMEI</th><td><?= h($protocol['imei'] ?? '—') ?></td></tr>
                    <?php endif; ?>
                    <?php if ($protocol['type'] === 'PS'): ?>
                    <?php if ($protocol['svc_serial']): ?>
                    <tr><th class="text-muted">Serwis dotyczy</th><td>
                        <?= h(trim($protocol['svc_manufacturer'] . ' ' . $protocol['svc_model'])) ?><br>
                        <small><?= h($protocol['svc_serial']) ?><?= $protocol['svc_imei'] ? ' [' . h($protocol['svc_imei']) . ']' : '' ?></small>
                    </td></tr>
                    <?php endif; ?>
                    <?php if ($protocol['service_type']): ?>
                    <tr><th class="text-muted">Typ czynności</th><td><strong><?= h($psServiceTypeLabels[$protocol['service_type']] ?? $protocol['service_type']) ?></strong></td></tr>
                    <?php endif; ?>
                    <?php if ($protocol['service_type'] === 'wymiana' && $protocol['rep_serial']): ?>
                    <tr><th class="text-muted">Urządzenie zastępcze</th><td>
                        <?= h(trim($protocol['rep_manufacturer'] . ' ' . $protocol['rep_model'])) ?><br>
                        <small><?= h($protocol['rep_serial']) ?><?= $protocol['rep_imei'] ? ' [' . h($protocol['rep_imei']) . ']' : '' ?></small>
                    </td></tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (empty($batchInstallations) && $protocol['contact_name']): ?>
                    <tr><th class="text-muted">Klient</th><td><?= h($protocol['company_name'] ?: $protocol['contact_name']) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php if ($protocol['notes']): ?>
                <hr><strong class="small">Uwagi:</strong><p class="small text-muted mt-1"><?= h($protocol['notes']) ?></p>
                <?php endif; ?>
                <?php if ($protocol['client_signature']): ?>
                <hr><strong class="small">Podpis klienta:</strong><p class="small mt-1"><?= h($protocol['client_signature']) ?></p>
                <?php endif; ?>

                <?php if (!empty($batchInstallations)): ?>
                <hr>
                <strong class="small text-primary">Urządzenia w grupie:</strong>
                <table class="table table-sm mt-2">
                    <thead><tr><th>Pojazd</th><th>Urządzenie</th><th>IMEI</th><th>Klient</th></tr></thead>
                    <tbody>
                    <?php foreach ($batchInstallations as $bi): ?>
                    <tr>
                        <td><?= h($bi['registration']) ?><br><small class="text-muted"><?= h($bi['make'] . ' ' . $bi['vehicle_model']) ?></small></td>
                        <td><?= h($bi['manufacturer_name'] . ' ' . $bi['model_name']) ?><br><small><?= h($bi['serial_number']) ?></small></td>
                        <td><?= h($bi['imei'] ?? '—') ?></td>
                        <td><?= h($bi['company_name'] ?: ($bi['contact_name'] ?? '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <a href="protocols.php?action=print&id=<?= $protocol['id'] ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print me-1"></i>Drukuj</a>
                <a href="protocols.php?action=edit&id=<?= $protocol['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#sendEmailModal">
                    <i class="fas fa-envelope me-1"></i>Wyślij do klienta
                </button>
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

<!-- Send Email Modal -->
<div class="modal fade" id="sendEmailModal" tabindex="-1" aria-labelledby="sendEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_email">
                <input type="hidden" name="id" value="<?= $protocol['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="sendEmailModalLabel"><i class="fas fa-envelope me-2 text-info"></i>Wyślij protokół do klienta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required-star">Adres e-mail odbiorcy</label>
                        <input type="email" name="email_to" class="form-control" required
                               value="<?= h($protocol['client_email'] ?? '') ?>"
                               placeholder="adres@email.pl">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nazwa odbiorcy</label>
                        <input type="text" name="email_to_name" class="form-control"
                               value="<?= h($protocol['company_name'] ?: ($protocol['contact_name'] ?? '')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-star">Treść wiadomości</label>
                        <textarea name="email_message" class="form-control" rows="5" required
                                  placeholder="Wpisz treść wiadomości do klienta…"><?= "Szanowni Państwo,\n\nW załączeniu przekazujemy protokół nr " . h($protocol['protocol_number']) . " z dnia " . formatDate($protocol['date']) . ".\n\nZ poważaniem," ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-info text-white"><i class="fas fa-paper-plane me-2"></i>Wyślij</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<?php
// Determine current protocol type for showing PS-specific section
$formType    = ($action === 'edit') ? ($protocol['type'] ?? 'PP') : $preType;
$showPsNow   = ($formType === 'PS');
$curSvcDev   = (int)($protocol['service_device_id']    ?? 0);
$curSvcType  = $protocol['service_type'] ?? '';
$curRepDev   = (int)($protocol['replacement_device_id'] ?? 0);
?>
<div class="card" style="max-width:700px">
    <div class="card-header"><i class="fas fa-clipboard me-2"></i><?= $action === 'add' ? 'Nowy protokół' : 'Edytuj protokół: ' . h($protocol['protocol_number'] ?? '') ?></div>
    <div class="card-body">
        <form method="POST" id="protocolForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $protocol['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <?php if ($action === 'add'): ?>
                <div class="col-md-6">
                    <label class="form-label required-star">Typ protokołu</label>
                    <select name="type" id="protocolType" class="form-select" required>
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
                    <?php
                    // Determine currently selected batch_ref value for the edit form
                    $curBatchRef = '';
                    if (!empty($protocol['batch_id'])) {
                        $curBatchRef = 'batch:' . $protocol['batch_id'];
                    } elseif (!empty($protocol['installation_id'])) {
                        $curBatchRef = 'inst:' . $protocol['installation_id'];
                    } elseif ((int)($_GET['installation'] ?? 0)) {
                        $curBatchRef = 'inst:' . (int)$_GET['installation'];
                    }
                    ?>
                    <select name="batch_ref" class="form-select">
                        <option value="">— brak —</option>
                        <?php if (!empty($activeInstallationBatches)): ?>
                        <optgroup label="🗂️ Montaże grupowe">
                        <?php foreach ($activeInstallationBatches as $bg): ?>
                        <?php $bgRef = 'batch:' . $bg['batch_id']; ?>
                        <option value="<?= h($bgRef) ?>" <?= $curBatchRef === $bgRef ? 'selected' : '' ?>>
                            Grupa <?= (int)$bg['device_count'] ?> urządz. — <?= h($bg['registrations']) ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <optgroup label="📌 Montaże pojedyncze">
                        <?php foreach ($activeInstallationsSingle as $inst): ?>
                        <?php $instRef = 'inst:' . $inst['id']; ?>
                        <option value="<?= h($instRef) ?>" <?= $curBatchRef === $instRef ? 'selected' : '' ?>>
                            <?= h($inst['registration'] . ' — ' . $inst['serial_number']) ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
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

                <!-- PS-specific section (shown only for PS protocols) -->
                <div id="psSectionWrapper" class="col-12" <?= !$showPsNow ? 'style="display:none"' : '' ?>>
                    <hr class="my-1">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold text-warning-emphasis"><i class="fas fa-tools me-1"></i>Serwis dotyczy urządzenia</label>
                            <select name="service_device_id" id="serviceDeviceSelect" class="form-select ts-device-ps">
                                <option value="">— wybierz urządzenie —</option>
                                <?php foreach ($allDevices as $dev): ?>
                                <option value="<?= $dev['id'] ?>" <?= $curSvcDev === $dev['id'] ? 'selected' : '' ?>>
                                    <?= h($dev['manufacturer_name'] . ' ' . $dev['model_name'] . ' — ' . $dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-warning-emphasis"><i class="fas fa-wrench me-1"></i>Typ czynności serwisowej</label>
                            <select name="service_type" id="serviceTypeSelect" class="form-select">
                                <option value="">— wybierz —</option>
                                <?php foreach ($psServiceTypeLabels as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?= $curSvcType === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Replacement device (wymiana only) -->
                        <div id="replacementDeviceWrapper" class="col-12" <?= $curSvcType !== 'wymiana' ? 'style="display:none"' : '' ?>>
                            <label class="form-label fw-semibold text-danger"><i class="fas fa-exchange-alt me-1"></i>Urządzenie zastępcze (wymiana na)</label>
                            <select name="replacement_device_id" id="replacementDeviceSelect" class="form-select ts-device-ps">
                                <option value="">— wybierz urządzenie zastępcze —</option>
                                <?php foreach ($allDevices as $dev): ?>
                                <option value="<?= $dev['id'] ?>" <?= $curRepDev === $dev['id'] ? 'selected' : '' ?>>
                                    <?= h($dev['manufacturer_name'] . ' ' . $dev['model_name'] . ' — ' . $dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-danger"><i class="fas fa-info-circle me-1"></i>Historia "wymieniono na/z" zostanie zapisana w obu urządzeniach.</div>
                        </div>
                    </div>
                    <hr class="my-1">
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

<script>
(function () {
    // Show/hide PS section when protocol type changes (add form only)
    var typeSelect = document.getElementById('protocolType');
    var psSection  = document.getElementById('psSectionWrapper');
    var svcType    = document.getElementById('serviceTypeSelect');
    var repWrapper = document.getElementById('replacementDeviceWrapper');

    function applyTypeVisibility(val) {
        if (psSection) psSection.style.display = (val === 'PS') ? '' : 'none';
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', function () { applyTypeVisibility(this.value); });
        applyTypeVisibility(typeSelect.value);
    }

    // Show/hide replacement device when service type = wymiana
    if (svcType) {
        svcType.addEventListener('change', function () {
            repWrapper.style.display = (this.value === 'wymiana') ? '' : 'none';
        });
    }

    // Init TomSelect on PS device pickers
    if (typeof TomSelect !== 'undefined') {
        document.querySelectorAll('select.ts-device-ps').forEach(function (sel) {
            if (!sel.tomselect) {
                new TomSelect(sel, {
                    placeholder: '— szukaj urządzenia —',
                    allowEmptyOption: true,
                    maxOptions: null,
                    searchField: ['text', 'value']
                });
            }
        });
    }
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
