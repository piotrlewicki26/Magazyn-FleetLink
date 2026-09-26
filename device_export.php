<?php
/**
 * FleetLink System GPS - Device Export (CSV / XLSX / PDF)
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Błąd bezpieczeństwa.');
}

$format = sanitize($_POST['format'] ?? 'csv');
if (!in_array($format, ['csv', 'xlsx', 'pdf'])) {
    $format = 'csv';
}

$deviceIds = array_map('intval', $_POST['device_ids'] ?? []);
$deviceIds = array_filter($deviceIds);

if (empty($deviceIds)) {
    header('Location: ' . getBaseUrl() . 'devices.php');
    exit;
}

$db = getDb();

$placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
$stmt = $db->prepare("
    SELECT d.id, d.serial_number, d.imei, d.sim_number,
           d.ble_id, d.major, d.minor, d.mac_address,
           d.status, d.purchase_date, d.sale_date, d.notes,
           d.purchase_price,
           m.name AS model_name, mf.name AS manufacturer_name,
           v.registration AS vehicle_registration,
           c.contact_name, c.company_name,
           i.installation_date
    FROM devices d
    JOIN models m ON m.id = d.model_id
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    LEFT JOIN (
        SELECT i2.*,
               ROW_NUMBER() OVER (
                   PARTITION BY i2.device_id
                   ORDER BY
                       CASE i2.status
                           WHEN 'aktywna'    THEN 0
                           WHEN 'zakonczona' THEN 1
                           ELSE 2
                       END,
                       i2.installation_date DESC
               ) AS rn
        FROM installations i2
        WHERE i2.status != 'anulowana'
    ) i ON i.device_id = d.id AND i.rn = 1
    LEFT JOIN vehicles v ON v.id = i.vehicle_id
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE d.id IN ($placeholders)
    ORDER BY d.id
");
$stmt->execute(array_values($deviceIds));
$devices = $stmt->fetchAll();

$statusLabels = [
    'nowy'         => 'Nowy',
    'sprawny'      => 'Sprawny',
    'w_serwisie'   => 'W serwisie',
    'uszkodzony'   => 'Uszkodzony',
    'zamontowany'  => 'Zamontowany',
    'wycofany'     => 'Wycofany',
    'sprzedany'    => 'Sprzedany',
    'dzierżawa'    => 'Dzierżawa',
    'do_demontazu' => 'Do demontażu',
];

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Returns the column headers for the export.
 * The purchase-price column is added only for admins.
 */
function exportHeaders(): array {
    $cols = [
        'Nr seryjny', 'IMEI', 'Nr SIM', 'Producent', 'Model',
        'Status', 'Rejestracja', 'Klient',
        'Data montażu', 'Data zakupu', 'Data sprzedaży',
    ];
    if (isAdmin()) {
        $cols[] = 'Cena zakupu (PLN)';
    }
    $cols[] = 'Uwagi';
    return $cols;
}

/**
 * Converts a single device row to a flat array of strings.
 */
function deviceToRow(array $d, array $statusLabels): array {
    $row = [
        $d['serial_number'],
        $d['imei'] ?? '',
        $d['sim_number'] ?? '',
        $d['manufacturer_name'],
        $d['model_name'],
        $statusLabels[$d['status']] ?? $d['status'],
        $d['vehicle_registration'] ?? '',
        $d['company_name'] ?: ($d['contact_name'] ?? ''),
        $d['installation_date'] ? formatDate($d['installation_date']) : '',
        $d['purchase_date']     ? formatDate($d['purchase_date'])     : '',
        $d['sale_date']         ? formatDate($d['sale_date'])         : '',
    ];
    if (isAdmin()) {
        $row[] = $d['purchase_price'] > 0
            ? number_format((float)$d['purchase_price'], 2, '.', '')
            : '';
    }
    $row[] = $d['notes'] ?? '';
    return $row;
}

