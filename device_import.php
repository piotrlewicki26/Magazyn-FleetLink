<?php
/**
 * FleetLink System GPS - Device Import (CSV / Excel .xlsx)
 * Multi-step: 1) Upload  2) Map columns  3) Preview & import
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();
if (!isAdmin()) { flashError('Import urządzeń jest dostępny tylko dla Administratora.'); redirect(getBaseUrl() . 'devices.php'); }

$db = getDb();

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

/**
 * Parse an uploaded CSV file and return [headers[], rows[][]].
 */
function parseCsv(string $path, string $delimiter = ','): array {
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) {
        return [[], []];
    }
    $headers = null;
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        // Skip completely blank lines
        if ($row === [null]) continue;
        if ($headers === null) {
            $headers = array_map('trim', $row);
        } else {
            $rows[] = $row;
        }
    }
    fclose($fh);
    return [$headers ?? [], $rows];
}

/**
 * Parse an xlsx file (Office Open XML) and return [headers[], rows[][]].
 * Uses ZipArchive + SimpleXML — no extra libraries needed.
 */
function parseXlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [[], []];

    // Read shared strings
    $sharedStrings = [];
    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                // Concatenate all <t> nodes (handles rich-text cells)
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                if ($text === '' && isset($si->t)) {
                    $text = (string)$si->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // Read first sheet
    $sheetXml = null;
    for ($i = 1; $i <= 10; $i++) {
        $xml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
        if ($xml !== false) { $sheetXml = $xml; break; }
    }
    $zip->close();
    if ($sheetXml === null) return [[], []];

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) return [[], []];

    $ns = $sheet->getDocNamespaces(true);
    $defaultNs = $ns[''] ?? '';

    $allRows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowData  = [];
        $lastCol  = -1;
        foreach ($row->c as $cell) {
            // Determine column index from reference like "A1", "B2", "AA1"
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $colLetters = $m[1] ?? 'A';
            $colIdx = 0;
            foreach (str_split($colLetters) as $ch) {
                $colIdx = $colIdx * 26 + (ord($ch) - ord('A') + 1);
            }
            $colIdx--; // 0-based

            // Fill gaps with empty strings
            while ($lastCol < $colIdx - 1) {
                $rowData[] = '';
                $lastCol++;
            }
            $lastCol = $colIdx;

            $type  = (string)($cell['t'] ?? '');
            $value = (string)($cell->v ?? '');
            if ($type === 's') {
                $value = $sharedStrings[(int)$value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($cell->is->t ?? '');
            }
            $rowData[] = $value;
        }
        $allRows[] = $rowData;
    }

    if (empty($allRows)) return [[], []];
    $headers = array_map('trim', array_shift($allRows));
    return [$headers, $allRows];
}

// ──────────────────────────────────────────────────────────────
// Target fields (device table columns the user can map to)
// ──────────────────────────────────────────────────────────────
$targetFields = [
    'serial_number'  => 'Numer seryjny *',
    'model'          => 'Model (nazwa)',
    'manufacturer'   => 'Producent (nazwa)',
    'imei'           => 'IMEI',
    'sim_number'     => 'Nr karty SIM',
    'status'         => 'Status',
    'purchase_date'  => 'Data zakupu (RRRR-MM-DD)',
    'purchase_price' => 'Cena zakupu',
    'notes'          => 'Uwagi',
];

