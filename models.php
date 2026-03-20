<?php
/**
 * FleetLink Magazyn - Device Models Management
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
$id = (int)($_GET['id'] ?? 0);
$filterManufacturer = (int)($_GET['manufacturer'] ?? 0);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'models.php');
    }
    $postAction = sanitize($_POST['action'] ?? '');
    $name              = sanitize($_POST['name'] ?? '');
    $manufacturerId    = (int)($_POST['manufacturer_id'] ?? 0);
    $description       = sanitize($_POST['description'] ?? '');
    $pricePurchase     = str_replace(',', '.', $_POST['price_purchase'] ?? '0');
    $priceSale         = str_replace(',', '.', $_POST['price_sale'] ?? '0');
    $priceInstall      = str_replace(',', '.', $_POST['price_installation'] ?? '0');
    $priceService      = str_replace(',', '.', $_POST['price_service'] ?? '0');
    $priceSub          = str_replace(',', '.', $_POST['price_subscription'] ?? '0');
    $active            = isset($_POST['active']) ? 1 : 0;

    if ($postAction === 'add') {
        if (empty($name) || !$manufacturerId) {
            flashError('Nazwa i producent są wymagane.');
            redirect(getBaseUrl() . 'models.php?action=add');
        }
        $stmt = $db->prepare("INSERT INTO models (manufacturer_id, name, description, price_purchase, price_sale, price_installation, price_service, price_subscription, active) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$manufacturerId, $name, $description, $pricePurchase, $priceSale, $priceInstall, $priceService, $priceSub, $active]);
        $newId = $db->lastInsertId();
        // Create inventory entry
        $db->prepare("INSERT INTO inventory (model_id, quantity, min_quantity) VALUES (?,0,0)")->execute([$newId]);
        flashSuccess("Model $name został dodany.");
        redirect(getBaseUrl() . 'models.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($name) || !$manufacturerId || !$editId) {
            flashError('Nieprawidłowe dane.');
            redirect(getBaseUrl() . 'models.php?action=edit&id=' . $editId);
        }
        $stmt = $db->prepare("UPDATE models SET manufacturer_id=?, name=?, description=?, price_purchase=?, price_sale=?, price_installation=?, price_service=?, price_subscription=?, active=? WHERE id=?");
        $stmt->execute([$manufacturerId, $name, $description, $pricePurchase, $priceSale, $priceInstall, $priceService, $priceSub, $active, $editId]);
        flashSuccess('Model został zaktualizowany.');
        redirect(getBaseUrl() . 'models.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM models WHERE id=?")->execute([$delId]);
            flashSuccess('Model został usunięty.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć modelu — posiada przypisane urządzenia.');
        }
        redirect(getBaseUrl() . 'models.php');
    }
}

if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT m.*, mf.name as manufacturer_name FROM models m JOIN manufacturers mf ON mf.id=m.manufacturer_id WHERE m.id=?");
    $stmt->execute([$id]);
    $model = $stmt->fetch();
    if (!$model) { flashError('Model nie istnieje.'); redirect(getBaseUrl() . 'models.php'); }
}

$manufacturers = $db->query("SELECT id, name FROM manufacturers WHERE active=1 ORDER BY name")->fetchAll();

$models = [];
if ($action === 'list') {
    $sql = "SELECT m.*, mf.name as manufacturer_name, 
                   COALESCE(inv.quantity, 0) as stock,
                   COUNT(d.id) as device_count
            FROM models m
            JOIN manufacturers mf ON mf.id = m.manufacturer_id
            LEFT JOIN inventory inv ON inv.model_id = m.id
            LEFT JOIN devices d ON d.model_id = m.id AND d.status != 'wycofany'
            WHERE 1=1";
    $params = [];
    if ($filterManufacturer) {
        $sql .= " AND m.manufacturer_id=?";
        $params[] = $filterManufacturer;
    }
    $sql .= " GROUP BY m.id ORDER BY mf.name, m.name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $models = $stmt->fetchAll();
}

$activePage = 'models';
$pageTitle = 'Modele urządzeń';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-tags me-2 text-primary"></i>Modele urządzeń</h1>
    <?php if ($action === 'list'): ?>
    <a href="models.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj model</a>
    <?php else: ?>
    <a href="models.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<?php if (!empty($manufacturers)): ?>
<div class="mb-3">
    <div class="btn-group flex-wrap">
        <a href="models.php" class="btn btn-sm <?= !$filterManufacturer ? 'btn-primary' : 'btn-outline-primary' ?>">Wszystkie</a>
        <?php foreach ($manufacturers as $mf): ?>
        <a href="models.php?manufacturer=<?= $mf['id'] ?>" class="btn btn-sm <?= $filterManufacturer === (int)$mf['id'] ? 'btn-primary' : 'btn-outline-primary' ?>"><?= h($mf['name']) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">Lista modeli (<?= count($models) ?>)</div>
            <div class="col-auto"><input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Szukaj..."></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Producent</th><th>Model</th><th>Cena zakupu</th><th>Cena sprzedaży</th><th>Montaż</th><th>Stan mag.</th><th>Urządzeń</th><th>Status</th><th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($models as $m): ?>
                <tr>
                    <td><?= h($m['manufacturer_name']) ?></td>
                    <td class="fw-semibold"><?= h($m['name']) ?></td>
                    <td><?= formatMoney($m['price_purchase']) ?></td>
                    <td><?= formatMoney($m['price_sale']) ?></td>
                    <td><?= formatMoney($m['price_installation']) ?></td>
                    <td class="<?= $m['stock'] == 0 ? 'text-danger' : '' ?>"><?= $m['stock'] ?> szt</td>
                    <td><?= $m['device_count'] ?></td>
                    <td><?= $m['active'] ? '<span class="badge bg-success">Aktywny</span>' : '<span class="badge bg-secondary">Nieaktywny</span>' ?></td>
                    <td>
                        <a href="models.php?action=edit&id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń model <?= h($m['name']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($models)): ?>
                <tr><td colspan="9" class="text-center text-muted p-3">Brak modeli.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:700px">
    <div class="card-header">
        <i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i>
        <?= $action === 'add' ? 'Dodaj model' : 'Edytuj model: ' . h($model['name'] ?? '') ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $model['id'] ?>"><?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-star">Producent</label>
                    <select name="manufacturer_id" class="form-select" required>
                        <option value="">— wybierz —</option>
                        <?php foreach ($manufacturers as $mf): ?>
                        <option value="<?= $mf['id'] ?>" <?= ($model['manufacturer_id'] ?? 0) == $mf['id'] ? 'selected' : '' ?>>
                            <?= h($mf['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Nazwa modelu</label>
                    <input type="text" name="name" class="form-control" required value="<?= h($model['name'] ?? '') ?>" placeholder="np. FMB920">
                </div>
                <div class="col-12">
                    <label class="form-label">Opis</label>
                    <textarea name="description" class="form-control" rows="2"><?= h($model['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12"><hr><h6 class="text-muted">Cennik</h6></div>
                <div class="col-md-4">
                    <label class="form-label">Cena zakupu (netto)</label>
                    <div class="input-group">
                        <input type="number" name="price_purchase" class="form-control" value="<?= h($model['price_purchase'] ?? '0.00') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cena sprzedaży (netto)</label>
                    <div class="input-group">
                        <input type="number" name="price_sale" class="form-control" value="<?= h($model['price_sale'] ?? '0.00') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cena montażu (netto)</label>
                    <div class="input-group">
                        <input type="number" name="price_installation" class="form-control" value="<?= h($model['price_installation'] ?? '0.00') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cena serwisu (netto)</label>
                    <div class="input-group">
                        <input type="number" name="price_service" class="form-control" value="<?= h($model['price_service'] ?? '0.00') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Abonament miesięczny (netto)</label>
                    <div class="input-group">
                        <input type="number" name="price_subscription" class="form-control" value="<?= h($model['price_subscription'] ?? '0.00') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($model['active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Aktywny</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz' ?></button>
                    <a href="models.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
