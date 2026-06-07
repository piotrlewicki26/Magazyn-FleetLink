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
        $allowedTable = statsAllowedIdentifier($table, ['installations']);
        $allowedColumn = statsAllowedIdentifier($column, ['work_order_id']);
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
 * Return a client name from report row.
 */
function statsResolveClientName(array $row): string
{
    $workOrderCompany = trim((string)($row['wo_company_name'] ?? ''));
    if ($workOrderCompany !== '') {
        return $workOrderCompany;
    }

    $workOrderContact = trim((string)($row['wo_contact_name'] ?? ''));
    if ($workOrderContact !== '') {
        return $workOrderContact;
    }

    $installationCompany = trim((string)($row['inst_company_name'] ?? ''));
    if ($installationCompany !== '') {
        return $installationCompany;
    }

    $installationContact = trim((string)($row['inst_contact_name'] ?? ''));
    if ($installationContact !== '') {
        return $installationContact;
    }

    return '—';
}

/**
 * Combine notes visible in monthly report.
 */
function statsResolveReportNotes(array $row): string
{
    $notes = [];
    foreach (['work_order_notes', 'installation_notes'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '' && !in_array($value, $notes, true)) {
            $notes[] = $value;
        }
    }

    return $notes ? implode("\n—\n", $notes) : '—';
}

/**
 * Return device label used in report views and exports.
 */
function statsResolveDeviceLabel(array $row): string
{
    $deviceLabel = trim((string)($row['manufacturer_name'] ?? '') . ' ' . (string)($row['model_name'] ?? ''));
    return $deviceLabel !== '' ? $deviceLabel : '—';
}

/**
 * Prepare export payload from monthly report rows.
 */
function statsBuildExportRows(array $rows): array
{
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            $row['installation_date'] ? formatDate($row['installation_date']) : '',
            statsResolveClientName($row),
            trim((string)($row['vehicle_registration'] ?? '')) ?: '—',
            trim((string)($row['manufacturer_name'] ?? '')),
            trim((string)($row['model_name'] ?? '')),
            trim((string)($row['serial_number'] ?? '')),
            trim((string)($row['technician_name'] ?? '')) ?: '—',
            trim((string)($row['order_number'] ?? '')) ?: '—',
            statsResolveReportNotes($row),
        ];
    }

    return $exportRows;
}

/**
 * Convert 1-based column index to XLSX column letter.
 */
function statsColumnIndexToLetter(int $index): string
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
function statsNormalizeReportMonthToken(string $monthValue): string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthValue)
        ? str_replace('-', '_', $monthValue)
        : date('Y_m');
}

/**
 * Export report data to CSV.
 */
function statsExportMonthlyReportCsv(array $rows, string $monthValue): void
{
    $filename = 'raport_montaze_' . statsNormalizeReportMonthToken($monthValue) . '_' . date('Y-m-d_His') . '.csv';
    $out = fopen('php://output', 'w');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    fputcsv($out, ['Data montażu', 'Klient', 'Pojazd', 'Producent', 'Model', 'Nr seryjny', 'Technik', 'Nr zlecenia', 'Uwagi'], ';');
    foreach (statsBuildExportRows($rows) as $row) {
        fputcsv($out, $row, ';');
    }

    fclose($out);
}

/**
 * Export report data to XLSX.
 */
