<?php
/**
 * FleetLink System GPS - Dashboard
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Warsaw');
requireLogin();

$db = getDb();
$stats = getDashboardStats();

// Dane do modali szybkich akcji
$dashModels = $db->query("SELECT m.id, m.name, mf.name as manufacturer_name FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE m.active=1 ORDER BY mf.name, m.name")->fetchAll();
// Modele z liczbą dostępnych urządzeń (dla trybu Auto w montażu)
$dashAvailableModels = $db->query("
    SELECT m.id as model_id, m.name as model_name, mf.name as manufacturer_name,
           COUNT(d.id) as available_count
    FROM models m
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    JOIN devices d ON d.model_id=m.id AND d.status IN ('nowy','sprawny')
    GROUP BY m.id
    HAVING available_count > 0
    ORDER BY mf.name, m.name
")->fetchAll();
$dashSimOptions = [];
try { $dashSimOptions = $db->query("SELECT phone_number FROM sim_cards WHERE active=1 ORDER BY phone_number")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
$dashClients  = $db->query("SELECT id, contact_name, company_name, address, city, postal_code FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
$dashVehicles = $db->query("SELECT v.id, v.registration, v.make, v.model_name, v.client_id FROM vehicles v WHERE v.active=1 ORDER BY v.registration")->fetchAll();
$dashAvailableDevices = $db->query("SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.status IN ('nowy','sprawny') ORDER BY mf.name, m.name, d.serial_number")->fetchAll();
$dashActiveInstallations = $db->query("SELECT i.id, v.registration, d.serial_number FROM installations i JOIN vehicles v ON v.id=i.vehicle_id JOIN devices d ON d.id=i.device_id WHERE i.status='aktywna' ORDER BY v.registration")->fetchAll();
$dashUsers = [];
try { $dashUsers = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll(); } catch (Exception $e) {}
$dashAllDevicesForSim = [];
try { $dashAllDevicesForSim = $db->query("SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE d.status NOT IN ('wycofany','sprzedany') ORDER BY mf.name, m.name, d.serial_number")->fetchAll(); } catch (Exception $e) {}
// All non-withdrawn devices with client_id + active vehicle registration for the service modal
$dashSvcAllDevices = $db->query("
    SELECT d.id, d.serial_number, m.name as model_name, mf.name as manufacturer_name,
           COALESCE(
               (SELECT COALESCE(i2.client_id, vv.client_id) FROM installations i2 LEFT JOIN vehicles vv ON vv.id=i2.vehicle_id WHERE i2.device_id=d.id AND i2.status='aktywna' ORDER BY i2.id DESC LIMIT 1),
               (SELECT COALESCE(i3.client_id, vv2.client_id) FROM installations i3 LEFT JOIN vehicles vv2 ON vv2.id=i3.vehicle_id WHERE i3.device_id=d.id ORDER BY i3.id DESC LIMIT 1),
               0) as client_id,
           COALESCE(
               (SELECT v.registration FROM installations i3 JOIN vehicles v ON v.id=i3.vehicle_id WHERE i3.device_id=d.id AND i3.status='aktywna' ORDER BY i3.id DESC LIMIT 1),
               (SELECT v.registration FROM installations i4 JOIN vehicles v ON v.id=i4.vehicle_id WHERE i4.device_id=d.id ORDER BY i4.id DESC LIMIT 1)
           ) as active_registration
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status != 'wycofany'
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

// Recent installations – grouped by batch_id so multi-device batches appear as one row
// Fetch more rows than needed so PHP grouping can collapse batches into 5 display items
$dashInstallationsFetchLimit = 30;
$recentInstallationRows = $db->query("
    SELECT i.id, i.installation_date, i.status, i.batch_id,
           d.serial_number, m.name as model_name, mf.name as manufacturer_name,
           v.registration, v.make, v.model_name as vehicle_model,
           c.contact_name as client_name, c.company_name
    FROM installations i
    JOIN devices d ON d.id = i.device_id
    JOIN models m ON m.id = d.model_id
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    JOIN vehicles v ON v.id = i.vehicle_id
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE i.status = 'aktywna'
    ORDER BY i.created_at DESC
    LIMIT $dashInstallationsFetchLimit
")->fetchAll();

// Collapse rows into display items: batches become a single grouped entry
$recentInstallations = [];
$seenBatches = [];
foreach ($recentInstallationRows as $row) {
    $bid = $row['batch_id'] ?? null;
    if ($bid !== null) {
        if (isset($seenBatches[$bid])) {
            // Append extra device info to existing batch entry
            $idx = $seenBatches[$bid];
            $recentInstallations[$idx]['device_count']++;
            $recentInstallations[$idx]['extra_serials'][] = $row['serial_number'];
        } else {
            $seenBatches[$bid] = count($recentInstallations);
            $row['is_batch']       = true;
            $row['device_count']   = 1;
            $row['extra_serials']  = [];
            $row['batch_link_id']  = $row['id']; // first installation id of batch
            $recentInstallations[] = $row;
        }
    } else {
        $row['is_batch']     = false;
        $row['device_count'] = 1;
        $recentInstallations[] = $row;
    }
    if (count($recentInstallations) >= 3) break;
}

// Recent work orders (Ostatnie zlecenia)
$recentOrders = [];
try {
    $recentOrders = $db->query("
        SELECT wo.id, wo.order_number, wo.date, wo.status,
               wo.installation_address,
               c.contact_name, c.company_name,
               u.name as technician_name,
               (SELECT COUNT(*) FROM installations i WHERE i.work_order_id=wo.id) as device_count
        FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        LEFT JOIN users u ON u.id=wo.technician_id
        WHERE wo.status NOT IN ('zakonczone', 'archiwum')
        ORDER BY wo.date DESC, wo.id DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) { $recentOrders = []; }

// Upcoming services
$upcomingServices = $db->query("
    SELECT s.id, s.planned_date, s.type, s.status, s.description,
           d.serial_number, m.name as model_name,
           v.registration, v.make,
           u.name as technician_name
    FROM services s
    JOIN devices d ON d.id = s.device_id
    JOIN models m ON m.id = d.model_id
    LEFT JOIN installations inst ON inst.id = s.installation_id
    LEFT JOIN vehicles v ON v.id = inst.vehicle_id
    LEFT JOIN users u ON u.id = s.technician_id
    WHERE s.status IN ('zaplanowany', 'w_trakcie')
    ORDER BY s.planned_date ASC
    LIMIT 3
")->fetchAll();

// Low stock
$lowStock = $db->query("
    SELECT i.quantity, i.min_quantity, m.name as model_name, mf.name as manufacturer_name
    FROM inventory i
    JOIN models m ON m.id = i.model_id
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    WHERE i.quantity <= i.min_quantity
    ORDER BY i.quantity ASC
    LIMIT 5
")->fetchAll();

$activePage = 'dashboard';
$pageTitle = 'Panel główny';
include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="page-header">
            <h1><i class="fas fa-tachometer-alt me-2 text-primary"></i>Panel główny</h1>
            <span class="text-muted"><?= formatPolishDate() ?></span>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card text-white" style="background: linear-gradient(135deg, #0d6efd, #0b5ed7)">
            <div class="card-body d-flex align-items-center">
                <div class="flex-grow-1">
                    <div class="stat-number"><?= $stats['total_devices'] ?></div>
                    <div class="small opacity-75">Urządzeń</div>
                </div>
                <i class="fas fa-microchip stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="devices.php" class="text-white-50 small text-decoration-none">
                    <i class="fas fa-arrow-right me-1"></i>Zobacz wszystkie
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-white" style="background: linear-gradient(135deg, #198754, #157347)">
            <div class="card-body d-flex align-items-center">
                <div class="flex-grow-1">
                    <div class="stat-number"><?= $stats['active_installations'] ?></div>
                    <div class="small opacity-75">Zlecenia</div>
                </div>
                <i class="fas fa-clipboard-list stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="orders.php" class="text-white-50 small text-decoration-none">
                    <i class="fas fa-arrow-right me-1"></i>Zobacz zlecenia
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-white" style="background: linear-gradient(135deg, #fd7e14, #dc6c0a)">
            <div class="card-body d-flex align-items-center">
                <div class="flex-grow-1">
                    <div class="stat-number"><?= $stats['pending_services'] ?></div>
                    <div class="small opacity-75">Serwisy</div>
                </div>
                <i class="fas fa-wrench stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="services.php" class="text-white-50 small text-decoration-none">
                    <i class="fas fa-arrow-right me-1"></i>Zobacz serwisy
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-white" style="background: linear-gradient(135deg, #6f42c1, #5a32a3)">
            <div class="card-body d-flex align-items-center">
                <div class="flex-grow-1">
                    <div class="stat-number"><?= $stats['total_stock'] ?></div>
                    <div class="small opacity-75">Magazyn</div>
                </div>
                <i class="fas fa-warehouse stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="inventory.php" class="text-white-50 small text-decoration-none">
                    <i class="fas fa-arrow-right me-1"></i>Stan magazynu
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent Orders (Ostatnie zlecenia) -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-clipboard-list me-2 text-success"></i>Ostatnie zlecenia</span>
                <a href="orders.php" class="btn btn-sm btn-outline-primary">Wszystkie</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentOrders)): ?>
                <div class="p-3 text-muted text-center">Brak zleceń. <a href="#" onclick="dashOpenNewOrder(); return false;">Utwórz pierwsze zlecenie</a>.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentOrders as $ord): ?>
                    <?php
                    $orderStatusMap = [
                        'nowe'      => ['primary', 'Nowe'],
                        'w_trakcie' => ['warning', 'W trakcie'],
                        'zakonczone'=> ['success', 'Zakończone'],
                        'anulowane' => ['danger', 'Anulowane'],
                    ];
                    $osi = $orderStatusMap[$ord['status']] ?? ['secondary', $ord['status']];
                    ?>
                    <a href="orders.php?action=view&id=<?= $ord['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?= h($ord['order_number']) ?></div>
                                <?php if ($ord['company_name'] || $ord['contact_name']): ?>
                                <small class="text-muted"><i class="fas fa-user me-1"></i><?= h($ord['company_name'] ?: $ord['contact_name']) ?></small>
                                <?php endif; ?>
                                <?php if ($ord['installation_address']): ?>
                                <br><small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= h($ord['installation_address']) ?></small>
                                <?php endif; ?>
                                <?php if ($ord['technician_name']): ?>
                                <br><small class="text-muted"><i class="fas fa-user-cog me-1"></i><?= h($ord['technician_name']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= $osi[0] ?>"><?= h($osi[1]) ?></span>
                                <br><small class="text-muted"><?= formatDate($ord['date']) ?></small>
                                <?php if ($ord['device_count'] > 0): ?>
                                <br><small class="badge bg-secondary"><?= (int)$ord['device_count'] ?> urządz.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Services -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-wrench me-2 text-warning"></i>Nadchodzące serwisy</span>
                <a href="services.php" class="btn btn-sm btn-outline-primary">Wszystkie</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcomingServices)): ?>
                <div class="p-3 text-muted text-center">Brak zaplanowanych serwisów</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($upcomingServices as $svc): ?>
                    <a href="services.php?action=view&id=<?= $svc['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?= h($svc['model_name']) ?> — <?= h($svc['serial_number']) ?></div>
                                <?php if ($svc['registration']): ?>
                                <small class="text-muted"><i class="fas fa-car me-1"></i><?= h($svc['registration'] . ' ' . $svc['make']) ?></small><br>
                                <?php endif; ?>
                                <small class="text-muted"><?= h($svc['description'] ?? '') ?></small>
                            </div>
                            <div class="text-end">
                                <?= getStatusBadge($svc['status'], 'service') ?>
                                <br><small class="text-muted"><?= formatDate($svc['planned_date']) ?></small>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="fas fa-bolt me-2 text-primary"></i>Szybkie akcje</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php if (isAdmin()): ?>
                    <div class="col-6 col-md-auto flex-fill">
                        <button type="button" class="btn btn-outline-primary quick-action-btn w-100 d-flex flex-column align-items-center py-3" onclick="dashOpenAddDevice()">
                            <i class="fas fa-plus-circle fa-lg mb-1"></i>
                            <span class="small">Dodaj urządzenie</span>
                        </button>
                    </div>
                    <?php endif; ?>
                    <div class="col-6 col-md-auto flex-fill">
                        <button type="button" class="btn btn-outline-dark quick-action-btn w-100 d-flex flex-column align-items-center py-3" onclick="dashOpenAddSim()">
                            <i class="fas fa-sim-card fa-lg mb-1"></i>
                            <span class="small">Dodaj kartę SIM</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-auto flex-fill">
                        <button type="button" class="btn btn-outline-success quick-action-btn w-100 d-flex flex-column align-items-center py-3" onclick="dashOpenNewOrder()">
                            <i class="fas fa-clipboard-list fa-lg mb-1"></i>
                            <span class="small">Nowe zlecenie</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-auto flex-fill">
                        <a href="orders.php?action=my" class="btn btn-outline-primary quick-action-btn w-100 d-flex flex-column align-items-center py-3">
                            <i class="fas fa-user-check fa-lg mb-1"></i>
                            <span class="small">Moje zlecenia</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-auto flex-fill">
                        <button type="button" class="btn btn-outline-warning quick-action-btn w-100 d-flex flex-column align-items-center py-3" onclick="dashOpenService()">
                            <i class="fas fa-wrench fa-lg mb-1"></i>
                            <span class="small">Nowy serwis</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-auto flex-fill">
                        <button type="button" class="btn btn-outline-secondary quick-action-btn w-100 d-flex flex-column align-items-center py-3" onclick="dashOpenClient()">
                            <i class="fas fa-user-plus fa-lg mb-1"></i>
                            <span class="small">Nowy klient</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-auto flex-fill">
                        <a href="calendar.php" class="btn btn-outline-info quick-action-btn w-100 d-flex flex-column align-items-center py-3">
                            <i class="fas fa-calendar-alt fa-lg mb-1"></i>
                            <span class="small">Kalendarz</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Low Stock Alert -->
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger bg-opacity-10 text-danger d-flex justify-content-between align-items-center">
                <span><i class="fas fa-exclamation-triangle me-2"></i>Niski stan magazynowy</span>
                <a href="inventory.php" class="btn btn-sm btn-outline-danger">Magazyn</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if (empty($lowStock)): ?>
                        <tr><td colspan="2" class="text-center text-muted p-2">Brak pozycji poniżej minimalnego stanu.</td></tr>
                        <?php else: ?>
                        <?php foreach ($lowStock as $item): ?>
                        <tr>
                            <td><?= h($item['manufacturer_name'] . ' ' . $item['model_name']) ?></td>
                            <td class="text-end">
                                <span class="<?= $item['quantity'] == 0 ? 'text-danger fw-bold' : 'text-warning fw-bold' ?>">
                                    <?= $item['quantity'] ?> szt
                                </span>
                                <span class="text-muted"> / min <?= $item['min_quantity'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php // ===================== MODALE SZYBKICH AKCJI ===================== ?>

<?php if (isAdmin()): ?>
<!-- Modal: Dodaj urządzenie (z możliwością dodania kilku naraz) -->
<div class="modal fade" id="dashAddDevicesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="devices.php" id="dashAddDevicesForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_add_devices">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2 text-primary"></i>Dodaj urządzenie / urządzenia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3 pb-3 border-bottom">
                        <div class="col-md-4">
                            <label class="form-label required-star">Model urządzenia (wspólny)</label>
                            <select name="model_id" id="dashAddModel" class="form-select" required>
                                <option value="">— wybierz model —</option>
                                <?php
                                $dashCurMf = '';
                                foreach ($dashModels as $m):
                                    if ($m['manufacturer_name'] !== $dashCurMf) {
                                        if ($dashCurMf) echo '</optgroup>';
                                        echo '<optgroup label="' . h($m['manufacturer_name']) . '">';
                                        $dashCurMf = $m['manufacturer_name'];
                                    }
                                ?>
                                <option value="<?= $m['id'] ?>"><?= h($m['name']) ?></option>
                                <?php endforeach; if ($dashCurMf) echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status (wspólny)</label>
                            <select name="status" class="form-select">
                                <?php foreach (['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa'] as $s): ?>
                                <option value="<?= $s ?>" <?= $s === 'nowy' ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_',' ',$s))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Data zakupu (wspólna)</label>
                            <input type="date" name="purchase_date" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cena zakupu (wspólna)</label>
                            <div class="input-group">
                                <input type="number" name="purchase_price" class="form-control" min="0" step="0.01" value="0">
                                <span class="input-group-text">zł</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold text-muted small">Urządzenia do dodania</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="dashDeviceAddRow()"><i class="fas fa-plus me-1"></i>Dodaj wiersz</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:30px">#</th>
                                    <th>Nr seryjny <span class="text-danger">*</span></th>
                                    <th>IMEI</th>
                                    <th>Nr telefonu SIM</th>
                                    <th>Uwagi</th>
                                    <th style="width:42px"></th>
                                </tr>
                            </thead>
                            <tbody id="dashDevicesBody"></tbody>
                        </table>
                    </div>
                    <div class="mt-2 text-muted small" id="dashDevicesCount">0 urządzeń do dodania</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Zapisz urządzenia</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<datalist id="dashSimListAdd">
    <?php foreach ($dashSimOptions as $sc): ?>
    <option value="<?= h($sc) ?>">
    <?php endforeach; ?>
</datalist>

<!-- Modal: Nowy montaż — pełny formularz z wieloma urządzeniami (Auto / Ręczny) -->
<div class="modal fade" id="dashInstallModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="installations.php" id="dashInstallForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-car me-2 text-success"></i>Nowy montaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Urządzenia GPS — multi-device rows -->
                        <div class="col-12">
                            <label class="form-label required-star">Urządzenia GPS do montażu</label>
                            <?php if (empty($dashAvailableModels) && empty($dashAvailableDevices)): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <i class="fas fa-exclamation-triangle me-2"></i>Brak dostępnych urządzeń w magazynie.
                                <a href="devices.php?action=add">Dodaj urządzenia</a> lub sprawdź stan magazynu.
                            </div>
                            <?php endif; ?>
                            <div id="dashInstDevRowsContainer" class="d-flex flex-column gap-2 mb-2">
                                <!-- Pierwszy wiersz (index 0) -->
                                <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                                    <div class="row g-2 align-items-center flex-wrap">
                                        <div class="col-auto">
                                            <span class="row-num badge bg-secondary">1</span>
                                        </div>
                                        <div class="col-auto">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="dim_auto_0" value="auto" checked>
                                                <label class="btn btn-outline-secondary" for="dim_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="dim_manual_0" value="manual">
                                                <label class="btn btn-outline-primary" for="dim_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm col-mode-auto">
                                            <select name="model_id[0]" class="form-select form-select-sm">
                                                <option value="">— wybierz model —</option>
                                                <?php foreach ($dashAvailableModels as $m): ?>
                                                <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm col-mode-manual" style="display:none">
                                            <select name="device_id_manual[0]" class="form-select form-select-sm ts-device-dash">
                                                <option value="">— wybierz urządzenie —</option>
                                                <?php
                                                $dimGroup = '';
                                                foreach ($dashAvailableDevices as $dev):
                                                    $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                                    if ($grp !== $dimGroup) {
                                                        if ($dimGroup) echo '</optgroup>';
                                                        echo '<optgroup label="' . h($grp) . '">';
                                                        $dimGroup = $grp;
                                                    }
                                                ?>
                                                <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?></option>
                                                <?php endforeach; if ($dimGroup) echo '</optgroup>'; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-auto">
                                            <input type="text" name="vehicle_registration[0]" class="form-control form-control-sm"
                                                   required placeholder="Nr rej. pojazdu"
                                                   style="text-transform:uppercase;min-width:130px">
                                        </div>
                                        <div class="col-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none"
                                                    title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div><!-- #dashInstDevRowsContainer -->
                            <button type="button" id="dashInstAddDevRowBtn" class="btn btn-sm btn-outline-success"
                                    <?= (empty($dashAvailableModels) && empty($dashAvailableDevices)) ? 'disabled' : '' ?>>
                                <i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie
                            </button>
                        </div>

                        <!-- Klient + quick add -->
                        <div class="col-md-6">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="dashInstClientSelect" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($dashClients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="dashInstQuickClientBtn" title="Dodaj nowego klienta">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                            </div>
                        </div>
                        <!-- Adres instalacji -->
                        <div class="col-md-6">
                            <label class="form-label">Adres instalacji</label>
                            <input type="text" name="installation_address" id="dashInstAddrField" class="form-control"
                                   placeholder="Automatycznie z klienta lub wpisz ręcznie">
                        </div>
                        <!-- Technik -->
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($dashUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Data montażu -->
                        <div class="col-md-6">
                            <label class="form-label required-star">Data montażu</label>
                            <input type="date" name="installation_date" id="dashInstDateField" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <!-- Status -->
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="aktywna" selected>Aktywna</option>
                                <option value="zakonczona">Zakończona</option>
                                <option value="anulowana">Anulowana</option>
                            </select>
                        </div>
                        <!-- Miejsce montażu -->
                        <div class="col-md-6">
                            <label class="form-label">Miejsce montażu w pojeździe</label>
                            <input type="text" name="location_in_vehicle" class="form-control" placeholder="np. pod deską rozdzielczą">
                        </div>
                        <!-- Uwagi -->
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
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

<!-- Quick-add klienta (wewnątrz modalu montażu) -->
<div class="modal fade" id="dashInstQuickClientModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="dashInstQCErr"></div>
                <div class="mb-2">
                    <label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label>
                    <input type="text" id="dashInstQCName" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Nazwa firmy</label>
                    <input type="text" id="dashInstQCCompany" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Telefon</label>
                    <input type="text" id="dashInstQCPhone" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">E-mail</label>
                    <input type="email" id="dashInstQCEmail" class="form-control form-control-sm">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="dashInstQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<!-- Template dla nowych wierszy urządzeń w montażu (dashboard) -->
<template id="dashInstDevRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center flex-wrap">
            <div class="col-auto">
                <span class="row-num badge bg-secondary">__NUM__</span>
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="dim_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="dim_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="dim_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="dim_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                </div>
            </div>
            <div class="col-12 col-sm col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($dashAvailableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm ts-device-dash">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $tplDimGroup = '';
                    foreach ($dashAvailableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $tplDimGroup) {
                            if ($tplDimGroup) echo '</optgroup>';
                            echo '<optgroup label="' . h($grp) . '">';
                            $tplDimGroup = $grp;
                        }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?></option>
                    <?php endforeach; if ($tplDimGroup) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-12 col-sm-auto">
                <input type="text" name="vehicle_registration[__IDX__]" class="form-control form-control-sm"
                       required placeholder="Nr rej. pojazdu"
                       style="text-transform:uppercase;min-width:130px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn"
                        title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>


<div class="modal fade" id="dashServiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="services.php" id="dashServiceForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wrench me-2 text-warning"></i>Nowy serwis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Filtruj wg klienta</label>
                            <select id="dashSvcClientFilter" class="form-select form-select-sm">
                                <option value="">— wszyscy klienci —</option>
                                <?php foreach ($dashClients as $cl): ?>
                                <option value="<?= $cl['id'] ?>">
                                    <?= h($cl['company_name'] ?: $cl['contact_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label required-star">Urządzenie GPS</label>
                            <input type="text" id="dashSvcDevSearch" class="form-control form-control-sm mb-1"
                                   placeholder="Szukaj urządzenia (nr seryjny, model, rejestracja…)" autocomplete="off">
                            <select name="device_id" id="dashSvcDevSelect" class="form-select" required size="5" style="height:auto">
                                <option value="">— wybierz urządzenie —</option>
                                <?php
                                $dashSvcGroup = '';
                                foreach ($dashSvcAllDevices as $dd):
                                    $grp = $dd['manufacturer_name'] . ' ' . $dd['model_name'];
                                    if ($grp !== $dashSvcGroup) {
                                        if ($dashSvcGroup) echo '</optgroup>';
                                        echo '<optgroup label="' . h($grp) . '">';
                                        $dashSvcGroup = $grp;
                                    }
                                ?>
                                <option value="<?= $dd['id'] ?>"
                                        data-client="<?= (int)$dd['client_id'] ?>"
                                        data-search="<?= h(strtolower($dd['serial_number'] . ' ' . $dd['model_name'] . ' ' . $dd['manufacturer_name'] . ' ' . ($dd['active_registration'] ?? ''))) ?>">
                                    <?= h($dd['serial_number']) ?> — <?= h($dd['manufacturer_name'] . ' ' . $dd['model_name']) ?><?= $dd['active_registration'] ? ' [' . h($dd['active_registration']) . ']' : '' ?>
                                </option>
                                <?php endforeach; if ($dashSvcGroup) echo '</optgroup>'; ?>
                            </select>
                            <div class="form-text">Wpisz fragment numeru seryjnego, modelu, producenta lub tablicy rejestracyjnej aby przefiltrować listę.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Typ serwisu</label>
                            <select name="type" class="form-select">
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
                            <input type="date" name="planned_date" id="dashSvcPlannedDate" class="form-control" required>
                        </div>
                        <?php if (!empty($dashUsers)): ?>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($dashUsers as $u): ?>
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

<!-- Modal: Nowy klient -->
<div class="modal fade" id="dashClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="clients.php" id="dashClientForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="active" value="1">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2 text-secondary"></i>Nowy klient</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nazwa firmy</label>
                            <input type="text" name="company_name" class="form-control" placeholder="Opcjonalne">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Imię i nazwisko kontaktu</label>
                            <input type="text" name="contact_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Adres</label>
                            <input type="text" name="address" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kod pocztowy</label>
                            <input type="text" name="postal_code" class="form-control" placeholder="00-000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Miasto</label>
                            <input type="text" name="city" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">NIP</label>
                            <input type="text" name="nip" class="form-control" placeholder="000-000-00-00">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save me-1"></i>Dodaj klienta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php // Modal: Dodaj kartę SIM — available to all logged-in users ?>
<!-- Modal: Dodaj kartę SIM -->
<div class="modal fade" id="dashAddSimModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="sim_cards.php" id="dashAddSimForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_add_sims">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sim-card me-2 text-dark"></i>Dodaj karty SIM</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3 pb-3 border-bottom">
                        <div class="col-md-4">
                            <label class="form-label">Operator <span class="text-muted">(wspólny dla wszystkich)</span></label>
                            <input type="text" name="operator" class="form-control"
                                   placeholder="np. Play, Orange, T-Mobile" maxlength="50">
                        </div>
                    </div>
                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold text-muted small">Karty SIM do dodania</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="dashSimAddRow()"><i class="fas fa-plus me-1"></i>Dodaj wiersz</button>
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
                            <tbody id="dashSimBulkBody"></tbody>
                        </table>
                    </div>
                    <div class="mt-2 text-muted small" id="dashSimBulkCount">0 kart do dodania</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-save me-1"></i>Zapisz karty SIM</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ===== MODAL: Dodaj urządzenia =====
var dashDevRowCount = 0;
function dashOpenAddDevice() {
    dashDevRowCount = 0;
    document.getElementById('dashDevicesBody').innerHTML = '';
    document.getElementById('dashAddModel').value = '';
    document.getElementById('dashDevicesCount').textContent = '0 urządzeń do dodania';
    dashDeviceAddRow();
    new bootstrap.Modal(document.getElementById('dashAddDevicesModal')).show();
}
function dashDeviceAddRow() {
    dashDevRowCount++;
    var n = dashDevRowCount;
    var tbody = document.getElementById('dashDevicesBody');
    var tr = document.createElement('tr');
    tr.id = 'dash-dev-row-' + n;
    tr.innerHTML =
        '<td class="text-muted text-center align-middle">' + n + '</td>' +
        '<td><input type="text" name="serial_numbers[]" class="form-control form-control-sm" placeholder="np. SN123456" required></td>' +
        '<td><input type="text" name="imeis[]" class="form-control form-control-sm" placeholder="15 cyfr" maxlength="20"></td>' +
        '<td><input type="text" name="sim_numbers[]" class="form-control form-control-sm" placeholder="np. +48 600 000 000" list="dashSimListAdd"></td>' +
        '<td><input type="text" name="notes_list[]" class="form-control form-control-sm" placeholder="Opcjonalne"></td>' +
        '<td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="dashDeviceRemoveRow(' + n + ')" title="Usuń"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input[name="serial_numbers[]"]').focus();
    dashUpdateDevCount();
}
function dashDeviceRemoveRow(n) {
    var row = document.getElementById('dash-dev-row-' + n);
    if (row) { row.remove(); dashUpdateDevCount(); }
}
function dashUpdateDevCount() {
    var rows = document.querySelectorAll('#dashDevicesBody tr').length;
    var el = document.getElementById('dashDevicesCount');
    if (!el) return;
    if (rows === 0) el.textContent = '0 urządzeń do dodania';
    else if (rows === 1) el.textContent = '1 urządzenie do dodania';
    else if (rows <= 4) el.textContent = rows + ' urządzenia do dodania';
    else el.textContent = rows + ' urządzeń do dodania';
}

// ===== MODAL: Nowy montaż — wielourządzeniowy z TomSelect =====
window.flDashDevices = <?= json_encode(array_values(array_map(function($d) {
    $text = $d['serial_number'];
    if ($d['imei'])       $text .= ' [' . $d['imei'] . ']';
    if ($d['sim_number']) $text .= ' (' . $d['sim_number'] . ')';
    return ['value' => (string)$d['id'], 'text' => $text];
}, $dashAvailableDevices))) ?>;

var dashInstClientAddresses = <?= json_encode(array_reduce($dashClients, function($carry, $c) {
    $parts = array_filter([$c['address'] ?? '', trim(($c['postal_code'] ?? '') . ' ' . ($c['city'] ?? ''))]);
    $carry[(string)$c['id']] = implode(', ', $parts);
    return $carry;
}, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function dashOpenInstall() {
    // Reset first row
    var container = document.getElementById('dashInstDevRowsContainer');
    // Remove all extra rows, keep only first
    Array.from(container.querySelectorAll('.device-row')).forEach(function(row, idx) {
        if (idx > 0) {
            dimDestroyTomSelect(row);
            row.remove();
        }
    });
    dimRowCounter = 1;
    // Reset first row fields
    var firstRow = container.querySelector('.device-row');
    if (firstRow) {
        firstRow.querySelector('input[name="vehicle_registration[0]"]').value = '';
        var autoRadio = firstRow.querySelector('input[value="auto"]');
        if (autoRadio) { autoRadio.checked = true; dimApplyMode(firstRow, 'auto'); }
        var modelSel = firstRow.querySelector('select[name="model_id[0]"]');
        if (modelSel) modelSel.value = '';
        dimDestroyTomSelect(firstRow);
    }
    // Reset common fields
    document.getElementById('dashInstClientSelect').value = '';
    document.getElementById('dashInstAddrField').value = '';
    document.getElementById('dashInstDateField').value = new Date().toISOString().slice(0, 10);
    new bootstrap.Modal(document.getElementById('dashInstallModal')).show();
}

var dimRowCounter = 1;

function dimSyncDeviceDropdowns() {
    var container = document.getElementById('dashInstDevRowsContainer');
    if (!container) return;
    var rows = Array.from(container.querySelectorAll('.device-row'));
    var rowValues = new Map();
    rows.forEach(function(row) {
        var sel = row.querySelector('select.ts-device-dash');
        if (!sel || !sel.tomselect) return;
        rowValues.set(row, sel.tomselect.getValue() || '');
    });
    rows.forEach(function(row) {
        var sel = row.querySelector('select.ts-device-dash');
        if (!sel || !sel.tomselect) return;
        var ts = sel.tomselect;
        var myVal = rowValues.get(row) || '';
        var othersTaken = new Set();
        rowValues.forEach(function(val, r) { if (r !== row && val) othersTaken.add(val); });
        (window.flDashDevices || []).forEach(function(dev) {
            if (othersTaken.has(dev.value)) {
                if (ts.options[dev.value]) ts.removeOption(dev.value);
            } else {
                if (!ts.options[dev.value]) ts.addOption({ value: dev.value, text: dev.text });
            }
        });
        ts.refreshOptions(false);
        if (myVal && ts.options[myVal]) ts.setValue(myVal, true);
    });
}

function dimInitTomSelect(row) {
    row.querySelectorAll('select.ts-device-dash').forEach(function(sel) {
        if (sel.tomselect) return;
        if (typeof TomSelect === 'undefined') return;
        var ts = new TomSelect(sel, {
            placeholder: '— szukaj urządzenia —',
            allowEmptyOption: true,
            maxOptions: null,
            searchField: ['text', 'value'],
            render: { option: function(data, escape) { return '<div>' + escape(data.text) + '</div>'; } },
            onDropdownOpen: function() {
                var mb = document.querySelector('#dashInstallModal .modal-body');
                if (mb) mb.style.overflow = 'visible';
            },
            onDropdownClose: function() {
                var mb = document.querySelector('#dashInstallModal .modal-body');
                if (mb) mb.style.overflow = '';
            }
        });
        void ts;
    });
}

function dimDestroyTomSelect(row) {
    row.querySelectorAll('select.ts-device-dash').forEach(function(sel) {
        if (sel.tomselect) sel.tomselect.destroy();
    });
}

function dimUpdateRowNumbers() {
    var container = document.getElementById('dashInstDevRowsContainer');
    if (!container) return;
    var rows = container.querySelectorAll('.device-row');
    rows.forEach(function(row, i) {
        var numEl = row.querySelector('.row-num');
        if (numEl) numEl.textContent = i + 1;
        var btn = row.querySelector('.remove-row-btn');
        if (btn) btn.style.display = rows.length > 1 ? '' : 'none';
    });
}

function dimApplyMode(row, mode) {
    var autoCol   = row.querySelector('.col-mode-auto');
    var manualCol = row.querySelector('.col-mode-manual');
    if (autoCol)   autoCol.style.display   = mode === 'auto'   ? '' : 'none';
    if (manualCol) manualCol.style.display = mode === 'manual' ? '' : 'none';
    if (mode === 'manual') {
        dimInitTomSelect(row);
        dimSyncDeviceDropdowns();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('dashInstDevRowsContainer');
    var addBtn    = document.getElementById('dashInstAddDevRowBtn');

    if (container) {
        // Event delegation: mode toggle + device change
        container.addEventListener('change', function(e) {
            if (e.target.type === 'radio' && e.target.name && e.target.name.startsWith('device_mode')) {
                dimApplyMode(e.target.closest('.device-row'), e.target.value);
            }
            if (e.target.classList.contains('ts-device-dash') || e.target.closest('select.ts-device-dash')) {
                dimSyncDeviceDropdowns();
            }
        });
        // Event delegation: remove row
        container.addEventListener('click', function(e) {
            var btn = e.target.closest('.remove-row-btn');
            if (btn) {
                var row = btn.closest('.device-row');
                dimDestroyTomSelect(row);
                row.remove();
                dimUpdateRowNumbers();
                dimSyncDeviceDropdowns();
            }
        });
        // Init first row
        container.querySelectorAll('.device-row').forEach(function(row) {
            var checked = row.querySelector('.btn-check:checked');
            if (checked) dimApplyMode(row, checked.value);
        });
        dimUpdateRowNumbers();
    }

    if (addBtn) {
        addBtn.addEventListener('click', function() {
            var tpl = document.getElementById('dashInstDevRowTemplate');
            if (!tpl) return;
            var idx   = dimRowCounter++;
            var clone = tpl.content.cloneNode(true);
            clone.querySelectorAll('[name]').forEach(function(el) { el.name = el.name.replace(/__IDX__/g, idx); });
            clone.querySelectorAll('[id]').forEach(function(el)   { el.id   = el.id.replace(/__IDX__/g, idx); });
            clone.querySelectorAll('[for]').forEach(function(el)  { el.htmlFor = el.htmlFor.replace(/__IDX__/g, idx); });
            container.appendChild(clone);
            dimUpdateRowNumbers();
        });
    }

    // Auto-fill address on client change
    var cliSel = document.getElementById('dashInstClientSelect');
    var addrFld = document.getElementById('dashInstAddrField');
    if (cliSel && addrFld) {
        cliSel.addEventListener('change', function() {
            var val = this.value;
            if (val && dashInstClientAddresses[val]) {
                addrFld.value = dashInstClientAddresses[val];
            } else if (!val) {
                addrFld.value = '';
            }
        });
    }

    // Quick-add client button
    var qcBtn = document.getElementById('dashInstQuickClientBtn');
    if (qcBtn) {
        qcBtn.addEventListener('click', function() {
            document.getElementById('dashInstQCName').value = '';
            document.getElementById('dashInstQCCompany').value = '';
            document.getElementById('dashInstQCPhone').value = '';
            document.getElementById('dashInstQCEmail').value = '';
            var errEl = document.getElementById('dashInstQCErr');
            if (errEl) errEl.classList.add('d-none');
            new bootstrap.Modal(document.getElementById('dashInstQuickClientModal')).show();
        });
    }

    // Save quick client
    var qcSave = document.getElementById('dashInstQCSaveBtn');
    if (qcSave) {
        qcSave.addEventListener('click', function() {
            var name = document.getElementById('dashInstQCName').value.trim();
            var errEl = document.getElementById('dashInstQCErr');
            if (!name) { errEl.textContent = 'Imię i nazwisko kontaktu jest wymagane.'; errEl.classList.remove('d-none'); return; }
            errEl.classList.add('d-none');
            var fd = new FormData();
            fd.append('action', 'quick_add_client');
            fd.append('csrf_token', document.querySelector('#dashInstallForm input[name="csrf_token"]').value);
            fd.append('contact_name', name);
            fd.append('company_name', document.getElementById('dashInstQCCompany').value);
            fd.append('phone', document.getElementById('dashInstQCPhone').value);
            fd.append('email', document.getElementById('dashInstQCEmail').value);
            fetch('installations.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
                    var sel = document.getElementById('dashInstClientSelect');
                    var opt = document.createElement('option');
                    opt.value = data.id;
                    opt.textContent = data.label;
                    opt.selected = true;
                    sel.appendChild(opt);
                    sel.dispatchEvent(new Event('change'));
                    bootstrap.Modal.getInstance(document.getElementById('dashInstQuickClientModal')).hide();
                })
                .catch(function() { errEl.textContent = 'Błąd połączenia z serwerem.'; errEl.classList.remove('d-none'); });
        });
    }

    // ===== Wyszukiwanie w selekcji urządzenia dla serwisu =====
    var svcSearch = document.getElementById('dashSvcDevSearch');
    var svcClientFilter = document.getElementById('dashSvcClientFilter');
    function dashSvcFilterDevices() {
        var q = (svcSearch ? svcSearch.value : '').toLowerCase().trim();
        var clientId = svcClientFilter ? parseInt(svcClientFilter.value) || 0 : 0;
        var groups = document.querySelectorAll('#dashSvcDevSelect optgroup');
        groups.forEach(function(grp) {
            var anyVisible = false;
            grp.querySelectorAll('option[data-search]').forEach(function(opt) {
                var matchSearch = !q || (opt.dataset.search || '').includes(q);
                var matchClient = !clientId || parseInt(opt.dataset.client || '0') === clientId;
                opt.style.display = (matchSearch && matchClient) ? '' : 'none';
                if (matchSearch && matchClient) anyVisible = true;
            });
            grp.style.display = anyVisible ? '' : 'none';
        });
    }
    if (svcSearch) {
        svcSearch.addEventListener('input', dashSvcFilterDevices);
    }
    if (svcClientFilter) {
        svcClientFilter.addEventListener('change', dashSvcFilterDevices);
    }
});

// ===== MODAL: Nowy serwis =====
function dashOpenService() {
    document.getElementById('dashSvcDevSearch').value = '';
    document.getElementById('dashSvcDevSelect').value = '';
    document.getElementById('dashSvcClientFilter').value = '';
    // Reset optgroup / option visibility
    document.querySelectorAll('#dashSvcDevSelect optgroup').forEach(function(grp) { grp.style.display = ''; });
    document.querySelectorAll('#dashSvcDevSelect option[data-search]').forEach(function(opt) { opt.style.display = ''; });
    document.getElementById('dashSvcPlannedDate').value = new Date().toISOString().slice(0, 10);
    new bootstrap.Modal(document.getElementById('dashServiceModal')).show();
}

// ===== MODAL: Nowy klient =====
function dashOpenClient() {
    document.getElementById('dashClientForm').reset();
    new bootstrap.Modal(document.getElementById('dashClientModal')).show();
}

// ===== MODAL: Dodaj kartę SIM =====
var dashSimDevices = <?= json_encode(array_values(array_map(function($d) {
    return [
        'id'    => (string)$d['id'],
        'label' => $d['serial_number']
                   . ($d['imei']       ? ' [' . $d['imei'] . ']' : '')
                   . ($d['sim_number'] ? ' — SIM: ' . $d['sim_number'] : '')
                   . ' (' . $d['manufacturer_name'] . ' ' . $d['model_name'] . ')',
    ];
}, $dashAllDevicesForSim))) ?>;

var dashSimRowCount = 0;

function dashSimBuildDeviceSelect() {
    var opts = '<option value="">— brak —</option>';
    dashSimDevices.forEach(function(d) {
        opts += '<option value="' + d.id + '">' + d.label.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') + '</option>';
    });
    return '<select name="device_ids_sim[]" class="form-select form-select-sm">' + opts + '</select>';
}

function dashSimAddRow() {
    dashSimRowCount++;
    var n = dashSimRowCount;
    var tbody = document.getElementById('dashSimBulkBody');
    var tr = document.createElement('tr');
    tr.id = 'dash-sim-row-' + n;
    tr.innerHTML =
        '<td class="text-muted text-center align-middle">' + n + '</td>' +
        '<td><input type="text" name="phone_numbers[]" class="form-control form-control-sm" placeholder="+48 123 456 789" maxlength="30" required></td>' +
        '<td><input type="text" name="iccids[]" class="form-control form-control-sm" placeholder="20-cyfrowy ICCID" maxlength="25"></td>' +
        '<td>' + dashSimBuildDeviceSelect() + '</td>' +
        '<td><input type="text" name="notes_list[]" class="form-control form-control-sm" placeholder="Opcjonalne"></td>' +
        '<td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="dashSimRemoveRow(' + n + ')" title="Usuń"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input[name="phone_numbers[]"]').focus();
    dashSimUpdateCount();
}

function dashSimRemoveRow(n) {
    var row = document.getElementById('dash-sim-row-' + n);
    if (row) { row.remove(); dashSimUpdateCount(); }
}

function dashSimUpdateCount() {
    var rows = document.querySelectorAll('#dashSimBulkBody tr').length;
    var el = document.getElementById('dashSimBulkCount');
    if (!el) return;
    if (rows === 0) el.textContent = '0 kart do dodania';
    else if (rows === 1) el.textContent = '1 karta do dodania';
    else if (rows <= 4) el.textContent = rows + ' karty do dodania';
    else el.textContent = rows + ' kart do dodania';
}

function dashOpenAddSim() {
    dashSimRowCount = 0;
    document.getElementById('dashSimBulkBody').innerHTML = '';
    document.getElementById('dashAddSimForm').querySelector('[name="operator"]').value = '';
    dashSimUpdateCount();
    dashSimAddRow();
    new bootstrap.Modal(document.getElementById('dashAddSimModal')).show();
}

// ===== MODAL: Nowe zlecenie (Dashboard) =====
function dashOpenNewOrder() {
    var form = document.getElementById('dashNewOrderForm');
    if (form) form.reset();
    var dateInput = document.querySelector('#dashNewOrderForm [name=date]');
    if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];
    var addrField = document.getElementById('dashOrderAddressField');
    if (addrField) addrField.dataset.manuallyEdited = '';
    var errEl = document.getElementById('dashNewOrderErr');
    if (errEl) errEl.classList.add('d-none');
    new bootstrap.Modal(document.getElementById('dashNewOrderModal')).show();
}
</script>

<!-- ── MODAL: Nowe zlecenie (Dashboard) ──────────────────────────── -->
<div class="modal fade" id="dashNewOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2 text-success"></i>Nowe zlecenie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="dashNewOrderErr" class="alert alert-danger d-none"></div>
                <form id="dashNewOrderForm">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Data zlecenia</label>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Technik</label>
                            <select name="technician_id" class="form-select" required>
                                <option value="">— wybierz technika —</option>
                                <?php foreach ($dashUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == getCurrentUser()['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="dashOrderClientSelect" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($dashClients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"
                                            data-address="<?= h(trim(($cl['address'] ?? '') . ' ' . ($cl['city'] ?? ''))) ?>">
                                        <?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="dashOrderQuickClientBtn" title="Dodaj nowego klienta"><i class="fas fa-user-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Adres miejsca instalacji</label>
                            <input type="text" name="installation_address" id="dashOrderAddressField" class="form-control" placeholder="Automatycznie z danych klienta lub wpisz ręcznie">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi / opis zlecenia</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Dodatkowe informacje dla technika..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success" id="dashNewOrderSaveBtn"><i class="fas fa-save me-2"></i>Utwórz zlecenie</button>
            </div>
        </div>
    </div>
</div>
<!-- Quick-add klienta (dla modalu nowe zlecenie dashboard) -->
<div class="modal fade" id="dashOrderQuickClientModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="dashOrdQCErr"></div>
                <div class="mb-2"><label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label><input type="text" id="dashOrdQCName" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Nazwa firmy</label><input type="text" id="dashOrdQCCompany" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Telefon</label><input type="text" id="dashOrdQCPhone" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">E-mail</label><input type="email" id="dashOrdQCEmail" class="form-control form-control-sm"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="dashOrdQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('dashOrderClientSelect').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var addr = opt ? (opt.getAttribute('data-address') || '') : '';
    var addrField = document.getElementById('dashOrderAddressField');
    if (addrField && addr && !addrField.dataset.manuallyEdited) { addrField.value = addr; }
});
document.getElementById('dashOrderAddressField').addEventListener('input', function() { this.dataset.manuallyEdited = '1'; });

document.getElementById('dashOrderQuickClientBtn').addEventListener('click', function() {
    new bootstrap.Modal(document.getElementById('dashOrderQuickClientModal')).show();
});
document.getElementById('dashOrdQCSaveBtn').addEventListener('click', function() {
    var name = document.getElementById('dashOrdQCName').value.trim();
    var company = document.getElementById('dashOrdQCCompany').value.trim();
    var phone = document.getElementById('dashOrdQCPhone').value.trim();
    var email = document.getElementById('dashOrdQCEmail').value.trim();
    var errEl = document.getElementById('dashOrdQCErr');
    if (!name) { errEl.textContent = 'Imię i nazwisko jest wymagane.'; errEl.classList.remove('d-none'); return; }
    errEl.classList.add('d-none');
    var fd = new FormData();
    fd.append('action', 'quick_add_client'); fd.append('contact_name', name);
    fd.append('company_name', company); fd.append('phone', phone); fd.append('email', email);
    fd.append('csrf_token', document.querySelector('#dashNewOrderForm [name=csrf_token]').value);
    fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function(data) {
        if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
        var sel = document.getElementById('dashOrderClientSelect');
        var opt = new Option(data.label, data.id, true, true);
        sel.add(opt); sel.dispatchEvent(new Event('change'));
        bootstrap.Modal.getInstance(document.getElementById('dashOrderQuickClientModal')).hide();
        document.getElementById('dashOrdQCName').value = '';
        document.getElementById('dashOrdQCCompany').value = '';
        document.getElementById('dashOrdQCPhone').value = '';
        document.getElementById('dashOrdQCEmail').value = '';
    }).catch(function() { errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none'); });
});

document.getElementById('dashNewOrderSaveBtn').addEventListener('click', function() {
    var btn = this;
    var form = document.getElementById('dashNewOrderForm');
    var errEl = document.getElementById('dashNewOrderErr');
    var date = form.querySelector('[name=date]').value;
    var tech = form.querySelector('[name=technician_id]').value;
    if (!date) { errEl.textContent = 'Data zlecenia jest wymagana.'; errEl.classList.remove('d-none'); return; }
    if (!tech) { errEl.textContent = 'Wybierz technika.'; errEl.classList.remove('d-none'); return; }
    errEl.classList.add('d-none');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zapisywanie...';
    var fd = new FormData(form);
    fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function(data) {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-2"></i>Utwórz zlecenie';
        if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
        if (data.redirect) { window.location.href = data.redirect; }
    }).catch(function() {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-2"></i>Utwórz zlecenie';
        errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none');
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