// Load available models for manual override selector
$allModels = $db->query("
    SELECT m.id, m.name, mf.name as manufacturer_name
    FROM models m
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    WHERE m.active = 1
    ORDER BY mf.name, m.name
")->fetchAll();

// ──────────────────────────────────────────────────────────────
// Step routing
// ──────────────────────────────────────────────────────────────
$step  = (int)($_REQUEST['step'] ?? 1);
$error = '';

// Ensure uploads dir
$uploadDir = __DIR__ . '/uploads/import/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// ──────────────────────────────────────────────────────────────
// Step 1 → 2  : handle file upload
// ──────────────────────────────────────────────────────────────
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'device_import.php');
    }
    if (empty($_FILES['import_file']['tmp_name'])) {
        $error = 'Wybierz plik do importu.';
    } else {
        $origName  = $_FILES['import_file']['name'];
        $ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xlsx'])) {
            $error = 'Obsługiwane formaty: CSV (.csv) i Excel (.xlsx).';
        } else {
            $tmpDest = $uploadDir . 'import_' . session_id() . '.' . $ext;
            if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $tmpDest)) {
                $error = 'Błąd podczas zapisywania pliku. Sprawdź uprawnienia katalogu uploads/.';
            } else {
                // Detect delimiter for CSV
                $delimiter = ',';
                if ($ext === 'csv') {
                    $sample = file_get_contents($tmpDest, false, null, 0, 8192);
                    $semicolons = substr_count($sample, ';');
                    $commas     = substr_count($sample, ',');
                    if ($semicolons > $commas) $delimiter = ';';
                }
                // Parse
                if ($ext === 'csv') {
                    [$headers, $rows] = parseCsv($tmpDest, $delimiter);
                } else {
                    [$headers, $rows] = parseXlsx($tmpDest);
                }
                if (empty($headers)) {
                    $error = 'Nie można odczytać nagłówków pliku. Upewnij się, że pierwsza linia zawiera nazwy kolumn.';
                    @unlink($tmpDest);
                } else {
                    // Store parsed data in session
                    $_SESSION['import_file']    = $tmpDest;
                    $_SESSION['import_headers'] = $headers;
                    $_SESSION['import_rows']    = $rows;
                    redirect(getBaseUrl() . 'device_import.php?step=2');
                }
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────
// Step 2 → 3  : handle column mapping
// ──────────────────────────────────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'device_import.php');
    }
    $mapping = [];
    foreach ($targetFields as $field => $label) {
        $idx = isset($_POST['map_' . $field]) ? (int)$_POST['map_' . $field] : -1;
        if ($idx >= 0) $mapping[$field] = $idx;
    }

    // Manual model override (0 = no override)
    $overrideModelId = (int)($_POST['override_model_id'] ?? 0);
    $_SESSION['import_override_model_id'] = $overrideModelId > 0 ? $overrideModelId : 0;

    // serial_number column is always required as the unique device identifier
    if (!isset($mapping['serial_number'])) {
        $error = 'Kolumna "Numer seryjny" jest wymagana.';
        $step  = 2;
    } else {
        $_SESSION['import_mapping'] = $mapping;
        redirect(getBaseUrl() . 'device_import.php?step=3');
    }
}

