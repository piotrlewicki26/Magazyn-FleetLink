<?php
/**
 * FleetLink Magazyn - Vehicle Management
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
$preClientId = (int)($_GET['client'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'vehicles.php'); }
    $postAction   = sanitize($_POST['action'] ?? '');
    $clientId     = (int)($_POST['client_id'] ?? 0) ?: null;
    $registration = strtoupper(sanitize($_POST['registration'] ?? ''));
    $make         = sanitize($_POST['make'] ?? '');
    $modelName    = sanitize($_POST['model_name'] ?? '');
    $year         = (int)($_POST['year'] ?? 0) ?: null;
    $vin          = strtoupper(sanitize($_POST['vin'] ?? ''));
    $notes        = sanitize($_POST['notes'] ?? '');
    $active       = isset($_POST['active']) ? 1 : 0;

    if ($postAction === 'add') {
        if (empty($registration)) { flashError('Numer rejestracyjny jest wymagany.'); redirect(getBaseUrl() . 'vehicles.php?action=add&client=' . ($clientId ?: '')); }
        $db->prepare("INSERT INTO vehicles (client_id, registration, make, model_name, year, vin, notes, active) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active]);
        flashSuccess("Pojazd $registration dodany.");
        redirect($clientId ? getBaseUrl() . 'clients.php?action=view&id=' . $clientId : getBaseUrl() . 'vehicles.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($registration) || !$editId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'vehicles.php?action=edit&id=' . $editId); }
        $db->prepare("UPDATE vehicles SET client_id=?, registration=?, make=?, model_name=?, year=?, vin=?, notes=?, active=? WHERE id=?")
           ->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active, $editId]);
        flashSuccess('Pojazd zaktualizowany.');
        redirect(getBaseUrl() . 'vehicles.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM vehicles WHERE id=?")->execute([$delId]);
            flashSuccess('Pojazd usunięty.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć pojazdu — posiada powiązane montaże.');
        }
        redirect(getBaseUrl() . 'vehicles.php');
    }
}

$vehicle = null;
if (in_array($action, ['edit','view']) && $id) {
    $stmt = $db->prepare("SELECT v.*, c.contact_name, c.company_name FROM vehicles v LEFT JOIN clients c ON c.id=v.client_id WHERE v.id=?");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch();
    if (!$vehicle) { flashError('Pojazd nie istnieje.'); redirect(getBaseUrl() . 'vehicles.php'); }
}

$clients = $db->query("SELECT id, contact_name, company_name FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();

$vehicles = [];
if ($action === 'list') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT v.*, c.contact_name, c.company_name, 
                   COUNT(DISTINCT i.id) as installation_count
            FROM vehicles v
            LEFT JOIN clients c ON c.id=v.client_id
            LEFT JOIN installations i ON i.vehicle_id=v.id
            WHERE v.active=1";
    $params = [];
    if ($search) { $sql .= " AND (v.registration LIKE ? OR v.make LIKE ? OR v.model_name LIKE ? OR c.contact_name LIKE ?)"; $params = ["%$search%","%$search%","%$search%","%$search%"]; }
    $sql .= " GROUP BY v.id ORDER BY v.registration";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
}

$activePage = 'clients';
$pageTitle = 'Pojazdy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-car me-2 text-primary"></i>Pojazdy</h1>
    <?php if ($action === 'list'): ?>
    <a href="vehicles.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj pojazd</a>
    <?php else: ?>
    <a href="vehicles.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (rejestracja, marka, klient...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Szukaj</button>
                <a href="vehicles.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Pojazdy (<?= count($vehicles) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Rejestracja</th><th>Marka / Model</th><th>Rok</th><th>Klient</th><th>Montaży</th><th>Akcje</th></tr></thead>
            <tbody>
                <?php foreach ($vehicles as $v): ?>
                <tr>
                    <td class="fw-bold"><?= h($v['registration']) ?></td>
                    <td><?= h(trim($v['make'] . ' ' . $v['model_name'])) ?: '—' ?></td>
                    <td><?= h($v['year'] ?? '—') ?></td>
                    <td><?= $v['contact_name'] ? h($v['company_name'] ?: $v['contact_name']) : '—' ?></td>
                    <td><?= $v['installation_count'] ?></td>
                    <td>
                        <a href="vehicles.php?action=edit&id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <a href="installations.php?action=add&vehicle=<?= $v['id'] ?>" class="btn btn-sm btn-outline-success btn-action" title="Nowy montaż"><i class="fas fa-plus"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $v['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" data-confirm="Usuń pojazd <?= h($v['registration']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vehicles)): ?><tr><td colspan="6" class="text-center text-muted p-3">Brak pojazdów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:600px">
    <div class="card-header"><i class="fas fa-car me-2"></i><?= $action === 'add' ? 'Dodaj pojazd' : 'Edytuj pojazd' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $vehicle['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-star">Nr rejestracyjny</label>
                    <input type="text" name="registration" class="form-control text-uppercase" required value="<?= h($vehicle['registration'] ?? '') ?>" placeholder="np. WA12345">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Klient</label>
                    <select name="client_id" class="form-select">
                        <option value="">— brak przypisania —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($vehicle['client_id'] ?? $preClientId) == $c['id'] ? 'selected' : '' ?>>
                            <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Marka</label>
                    <input type="text" name="make" class="form-control" value="<?= h($vehicle['make'] ?? '') ?>" placeholder="np. Toyota">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Model pojazdu</label>
                    <input type="text" name="model_name" class="form-control" value="<?= h($vehicle['model_name'] ?? '') ?>" placeholder="np. Corolla">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rok produkcji</label>
                    <input type="number" name="year" class="form-control" value="<?= h($vehicle['year'] ?? '') ?>" min="1990" max="<?= date('Y') + 1 ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">VIN</label>
                    <input type="text" name="vin" class="form-control text-uppercase" value="<?= h($vehicle['vin'] ?? '') ?>" maxlength="17" placeholder="17-znakowy numer VIN">
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="2"><?= h($vehicle['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($vehicle['active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Aktywny</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz' ?></button>
                    <a href="vehicles.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
