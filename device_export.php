<?php
/**
 * FleetLink System GPS - Device Export (XML / PDF)
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

$format = sanitize($_POST['format'] ?? 'xml');
if (!in_array($format, ['xml', 'pdf'])) {
    $format = 'xml';
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

// ── XML export ────────────────────────────────────────────────────────────────
if ($format === 'xml') {
    $filename = 'urzadzenia_' . date('Y-m-d_His') . '.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->setIndent(true);
    $xml->setIndentString('    ');
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('urzadzenia');
    $xml->writeAttribute('eksport', date('Y-m-d H:i:s'));
    $xml->writeAttribute('liczba', (string)count($devices));

    foreach ($devices as $d) {
        $xml->startElement('urzadzenie');
        $xml->writeAttribute('id', (string)$d['id']);
        $xml->writeElement('nr_seryjny',   $d['serial_number']);
        $xml->writeElement('imei',         $d['imei'] ?? '');
        $xml->writeElement('nr_sim',       $d['sim_number'] ?? '');
        if (!empty($d['ble_id']))      $xml->writeElement('ble_id',      $d['ble_id']);
        if ($d['major'] !== null)      $xml->writeElement('major',       (string)$d['major']);
        if ($d['minor'] !== null)      $xml->writeElement('minor',       (string)$d['minor']);
        if (!empty($d['mac_address'])) $xml->writeElement('mac_address', $d['mac_address']);
        $xml->writeElement('producent',    $d['manufacturer_name']);
        $xml->writeElement('model',        $d['model_name']);
        $xml->writeElement('status',       $statusLabels[$d['status']] ?? $d['status']);
        $xml->writeElement('rejestracja',  $d['vehicle_registration'] ?? '');
        $xml->writeElement('klient',       $d['company_name'] ?: ($d['contact_name'] ?? ''));
        $xml->writeElement('data_montazu', $d['installation_date'] ?? '');
        $xml->writeElement('data_zakupu',  $d['purchase_date'] ?? '');
        $xml->writeElement('data_sprzedazy', $d['sale_date'] ?? '');
        if (isAdmin()) {
            $xml->writeElement('cena_zakupu', number_format((float)($d['purchase_price'] ?? 0), 2, '.', ''));
        }
        $xml->writeElement('uwagi', $d['notes'] ?? '');
        $xml->endElement(); // urzadzenie
    }

    $xml->endElement(); // urzadzenia
    $xml->endDocument();
    echo $xml->outputMemory();
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
