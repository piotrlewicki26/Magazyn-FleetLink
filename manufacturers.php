<?php
/**
 * FleetLink Magazyn - Manufacturer Management
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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa. Spróbuj ponownie.');
        redirect(getBaseUrl() . 'manufacturers.php');
    }

    $postAction = sanitize($_POST['action'] ?? '');
    $name       = sanitize($_POST['name'] ?? '');
    $country    = sanitize($_POST['country'] ?? '');
    $website    = sanitize($_POST['website'] ?? '');
    $notes      = sanitize($_POST['notes'] ?? '');
    $active     = isset($_POST['active']) ? 1 : 0;

    if ($postAction === 'add') {
        if (empty($name)) {
            flashError('Nazwa producenta jest wymagana.');
            redirect(getBaseUrl() . 'manufacturers.php?action=add');
        }
        $stmt = $db->prepare("INSERT INTO manufacturers (name, country, website, notes, active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $country, $website, $notes, $active]);
        flashSuccess('Producent ' . $name . ' został dodany.');
        redirect(getBaseUrl() . 'manufacturers.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($name) || !$editId) {
            flashError('Dane są nieprawidłowe.');
            redirect(getBaseUrl() . 'manufacturers.php?action=edit&id=' . $editId);
        }
        $stmt = $db->prepare("UPDATE manufacturers SET name=?, country=?, website=?, notes=?, active=? WHERE id=?");
        $stmt->execute([$name, $country, $website, $notes, $active, $editId]);
        flashSuccess('Producent został zaktualizowany.');
        redirect(getBaseUrl() . 'manufacturers.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM manufacturers WHERE id=?");
            $stmt->execute([$delId]);
            flashSuccess('Producent został usunięty.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć producenta — posiada przypisane modele.');
        }
        redirect(getBaseUrl() . 'manufacturers.php');
    }
}

// Fetch data for views
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM manufacturers WHERE id=?");
    $stmt->execute([$id]);
    $manufacturer = $stmt->fetch();
    if (!$manufacturer) {
        flashError('Producent nie istnieje.');
        redirect(getBaseUrl() . 'manufacturers.php');
    }
}

$manufacturers = [];
if ($action === 'list') {
    $manufacturers = $db->query("
        SELECT m.*, COUNT(mo.id) as model_count
        FROM manufacturers m
        LEFT JOIN models mo ON mo.manufacturer_id = m.id
        GROUP BY m.id
        ORDER BY m.name
    ")->fetchAll();
}

$activePage = 'manufacturers';
$pageTitle = 'Producenci';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-industry me-2 text-primary"></i>Producenci</h1>
    <?php if ($action === 'list'): ?>
    <a href="manufacturers.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj producenta</a>
    <?php else: ?>
    <a href="manufacturers.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">Lista producentów (<?= count($manufacturers) ?>)</div>
            <div class="col-auto">
                <input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Szukaj...">
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nazwa</th><th>Kraj</th><th>Strona WWW</th><th>Modeli</th><th>Status</th><th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($manufacturers as $m): ?>
                <tr>
                    <td class="fw-semibold"><?= h($m['name']) ?></td>
                    <td><?= h($m['country'] ?? '—') ?></td>
                    <td>
                        <?php if ($m['website']): ?>
                        <a href="<?= h($m['website']) ?>" target="_blank" rel="noopener"><?= h($m['website']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><a href="models.php?manufacturer=<?= $m['id'] ?>"><?= $m['model_count'] ?> modeli</a></td>
                    <td><?= $m['active'] ? '<span class="badge bg-success">Aktywny</span>' : '<span class="badge bg-secondary">Nieaktywny</span>' ?></td>
                    <td>
                        <a href="manufacturers.php?action=edit&id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary btn-action">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Czy na pewno chcesz usunąć producenta <?= h($m['name']) ?>?">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($manufacturers)): ?>
                <tr><td colspan="6" class="text-center text-muted">Brak producentów. <a href="manufacturers.php?action=add">Dodaj pierwszego.</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:600px">
    <div class="card-header">
        <i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i>
        <?= $action === 'add' ? 'Dodaj producenta' : 'Edytuj producenta' ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= h($manufacturer['id']) ?>"><?php endif; ?>

            <div class="mb-3">
                <label class="form-label required-star">Nazwa producenta</label>
                <input type="text" name="name" class="form-control" required
                       value="<?= h($manufacturer['name'] ?? '') ?>" placeholder="np. Teltonika">
            </div>
            <div class="mb-3">
                <label class="form-label">Kraj</label>
                <input type="text" name="country" class="form-control"
                       value="<?= h($manufacturer['country'] ?? '') ?>" placeholder="np. Litwa">
            </div>
            <div class="mb-3">
                <label class="form-label">Strona WWW</label>
                <input type="url" name="website" class="form-control"
                       value="<?= h($manufacturer['website'] ?? '') ?>" placeholder="https://...">
            </div>
            <div class="mb-3">
                <label class="form-label">Uwagi</label>
                <textarea name="notes" class="form-control" rows="3"><?= h($manufacturer['notes'] ?? '') ?></textarea>
            </div>
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="active" id="active"
                           <?= ($manufacturer['active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="active">Aktywny</label>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz zmiany' ?>
                </button>
                <a href="manufacturers.php" class="btn btn-outline-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