// ── CSV export ────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'urzadzenia_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // UTF-8 BOM so Excel opens the file correctly
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, exportHeaders(), ';');
    foreach ($devices as $d) {
        fputcsv($out, deviceToRow($d, $statusLabels), ';');
    }
    fclose($out);
    exit;
}

// ── XLSX export ───────────────────────────────────────────────────────────────
if ($format === 'xlsx') {
    $filename = 'urzadzenia_' . date('Y-m-d_His') . '.xlsx';

    /* ---- minimal XLSX builder (no external library needed) ---- */

    // Escape a value for XML cell content
    $xmlEsc = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    // Build shared-strings table and return index
    $sharedStrings = [];
    $ssIndex = 0;
    $ssMap = [];
    $getSSIdx = function (string $v) use (&$sharedStrings, &$ssIndex, &$ssMap): int {
        if (!isset($ssMap[$v])) {
            $ssMap[$v]     = $ssIndex++;
            $sharedStrings[] = $v;
        }
        return $ssMap[$v];
    };

    // Collect all rows (header + data)
    $allRows = [];
    $headers = exportHeaders();
    $allRows[] = $headers;
    foreach ($devices as $d) {
        $allRows[] = deviceToRow($d, $statusLabels);
    }

    // Build worksheet XML
    $wsRows = '';
    foreach ($allRows as $ri => $row) {
        $wsRows .= '<row r="' . ($ri + 1) . '">';
        foreach ($row as $ci => $cellVal) {
            $colLetter = '';
            $n = $ci + 1;
            while ($n > 0) {
                $rem = ($n - 1) % 26;
                $colLetter = chr(65 + $rem) . $colLetter;
                $n = (int)(($n - 1) / 26);
            }
            $cellRef = $colLetter . ($ri + 1);
            $idx = $getSSIdx((string)$cellVal);
            $wsRows .= '<c r="' . $cellRef . '" t="s"><v>' . $idx . '</v></c>';
        }
        $wsRows .= '</row>';
    }

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetData>' . $wsRows . '</sheetData></worksheet>';

    // Build shared strings XML
    $ssItems = '';
    foreach ($sharedStrings as $s) {
        $ssItems .= '<si><t xml:space="preserve">' . $xmlEsc($s) . '</t></si>';
    }
    $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">'
        . $ssItems . '</sst>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Urządzenia" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml"  ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';

    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    // Assemble zip in memory
    $tmpFile = tempnam(sys_get_temp_dir(), 'fleetlink_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('Nie można wygenerować pliku XLSX.');
    }
    $zip->addFromString('[Content_Types].xml',             $contentTypes);
    $zip->addFromString('_rels/.rels',                     $relsRoot);
    $zip->addFromString('xl/workbook.xml',                 $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels',      $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml',        $worksheetXml);
    $zip->addFromString('xl/sharedStrings.xml',            $sharedStringsXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

// ── PDF (print-friendly HTML) ─────────────────────────────────────────────────
$settings = [];
try {
    $rows = $db->query("SELECT `key`, `value` FROM settings")->fetchAll();
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
} catch (Exception $e) { /* settings table may not exist */ }
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Eksport urządzeń — <?= date('d.m.Y') ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; background: #fff; }
        .doc { max-width: 1100px; margin: 0 auto; padding: 20px 25px; }
        /* Header */
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0d6efd; }
        .company-name { font-size: 18px; font-weight: bold; color: #0d6efd; }
        .company-sub { font-size: 10px; color: #555; margin-top: 4px; line-height: 1.7; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 20px; color: #333; }
        .doc-title p { font-size: 10px; color: #666; margin-top: 3px; }
        /* Table */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 10px; }
        th { background: #0d6efd; color: #fff; padding: 6px 8px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        td { border-bottom: 1px solid #dee2e6; padding: 5px 8px; vertical-align: top; word-break: break-word; }
        tr:nth-child(even) td { background: #f8f9fa; }
        /* Status badges */
        .st { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 9px; font-weight: bold; white-space: nowrap; }
        .st-nowy, .st-sprawny     { background: #198754; color: #fff; }
        .st-zamontowany           { background: #0d6efd; color: #fff; }
        .st-w_serwisie,
        .st-do_demontazu          { background: #e67e22; color: #fff; }
        .st-uszkodzony,
        .st-wycofany              { background: #212529; color: #fff; }
        .st-sprzedany             { background: #dc3545; color: #fff; }
        .st-dzierzawa             { background: #6f42c1; color: #fff; }
        /* Footer */
        .doc-footer { margin-top: 20px; border-top: 1px solid #dee2e6; padding-top: 8px; font-size: 9px; color: #aaa; text-align: center; }
        /* Controls */
        .no-print { margin-bottom: 15px; display: flex; gap: 8px; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 10px; }
            .doc { padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>
<div class="doc">
    <!-- Print controls (hidden on actual print) -->
    <div class="no-print">
        <button onclick="window.print()" style="padding:7px 16px;background:#0d6efd;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;">
            🖨️ Drukuj / Zapisz jako PDF
        </button>
        <a href="devices.php" style="padding:7px 16px;background:#6c757d;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;">
            ← Powrót do listy
        </a>
    </div>

    <!-- Document header -->
    <div class="doc-header">
        <div>
            <div class="company-name"><?= h($settings['company_name'] ?? 'FleetLink') ?></div>
            <div class="company-sub">
                <?php if (!empty($settings['company_address'])): ?><?= h($settings['company_address']) ?><br><?php endif; ?>
                <?php if (!empty($settings['company_city'])): ?><?= h($settings['company_city']) ?><br><?php endif; ?>
                <?php if (!empty($settings['company_nip'])): ?>NIP: <?= h($settings['company_nip']) ?><?php endif; ?>
            </div>
        </div>
        <div class="doc-title">
            <h1>LISTA URZĄDZEŃ</h1>
            <p>Data eksportu: <?= date('d.m.Y H:i') ?></p>
            <p>Liczba urządzeń: <?= count($devices) ?></p>
        </div>
    </div>

    <!-- Device table -->
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Nr seryjny</th>
                <th>IMEI</th>
                <th>Producent / Model</th>
                <th>Status</th>
                <th>Rejestracja</th>
                <th>Nr SIM</th>
                <th>Klient</th>
                <th>Data montażu</th>
                <th>Data zakupu</th>
                <?php if (isAdmin()): ?><th>Cena zakupu</th><?php endif; ?>
                <th>Uwagi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $i => $d): ?>
            <?php
            $statusSlug = strtr($d['status'], ['ż' => 'z', 'ó' => 'o', 'ą' => 'a', 'ę' => 'e', 'ś' => 's', 'ź' => 'z', 'ć' => 'c', 'ń' => 'n', 'ł' => 'l']);
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= h($d['serial_number']) ?></td>
                <td><?= h($d['imei'] ?? '—') ?></td>
                <td><?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?></td>
                <td><span class="st st-<?= h($statusSlug) ?>"><?= h($statusLabels[$d['status']] ?? $d['status']) ?></span></td>
                <td><?= h($d['vehicle_registration'] ?? '—') ?></td>
                <td><?= h($d['sim_number'] ?? '—') ?></td>
                <td><?= h($d['company_name'] ?: ($d['contact_name'] ?? '—')) ?></td>
                <td><?= $d['installation_date'] ? formatDate($d['installation_date']) : '—' ?></td>
                <td><?= $d['purchase_date']    ? formatDate($d['purchase_date'])    : '—' ?></td>
                <?php if (isAdmin()): ?>
                <td><?= $d['purchase_price'] > 0 ? formatMoney($d['purchase_price']) : '—' ?></td>
                <?php endif; ?>
                <td><?= h($d['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="doc-footer">
        FleetLink System GPS &bull; Eksport wygenerowany <?= date('d.m.Y H:i') ?>
    </div>
</div>
</body>
</html>
