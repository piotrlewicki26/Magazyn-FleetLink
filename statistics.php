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

// Auto-migration: ensure work_orders table and work_order_id column exist.
// This mirrors the migration in orders.php so statistics.php works even if
// the user has never visited the orders page.
try {
    $db->query("SELECT 1 FROM work_orders LIMIT 1");
} catch (PDOException $e) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `work_orders` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_number` VARCHAR(30) NOT NULL UNIQUE,
              `date` DATE NOT NULL,
              `client_id` INT UNSIGNED DEFAULT NULL,
              `installation_address` VARCHAR(255) DEFAULT NULL,
              `technician_id` INT UNSIGNED DEFAULT NULL,
              `status` ENUM('nowe','w_trakcie','zakonczone','anulowane','archiwum') NOT NULL DEFAULT 'nowe',
              `notes` TEXT DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $migEx) { /* ignore */ }
}
try {
    $db->query("SELECT work_order_id FROM installations LIMIT 1");
} catch (PDOException $e) {
    try {
        $db->exec("ALTER TABLE `installations` ADD COLUMN `work_order_id` INT UNSIGNED DEFAULT NULL");
    } catch (PDOException $ex) { /* ignore */ }
}

/**
 * Return user-friendly client display name.
 */
function resolveClientName(array $row): string
{
    $workOrderName = trim((string)($row['wo_company_name'] ?: $row['wo_contact_name'] ?? ''));
    if ($workOrderName !== '') {
        return $workOrderName;
    }

    $installationName = trim((string)($row['inst_company_name'] ?: $row['inst_contact_name'] ?? ''));
    if ($installationName !== '') {
        return $installationName;
    }

    return '—';
}

/**
 * Fetch monthly installations report rows with optional client filtering.
 */
function getMonthlyInstallReportRows(PDO $db, string $startDate, string $endDate, ?int $clientId = null): array
{
    $sql = "
        SELECT i.id as installation_id,
               i.installation_date,
               d.serial_number,
               m.name as model_name,
               mf.name as manufacturer_name,
               wo.order_number,
               wo.notes as work_order_notes,
               i.notes as installation_notes,
               wo.client_id as wo_client_id,
               i.client_id as inst_client_id,
               cwo.company_name as wo_company_name,
               cwo.contact_name as wo_contact_name,
               ci.company_name as inst_company_name,
               ci.contact_name as inst_contact_name
        FROM installations i
        JOIN devices d ON d.id = i.device_id
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        LEFT JOIN work_orders wo ON wo.id = i.work_order_id
        LEFT JOIN clients cwo ON cwo.id = wo.client_id
        LEFT JOIN clients ci ON ci.id = i.client_id
        WHERE i.installation_date >= ? AND i.installation_date < ?
    ";
    $params = [$startDate, $endDate];

    if ($clientId) {
        $sql .= " AND COALESCE(wo.client_id, i.client_id) = ?";
        $params[] = $clientId;
    }

    $sql .= " ORDER BY i.installation_date DESC, mf.name, m.name, d.serial_number";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Convert 1-based column index to XLSX column letter.
 */
function columnIndexToLetter(int $index): string
{
    $colLetter = '';
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $colLetter = chr(65 + $rem) . $colLetter;
        $index = (int)(($index - 1) / 26);
    }
    return $colLetter;
}

/**
 * Sanitize month token used in exported file names.
 */
function normalizeReportMonthToken(string $monthValue): string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthValue)
        ? str_replace('-', '_', $monthValue)
        : date('Y_m');
}

/**
 * Export report data to CSV.
 */
function exportMonthlyReportCsv(array $rows, string $monthValue): void
{
    $filename = 'raport_montaze_' . normalizeReportMonthToken($monthValue) . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data montażu', 'Klient', 'Producent', 'Model', 'Nr seryjny', 'Nr zlecenia', 'Uwagi ze zlecenia'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['installation_date'] ? formatDate($row['installation_date']) : '',
            resolveClientName($row),
            $row['manufacturer_name'] ?? '',
            $row['model_name'] ?? '',
            $row['serial_number'] ?? '',
            $row['order_number'] ?? '',
            $row['work_order_notes'] ?? '',
        ], ';');
    }
    fclose($out);
}

/**
 * Export report data to XLSX.
 */
function exportMonthlyReportXlsx(array $rows, string $monthValue): void
{
    $filename = 'raport_montaze_' . normalizeReportMonthToken($monthValue) . '_' . date('Y-m-d_His') . '.xlsx';
    $xmlEsc = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $allRows = [[
        'Data montażu',
        'Klient',
        'Producent',
        'Model',
        'Nr seryjny',
        'Nr zlecenia',
        'Uwagi ze zlecenia',
    ]];
    foreach ($rows as $row) {
        $allRows[] = [
            $row['installation_date'] ? formatDate($row['installation_date']) : '',
            resolveClientName($row),
            $row['manufacturer_name'] ?? '',
            $row['model_name'] ?? '',
            $row['serial_number'] ?? '',
            $row['order_number'] ?? '',
            $row['work_order_notes'] ?? '',
        ];
    }

    $sharedStrings = [];
    $ssMap = [];
    $getSSIdx = static function (string $value) use (&$sharedStrings, &$ssMap): int {
        if (!array_key_exists($value, $ssMap)) {
            $ssMap[$value] = count($sharedStrings);
            $sharedStrings[] = $value;
        }
        return $ssMap[$value];
    };

    $wsRows = '';
    foreach ($allRows as $ri => $row) {
        $wsRows .= '<row r="' . ($ri + 1) . '">';
        foreach ($row as $ci => $cellVal) {
            $colLetter = columnIndexToLetter($ci + 1);
            $idx = $getSSIdx((string)$cellVal);
            $wsRows .= '<c r="' . $colLetter . ($ri + 1) . '" t="s"><v>' . $idx . '</v></c>';
        }
        $wsRows .= '</row>';
    }

    $ssItems = '';
    foreach ($sharedStrings as $s) {
        $ssItems .= '<si><t xml:space="preserve">' . $xmlEsc($s) . '</t></si>';
    }

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetData>' . $wsRows . '</sheetData></worksheet>';

    $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">'
        . $ssItems . '</sst>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Raport montaży" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';

    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'fleetlink_monthly_report_');
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            header('HTTP/1.1 500 Internal Server Error');
            exit('Nie można wygenerować pliku XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $relsRoot);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
    } finally {
        if (is_file($tmpFile)) {
            unlink($tmpFile);
        }
    }
}

$year = (int)($_GET['year'] ?? date('Y'));
$reportMonth = sanitize($_GET['report_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
    $reportMonth = date('Y-m');
}
$reportClientId = (int)($_GET['report_client_id'] ?? 0);
$reportClientId = $reportClientId > 0 ? $reportClientId : null;

$reportMonthStart = $reportMonth . '-01';
$reportMonthStartDate = DateTimeImmutable::createFromFormat('Y-m-d', $reportMonthStart);
if (!$reportMonthStartDate) {
    $reportMonthStartDate = new DateTimeImmutable(date('Y-m-01'));
    $reportMonth = $reportMonthStartDate->format('Y-m');
}
$reportMonthStart = $reportMonthStartDate->format('Y-m-01');
$reportMonthEnd = $reportMonthStartDate->modify('+1 month')->format('Y-m-01');
$reportMonthLabel = ucfirst(formatDate($reportMonthStart, 'F Y'));

$monthlyReportClients = [];
$monthlyReportRows = [];
$monthlyReportError = null;

try {
    $clientsStmt = $db->prepare("
        SELECT COALESCE(cwo.id, ci.id) as client_id,
               COALESCE(NULLIF(cwo.company_name, ''), NULLIF(cwo.contact_name, ''), NULLIF(ci.company_name, ''), NULLIF(ci.contact_name, '')) as client_name
        FROM installations i
        LEFT JOIN work_orders wo ON wo.id = i.work_order_id
        LEFT JOIN clients cwo ON cwo.id = wo.client_id
        LEFT JOIN clients ci ON ci.id = i.client_id
        WHERE i.installation_date >= ? AND i.installation_date < ?
          AND COALESCE(cwo.id, ci.id) IS NOT NULL
        GROUP BY COALESCE(cwo.id, ci.id), COALESCE(NULLIF(cwo.company_name, ''), NULLIF(cwo.contact_name, ''), NULLIF(ci.company_name, ''), NULLIF(ci.contact_name, ''))
        ORDER BY client_name
    ");
    $clientsStmt->execute([$reportMonthStart, $reportMonthEnd]);
    $monthlyReportClients = $clientsStmt->fetchAll();

    $monthlyReportRows = getMonthlyInstallReportRows($db, $reportMonthStart, $reportMonthEnd, $reportClientId);
} catch (Exception $e) {
    $monthlyReportError = 'Nie udało się przygotować raportu miesięcznego. Sprawdź konfigurację danych lub skontaktuj się z administratorem.';
    error_log('statistics monthlyReport failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_monthly_installations') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('HTTP/1.1 403 Forbidden');
        exit('Błąd bezpieczeństwa.');
    }

    $exportFormat = sanitize($_POST['format'] ?? 'csv');
    if (!in_array($exportFormat, ['csv', 'xlsx'], true)) {
        $exportFormat = 'csv';
    }

    $exportMonth = sanitize($_POST['report_month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $exportMonth)) {
        $exportMonth = date('Y-m');
    }
    $exportClientId = (int)($_POST['report_client_id'] ?? 0);
    $exportClientId = $exportClientId > 0 ? $exportClientId : null;

    $exportStartDate = $exportMonth . '-01';
    $exportStartDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $exportStartDate);
    if (!$exportStartDateObj) {
        $exportStartDateObj = new DateTimeImmutable(date('Y-m-01'));
        $exportMonth = $exportStartDateObj->format('Y-m');
    }

    $exportStartDate = $exportStartDateObj->format('Y-m-01');
    $exportEndDate = $exportStartDateObj->modify('+1 month')->format('Y-m-01');

    try {
        $exportRows = getMonthlyInstallReportRows($db, $exportStartDate, $exportEndDate, $exportClientId);
        if ($exportFormat === 'xlsx') {
            exportMonthlyReportXlsx($exportRows, $exportMonth);
        } else {
            exportMonthlyReportCsv($exportRows, $exportMonth);
        }
        exit;
    } catch (Exception $e) {
        flashError('Nie udało się wygenerować eksportu raportu. Spróbuj ponownie lub skontaktuj się z administratorem.');
        error_log('statistics monthlyReport export failed: ' . $e->getMessage());
        redirect(getBaseUrl() . 'statistics.php?year=' . urlencode((string)$year) . '&report_month=' . urlencode($reportMonth) . '&report_client_id=' . urlencode((string)($reportClientId ?? 0)));
    }
}

$monthlyReportTotal = count($monthlyReportRows);
$monthlyReportUniqueClients = count(array_unique(array_map(static fn($row) => resolveClientName($row), $monthlyReportRows)));

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
$statsFailureCount   = 0;
$totalStatsQueries   = 8;
// Consider stats page degraded when at least half of key queries fail.
$criticalFailureThreshold = (int)ceil($totalStatsQueries / 2);
$dbDriver            = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$isSqlite            = $dbDriver === 'sqlite';
$monthInstallExpr    = $isSqlite ? "strftime('%m', installation_date)" : "DATE_FORMAT(installation_date,'%m')";
$monthServiceExpr    = $isSqlite ? "strftime('%m', completed_date)" : "DATE_FORMAT(completed_date,'%m')";
$yearInstallCond     = $isSqlite ? "strftime('%Y', installation_date) = ?" : "YEAR(installation_date) = ?";
$yearServiceCond     = $isSqlite ? "strftime('%Y', completed_date) = ?" : "YEAR(completed_date) = ?";
$yearCompletedCond   = $isSqlite ? "strftime('%Y', completed_date) = ?" : "YEAR(completed_date) = ?";
$yearCreatedCond     = $isSqlite ? "strftime('%Y', created_at) = ?" : "YEAR(created_at) = ?";
$yearParam           = (string)$year;

try {
    $installsByMonth = $db->prepare("
        SELECT {$monthInstallExpr} as month, COUNT(*) as count
        FROM installations
        WHERE {$yearInstallCond}
        GROUP BY month
        ORDER BY month
    ");
    $installsByMonth->execute([$yearParam]);
    foreach ($installsByMonth->fetchAll() as $row) {
        $installsByMonthData[(int)$row['month']] = (int)$row['count'];
    }
} catch (Exception $e) { $statsFailureCount++; error_log('statistics installsByMonth failed: ' . $e->getMessage()); }

try {
    $servicesByMonth = $db->prepare("
        SELECT {$monthServiceExpr} as month, COUNT(*) as count
        FROM services
        WHERE {$yearServiceCond} AND status = 'zakończony'
        GROUP BY month
        ORDER BY month
    ");
    $servicesByMonth->execute([$yearParam]);
    foreach ($servicesByMonth->fetchAll() as $row) {
        $servicesByMonthData[(int)$row['month']] = (int)$row['count'];
    }
} catch (Exception $e) { $statsFailureCount++; error_log('statistics servicesByMonth failed: ' . $e->getMessage()); }

try {
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
} catch (Exception $e) { $statsFailureCount++; error_log('statistics topDevices failed: ' . $e->getMessage()); }

try {
    $servicesByType = $db->query("SELECT type, COUNT(*) as count FROM services GROUP BY type ORDER BY count DESC")->fetchAll();
} catch (Exception $e) { $statsFailureCount++; error_log('statistics servicesByType failed: ' . $e->getMessage()); }

try {
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
} catch (Exception $e) { $statsFailureCount++; error_log('statistics topTechs failed: ' . $e->getMessage()); }

try {
    $serviceRevenue = $db->prepare("
        SELECT SUM(cost) as total FROM services
        WHERE {$yearCompletedCond} AND status='zakończony'
    ");
    $serviceRevenue->execute([$yearParam]);
    $totalServiceRevenue = (float)($serviceRevenue->fetchColumn() ?? 0);
} catch (Exception $e) { $statsFailureCount++; error_log('statistics serviceRevenue failed: ' . $e->getMessage()); }

try {
    $offerStats = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='zaakceptowana' THEN 1 ELSE 0 END) as accepted,
            SUM(total_gross) as total_value,
            SUM(CASE WHEN status='zaakceptowana' THEN total_gross ELSE 0 END) as accepted_value
        FROM offers WHERE {$yearCreatedCond}
    ");
    $offerStats->execute([$yearParam]);
    $offerRow = $offerStats->fetch();
    if ($offerRow) $offerStatsData = $offerRow;
} catch (Exception $e) { $statsFailureCount++; error_log('statistics offerStats failed: ' . $e->getMessage()); }

try {
    $deviceStats = $db->query("SELECT status, COUNT(*) as count FROM devices GROUP BY status")->fetchAll();
    foreach ($deviceStats as $row) $deviceStatusMap[$row['status']] = $row['count'];
} catch (Exception $e) { $statsFailureCount++; error_log('statistics deviceStats failed: ' . $e->getMessage()); }

if ($statsFailureCount > 0 && $statsFailureCount >= $criticalFailureThreshold) {
    $statsError = 'Część zapytań statystycznych nie została wykonana.';
}

$activePage = 'statistics';
$pageTitle = 'Statystyki';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar me-2 text-primary"></i>Statystyki</h1>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="report_month" value="<?= h($reportMonth) ?>">
        <input type="hidden" name="report_client_id" value="<?= (int)($reportClientId ?? 0) ?>">
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

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><i class="fas fa-file-alt me-2 text-primary"></i>Raport montaży za miesiąc</div>
        <div class="small text-muted">Okres: <?= h($reportMonthLabel) ?></div>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="year" value="<?= $year ?>">
            <div class="col-sm-4 col-md-3">
                <label class="form-label mb-1">Miesiąc</label>
                <input type="month" name="report_month" class="form-control" value="<?= h($reportMonth) ?>" required>
            </div>
            <div class="col-sm-5 col-md-4">
                <label class="form-label mb-1">Klient</label>
                <select name="report_client_id" class="form-select">
                    <option value="0">Wszyscy klienci</option>
                    <?php foreach ($monthlyReportClients as $client): ?>
                    <option value="<?= (int)$client['client_id'] ?>" <?= (int)$client['client_id'] === (int)($reportClientId ?? 0) ? 'selected' : '' ?>>
                        <?= h($client['client_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i>Filtruj
                </button>
            </div>
        </form>

        <?php if ($monthlyReportError): ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i><?= h($monthlyReportError) ?>
        </div>
        <?php endif; ?>
        <div class="small text-uppercase text-muted fw-semibold mb-2">Podsumowanie</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge text-bg-primary">Montaże: <?= $monthlyReportTotal ?></span>
            <span class="badge text-bg-secondary">Klienci: <?= $monthlyReportUniqueClients ?></span>
            <span class="badge text-bg-light border text-dark">Miesiąc: <?= h($reportMonthLabel) ?></span>
        </div>

        <div class="small text-uppercase text-muted fw-semibold mb-2">Eksport</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="export_monthly_installations">
                <input type="hidden" name="format" value="csv">
                <input type="hidden" name="report_month" value="<?= h($reportMonth) ?>">
                <input type="hidden" name="report_client_id" value="<?= (int)($reportClientId ?? 0) ?>">
                <button type="submit" class="btn btn-outline-success btn-sm">
                    <i class="fas fa-file-csv me-1"></i>Eksport CSV
                </button>
            </form>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="export_monthly_installations">
                <input type="hidden" name="format" value="xlsx">
                <input type="hidden" name="report_month" value="<?= h($reportMonth) ?>">
                <input type="hidden" name="report_client_id" value="<?= (int)($reportClientId ?? 0) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Eksport XLSX
                </button>
            </form>
        </div>

        <div class="small text-uppercase text-muted fw-semibold mb-2">Tabela raportu</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data montażu</th>
                        <th>Klient</th>
                        <th>Producent / Model</th>
                        <th>Nr seryjny</th>
                        <th>Nr zlecenia</th>
                        <th>Uwagi ze zlecenia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlyReportRows as $row): ?>
                    <tr>
                        <td><?= $row['installation_date'] ? formatDate($row['installation_date']) : '—' ?></td>
                        <td><?= h(resolveClientName($row)) ?></td>
                        <td><?= h(trim(($row['manufacturer_name'] ?? '') . ' ' . ($row['model_name'] ?? ''))) ?></td>
                        <td class="fw-semibold"><?= h($row['serial_number'] ?? '—') ?></td>
                        <td><?= h($row['order_number'] ?: '—') ?></td>
                        <td class="small text-muted"><?= nl2br(h($row['work_order_notes'] ?: '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthlyReportRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?= $monthlyReportError ? 'Brak danych raportu. Spróbuj ponownie po odświeżeniu strony.' : 'Brak montaży dla wybranych filtrów.' ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