// ──────────────────────────────────────────────────────────────
// Step 3  : execute import
// ──────────────────────────────────────────────────────────────
$importStats = null;
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'device_import.php');
    }

    $headers         = $_SESSION['import_headers']          ?? [];
    $rows            = $_SESSION['import_rows']              ?? [];
    $mapping         = $_SESSION['import_mapping']           ?? [];
    $overrideModelId = (int)($_SESSION['import_override_model_id'] ?? 0);

    if (empty($rows) || !isset($mapping['serial_number'])) {
        flashError('Brak danych sesji. Zacznij od nowa.');
        redirect(getBaseUrl() . 'device_import.php');
    }

    // Pre-load manufacturers and models (case-insensitive lookup)
    $mfMap    = [];
    $modelMap = [];
    foreach ($db->query("SELECT id, name FROM manufacturers")->fetchAll() as $r) {
        $mfMap[mb_strtolower(trim($r['name']))] = $r['id'];
    }
    foreach ($db->query("SELECT m.id, m.name, mf.name as mf_name FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id")->fetchAll() as $r) {
        $modelMap[mb_strtolower(trim($r['mf_name'])) . '|' . mb_strtolower(trim($r['name']))] = $r['id'];
        $modelMap[mb_strtolower(trim($r['name']))] = $r['id']; // also by model name alone
    }

    $validStatuses = ['nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa'];
    $imported = 0; $skipped = 0; $errors = [];

    /**
     * Resolve (or auto-create) a manufacturer+model pair, caching results
     * in $mfMap/$modelMap to avoid repeated DB round-trips.
     * Returns model_id or null on failure.
     */
    $resolveModelId = function (string $mfName, string $modelName) use ($db, &$mfMap, &$modelMap): ?int {
        if ($modelName === '') return null;
        $key1 = mb_strtolower($mfName) . '|' . mb_strtolower($modelName);
        $key2 = mb_strtolower($modelName);
        if (isset($modelMap[$key1])) return $modelMap[$key1];
        if (isset($modelMap[$key2]))  return $modelMap[$key2];

        // Need to auto-create — resolve manufacturer first
        $mfId = null;
        if ($mfName !== '') {
            $mfKey = mb_strtolower($mfName);
            if (isset($mfMap[$mfKey])) {
                $mfId = $mfMap[$mfKey];
            } else {
                $db->prepare("INSERT IGNORE INTO manufacturers (name) VALUES (?)")->execute([$mfName]);
                $newMfId = (int)$db->lastInsertId();
                if (!$newMfId) {
                    $r = $db->prepare("SELECT id FROM manufacturers WHERE name=? LIMIT 1");
                    $r->execute([$mfName]);
                    $newMfId = (int)$r->fetchColumn();
                }
                $mfId = $newMfId;
                if ($mfId) $mfMap[$mfKey] = $mfId;
            }
        }
        if (!$mfId) return null;

        $db->prepare("INSERT IGNORE INTO models (manufacturer_id, name) VALUES (?,?)")->execute([$mfId, $modelName]);
        $newModelId = (int)$db->lastInsertId();
        if (!$newModelId) {
            $r2 = $db->prepare("SELECT id FROM models WHERE manufacturer_id=? AND name=? LIMIT 1");
            $r2->execute([$mfId, $modelName]);
            $newModelId = (int)$r2->fetchColumn();
        }
        if ($newModelId) {
            $modelMap[$key1] = $newModelId;
            $modelMap[$key2] = $newModelId;
        }
        return $newModelId ?: null;
    };

    $stmtInsert = $db->prepare("INSERT IGNORE INTO devices (model_id, serial_number, imei, sim_number, status, purchase_date, purchase_price, notes) VALUES (?,?,?,?,?,?,?,?)");

    foreach ($rows as $rowIdx => $row) {
        $get = function (string $field) use ($row, $mapping): string {
            if (!isset($mapping[$field])) return '';
            return trim($row[$mapping[$field]] ?? '');
        };

        $serialNumber = $get('serial_number');
        if ($serialNumber === '') {
            $skipped++;
            continue;
        }

        // Resolve model_id — prefer manual override, then try columns
        $modelId   = $overrideModelId > 0 ? $overrideModelId : null;
        if (!$modelId) {
            $modelName = $get('model');
            $mfName    = $get('manufacturer');
            if ($modelName !== '') {
                try {
                    $modelId = $resolveModelId($mfName, $modelName);
                } catch (Exception $e) {
                    $errors[] = "Wiersz " . ($rowIdx + 2) . ": " . $e->getMessage();
                    $skipped++;
                    continue;
                }
            }
        }
        if (!$modelId) {
            $errors[] = "Wiersz " . ($rowIdx + 2) . ": nie można ustalić modelu dla urządzenia $serialNumber — pominięto.";
            $skipped++;
            continue;
        }

        $status = $get('status');
        if (!in_array($status, $validStatuses)) $status = 'nowy';

        $purchaseDate  = $get('purchase_date') ?: null;
        $purchasePrice = str_replace(',', '.', $get('purchase_price')) ?: null;

        try {
            $stmtInsert->execute([
                $modelId,
                $serialNumber,
                $get('imei') ?: null,
                $get('sim_number') ?: null,
                $status,
                $purchaseDate,
                $purchasePrice,
                $get('notes') ?: null,
            ]);
            if ($stmtInsert->rowCount() > 0) {
                $imported++;
            } else {
                $errors[] = "Wiersz " . ($rowIdx + 2) . ": duplikat nr seryjnego '$serialNumber' — pominięto.";
                $skipped++;
            }
        } catch (PDOException $e) {
            $errors[] = "Wiersz " . ($rowIdx + 2) . ": " . $e->getMessage();
            $skipped++;
        }
    }

    // Clean up session
    @unlink($_SESSION['import_file'] ?? '');
    unset($_SESSION['import_file'], $_SESSION['import_headers'], $_SESSION['import_rows'], $_SESSION['import_mapping'], $_SESSION['import_override_model_id']);

    $importStats = compact('imported', 'skipped', 'errors');
}

// ──────────────────────────────────────────────────────────────
// Page setup
// ──────────────────────────────────────────────────────────────
$activePage = 'devices';
$pageTitle  = 'Import urządzeń';
include __DIR__ . '/includes/header.php';

$sessionHeaders      = $_SESSION['import_headers']          ?? [];
$sessionRows         = $_SESSION['import_rows']              ?? [];
$sessionMapping      = $_SESSION['import_mapping']           ?? [];
$sessionOverrideId   = (int)($_SESSION['import_override_model_id'] ?? 0);
?>

<div class="page-header">
    <h1><i class="fas fa-file-import me-2 text-primary"></i>Import urządzeń GPS</h1>
    <a href="devices.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
</div>

<!-- Step indicator -->
<?php if ($importStats === null): ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <?php foreach ([1 => 'Prześlij plik', 2 => 'Mapowanie kolumn', 3 => 'Podgląd i import'] as $s => $label): ?>
    <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
             style="width:32px;height:32px;background:<?= $step >= $s ? 'var(--fl-primary)' : '#dee2e6' ?>;color:<?= $step >= $s ? '#fff' : '#6c757d' ?>">
            <?= $s ?>
        </div>
        <span class="<?= $step === $s ? 'fw-semibold' : 'text-muted' ?>"><?= $label ?></span>
    </div>
    <?php if ($s < 3): ?><i class="fas fa-chevron-right text-muted"></i><?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= h($error) ?></div>
