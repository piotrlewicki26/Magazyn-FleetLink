<?php
/**
 * FleetLink System GPS - Statistics
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

if (!isAdmin()) {
    flashError('Brak dostępu do statystyk.');
    redirect(getBaseUrl() . 'dashboard.php');
}

$db = getDb();
$dbDriver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$isSqlite = $dbDriver === 'sqlite';

/**
 * Return a whitelisted identifier.
 */
function statsAllowedIdentifier(string $value, array $allowedValues): ?string
{
    return in_array($value, $allowedValues, true) ? $value : null;
}

/**
 * Return whether a table is available.
 */
function statsTableExists(PDO $db, string $table): bool
{
    try {
        $allowedTable = statsAllowedIdentifier($table, ['work_orders', 'installations']);
        if ($allowedTable === null) {
            return false;
        }

        $db->query("SELECT 1 FROM `{$allowedTable}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Return whether a column is available.
 */
function statsColumnExists(PDO $db, string $table, string $column): bool
{
    try {
        $allowedTable  = statsAllowedIdentifier($table, ['installations', 'work_orders']);
        $allowedColumn = statsAllowedIdentifier($column, ['work_order_id', 'other_devices']);
        if ($allowedTable === null || $allowedColumn === null) {
            return false;
        }

        $db->query("SELECT `{$allowedColumn}` FROM `{$allowedTable}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fetch work-order installations grouped by month for the yearly view modal.
 * Returns array indexed 1..12, each element an array of order rows with:
 *   order_date, client_name, model_name, install_count.
 */
function statsGetYearlyMonthlyDetails(PDO $db, int $year, bool $isSqlite): array
{
    $yearExpr  = $isSqlite ? "strftime('%Y', wo.date) = ?" : 'YEAR(wo.date) = ?';
    $monthExpr = $isSqlite
        ? "CAST(strftime('%m', wo.date) AS INTEGER)"
        : 'MONTH(wo.date)';
    $modelExpr = $isSqlite
        ? "COALESCE(mf.name || ' ' || m.name, m.name, '—')"
        : "COALESCE(CONCAT(mf.name, ' ', m.name), m.name, '—')";

    $sql = "
        SELECT
            {$monthExpr} AS month_no,
            wo.date AS order_date,
            COALESCE(NULLIF(c.company_name,''), NULLIF(c.contact_name,''), '—') AS client_name,
            {$modelExpr} AS model_name,
            COUNT(i.id) AS install_count
        FROM work_orders wo
        LEFT JOIN clients c ON c.id = wo.client_id
        INNER JOIN installations i ON i.work_order_id = wo.id
        LEFT JOIN devices d ON d.id = i.device_id
        LEFT JOIN models m ON m.id = d.model_id
        LEFT JOIN manufacturers mf ON mf.id = m.manufacturer_id
        WHERE {$yearExpr}
        GROUP BY wo.id, wo.date, c.company_name, c.contact_name, m.id, mf.name, m.name
        ORDER BY wo.date, wo.id
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([(string)$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byMonth = array_fill(1, 12, []);
    foreach ($rows as $row) {
        $month = (int)$row['month_no'];
        if ($month >= 1 && $month <= 12) {
            $byMonth[$month][] = $row;
        }
    }

    return $byMonth;
}

$year = (int)($_GET['year'] ?? date('Y'));

$hasWorkOrdersTable = statsTableExists($db, 'work_orders');
$hasWorkOrderColumn = statsColumnExists($db, 'installations', 'work_order_id');

// ── Yearly stats data ──────────────────────────────────────────────────
$installsByMonthData = array_fill(1, 12, 0);
$servicesByMonthData = array_fill(1, 12, 0);
$topDevices = [];
$servicesByType = [];
$topTechnicians = [];
$totalServiceRevenue = 0.0;
$offerStats = ['total' => 0, 'accepted' => 0, 'total_value' => 0.0, 'accepted_value' => 0.0];
$deviceStatuses = [];
$statsWarnings = [];

{
    $monthInstallExpr = $isSqlite ? "CAST(strftime('%m', installation_date) AS INTEGER)" : 'MONTH(installation_date)';
    $monthServiceExpr = $isSqlite ? "CAST(strftime('%m', completed_date) AS INTEGER)" : 'MONTH(completed_date)';
    $yearInstallExpr = $isSqlite ? "strftime('%Y', installation_date) = ?" : 'YEAR(installation_date) = ?';
    $yearServiceExpr = $isSqlite ? "strftime('%Y', completed_date) = ?" : 'YEAR(completed_date) = ?';
    $yearCreatedExpr = $isSqlite ? "strftime('%Y', created_at) = ?" : 'YEAR(created_at) = ?';
    $yearParam = (string)$year;

    try {
        $stmt = $db->prepare("SELECT {$monthInstallExpr} AS month_no, COUNT(*) AS item_count FROM installations WHERE {$yearInstallExpr} GROUP BY month_no ORDER BY month_no");
        $stmt->execute([$yearParam]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $month = (int)($row['month_no'] ?? 0);
            if ($month >= 1 && $month <= 12) {
                $installsByMonthData[$month] = (int)$row['item_count'];
            }
        }
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać miesięcznych montaży.';
        error_log('statistics installs by month failed: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("SELECT {$monthServiceExpr} AS month_no, COUNT(*) AS item_count FROM services WHERE {$yearServiceExpr} AND status = 'zakończony' GROUP BY month_no ORDER BY month_no");
        $stmt->execute([$yearParam]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $month = (int)($row['month_no'] ?? 0);
            if ($month >= 1 && $month <= 12) {
                $servicesByMonthData[$month] = (int)$row['item_count'];
            }
        }
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać miesięcznych serwisów.';
        error_log('statistics services by month failed: ' . $e->getMessage());
    }

    try {
        $topDevices = $db->query("
            SELECT mf.name AS manufacturer_name, m.name AS model_name, COUNT(i.id) AS install_count
            FROM installations i
            JOIN devices d ON d.id = i.device_id
            JOIN models m ON m.id = d.model_id
            JOIN manufacturers mf ON mf.id = m.manufacturer_id
            GROUP BY m.id, mf.name, m.name
            ORDER BY install_count DESC, mf.name ASC, m.name ASC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać rankingu modeli.';
        error_log('statistics top models failed: ' . $e->getMessage());
    }

    try {
        $servicesByType = $db->query("SELECT type, COUNT(*) AS item_count FROM services GROUP BY type ORDER BY item_count DESC, type ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać typów serwisów.';
        error_log('statistics services by type failed: ' . $e->getMessage());
    }

    try {
        $topTechnicians = $db->query("
            SELECT
                u.name,
                COUNT(DISTINCT i.id) AS installs,
                COUNT(DISTINCT s.id) AS services
            FROM users u
            LEFT JOIN installations i ON i.technician_id = u.id
            LEFT JOIN services s ON s.technician_id = u.id AND s.status = 'zakończony'
            GROUP BY u.id, u.name
            HAVING installs > 0 OR services > 0
            ORDER BY (installs + services) DESC, u.name ASC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać statystyk techników.';
        error_log('statistics technicians failed: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(cost), 0) AS total_revenue FROM services WHERE {$yearServiceExpr} AND status = 'zakończony'");
        $stmt->execute([$yearParam]);
        $totalServiceRevenue = (float)($stmt->fetchColumn() ?? 0);
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać przychodu serwisów.';
        error_log('statistics service revenue failed: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'zaakceptowana' THEN 1 ELSE 0 END) AS accepted,
                COALESCE(SUM(total_gross), 0) AS total_value,
                COALESCE(SUM(CASE WHEN status = 'zaakceptowana' THEN total_gross ELSE 0 END), 0) AS accepted_value
            FROM offers
            WHERE {$yearCreatedExpr}
        ");
        $stmt->execute([$yearParam]);
        $offerStatsRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($offerStatsRow) {
            $offerStats = array_merge($offerStats, $offerStatsRow);
        }
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać statystyk ofert.';
        error_log('statistics offers failed: ' . $e->getMessage());
    }

    try {
        $deviceStatuses = $db->query("SELECT status, COUNT(*) AS item_count FROM devices GROUP BY status ORDER BY item_count DESC, status ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $statsWarnings[] = 'Nie udało się pobrać statusów urządzeń.';
        error_log('statistics device statuses failed: ' . $e->getMessage());
    }
}

// ── Yearly monthly details (for modal) ────────────────────────────────
$yearlyMonthlyDetails = array_fill(1, 12, []);
if ($hasWorkOrdersTable && $hasWorkOrderColumn) {
    try {
        $yearlyMonthlyDetails = statsGetYearlyMonthlyDetails($db, $year, $isSqlite);
    } catch (Throwable $e) {
        error_log('statistics yearly monthly details failed: ' . $e->getMessage());
    }
}

$statsError = count($statsWarnings) >= 3
    ? 'Część sekcji statystyk nie mogła zostać wczytana. Dane poniżej mogą być niepełne.'
    : null;

$acceptedOffers = (int)($offerStats['accepted'] ?? 0);
$totalOffers = (int)($offerStats['total'] ?? 0);
$acceptanceRate = $totalOffers > 0 ? round(($acceptedOffers / $totalOffers) * 100) : 0;
$deviceStatusChartLabels = array_map(static fn(array $row): string => ucfirst(str_replace('_', ' ', (string)$row['status'])), $deviceStatuses);
$deviceStatusChartValues = array_map(static fn(array $row): int => (int)$row['item_count'], $deviceStatuses);

$activePage = 'statistics';
$pageTitle = 'Statystyki';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-chart-bar me-2 text-primary"></i>Statystyki</h1>
        <p class="text-muted mb-0">Zestawienie montaży, serwisów, ofert i statusów urządzeń.</p>
    </div>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <label class="mb-0 fw-semibold">Rok:</label>
        <select name="year" class="form-select" onchange="this.form.submit()" style="min-width: 120px;">
            <?php for ($availableYear = (int)date('Y'); $availableYear >= (int)date('Y') - 5; $availableYear--): ?>
            <option value="<?= $availableYear ?>" <?= $availableYear === $year ? 'selected' : '' ?>><?= $availableYear ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<?php if ($statsError): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i><?= h($statsError) ?>
</div>
<?php endif; ?>

<!-- ═══ ZESTAWIENIE ROCZNE ════════════════════════════════════════════ -->

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Montaże w <?= $year ?></div>
                <div class="h3 fw-bold text-primary mb-0"><?= array_sum($installsByMonthData) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Serwisy zakończone</div>
                <div class="h3 fw-bold text-warning mb-0"><?= array_sum($servicesByMonthData) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Przychód serwisów</div>
                <div class="h3 fw-bold text-success mb-0"><?= formatMoney($totalServiceRevenue) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Akceptacja ofert</div>
                <div class="h3 fw-bold text-info mb-0"><?= $acceptanceRate ?>%</div>
                <div class="small text-muted mt-1"><?= $acceptedOffers ?> / <?= $totalOffers ?> zaakceptowanych</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-calendar-alt me-2 text-primary"></i>Miesięcznie — montaże i serwisy w <?= $year ?> roku</div>
            <div class="card-body">
                <canvas id="monthlyChart" height="120" style="cursor:pointer"></canvas>
                <div class="small text-muted mt-2"><i class="fas fa-hand-pointer me-1"></i>Kliknij na słupek miesiąca, aby zobaczyć szczegóły zleceń.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Statusy urządzeń</div>
            <div class="card-body">
                <?php if ($deviceStatuses): ?>
                <canvas id="deviceStatusChart" height="220"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-5">Brak danych o statusach urządzeń.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-trophy me-2 text-warning"></i>Najpopularniejsze modele</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th class="text-end">Montaże</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topDevices as $device): ?>
                        <tr>
                            <td><?= h($device['manufacturer_name'] . ' ' . $device['model_name']) ?></td>
                            <td class="text-end fw-semibold"><?= (int)$device['install_count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$topDevices): ?>
                        <tr><td colspan="2" class="text-center text-muted py-4">Brak danych.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-screwdriver-wrench me-2 text-info"></i>Serwisy według typu</div>
            <div class="card-body">
                <?php $serviceTypeTotal = array_sum(array_map(static fn(array $row): int => (int)$row['item_count'], $servicesByType)); ?>
                <?php foreach ($servicesByType as $serviceType): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between gap-3 mb-1">
                        <span><?= h(ucfirst((string)$serviceType['type'])) ?></span>
                        <span class="fw-semibold"><?= (int)$serviceType['item_count'] ?></span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar" role="progressbar" style="width: <?= $serviceTypeTotal > 0 ? round(((int)$serviceType['item_count'] / $serviceTypeTotal) * 100) : 0 ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$servicesByType): ?>
                <div class="text-center text-muted py-4">Brak danych.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-user-cog me-2 text-success"></i>Technicy</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Technik</th>
                            <th class="text-end">Montaże</th>
                            <th class="text-end">Serwisy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topTechnicians as $technician): ?>
                        <tr>
                            <td><?= h($technician['name']) ?></td>
                            <td class="text-end"><?= (int)$technician['installs'] ?></td>
                            <td class="text-end"><?= (int)$technician['services'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$topTechnicians): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Brak danych.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($statsWarnings && !$statsError): ?>
<div class="alert alert-light border mt-4 mb-0">
    <div class="fw-semibold mb-2">Informacje diagnostyczne</div>
    <ul class="mb-0">
        <?php foreach ($statsWarnings as $warning): ?>
        <li><?= h($warning) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ─── Modal: szczegóły miesiąca ─────────────────────────────────── -->
<div class="modal fade" id="monthDetailModal" tabindex="-1" aria-labelledby="monthDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="monthDetailModalLabel"><i class="fas fa-calendar-alt me-2 text-primary"></i>Szczegóły miesiąca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data zlecenia</th>
                                <th>Klient</th>
                                <th>Model</th>
                                <th class="text-end">Ilość GPS</th>
                            </tr>
                        </thead>
                        <tbody id="monthDetailBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var monthLabels     = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
    var monthShort      = ['Sty','Lut','Mar','Apr','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'];
    var installSeries   = <?= json_encode(array_values($installsByMonthData), JSON_UNESCAPED_UNICODE) ?>;
    var serviceSeries   = <?= json_encode(array_values($servicesByMonthData), JSON_UNESCAPED_UNICODE) ?>;
    var monthlyDetails  = <?= json_encode(array_values($yearlyMonthlyDetails), JSON_UNESCAPED_UNICODE) ?>;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function openMonthModal(monthIndex) {
        var rows = monthlyDetails[monthIndex] || [];
        var label = monthLabels[monthIndex] + ' <?= $year ?>';
        document.getElementById('monthDetailModalLabel').innerHTML =
            '<i class="fas fa-calendar-alt me-2 text-primary"></i>' + escHtml(label);
        var tbody = document.getElementById('monthDetailBody');
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Brak zleceń w tym miesiącu.</td></tr>';
        } else {
            rows.forEach(function (row) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="text-nowrap">' + escHtml(row.order_date || '—') + '</td>' +
                    '<td class="fw-semibold">' + escHtml(row.client_name || '—') + '</td>' +
                    '<td>' + escHtml(row.model_name || '—') + '</td>' +
                    '<td class="text-end"><span class="badge bg-success">' + (parseInt(row.install_count, 10) || 0) + '</span></td>';
                tbody.appendChild(tr);
            });
        }
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('monthDetailModal'));
        modal.show();
    }

    try {
        new Chart(document.getElementById('monthlyChart'), {
            type: 'bar',
            data: {
                labels: monthShort,
                datasets: [
                    {
                        label: 'Montaże',
                        data: installSeries,
                        backgroundColor: 'rgba(13, 110, 253, 0.82)',
                        borderRadius: 6
                    },
                    {
                        label: 'Serwisy',
                        data: serviceSeries,
                        backgroundColor: 'rgba(253, 126, 20, 0.82)',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                onClick: function (evt, elements) {
                    if (!elements.length) return;
                    openMonthModal(elements[0].index);
                }
            }
        });
    } catch (e) { console.error('Chart init error:', e); }

    <?php if ($deviceStatuses): ?>
    try {
        new Chart(document.getElementById('deviceStatusChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($deviceStatusChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                datasets: [{
                    data: <?= json_encode($deviceStatusChartValues, JSON_UNESCAPED_UNICODE) ?>,
                    backgroundColor: ['#0d6efd', '#198754', '#fd7e14', '#dc3545', '#6f42c1', '#20c997', '#6c757d', '#0dcaf0', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    } catch (e) { console.error('Chart init error:', e); }
    <?php endif; ?>
});
</script>
<?php
$pageEndScripts = ob_get_clean();
include __DIR__ . '/includes/footer.php'; ?>
