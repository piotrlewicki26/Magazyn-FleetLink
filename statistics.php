<?php
/**
 * FleetLink Magazyn - Statistics
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();

$year = (int)($_GET['year'] ?? date('Y'));

// ─── Initialise all variables with safe defaults ──────────────────────────
$installsByMonthData = array_fill(1, 12, 0);
$servicesByMonthData = array_fill(1, 12, 0);
$topDevices          = [];
$servicesByType      = [];
$topTechs            = [];
$totalServiceRevenue = 0.0;
$offerStatsData      = ['total' => 0, 'accepted' => 0, 'total_value' => 0, 'accepted_value' => 0];
$deviceStatusMap     = [];
$statsError          = null;

try {
// Installations by month
$installsByMonth = $db->prepare("
    SELECT DATE_FORMAT(installation_date,'%m') as month, COUNT(*) as count
    FROM installations
    WHERE YEAR(installation_date) = ?
    GROUP BY month
    ORDER BY month
");
$installsByMonth->execute([$year]);
foreach ($installsByMonth->fetchAll() as $row) {
    $installsByMonthData[(int)$row['month']] = (int)$row['count'];
}

// Services by month
$servicesByMonth = $db->prepare("
    SELECT DATE_FORMAT(planned_date,'%m') as month, COUNT(*) as count
    FROM services
    WHERE YEAR(planned_date) = ? AND status = 'zakończony'
    GROUP BY month
    ORDER BY month
");
$servicesByMonth->execute([$year]);
foreach ($servicesByMonth->fetchAll() as $row) {
    $servicesByMonthData[(int)$row['month']] = (int)$row['count'];
}

// Top devices by installations
$topDevices = $db->query("
    SELECT mf.name as manufacturer, m.name as model, COUNT(i.id) as install_count
    FROM installations i
    JOIN devices d ON d.id=i.device_id
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    GROUP BY m.id
    ORDER BY install_count DESC
    LIMIT 10
")->fetchAll();

// Services by type
$servicesByType = $db->query("
    SELECT type, COUNT(*) as count FROM services GROUP BY type ORDER BY count DESC
")->fetchAll();

// Top technicians
$topTechs = $db->query("
    SELECT u.name, 
           COUNT(DISTINCT i.id) as installs,
           COUNT(DISTINCT s.id) as services
    FROM users u
    LEFT JOIN installations i ON i.technician_id=u.id
    LEFT JOIN services s ON s.technician_id=u.id AND s.status='zakończony'
    GROUP BY u.id
    HAVING installs > 0 OR services > 0
    ORDER BY (installs + services) DESC
")->fetchAll();

// Revenue from services this year
$serviceRevenue = $db->prepare("
    SELECT SUM(cost) as total FROM services
    WHERE YEAR(completed_date)=? AND status='zakończony'
");
$serviceRevenue->execute([$year]);
$totalServiceRevenue = (float)($serviceRevenue->fetchColumn() ?? 0);

// Offer statistics
try {
    $offerStats = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='zaakceptowana' THEN 1 ELSE 0 END) as accepted,
            SUM(total_gross) as total_value,
            SUM(CASE WHEN status='zaakceptowana' THEN total_gross ELSE 0 END) as accepted_value
        FROM offers WHERE YEAR(created_at)=?
    ");
    $offerStats->execute([$year]);
    $offerRow = $offerStats->fetch();
    if ($offerRow) $offerStatsData = $offerRow;
} catch (Exception $e) {
    // offers table may not exist – silently skip
}

// Active devices count
$deviceStats = $db->query("
    SELECT status, COUNT(*) as count FROM devices GROUP BY status
")->fetchAll();
foreach ($deviceStats as $row) $deviceStatusMap[$row['status']] = $row['count'];

} catch (Exception $e) {
    $statsError = $e->getMessage();
}

$activePage = 'statistics';
$pageTitle = 'Statystyki';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar me-2 text-primary"></i>Statystyki</h1>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <label class="me-2 mb-0">Rok:</label>
        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<?php if ($statsError): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Nie udało się wczytać części danych statystycznych. Sprawdź, czy baza danych jest poprawnie skonfigurowana.
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-primary fw-bold"><?= array_sum($installsByMonthData) ?></div>
            <div class="text-muted small">Montaży w <?= $year ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-warning fw-bold"><?= array_sum($servicesByMonthData) ?></div>
            <div class="text-muted small">Serwisów w <?= $year ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-success fw-bold"><?= formatMoney($totalServiceRevenue) ?></div>
            <div class="text-muted small">Przychód serwisów <?= $year ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-info fw-bold"><?= (int)($offerStatsData['accepted'] ?? 0) ?> / <?= (int)($offerStatsData['total'] ?? 0) ?></div>
            <div class="text-muted small">Ofert zaakceptowanych/wystawionych <?= $year ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Monthly Chart -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Montaże i serwisy w <?= $year ?> r.</div>
            <div class="card-body">
                <canvas id="monthlyChart" height="120"></canvas>
            </div>
        </div>
    </div>

    <!-- Device Status -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Statusy urządzeń</div>
            <div class="card-body">
                <canvas id="deviceStatusChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Top Models -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-trophy me-2 text-warning"></i>Najpopularniejsze modele</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Model</th><th class="text-end">Montaże</th></tr></thead>
                    <tbody>
                        <?php foreach ($topDevices as $i => $d): ?>
                        <tr>
                            <td><?= h($d['manufacturer'] . ' ' . $d['model']) ?></td>
                            <td class="text-end fw-bold"><?= $d['install_count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDevices)): ?><tr><td colspan="2" class="text-muted text-center">Brak danych</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Services by Type -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-wrench me-2 text-info"></i>Serwisy według typu</div>
            <div class="card-body">
                <?php $totalSvc = array_sum(array_column($servicesByType, 'count')); ?>
                <?php foreach ($servicesByType as $svc): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small"><?= h(ucfirst($svc['type'])) ?></span>
                        <span class="small fw-bold"><?= $svc['count'] ?></span>
                    </div>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar" style="width:<?= $totalSvc > 0 ? round($svc['count']/$totalSvc*100) : 0 ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($servicesByType)): ?><p class="text-muted text-center">Brak danych</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Technicians -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-user-cog me-2 text-success"></i>Technicy</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Technik</th><th class="text-end">Montaże</th><th class="text-end">Serwisy</th></tr></thead>
                    <tbody>
                        <?php foreach ($topTechs as $tech): ?>
                        <tr>
                            <td><?= h($tech['name']) ?></td>
                            <td class="text-end"><?= $tech['installs'] ?></td>
                            <td class="text-end"><?= $tech['services'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topTechs)): ?><tr><td colspan="3" class="text-muted text-center">Brak danych</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const months = ['Sty','Lut','Mar','Apr','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'];
const instData = [<?= implode(',', array_values($installsByMonthData)) ?>];
const svcData  = [<?= implode(',', array_values($servicesByMonthData)) ?>];

new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: months,
        datasets: [
            { label: 'Montaże', data: instData, backgroundColor: 'rgba(13,110,253,0.8)', borderRadius: 4 },
            { label: 'Serwisy', data: svcData, backgroundColor: 'rgba(253,126,20,0.8)', borderRadius: 4 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

const deviceLabels = [<?= implode(',', array_map(fn($k) => '"' . ucfirst(str_replace('_',' ',$k)) . '"', array_keys($deviceStatusMap))) ?>];
const deviceData   = [<?= implode(',', array_values($deviceStatusMap)) ?>];
const deviceColors = ['#198754','#0d6efd','#fd7e14','#dc3545','#6f42c1','#6c757d'];

new Chart(document.getElementById('deviceStatusChart'), {
    type: 'doughnut',
    data: {
        labels: deviceLabels,
        datasets: [{ data: deviceData, backgroundColor: deviceColors }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
