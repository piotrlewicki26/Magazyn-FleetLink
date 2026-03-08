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
    }
}

// Get inventory data
$inventory = $db->query("
    SELECT i.id, i.model_id, i.quantity, i.min_quantity, i.updated_at,
           m.name as model_name, m.price_purchase, m.price_sale,
           mf.name as manufacturer_name
    FROM inventory i
    JOIN models m ON m.id = i.model_id
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    WHERE m.active = 1
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

// SIM cards list
$simCards = [];
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
        <a href="inventory.php?action=movements" class="btn <?= $action === 'movements' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm me-1">
            <i class="fas fa-history me-1"></i>Historia ruchów
        </a>
        <a href="inventory.php?action=sim_cards" class="btn <?= $action === 'sim_cards' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm me-1">
            <i class="fas fa-sim-card me-1"></i>Karty SIM
        </a>
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
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="h3 text-success fw-bold"><?= formatMoney($totalValue) ?></div>
            <div class="text-muted small">Wartość magazynu</div>
        </div>
    </div>
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
                    <th>Producent</th><th>Model</th><th>Stan</th><th>Min. stan</th><th>Wartość (zakup)</th><th>Wartość (sprzedaż)</th><th>Ostatnia zmiana</th><th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory as $item): ?>
                <tr class="<?= $item['quantity'] <= $item['min_quantity'] ? 'table-warning' : '' ?>">
                    <td><?= h($item['manufacturer_name']) ?></td>
                    <td class="fw-semibold"><?= h($item['model_name']) ?></td>
                    <td>
                        <span class="fw-bold <?= $item['quantity'] == 0 ? 'text-danger' : ($item['quantity'] <= $item['min_quantity'] ? 'text-warning' : 'text-success') ?>">
                            <?= $item['quantity'] ?> szt
                        </span>
                        <?php if ($item['quantity'] <= $item['min_quantity'] && $item['min_quantity'] > 0): ?>
                        <span class="badge bg-warning ms-1">niski stan</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $item['min_quantity'] ?> szt</td>
                    <td><?= formatMoney($item['quantity'] * $item['price_purchase']) ?></td>
                    <td><?= formatMoney($item['quantity'] * $item['price_sale']) ?></td>
                    <td class="text-muted small"><?= formatDateTime($item['updated_at']) ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action"
                                onclick="openAdjustModal(<?= $item['model_id'] ?>, '<?= h(addslashes($item['manufacturer_name'] . ' ' . $item['model_name'])) ?>', <?= $item['quantity'] ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action"
                                onclick="openMinModal(<?= $item['model_id'] ?>, '<?= h(addslashes($item['model_name'])) ?>', <?= $item['min_quantity'] ?>)">
                            <i class="fas fa-bell"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($inventory)): ?>
                <tr><td colspan="8" class="text-center text-muted p-3">Brak modeli w magazynie. Dodaj <a href="models.php">modele urządzeń</a> najpierw.</td></tr>
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
