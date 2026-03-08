<?php
/**
 * FleetLink Magazyn - Inventory Management (Stan magazynowy)
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

// Handle POST - inventory adjustments
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'inventory.php');
    }
    $postAction = sanitize($_POST['action'] ?? '');
    $modelId    = (int)($_POST['model_id'] ?? 0);
    $type       = sanitize($_POST['type'] ?? 'in');
    $quantity   = (int)($_POST['quantity'] ?? 0);
    $reason     = sanitize($_POST['reason'] ?? '');
    $user       = getCurrentUser();

    if (!in_array($type, ['in','out','correction'])) $type = 'in';

    if ($postAction === 'sync_inventory') {
        $db->beginTransaction();
        try {
            // Ensure inventory rows exist for all active models
            $db->exec("
                INSERT INTO inventory (model_id, quantity, min_quantity)
                SELECT m.id, 0, 0 FROM models m WHERE m.active = 1
                ON DUPLICATE KEY UPDATE model_id = model_id
            ");
            // Set quantity = actual in-stock device count (0 when none)
            $db->exec("
                UPDATE inventory i
                LEFT JOIN (
                    SELECT model_id, COUNT(*) AS cnt
                    FROM devices
                    WHERE status IN ('nowy','sprawny')
                    GROUP BY model_id
                ) AS actual ON actual.model_id = i.model_id
                SET i.quantity = COALESCE(actual.cnt, 0)
            ");
            // Log a correction movement for each model that now has stock
            $adminStmt = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
            $adminRow  = $adminStmt ? $adminStmt->fetch() : false;
            if ($adminRow) {
                $db->prepare("INSERT INTO inventory_movements (model_id, user_id, type, quantity, reason, reference_type)
                              SELECT model_id, ?, 'correction', quantity,
                                     CONCAT('Synchronizacja z listą urządzeń (nowy stan: ', quantity, ' szt.)'),
                                     'sync'
                              FROM inventory WHERE quantity > 0")
                   ->execute([$adminRow['id']]);
            }
            $db->commit();
            flashSuccess('Stan magazynowy zsynchronizowany z listą urządzeń.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd synchronizacji: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'inventory.php');
    }

    if ($postAction === 'adjustment' && $modelId && $quantity != 0) {
        // Update inventory
        $db->beginTransaction();
        try {
            // Ensure inventory row exists
            $db->prepare("INSERT INTO inventory (model_id, quantity, min_quantity) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE model_id=model_id")->execute([$modelId]);

            if ($type === 'correction') {
                $db->prepare("UPDATE inventory SET quantity=? WHERE model_id=?")->execute([$quantity, $modelId]);
            } elseif ($type === 'in') {
                $db->prepare("UPDATE inventory SET quantity=quantity+? WHERE model_id=?")->execute([$quantity, $modelId]);
            } elseif ($type === 'out') {
                // Check we have enough
                $stmt = $db->prepare("SELECT quantity FROM inventory WHERE model_id=?");
                $stmt->execute([$modelId]);
                $current = (int)$stmt->fetchColumn();
                if ($current < $quantity) {
                    throw new Exception('Niewystarczający stan magazynowy. Dostępne: ' . $current . ' szt.');
                }
                $db->prepare("UPDATE inventory SET quantity=quantity-? WHERE model_id=?")->execute([$quantity, $modelId]);
            }

            // Log movement
            $db->prepare("INSERT INTO inventory_movements (model_id, user_id, type, quantity, reason) VALUES (?,?,?,?,?)")
               ->execute([$modelId, $user['id'], $type, $quantity, $reason]);

            $db->commit();
            flashSuccess('Stan magazynu został zaktualizowany.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError($e->getMessage());
        }
        redirect(getBaseUrl() . 'inventory.php');

    } elseif ($postAction === 'set_min') {
        $minQty = (int)($_POST['min_quantity'] ?? 0);
        $db->prepare("UPDATE inventory SET min_quantity=? WHERE model_id=?")->execute([$minQty, $modelId]);
        flashSuccess('Minimalny stan magazynowy zaktualizowany.');
        redirect(getBaseUrl() . 'inventory.php');

    } elseif ($postAction === 'accessory_add') {
        if (!isAdmin()) { flashError('Brak uprawnień.'); redirect(getBaseUrl() . 'inventory.php?action=accessories'); }
        $name  = sanitize($_POST['name'] ?? '');
        $qty   = max(0, (int)($_POST['quantity_initial'] ?? 0));
        $notes = sanitize($_POST['notes'] ?? '');
        if (empty($name)) { flashError('Nazwa akcesoriów jest wymagana.'); redirect(getBaseUrl() . 'inventory.php?action=accessories'); }
        try {
            $db->prepare("INSERT INTO accessories (name, quantity_initial, notes) VALUES (?,?,?)")->execute([$name, $qty, $notes]);
            flashSuccess('Akcesorium dodane.');
        } catch (Exception $e) { flashError('Błąd: ' . $e->getMessage()); }
        redirect(getBaseUrl() . 'inventory.php?action=accessories');

    } elseif ($postAction === 'accessory_edit') {
        if (!isAdmin()) { flashError('Brak uprawnień.'); redirect(getBaseUrl() . 'inventory.php?action=accessories'); }
        $accId = (int)($_POST['accessory_id'] ?? 0);
        $name  = sanitize($_POST['name'] ?? '');
        $qty   = max(0, (int)($_POST['quantity_initial'] ?? 0));
        $notes = sanitize($_POST['notes'] ?? '');
        if (!$accId || empty($name)) { flashError('Dane niepoprawne.'); redirect(getBaseUrl() . 'inventory.php?action=accessories'); }
        $db->prepare("UPDATE accessories SET name=?, quantity_initial=?, notes=? WHERE id=?")->execute([$name, $qty, $notes, $accId]);
        flashSuccess('Akcesorium zaktualizowane.');
        redirect(getBaseUrl() . 'inventory.php?action=accessories');

    } elseif ($postAction === 'accessory_delete') {
        if (!isAdmin()) { flashError('Brak uprawnień.'); redirect(getBaseUrl() . 'inventory.php?action=accessories'); }
        $accId = (int)($_POST['accessory_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM accessories WHERE id=?")->execute([$accId]);
            flashSuccess('Akcesorium usunięte.');
        } catch (PDOException $e) { flashError('Nie można usunąć — akcesorium jest powiązane z wydaniami.'); }
        redirect(getBaseUrl() . 'inventory.php?action=accessories');
    }
}

// Get inventory data – use models as the primary source so all models with devices
// appear even if no inventory row exists yet. Also show the actual in-stock count
// derived directly from the devices table.
$inventory = $db->query("
    SELECT m.id as model_id,
           COALESCE(i.quantity, 0) as quantity,
           COALESCE(i.min_quantity, 0) as min_quantity,
           i.updated_at,
           m.name as model_name, m.price_purchase, m.price_sale,
           mf.name as manufacturer_name,
           (SELECT COUNT(*) FROM devices d2
            WHERE d2.model_id = m.id
              AND d2.status IN ('nowy','sprawny')) as actual_count
    FROM models m
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    LEFT JOIN inventory i ON i.model_id = m.id
    WHERE m.active = 1
      AND (i.model_id IS NOT NULL
           OR EXISTS (SELECT 1 FROM devices d3 WHERE d3.model_id = m.id))
    ORDER BY mf.name, m.name
")->fetchAll();

// Movements history
$movements = [];
if ($action === 'movements') {
    $movements = $db->query("
        SELECT im.*, m.name as model_name, mf.name as manufacturer_name, u.name as user_name
        FROM inventory_movements im
        JOIN models m ON m.id = im.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        JOIN users u ON u.id = im.user_id
        ORDER BY im.created_at DESC
        LIMIT 100
    ")->fetchAll();
}

// Accessories list
$accessories = [];
if ($action === 'accessories') {
    try {
        $accessories = $db->query("
            SELECT a.*,
                   COALESCE((SELECT SUM(ai.quantity) FROM accessory_issues ai WHERE ai.accessory_id = a.id), 0) AS issued
            FROM accessories a
            WHERE a.active = 1
            ORDER BY a.name
        ")->fetchAll();
    } catch (Exception $e) { $accessories = []; }
}

// Accessories issue history
$accHistory = [];
if ($action === 'accessories') {
    try {
        $accHistory = $db->query("
            SELECT ai.*, a.name AS accessory_name, u.name AS user_name,
                   i.id AS inst_id,
                   CONCAT('ZM/', YEAR(i.installation_date), '/', LPAD(i.id,4,'0')) AS order_num
            FROM accessory_issues ai
            JOIN accessories a ON a.id = ai.accessory_id
            JOIN users u ON u.id = ai.user_id
            LEFT JOIN installations i ON i.id = ai.installation_id
            ORDER BY ai.issued_at DESC
            LIMIT 200
        ")->fetchAll();
    } catch (Exception $e) { $accHistory = []; }
}

if ($action === 'sim_cards') {
    $simCards = $db->query("
        SELECT d.id, d.serial_number, d.sim_number, d.status,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration as vehicle_registration,
               c.company_name, c.contact_name
        FROM devices d
        JOIN models m ON m.id = d.model_id
        JOIN manufacturers mf ON mf.id = m.manufacturer_id
        LEFT JOIN installations i ON i.device_id = d.id AND i.status = 'aktywna'
        LEFT JOIN vehicles v ON v.id = i.vehicle_id
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE d.sim_number IS NOT NULL AND d.sim_number <> ''
        ORDER BY d.sim_number
    ")->fetchAll();
}

$models = $db->query("SELECT m.id, m.name, mf.name as manufacturer_name FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE m.active=1 ORDER BY mf.name, m.name")->fetchAll();

$activePage = 'inventory';
$pageTitle = 'Stan magazynowy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-warehouse me-2 text-primary"></i>Stan magazynowy</h1>
    <div>
        <a href="inventory.php" class="btn <?= $action === 'list' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm me-1">
            <i class="fas fa-boxes me-1"></i>Stany
        </a>
        <a href="inventory.php?action=accessories" class="btn <?= $action === 'accessories' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm me-1">
            <i class="fas fa-toolbox me-1"></i>Akcesoria
        </a>
        <a href="inventory.php?action=movements" class="btn <?= $action === 'movements' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm me-1">
            <i class="fas fa-history me-1"></i>Historia ruchów
        </a>
        <a href="inventory.php?action=sim_cards" class="btn <?= $action === 'sim_cards' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm me-1">
            <i class="fas fa-sim-card me-1"></i>Karty SIM
        </a>
        <form method="POST" class="d-inline me-1">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync_inventory">
            <button type="submit" class="btn btn-outline-info btn-sm"
                    data-confirm="Zsynchronizować stan magazynowy z listą urządzeń? Ilości w magazynie zostaną nadpisane rzeczywistą liczbą urządzeń o statusie Nowy/Sprawny."
                    title="Przelicz stany magazynowe na podstawie listy urządzeń">
                <i class="fas fa-sync-alt me-1"></i>Synchronizuj z urządzeniami
            </button>
        </form>
        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#adjustModal">
            <i class="fas fa-plus-minus me-1"></i>Koryguj stan
        </button>
    </div>
</div>

<!-- Summary Stats -->
<?php
$totalStock = array_sum(array_column($inventory, 'quantity'));
$totalValue = array_sum(array_map(fn($i) => $i['quantity'] * $i['price_purchase'], $inventory));
$lowStockCount = count(array_filter($inventory, fn($i) => $i['quantity'] <= $i['min_quantity']));
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-primary fw-bold"><?= $totalStock ?></div>
            <div class="text-muted small">Łącznie szt.</div>
        </div>
    </div>
    <?php if (isAdmin()): ?>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-success fw-bold"><?= formatMoney($totalValue) ?></div>
            <div class="text-muted small">Wartość magazynu</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 fw-bold <?= $lowStockCount > 0 ? 'text-danger' : 'text-success' ?>"><?= $lowStockCount ?></div>
            <div class="text-muted small">Niski stan</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-info fw-bold"><?= count($inventory) ?></div>
            <div class="text-muted small">Modeli w magazynie</div>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">Stany magazynowe</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Producent</th><th>Model</th><th>Stan magazynowy</th><th>Faktyczny stan urządzeń</th><th>Min. stan</th><?php if (isAdmin()): ?><th>Wartość (zakup)</th><th>Wartość (sprzedaż)</th><?php endif; ?><th>Ostatnia zmiana</th><th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory as $item): ?>
                <?php $mismatch = (int)$item['actual_count'] !== (int)$item['quantity']; ?>
                <tr class="<?= $item['quantity'] <= $item['min_quantity'] ? 'table-warning' : '' ?>">
                    <td><?= h($item['manufacturer_name']) ?></td>
                    <td class="fw-semibold"><?= h($item['model_name']) ?></td>
                    <td>
                        <span class="fw-bold <?= $item['quantity'] == 0 ? 'text-danger' : ($item['quantity'] <= $item['min_quantity'] ? 'text-warning' : 'text-success') ?>">
                            <?= (int)$item['quantity'] ?> szt
                        </span>
                        <?php if ($item['quantity'] <= $item['min_quantity'] && $item['min_quantity'] > 0): ?>
                        <span class="badge bg-warning ms-1">niski stan</span>
                        <?php endif; ?>
                        <?php if ($mismatch): ?>
                        <span class="badge bg-danger ms-1" title="Rozbieżność z listą urządzeń — użyj Synchronizuj">!</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-bold <?= $item['actual_count'] == 0 ? 'text-danger' : 'text-success' ?>">
                            <?= (int)$item['actual_count'] ?> szt
                        </span>
                        <?php if ($mismatch): ?>
                        <i class="fas fa-exclamation-triangle text-warning ms-1" title="Różni się od stanu magazynowego — kliknij Synchronizuj"></i>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$item['min_quantity'] ?> szt</td>
                    <?php if (isAdmin()): ?>
                    <td><?= formatMoney($item['quantity'] * $item['price_purchase']) ?></td>
                    <td><?= formatMoney($item['quantity'] * $item['price_sale']) ?></td>
                    <?php endif; ?>
                    <td class="text-muted small"><?= $item['updated_at'] ? formatDateTime($item['updated_at']) : '—' ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action"
                                onclick="openAdjustModal(<?= $item['model_id'] ?>, '<?= h(addslashes($item['manufacturer_name'] . ' ' . $item['model_name'])) ?>', <?= (int)$item['quantity'] ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action"
                                onclick="openMinModal(<?= $item['model_id'] ?>, '<?= h(addslashes($item['model_name'])) ?>', <?= (int)$item['min_quantity'] ?>)">
                            <i class="fas fa-bell"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($inventory)): ?>
                <tr><td colspan="<?= isAdmin() ? 9 : 7 ?>" class="text-center text-muted p-3">Brak modeli w magazynie z urządzeniami. Dodaj <a href="models.php">modele urządzeń</a> i <a href="devices.php?action=add">urządzenia</a> najpierw. Po dodaniu użyj przycisku <strong>Synchronizuj z urządzeniami</strong>, aby zaktualizować stany.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'movements'): ?>
<div class="card">
    <div class="card-header">Historia ruchów magazynowych (ostatnie 100)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>Data</th><th>Model</th><th>Typ</th><th>Ilość</th><th>Powód</th><th>Użytkownik</th></tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $mv): ?>
                <tr>
                    <td class="text-muted small"><?= formatDateTime($mv['created_at']) ?></td>
                    <td><?= h($mv['manufacturer_name'] . ' ' . $mv['model_name']) ?></td>
                    <td>
                        <?php
                        $typeBadge = ['in' => 'success', 'out' => 'danger', 'correction' => 'info'];
                        $typeLabel = ['in' => '⬆ Przyjęcie', 'out' => '⬇ Wydanie', 'correction' => '↻ Korekta'];
                        ?>
                        <span class="badge bg-<?= $typeBadge[$mv['type']] ?>"><?= $typeLabel[$mv['type']] ?></span>
                    </td>
                    <td class="fw-bold"><?= $mv['quantity'] ?> szt</td>
                    <td><?= h($mv['reason'] ?? '—') ?></td>
                    <td><?= h($mv['user_name']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($movements)): ?>
                <tr><td colspan="6" class="text-center text-muted">Brak ruchów magazynowych.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'accessories'): ?>
<!-- ── Accessories section ──────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-toolbox me-2 text-warning"></i>Akcesoria magazynowe (<?= count($accessories) ?>)</span>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addAccessoryModal">
                    <i class="fas fa-plus me-1"></i>Dodaj akcesorium
                </button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px">L.p.</th>
                            <th>Nazwa</th>
                            <th class="text-center">Ilość początkowa</th>
                            <th class="text-center">Ilość wydana</th>
                            <th class="text-center">Pozostało</th>
                            <th>Uwagi</th>
                            <?php if (isAdmin()): ?><th style="width:80px">Akcje</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accessories as $lp => $acc):
                            $issued    = (int)$acc['issued'];
                            $remaining = (int)$acc['quantity_initial'] - $issued;
                        ?>
                        <tr>
                            <td class="text-muted"><?= $lp + 1 ?></td>
                            <td class="fw-semibold"><?= h($acc['name']) ?></td>
                            <td class="text-center"><?= (int)$acc['quantity_initial'] ?></td>
                            <td class="text-center text-warning fw-bold"><?= $issued ?></td>
                            <td class="text-center fw-bold <?= $remaining <= 0 ? 'text-danger' : ($remaining <= 3 ? 'text-warning' : 'text-success') ?>">
                                <?= $remaining ?>
                            </td>
                            <td class="text-muted small"><?= h($acc['notes'] ?? '') ?></td>
                            <?php if (isAdmin()): ?>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-action acc-edit-btn"
                                        data-id="<?= $acc['id'] ?>"
                                        data-name="<?= h($acc['name']) ?>"
                                        data-qty="<?= (int)$acc['quantity_initial'] ?>"
                                        data-notes="<?= h($acc['notes'] ?? '') ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="accessory_delete">
                                    <input type="hidden" name="accessory_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                            data-confirm="Usunąć akcesorium „<?= h($acc['name']) ?>"?"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($accessories)): ?>
                        <tr><td colspan="<?= isAdmin() ? 7 : 6 ?>" class="text-center text-muted p-3">Brak akcesoriów. <?= isAdmin() ? 'Kliknij „Dodaj akcesorium", aby dodać pierwszy wpis.' : '' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php
        $totalAcc       = count($accessories);
        $totalInitial   = array_sum(array_column($accessories, 'quantity_initial'));
        $totalIssued    = array_sum(array_column($accessories, 'issued'));
        $totalRemaining = $totalInitial - $totalIssued;
        ?>
        <div class="card text-center p-3 mb-3">
            <div class="h3 text-primary fw-bold"><?= $totalAcc ?></div>
            <div class="text-muted small">Rodzajów akcesoriów</div>
        </div>
        <div class="card text-center p-3 mb-3">
            <div class="h3 text-warning fw-bold"><?= $totalIssued ?></div>
            <div class="text-muted small">Łącznie wydano</div>
        </div>
        <div class="card text-center p-3">
            <div class="h3 fw-bold <?= $totalRemaining <= 0 ? 'text-danger' : 'text-success' ?>"><?= $totalRemaining ?></div>
            <div class="text-muted small">Łącznie pozostało</div>
        </div>
    </div>
</div>

<!-- Accessory issues history -->
<div class="card">
    <div class="card-header"><i class="fas fa-history me-2 text-info"></i>Historia wydań z magazynu (akcesoria)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>Data wydania</th><th>Akcesorium</th><th>Ilość</th><th>Zlecenie montażu</th><th>Wydał</th><th>Uwagi</th></tr>
            </thead>
            <tbody>
                <?php foreach ($accHistory as $ah): ?>
                <tr>
                    <td class="text-muted small"><?= formatDateTime($ah['issued_at']) ?></td>
                    <td class="fw-semibold"><?= h($ah['accessory_name']) ?></td>
                    <td class="fw-bold"><?= (int)$ah['quantity'] ?> szt</td>
                    <td>
                        <?php if ($ah['inst_id']): ?>
                        <a href="installations.php?action=view&id=<?= $ah['inst_id'] ?>"><?= h($ah['order_num']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($ah['user_name']) ?></td>
                    <td class="text-muted small"><?= h($ah['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($accHistory)): ?>
                <tr><td colspan="6" class="text-center text-muted p-3">Brak historii wydań.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Accessory Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="addAccessoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="accessory_add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2 text-success"></i>Nowe akcesorium</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required-star">Nazwa</label>
                        <input type="text" name="name" class="form-control" required placeholder="np. Kabel zasilający, Taśma montażowa">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ilość początkowa (szt.)</label>
                        <input type="number" name="quantity_initial" class="form-control" min="0" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Uwagi</label>
                        <input type="text" name="notes" class="form-control" placeholder="Opcjonalny opis">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Dodaj</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Accessory Modal -->
<div class="modal fade" id="editAccessoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="accessory_edit">
                <input type="hidden" name="accessory_id" id="editAccId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2 text-primary"></i>Edytuj akcesorium</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required-star">Nazwa</label>
                        <input type="text" name="name" id="editAccName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ilość początkowa (szt.)</label>
                        <input type="number" name="quantity_initial" id="editAccQty" class="form-control" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Uwagi</label>
                        <input type="text" name="notes" id="editAccNotes" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz zmiany</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.acc-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editAccId').value    = btn.dataset.id;
            document.getElementById('editAccName').value  = btn.dataset.name;
            document.getElementById('editAccQty').value   = btn.dataset.qty;
            document.getElementById('editAccNotes').value = btn.dataset.notes;
            new bootstrap.Modal(document.getElementById('editAccessoryModal')).show();
        });
    });
});
</script>
<?php endif; ?>

<?php elseif ($action === 'sim_cards'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-sim-card me-2 text-primary"></i>Karty SIM — numery telefonów urządzeń (<?= count($simCards) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr telefonu SIM</th>
                    <th>Urządzenie</th>
                    <th>Model</th>
                    <th>Status</th>
                    <th>Pojazd</th>
                    <th>Klient</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($simCards as $sc): ?>
                <tr>
                    <td class="fw-semibold"><?= h($sc['sim_number']) ?></td>
                    <td><?= h($sc['serial_number']) ?></td>
                    <td><?= h($sc['manufacturer_name'] . ' ' . $sc['model_name']) ?></td>
                    <td><?= getStatusBadge($sc['status'], 'device') ?></td>
                    <td><?= $sc['vehicle_registration'] ? h($sc['vehicle_registration']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?php $cl = $sc['company_name'] ?: ($sc['contact_name'] ?: null); echo $cl ? h($cl) : '<span class="text-muted">—</span>'; ?></td>
                    <td>
                        <a href="devices.php?action=view&id=<?= $sc['id'] ?>" class="btn btn-sm btn-outline-info btn-action" title="Podgląd urządzenia">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($simCards)): ?>
                <tr><td colspan="7" class="text-center text-muted p-3">Brak urządzeń z przypisanymi numerami SIM.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Adjustment Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="adjustment">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Korekta stanu magazynowego</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Model</label>
                        <select name="model_id" id="adjustModelId" class="form-select" required>
                            <option value="">— wybierz model —</option>
                            <?php
                            $currentMf = '';
                            foreach ($models as $m):
                                if ($m['manufacturer_name'] !== $currentMf) {
                                    if ($currentMf) echo '</optgroup>';
                                    echo '<optgroup label="' . h($m['manufacturer_name']) . '">';
                                    $currentMf = $m['manufacturer_name'];
                                }
                            ?>
                            <option value="<?= $m['id'] ?>"><?= h($m['name']) ?></option>
                            <?php endforeach; if ($currentMf) echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Typ operacji</label>
                        <select name="type" id="adjustType" class="form-select">
                            <option value="in">⬆ Przyjęcie (zwiększ stan)</option>
                            <option value="out">⬇ Wydanie (zmniejsz stan)</option>
                            <option value="correction">↻ Korekta (ustaw dokładną wartość)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ilość (szt.)</label>
                        <input type="number" name="quantity" class="form-control" required min="1" value="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Powód / Opis</label>
                        <input type="text" name="reason" class="form-control" placeholder="np. Dostawa faktury 123/2024">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Min Stock Modal -->
<div class="modal fade" id="minModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_min">
                <div class="modal-header">
                    <h5 class="modal-title">Minimalny stan: <span id="minModalName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="model_id" id="minModelId">
                    <label class="form-label">Minimalny stan magazynowy</label>
                    <input type="number" name="min_quantity" id="minQuantityInput" class="form-control" min="0" value="0">
                    <small class="text-muted">Poniżej tej wartości system wyświetli ostrzeżenie.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm">Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAdjustModal(modelId, modelName, currentQty) {
    document.getElementById('adjustModelId').value = modelId;
    new bootstrap.Modal(document.getElementById('adjustModal')).show();
}
function openMinModal(modelId, modelName, minQty) {
    document.getElementById('minModelId').value = modelId;
    document.getElementById('minModalName').textContent = modelName;
    document.getElementById('minQuantityInput').value = minQty;
    new bootstrap.Modal(document.getElementById('minModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