function statsExportMonthlyReportXlsx(array $rows, string $monthValue): void
{
    $filename = 'raport_montaze_' . statsNormalizeReportMonthToken($monthValue) . '_' . date('Y-m-d_His') . '.xlsx';
    $xmlEsc = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $allRows = array_merge([
        ['Data montażu', 'Klient', 'Pojazd', 'Producent', 'Model', 'Nr seryjny', 'Technik', 'Nr zlecenia', 'Uwagi'],
    ], statsBuildExportRows($rows));

    $sharedStrings = [];
    $sharedStringsIndex = [];
    $sharedStringId = static function (string $value) use (&$sharedStrings, &$sharedStringsIndex): int {
        if (!array_key_exists($value, $sharedStringsIndex)) {
            $sharedStringsIndex[$value] = count($sharedStrings);
            $sharedStrings[] = $value;
        }

        return $sharedStringsIndex[$value];
    };

    $sheetRows = '';
    foreach ($allRows as $rowIndex => $row) {
        $sheetRows .= '<row r="' . ($rowIndex + 1) . '">';
        foreach ($row as $columnIndex => $cellValue) {
            $columnLetter = statsColumnIndexToLetter($columnIndex + 1);
            $sharedStringIndex = $sharedStringId((string)$cellValue);
            $sheetRows .= '<c r="' . $columnLetter . ($rowIndex + 1) . '" t="s"><v>' . $sharedStringIndex . '</v></c>';
        }
        $sheetRows .= '</row>';
    }

    $sharedStringItems = '';
    foreach ($sharedStrings as $value) {
        $sharedStringItems .= '<si><t xml:space="preserve">' . $xmlEsc($value) . '</t></si>';
    }

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';
    $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">' . $sharedStringItems . '</sst>';
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Raport montaży" sheetId="1" r:id="rId1"/></sheets></workbook>';
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
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'fleetlink_stats_report_');
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            header('HTTP/1.1 500 Internal Server Error');
            exit('Nie można wygenerować pliku XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
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

/**
 * Fetch monthly installation report rows.
 */
function statsGetMonthlyReportRows(PDO $db, string $startDate, string $endDate, ?int $clientId, bool $includeWorkOrders): array
{
    $sql = "
        SELECT
            i.id AS installation_id,
            i.installation_date,
            i.status AS installation_status,
            i.notes AS installation_notes,
            d.serial_number,
            m.name AS model_name,
            mf.name AS manufacturer_name,
            v.registration AS vehicle_registration,
            u.name AS technician_name,
            ci.company_name AS inst_company_name,
            ci.contact_name AS inst_contact_name,
            " . ($includeWorkOrders ? "wo.order_number, wo.notes AS work_order_notes, cwo.company_name AS wo_company_name, cwo.contact_name AS wo_contact_name, COALESCE(cwo.id, ci.id) AS effective_client_id" : "NULL AS order_number, NULL AS work_order_notes, NULL AS wo_company_name, NULL AS wo_contact_name, ci.id AS effective_client_id") . "
        FROM installations i
        JOIN devices d ON d.id = i.device_id
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        LEFT JOIN vehicles v ON v.id = i.vehicle_id
        LEFT JOIN users u ON u.id = i.technician_id
        LEFT JOIN clients ci ON ci.id = i.client_id
        " . ($includeWorkOrders ? "LEFT JOIN work_orders wo ON wo.id = i.work_order_id LEFT JOIN clients cwo ON cwo.id = wo.client_id" : "") . "
        WHERE i.installation_date >= ? AND i.installation_date < ?
    ";

    $params = [$startDate, $endDate];
    if ($clientId !== null) {
        $sql .= " AND " . ($includeWorkOrders ? "COALESCE(wo.client_id, i.client_id) = ?" : "i.client_id = ?");
        $params[] = $clientId;
    }

    $sql .= ' ORDER BY i.installation_date DESC, v.registration ASC, d.serial_number ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch client list for monthly report filter.
 */
function statsGetMonthlyReportClients(PDO $db, string $startDate, string $endDate, bool $includeWorkOrders): array
{
    $sql = $includeWorkOrders
        ? "
            SELECT
                COALESCE(cwo.id, ci.id) AS client_id,
                COALESCE(NULLIF(cwo.company_name, ''), NULLIF(cwo.contact_name, ''), NULLIF(ci.company_name, ''), NULLIF(ci.contact_name, '')) AS client_name
            FROM installations i
            LEFT JOIN work_orders wo ON wo.id = i.work_order_id
            LEFT JOIN clients cwo ON cwo.id = wo.client_id
            LEFT JOIN clients ci ON ci.id = i.client_id
            WHERE i.installation_date >= ? AND i.installation_date < ?
              AND COALESCE(cwo.id, ci.id) IS NOT NULL
            GROUP BY COALESCE(cwo.id, ci.id), COALESCE(NULLIF(cwo.company_name, ''), NULLIF(cwo.contact_name, ''), NULLIF(ci.company_name, ''), NULLIF(ci.contact_name, ''))
            ORDER BY client_name
        "
        : "
            SELECT
                ci.id AS client_id,
                COALESCE(NULLIF(ci.company_name, ''), NULLIF(ci.contact_name, '')) AS client_name
            FROM installations i
            LEFT JOIN clients ci ON ci.id = i.client_id
            WHERE i.installation_date >= ? AND i.installation_date < ?
              AND ci.id IS NOT NULL
            GROUP BY ci.id, COALESCE(NULLIF(ci.company_name, ''), NULLIF(ci.contact_name, ''))
            ORDER BY client_name
        ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$startDate, $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch monthly service report rows.
 */
function statsGetMonthlyServiceRows(PDO $db, string $startDate, string $endDate, ?int $clientId): array
{
    $sql = "
        SELECT
            s.id AS service_id,
            s.type AS service_type,
            s.status AS service_status,
            COALESCE(s.completed_date, s.planned_date) AS service_date,
            s.completed_date,
            s.planned_date,
            s.description AS service_description,
            s.cost AS service_cost,
            d.serial_number,
            m.name AS model_name,
            mf.name AS manufacturer_name,
            u.name AS technician_name,
            v.registration AS vehicle_registration,
            c.company_name AS client_company_name,
            c.contact_name AS client_contact_name,
            c.id AS client_id
        FROM services s
        JOIN devices d ON d.id = s.device_id
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        LEFT JOIN users u ON u.id = s.technician_id
        LEFT JOIN installations inst ON inst.id = s.installation_id
        LEFT JOIN vehicles v ON v.id = inst.vehicle_id
        LEFT JOIN clients c ON c.id = inst.client_id
        WHERE COALESCE(s.completed_date, s.planned_date) >= ? AND COALESCE(s.completed_date, s.planned_date) < ?
    ";

    $params = [$startDate, $endDate];
    if ($clientId !== null) {
        $sql .= ' AND c.id = ?';
        $params[] = $clientId;
    }

    $sql .= ' ORDER BY COALESCE(s.completed_date, s.planned_date) DESC, d.serial_number ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch active devices installed in a given month for monthly report.
 */
function statsGetMonthlyActiveDevices(PDO $db, string $startDate, string $endDate, bool $includeWorkOrders, int $limit): array
{
    $sql = "
        SELECT
            i.id AS installation_id,
            i.installation_date,
            d.serial_number,
            m.name AS model_name,
            mf.name AS manufacturer_name,
            v.registration AS vehicle_registration,
            " . ($includeWorkOrders
                ? "COALESCE(c.company_name, oc.company_name) AS client_company_name,
                   COALESCE(c.contact_name, oc.contact_name) AS client_contact_name"
                : "c.company_name AS client_company_name,
                   c.contact_name AS client_contact_name") . "
        FROM installations i
        JOIN devices d ON d.id = i.device_id
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        LEFT JOIN vehicles v ON v.id = i.vehicle_id
        LEFT JOIN clients c ON c.id = i.client_id
        " . ($includeWorkOrders ? "LEFT JOIN work_orders wo ON wo.id = i.work_order_id
        LEFT JOIN clients oc ON oc.id = wo.client_id" : "") . "
        WHERE i.status = 'aktywna'
          AND i.installation_date >= ? AND i.installation_date < ?
        ORDER BY i.installation_date DESC, i.id DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$startDate, $endDate, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve service client name from service row.
 */
function statsResolveServiceClientName(array $row): string
{
    $company = trim((string)($row['client_company_name'] ?? ''));
    if ($company !== '') {
        return $company;
    }
    $contact = trim((string)($row['client_contact_name'] ?? ''));
    if ($contact !== '') {
        return $contact;
    }
    return '—';
}

/**
 * Translate service type to Polish label.
 */
function statsServiceTypeLabel(string $type): string
{
    $map = [
        'przeglad'    => 'Przegląd',
        'naprawa'     => 'Naprawa',
        'wymiana'     => 'Wymiana',
        'aktualizacja' => 'Aktualizacja',
        'inne'        => 'Inne',
    ];
    return $map[$type] ?? ucfirst($type);
}

/**
 * Translate service status to Polish label.
 */
function statsServiceStatusLabel(string $status): string
{
    $map = [
        'zaplanowany' => 'Zaplanowany',
        'w_trakcie'   => 'W trakcie',
        'zakończony'  => 'Zakończony',
        'anulowany'   => 'Anulowany',
        'archiwum'    => 'Archiwum',
    ];
    return $map[$status] ?? ucfirst($status);
}

$year = (int)($_GET['year'] ?? date('Y'));

$activeTab = sanitize($_GET['tab'] ?? 'yearly');
// 'devices' was a former standalone tab — redirect to monthly
if (!in_array($activeTab, ['yearly', 'monthly'], true)) {
    $activeTab = $activeTab === 'devices' ? 'monthly' : 'yearly';
}

$reportMonth = sanitize($_GET['report_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
    $reportMonth = date('Y-m');
}
$reportClientId = (int)($_GET['report_client_id'] ?? 0);
$reportClientId = $reportClientId > 0 ? $reportClientId : null;

$reportMonthStart = DateTimeImmutable::createFromFormat('Y-m-d', $reportMonth . '-01');
if (!$reportMonthStart) {
    $reportMonthStart = new DateTimeImmutable(date('Y-m-01'));
    $reportMonth = $reportMonthStart->format('Y-m');
}
$reportMonthStartValue = $reportMonthStart->format('Y-m-01');
$reportMonthEndValue = $reportMonthStart->modify('+1 month')->format('Y-m-01');
$reportMonthLabel = ucfirst(formatDate($reportMonthStartValue, 'F Y'));

$hasWorkOrdersTable = statsTableExists($db, 'work_orders');
$hasWorkOrderColumn = statsColumnExists($db, 'installations', 'work_order_id');
$includeWorkOrders = $hasWorkOrdersTable && $hasWorkOrderColumn;

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
    $exportStartDate = DateTimeImmutable::createFromFormat('Y-m-d', $exportMonth . '-01');
    if (!$exportStartDate) {
        $exportStartDate = new DateTimeImmutable(date('Y-m-01'));
        $exportMonth = $exportStartDate->format('Y-m');
    }

    try {
        $exportRows = statsGetMonthlyReportRows(
            $db,
            $exportStartDate->format('Y-m-01'),
            $exportStartDate->modify('+1 month')->format('Y-m-01'),
            $exportClientId,
            $includeWorkOrders
        );

        if ($exportFormat === 'xlsx') {
            statsExportMonthlyReportXlsx($exportRows, $exportMonth);
        } else {
            statsExportMonthlyReportCsv($exportRows, $exportMonth);
        }
        exit;
    } catch (Throwable $e) {
        flashError('Nie udało się wygenerować eksportu raportu.');
        error_log('statistics export failed: ' . $e->getMessage());
        redirect(getBaseUrl() . 'statistics.php?tab=monthly&report_month=' . urlencode($reportMonth) . '&report_client_id=' . urlencode((string)($reportClientId ?? 0)));
    }
}

// ── Monthly report data ────────────────────────────────────────────────
$monthlyReportRows = [];
$monthlyReportClients = [];
$monthlyReportError = null;
$monthlyServiceRows = [];
$monthlyServiceError = null;
$monthlyActiveDevices = [];
$monthlyActiveDevicesTotal = 0;
$monthlyActiveDevicesError = null;
$monthlyActiveDevicesDisplayLimit = 500;
$monthlyReportSummary = ['total_installations' => 0, 'unique_clients' => 0, 'total_services' => 0, 'unique_technicians' => 0];
$monthlyReportEmptyMessage = 'Brak montaży dla wybranych filtrów.';

if ($activeTab === 'monthly') {
    try {
        $monthlyReportClients = statsGetMonthlyReportClients($db, $reportMonthStartValue, $reportMonthEndValue, $includeWorkOrders);
        $monthlyReportRows = statsGetMonthlyReportRows($db, $reportMonthStartValue, $reportMonthEndValue, $reportClientId, $includeWorkOrders);
    } catch (Throwable $e) {
        $monthlyReportError = 'Nie udało się przygotować raportu montaży za wybrany miesiąc.';
        error_log('statistics monthly report failed: ' . $e->getMessage());
    }

    try {
        $monthlyServiceRows = statsGetMonthlyServiceRows($db, $reportMonthStartValue, $reportMonthEndValue, $reportClientId);
    } catch (Throwable $e) {
        $monthlyServiceError = 'Nie udało się przygotować raportu serwisów za wybrany miesiąc.';
        error_log('statistics monthly service report failed: ' . $e->getMessage());
    }

    try {
        $countActiveStmt = $db->prepare(
            "SELECT COUNT(*) FROM installations i WHERE i.status = 'aktywna' AND i.installation_date >= ? AND i.installation_date < ?"
        );
        $countActiveStmt->execute([$reportMonthStartValue, $reportMonthEndValue]);
        $monthlyActiveDevicesTotal = (int)$countActiveStmt->fetchColumn();
        $monthlyActiveDevices = statsGetMonthlyActiveDevices(
            $db,
            $reportMonthStartValue,
            $reportMonthEndValue,
            $includeWorkOrders,
            $monthlyActiveDevicesDisplayLimit
        );
    } catch (Throwable $e) {
        $monthlyActiveDevicesError = 'Nie udało się pobrać listy zamontowanych urządzeń.';
        error_log('statistics monthly active devices failed: ' . $e->getMessage());
    }

    $uniqueMonthlyClients = [];
    $uniqueMonthlyTechnicians = [];
    foreach ($monthlyReportRows as $monthlyReportRow) {
        $clientName = statsResolveClientName($monthlyReportRow);
        if ($clientName !== '—') {
            $uniqueMonthlyClients[$clientName] = true;
        }
        $technicianName = trim((string)($monthlyReportRow['technician_name'] ?? ''));
        if ($technicianName !== '') {
            $uniqueMonthlyTechnicians[$technicianName] = true;
        }
    }
    foreach ($monthlyServiceRows as $svcRow) {
        $tech = trim((string)($svcRow['technician_name'] ?? ''));
        if ($tech !== '') {
            $uniqueMonthlyTechnicians[$tech] = true;
        }
    }

    $monthlyReportSummary = [
        'total_installations' => count($monthlyReportRows),
        'unique_clients'      => count($uniqueMonthlyClients),
        'total_services'      => count($monthlyServiceRows),
        'unique_technicians'  => count($uniqueMonthlyTechnicians),
    ];
    $monthlyReportEmptyMessage = $monthlyReportError ?: 'Brak montaży dla wybranych filtrów.';
}

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

if ($activeTab === 'yearly') {
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
    <?php if ($activeTab === 'yearly'): ?>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="hidden" name="tab" value="yearly">
        <label class="mb-0 fw-semibold">Rok:</label>
        <select name="year" class="form-select" onchange="this.form.submit()" style="min-width: 120px;">
            <?php for ($availableYear = (int)date('Y'); $availableYear >= (int)date('Y') - 5; $availableYear--): ?>
            <option value="<?= $availableYear ?>" <?= $availableYear === $year ? 'selected' : '' ?>><?= $availableYear ?></option>
            <?php endfor; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<!-- Tab navigation -->
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'yearly' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>statistics.php?tab=yearly&year=<?= $year ?>">
            <i class="fas fa-chart-bar me-1"></i>Zestawienie roczne
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'monthly' ? 'active' : '' ?>" href="<?= getBaseUrl() ?>statistics.php?tab=monthly&report_month=<?= h($reportMonth) ?>">
            <i class="fas fa-file-alt me-1"></i>Raport miesięczny
        </a>
    </li>
</ul>

<?php if ($statsError): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i><?= h($statsError) ?>
</div>
<?php endif; ?>

<?php if ($activeTab === 'yearly'): ?>
<!-- ═══ TAB: ZESTAWIENIE ROCZNE ═══════════════════════════════════════ -->

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
            <div class="card-header bg-white fw-semibold">Montaże i serwisy w <?= $year ?> roku</div>
            <div class="card-body">
                <canvas id="monthlyChart" height="120"></canvas>
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

<?php elseif ($activeTab === 'monthly'): ?>
<!-- ═══ TAB: RAPORT MIESIĘCZNY ════════════════════════════════════════ -->

<!-- Filter card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i>Raport miesięczny</div>
            <div class="small text-muted">Okres: <?= h($reportMonthLabel) ?></div>
        </div>
        <span class="badge rounded-pill text-bg-light border"><?= $includeWorkOrders ? 'Źródło: montaże + zlecenia' : 'Źródło: montaże' ?></span>
    </div>
    <div class="card-body pb-3">
        <form method="GET" id="reportFilterForm" class="row g-3 align-items-end mb-3">
            <input type="hidden" name="tab" value="monthly">
            <div class="col-md-3">
                <label class="form-label">Miesiąc</label>
                <input type="month" id="reportMonthInput" name="report_month" class="form-control" value="<?= h($reportMonth) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Klient</label>
                <select name="report_client_id" id="reportClientSelect" class="form-select">
                    <option value="0">Wszyscy klienci</option>
                    <?php foreach ($monthlyReportClients as $client): ?>
                    <option value="<?= (int)$client['client_id'] ?>" <?= (int)$client['client_id'] === (int)($reportClientId ?? 0) ? 'selected' : '' ?>>
                        <?= h($client['client_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filtruj</button>
            </div>
        </form>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="export_monthly_installations">
                <input type="hidden" name="format" value="csv">
                <input type="hidden" name="report_month" value="<?= h($reportMonth) ?>">
                <input type="hidden" name="report_client_id" value="<?= (int)($reportClientId ?? 0) ?>">
                <button type="submit" class="btn btn-outline-success btn-sm"><i class="fas fa-file-csv me-1"></i>Eksport montaży CSV</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="export_monthly_installations">
                <input type="hidden" name="format" value="xlsx">
                <input type="hidden" name="report_month" value="<?= h($reportMonth) ?>">
                <input type="hidden" name="report_client_id" value="<?= (int)($reportClientId ?? 0) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-excel me-1"></i>Eksport montaży XLSX</button>
            </form>
        </div>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fas fa-tools me-1 text-primary"></i>Montaże</div>
                <div class="h3 fw-bold text-primary mb-0"><?= $monthlyReportSummary['total_installations'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fas fa-wrench me-1 text-warning"></i>Serwisy</div>
                <div class="h3 fw-bold text-warning mb-0"><?= $monthlyReportSummary['total_services'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fas fa-users me-1 text-secondary"></i>Klienci (montaże)</div>
                <div class="h3 fw-bold text-secondary mb-0"><?= $monthlyReportSummary['unique_clients'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fas fa-user-cog me-1 text-info"></i>Technicy</div>
                <div class="h3 fw-bold text-info mb-0"><?= $monthlyReportSummary['unique_technicians'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ─── Section 1: Montaże ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <div class="fw-semibold"><i class="fas fa-tools me-2 text-primary"></i>Montaże
            <span class="badge bg-primary ms-1"><?= count($monthlyReportRows) ?></span>
        </div>
        <div class="small text-muted">Lista montaży wykonanych w <?= h($reportMonthLabel) ?></div>
    </div>

    <?php if ($monthlyReportError): ?>
    <div class="card-body">
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle me-2"></i><?= h($monthlyReportError) ?>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data montażu</th>
                    <th>Model urządzenia</th>
                    <th>Klient</th>
                    <th>Pojazd</th>
                    <th>Nr seryjny</th>
                    <th>Technik</th>
                    <th>Nr zlecenia</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyReportRows as $row): ?>
                <tr>
                    <td><?= $row['installation_date'] ? formatDate($row['installation_date']) : '—' ?></td>
                    <td><?= h(statsResolveDeviceLabel($row)) ?></td>
                    <td><?= h(statsResolveClientName($row)) ?></td>
                    <td><?= h($row['vehicle_registration'] ?: '—') ?></td>
                    <td class="fw-semibold"><?= h($row['serial_number'] ?: '—') ?></td>
                    <td><?= h($row['technician_name'] ?: '—') ?></td>
                    <td><?= h($row['order_number'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$monthlyReportRows): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4"><?= h($monthlyReportEmptyMessage) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ─── Section 2: Serwisy ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <div class="fw-semibold"><i class="fas fa-wrench me-2 text-warning"></i>Serwisy
            <span class="badge bg-warning text-dark ms-1"><?= count($monthlyServiceRows) ?></span>
        </div>
        <div class="small text-muted">Lista serwisów w <?= h($reportMonthLabel) ?></div>
    </div>

    <?php if ($monthlyServiceError): ?>
    <div class="card-body">
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle me-2"></i><?= h($monthlyServiceError) ?>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data serwisu</th>
                    <th>Model urządzenia</th>
                    <th>Klient</th>
                    <th>Nr seryjny</th>
                    <th>Typ serwisu</th>
                    <th>Technik</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyServiceRows as $svcRow): ?>
                <tr>
                    <td><?= $svcRow['service_date'] ? formatDate($svcRow['service_date']) : '—' ?></td>
                    <td><?= h(trim(($svcRow['manufacturer_name'] ?? '') . ' ' . ($svcRow['model_name'] ?? '')) ?: '—') ?></td>
                    <td><?= h(statsResolveServiceClientName($svcRow)) ?></td>
                    <td class="fw-semibold"><?= h($svcRow['serial_number'] ?: '—') ?></td>
                    <td><?= h(statsServiceTypeLabel((string)($svcRow['service_type'] ?? ''))) ?></td>
                    <td><?= h($svcRow['technician_name'] ?: '—') ?></td>
                    <td>
                        <?php
                        $svcStatus = (string)($svcRow['service_status'] ?? '');
                        $statusClass = match($svcStatus) {
                            'zakończony' => 'bg-success',
                            'w_trakcie'  => 'bg-warning text-dark',
                            'zaplanowany' => 'bg-info text-dark',
                            'anulowany'  => 'bg-secondary',
                            default      => 'bg-light text-dark border',
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= h(statsServiceStatusLabel($svcStatus)) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$monthlyServiceRows): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">Brak serwisów dla wybranych filtrów.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ─── Section 3: Zamontowane urządzenia w tym miesiącu ───────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-semibold"><i class="fas fa-microchip me-2 text-success"></i>Zamontowane urządzenia (aktywne)
                <?php if ($monthlyActiveDevicesError === null): ?>
                <span class="badge bg-success ms-1"><?= $monthlyActiveDevicesTotal ?></span>
                <?php endif; ?>
            </div>
            <div class="small text-muted">Urządzenia zamontowane w <?= h($reportMonthLabel) ?>, których instalacja jest nadal aktywna</div>
        </div>
    </div>

    <?php if ($monthlyActiveDevicesError): ?>
    <div class="card-body">
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle me-2"></i><?= h($monthlyActiveDevicesError) ?>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data montażu</th>
                    <th>Model urządzenia</th>
                    <th>Klient</th>
                    <th>Nr seryjny</th>
                    <th>Pojazd</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyActiveDevices as $ad):
                    $adClient = !empty($ad['client_company_name']) ? $ad['client_company_name']
                        : (!empty($ad['client_contact_name']) ? $ad['client_contact_name'] : '—');
                ?>
                <tr>
                    <td><?= formatDate($ad['installation_date']) ?></td>
                    <td><?= h($ad['manufacturer_name'] . ' ' . $ad['model_name']) ?></td>
                    <td><?= h($adClient) ?></td>
                    <td class="fw-semibold"><?= h($ad['serial_number']) ?></td>
                    <td><?= h($ad['vehicle_registration'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($monthlyActiveDevices)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Brak aktywnych instalacji z wybranego miesiąca.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($monthlyActiveDevicesTotal > count($monthlyActiveDevices)): ?>
    <div class="card-footer py-2 text-muted small">
        Wyświetlono pierwsze <?= count($monthlyActiveDevices) ?> z <?= $monthlyActiveDevicesTotal ?> rekordów.
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
ob_start();
if ($activeTab === 'yearly'):
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    try {
        var monthLabels = ['Sty', 'Lut', 'Mar', 'Apr', 'Maj', 'Cze', 'Lip', 'Sie', 'Wrz', 'Paź', 'Lis', 'Gru'];
        var installSeries = <?= json_encode(array_values($installsByMonthData), JSON_UNESCAPED_UNICODE) ?>;
        var serviceSeries = <?= json_encode(array_values($servicesByMonthData), JSON_UNESCAPED_UNICODE) ?>;

        new Chart(document.getElementById('monthlyChart'), {
            type: 'bar',
            data: {
                labels: monthLabels,
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
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
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
<?php endif; ?>
<?php
$pageEndScripts = ob_get_clean();
include __DIR__ . '/includes/footer.php'; ?>