<?php endif; ?>

<?php
// ──────────────────────────────────────────────────────────────
// Render completed import summary
// ──────────────────────────────────────────────────────────────
if ($importStats !== null):
?>
<div class="card" style="max-width:700px">
    <div class="card-header"><i class="fas fa-check-circle me-2 text-success"></i>Import zakończony</div>
    <div class="card-body">
        <div class="row text-center g-3 mb-3">
            <div class="col-6">
                <div class="card border-success">
                    <div class="card-body py-2">
                        <div class="fs-3 fw-bold text-success"><?= $importStats['imported'] ?></div>
                        <div class="small text-muted">Zaimportowano</div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="card border-warning">
                    <div class="card-body py-2">
                        <div class="fs-3 fw-bold text-warning"><?= $importStats['skipped'] ?></div>
                        <div class="small text-muted">Pominięto</div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!empty($importStats['errors'])): ?>
        <details>
            <summary class="text-danger fw-semibold mb-2">Szczegóły błędów (<?= count($importStats['errors']) ?>)</summary>
            <ul class="small text-danger mt-2">
                <?php foreach ($importStats['errors'] as $e): ?>
                <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-3">
            <a href="devices.php" class="btn btn-primary"><i class="fas fa-list me-2"></i>Lista urządzeń</a>
            <a href="device_import.php" class="btn btn-outline-secondary"><i class="fas fa-redo me-2"></i>Nowy import</a>
        </div>
    </div>
</div>

<?php
// ──────────────────────────────────────────────────────────────
// Step 1: File upload
// ──────────────────────────────────────────────────────────────
elseif ($step === 1):
?>
<div class="card" style="max-width:600px">
    <div class="card-header"><i class="fas fa-upload me-2"></i>Krok 1 — Wybierz plik</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="1">
            <div class="mb-3">
                <label class="form-label fw-semibold">Plik CSV lub Excel (.xlsx)</label>
                <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx" required>
                <div class="form-text">
                    Plik powinien zawierać nagłówki w pierwszym wierszu.<br>
                    Obsługiwane: <strong>CSV</strong> (separator , lub ;) i <strong>Excel .xlsx</strong>.
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right me-2"></i>Dalej</button>
        </form>
    </div>
</div>
<div class="card mt-3" style="max-width:600px">
    <div class="card-header"><i class="fas fa-info-circle me-2 text-info"></i>Wskazówki dotyczące importu</div>
    <div class="card-body">
        <p class="small mb-2">Możliwe kolumny do zmapowania:</p>
        <table class="table table-sm table-borderless small">
            <tr><th>Pole</th><th>Opis</th></tr>
            <?php foreach ($targetFields as $field => $label): ?>
            <tr><td><code><?= h($field) ?></code></td><td><?= h($label) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php
