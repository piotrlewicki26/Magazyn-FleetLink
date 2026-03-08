<?php
/**
 * FleetLink Magazyn - Dashboard
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

// Recent installations
$recentInstallations = $db->query("
    SELECT i.id, i.installation_date, i.status,
           d.serial_number, m.name as model_name, mf.name as manufacturer_name,
           v.registration, v.make, v.model_name as vehicle_model,
           c.contact_name as client_name, c.company_name
    FROM installations i
    JOIN devices d ON d.id = i.device_id
    JOIN models m ON m.id = d.model_id
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    JOIN vehicles v ON v.id = i.vehicle_id
    LEFT JOIN clients c ON c.id = i.client_id
    ORDER BY i.created_at DESC
    LIMIT 5
")->fetchAll();

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
    LIMIT 5
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
            <span class="text-muted"><?= date('l, d F Y', time()) ?></span>
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
                    <div class="small opacity-75">Aktywnych montaży</div>
                </div>
                <i class="fas fa-car stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="installations.php" class="text-white-50 small text-decoration-none">
                    <i class="fas fa-arrow-right me-1"></i>Zobacz wszystkie
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card text-white" style="background: linear-gradient(135deg, #fd7e14, #dc6c0a)">
            <div class="card-body d-flex align-items-center">
                <div class="flex-grow-1">
                    <div class="stat-number"><?= $stats['pending_services'] ?></div>
                    <div class="small opacity-75">Zaplanowanych serwisów</div>
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
                    <div class="small opacity-75">Szt. w magazynie</div>
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
    <!-- Recent Installations -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-car me-2 text-success"></i>Ostatnie montaże</span>
                <a href="installations.php" class="btn btn-sm btn-outline-primary">Wszystkie</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentInstallations)): ?>
                <div class="p-3 text-muted text-center">Brak montaży</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentInstallations as $inst): ?>
                    <a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?= h($inst['registration']) ?> — <?= h($inst['make'] . ' ' . $inst['vehicle_model']) ?></div>
                                <small class="text-muted"><?= h($inst['manufacturer_name'] . ' ' . $inst['model_name']) ?> / <?= h($inst['serial_number']) ?></small>
                                <?php if ($inst['client_name']): ?>
                                <br><small class="text-muted"><i class="fas fa-user me-1"></i><?= h($inst['company_name'] ?: $inst['client_name']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <?= getStatusBadge($inst['status'], 'installation') ?>
                                <br><small class="text-muted"><?= formatDate($inst['installation_date']) ?></small>
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

    <!-- Low Stock Alert -->
    <?php if (!empty($lowStock)): ?>
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger bg-opacity-10 text-danger d-flex justify-content-between align-items-center">
                <span><i class="fas fa-exclamation-triangle me-2"></i>Niski stan magazynowy</span>
                <a href="inventory.php" class="btn btn-sm btn-outline-danger">Magazyn</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
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
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="fas fa-bolt me-2 text-primary"></i>Szybkie akcje</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6 col-md-2">
                        <a href="devices.php?action=add" class="btn btn-outline-primary quick-action-btn w-100 d-flex flex-column align-items-center">
                            <i class="fas fa-plus-circle fa-2x mb-2"></i>
                            <span>Dodaj urządzenie</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="installations.php?action=add" class="btn btn-outline-success quick-action-btn w-100 d-flex flex-column align-items-center">
                            <i class="fas fa-car fa-2x mb-2"></i>
                            <span>Nowy montaż</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="services.php?action=add" class="btn btn-outline-warning quick-action-btn w-100 d-flex flex-column align-items-center">
                            <i class="fas fa-wrench fa-2x mb-2"></i>
                            <span>Nowy serwis</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="clients.php?action=add" class="btn btn-outline-secondary quick-action-btn w-100 d-flex flex-column align-items-center">
                            <i class="fas fa-user-plus fa-2x mb-2"></i>
                            <span>Nowy klient</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="calendar.php" class="btn btn-outline-dark quick-action-btn w-100 d-flex flex-column align-items-center">
                            <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                            <span>Kalendarz</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="statistics.php" class="btn btn-outline-info quick-action-btn w-100 d-flex flex-column align-items-center">
                            <i class="fas fa-chart-bar fa-2x mb-2"></i>
                            <span>Statystyki</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