// ──────────────────────────────────────────────────────────────
// Step 2: Column mapping
// ──────────────────────────────────────────────────────────────
elseif ($step === 2 && !empty($sessionHeaders)):
?>
<div class="card" style="max-width:700px">
    <div class="card-header"><i class="fas fa-columns me-2"></i>Krok 2 — Mapowanie kolumn</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Wykryto <strong><?= count($sessionHeaders) ?></strong> kolumn i
            <strong><?= count($sessionRows) ?></strong> wierszy danych.
            Przypisz kolumny z pliku do pól urządzenia.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="2">

            <!-- Manual model override -->
            <div class="mb-4 p-3 rounded border" style="background:rgba(13,110,253,.05)">
                <label class="form-label fw-semibold">
                    <i class="fas fa-tag me-1 text-primary"></i>Przypisz model dla wszystkich urządzeń (opcjonalne)
                </label>
                <select name="override_model_id" id="overrideModelSelect" class="form-select form-select-sm">
                    <option value="0">— nie przypisuj — użyj wartości z kolumn pliku —</option>
                    <?php
                    $curMf = '';
                    foreach ($allModels as $m):
                        if ($m['manufacturer_name'] !== $curMf) {
                            if ($curMf) echo '</optgroup>';
                            echo '<optgroup label="' . h($m['manufacturer_name']) . '">';
                            $curMf = $m['manufacturer_name'];
                        }
                    ?>
                    <option value="<?= $m['id'] ?>" <?= $sessionOverrideId === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= h($m['name']) ?>
                    </option>
                    <?php endforeach; if ($curMf) echo '</optgroup>'; ?>
                </select>
                <div class="form-text">
                    Jeśli wybierzesz model tutaj, kolumny <em>Model</em> i <em>Producent</em> z pliku zostaną zignorowane —
                    każde urządzenie zostanie przypisane do wybranego modelu.
                </div>
            </div>

            <div class="row g-3">
                <?php foreach ($targetFields as $field => $label):
                    $dimWhenOverride = in_array($field, ['model', 'manufacturer']);
                    $colClass = $dimWhenOverride ? 'col-md-6 model-col' : 'col-md-6';
                ?>
                <div class="<?= $colClass ?>">
                    <label class="form-label small fw-semibold <?= $field === 'serial_number' ? 'required-star' : '' ?>">
                        <?= h($label) ?>
                    </label>
                    <select name="map_<?= $field ?>" class="form-select form-select-sm">
                        <option value="-1">— nie importuj —</option>
                        <?php foreach ($sessionHeaders as $colIdx => $colName): ?>
                        <?php
                        // Auto-detect matching: check if column name is similar to the field
                        $autoMatch = (mb_strtolower(trim($colName)) === mb_strtolower($field))
                            || mb_stripos($colName, str_replace('_', ' ', $field)) !== false;
                        // Pre-select from previous session mapping
                        $selected  = (isset($sessionMapping[$field]) && $sessionMapping[$field] === $colIdx)
                            || (!isset($sessionMapping[$field]) && $autoMatch);
                        ?>
                        <option value="<?= $colIdx ?>" <?= $selected ? 'selected' : '' ?>>
                            <?= h($colName) ?>
                            <?php if (!empty($sessionRows[0][$colIdx])): ?>
                            — <?= h(mb_strimwidth($sessionRows[0][$colIdx], 0, 30, '…')) ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-eye me-2"></i>Podgląd importu</button>
                <a href="device_import.php" class="btn btn-outline-secondary">Zacznij od nowa</a>
            </div>
        </form>
    </div>
</div>
<script>
// Dim model/manufacturer column selectors when override model is chosen
(function(){
    const sel = document.getElementById('overrideModelSelect');
    const modelCols = document.querySelectorAll('.model-col');
    function toggle(){
        const override = sel.value !== '0';
        modelCols.forEach(function(el){
            el.style.opacity = override ? '0.4' : '1';
            el.querySelectorAll('select').forEach(function(s){ s.disabled = override; });
        });
    }
    sel.addEventListener('change', toggle);
    toggle();
})();
</script>

<?php
// ──────────────────────────────────────────────────────────────
// Step 3: Preview
// ──────────────────────────────────────────────────────────────
elseif ($step === 3 && !empty($sessionRows) && !empty($sessionMapping)):
    $previewRows = array_slice($sessionRows, 0, 10);
    // Resolve override model name for display
    $overrideModelLabel = null;
    if ($sessionOverrideId > 0) {
        foreach ($allModels as $m) {
            if ((int)$m['id'] === $sessionOverrideId) {
                $overrideModelLabel = $m['manufacturer_name'] . ' ' . $m['name'];
                break;
            }
        }
    }
?>
<?php if ($overrideModelLabel): ?>
<div class="alert alert-info mb-3">
    <i class="fas fa-tag me-2"></i>Model dla wszystkich urządzeń: <strong><?= h($overrideModelLabel) ?></strong>
</div>
<?php endif; ?>
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-table me-2"></i>Krok 3 — Podgląd importu (pierwsze <?= count($previewRows) ?> z <?= count($sessionRows) ?> wierszy)</div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 small">
            <thead class="table-light">
                <tr>
                    <?php foreach ($sessionMapping as $field => $colIdx): ?>
                    <th><?= h($targetFields[$field] ?? $field) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($previewRows as $row): ?>
                <tr>
                    <?php foreach ($sessionMapping as $field => $colIdx): ?>
                    <td><?= h($row[$colIdx] ?? '') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="d-flex gap-2 mb-4">
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="3">
        <button type="submit" class="btn btn-success">
            <i class="fas fa-file-import me-2"></i>Importuj <?= count($sessionRows) ?> rekordów
        </button>
    </form>
    <a href="device_import.php?step=2" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-2"></i>Wróć do mapowania
    </a>
    <a href="device_import.php" class="btn btn-outline-danger">
        <i class="fas fa-times me-2"></i>Anuluj
    </a>
</div>

<?php else: ?>
<div class="alert alert-warning">Nieprawidłowy krok. <a href="device_import.php">Zacznij od nowa.</a></div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
