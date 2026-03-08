<?php
/**
 * FleetLink Magazyn - Client Management
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'clients.php');
    }
    $postAction  = sanitize($_POST['action'] ?? '');
    $companyName = sanitize($_POST['company_name'] ?? '');
    $contactName = sanitize($_POST['contact_name'] ?? '');
    $email       = sanitize($_POST['email'] ?? '');
    $phone       = sanitize($_POST['phone'] ?? '');
    $address     = sanitize($_POST['address'] ?? '');
    $city        = sanitize($_POST['city'] ?? '');
    $postalCode  = sanitize($_POST['postal_code'] ?? '');
    $nip         = sanitize($_POST['nip'] ?? '');
    $notes       = sanitize($_POST['notes'] ?? '');
    $active      = isset($_POST['active']) ? 1 : 0;

    if ($postAction === 'add') {
        if (empty($contactName)) { flashError('Imię i nazwisko kontaktu jest wymagane.'); redirect(getBaseUrl() . 'clients.php?action=add'); }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flashError('Nieprawidłowy adres e-mail.'); redirect(getBaseUrl() . 'clients.php?action=add'); }
        $db->prepare("INSERT INTO clients (company_name, contact_name, email, phone, address, city, postal_code, nip, notes, active) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$companyName, $contactName, $email, $phone, $address, $city, $postalCode, $nip, $notes, $active]);
        flashSuccess("Klient $contactName został dodany.");
        redirect(getBaseUrl() . 'clients.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($contactName) || !$editId) { flashError('Dane są nieprawidłowe.'); redirect(getBaseUrl() . 'clients.php?action=edit&id=' . $editId); }
        $db->prepare("UPDATE clients SET company_name=?, contact_name=?, email=?, phone=?, address=?, city=?, postal_code=?, nip=?, notes=?, active=? WHERE id=?")
           ->execute([$companyName, $contactName, $email, $phone, $address, $city, $postalCode, $nip, $notes, $active, $editId]);
        flashSuccess('Klient zaktualizowany.');
        redirect(getBaseUrl() . 'clients.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM clients WHERE id=?")->execute([$delId]);
            flashSuccess('Klient usunięty.');
        } catch (PDOException $e) {
            flashError('Nie można usunąć klienta — ma powiązane rekordy.');
        }
        redirect(getBaseUrl() . 'clients.php');
    }
}

$client = null;
if (in_array($action, ['edit','view']) && $id) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE id=?");
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) { flashError('Klient nie istnieje.'); redirect(getBaseUrl() . 'clients.php'); }
}

$clients = [];
if ($action === 'list') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT c.*, COUNT(DISTINCT v.id) as vehicle_count, COUNT(DISTINCT o.id) as offer_count
            FROM clients c
            LEFT JOIN vehicles v ON v.client_id=c.id
            LEFT JOIN offers o ON o.client_id=c.id
            WHERE c.active=1";
    $params = [];
    if ($search) { $sql .= " AND (c.contact_name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%","%$search%"]; }
    $sql .= " GROUP BY c.id ORDER BY c.company_name, c.contact_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
}

// For view - get vehicles and related data
$clientVehicles = [];
$clientInstallations = [];
if ($action === 'view' && $client) {
    $clientVehicles = $db->prepare("SELECT * FROM vehicles WHERE client_id=? ORDER BY registration")->execute([$id]) ? [] : [];
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE client_id=? ORDER BY registration");
    $stmt->execute([$id]);
    $clientVehicles = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT i.*, v.registration, d.serial_number, m.name as model_name
        FROM installations i
        JOIN vehicles v ON v.id=i.vehicle_id
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        WHERE i.client_id=?
        ORDER BY i.installation_date DESC
        LIMIT 10
    ");
    $stmt->execute([$id]);
    $clientInstallations = $stmt->fetchAll();
}

$activePage = 'clients';
$pageTitle = 'Klienci';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-users me-2 text-primary"></i>Klienci</h1>
    <?php if ($action === 'list'): ?>
    <a href="clients.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj klienta</a>
    <?php else: ?>
    <a href="clients.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (nazwa, firma, e-mail, tel.)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Szukaj</button>
                <a href="clients.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Klienci (<?= count($clients) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Firma</th><th>Kontakt</th><th>E-mail</th><th>Telefon</th><th>NIP</th><th>Pojazdów</th><th>Ofert</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= h($c['company_name'] ?: '—') ?></td>
                    <td><a href="clients.php?action=view&id=<?= $c['id'] ?>"><?= h($c['contact_name']) ?></a></td>
                    <td><?= $c['email'] ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '—' ?></td>
                    <td><?= h($c['phone'] ?? '—') ?></td>
                    <td><?= h($c['nip'] ?? '—') ?></td>
                    <td><?= $c['vehicle_count'] ?></td>
                    <td><?= $c['offer_count'] ?></td>
                    <td>
                        <a href="clients.php?action=view&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="clients.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń klienta <?= h($c['contact_name']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($clients)): ?><tr><td colspan="8" class="text-center text-muted p-3">Brak klientów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && $client): ?>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Dane klienta</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <?php if ($client['company_name']): ?>
                    <tr><th class="text-muted">Firma</th><td class="fw-bold"><?= h($client['company_name']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted">Kontakt</th><td><?= h($client['contact_name']) ?></td></tr>
                    <tr><th class="text-muted">E-mail</th><td><?= $client['email'] ? '<a href="mailto:' . h($client['email']) . '">' . h($client['email']) . '</a>' : '—' ?></td></tr>
                    <tr><th class="text-muted">Tel.</th><td><?= h($client['phone'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Adres</th><td><?= h(trim(($client['address'] ?? '') . ' ' . ($client['city'] ?? ''))) ?: '—' ?></td></tr>
                    <tr><th class="text-muted">NIP</th><td><?= h($client['nip'] ?? '—') ?></td></tr>
                </table>
                <?php if ($client['notes']): ?>
                <hr><p class="small text-muted"><?= h($client['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <a href="clients.php?action=edit&id=<?= $client['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <a href="offers.php?action=add&client=<?= $client['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-file-invoice me-1"></i>Nowa oferta</a>
                <a href="vehicles.php?action=add&client=<?= $client['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-car me-1"></i>Dodaj pojazd</a>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-car me-2 text-primary"></i>Pojazdy</span>
                <a href="vehicles.php?action=add&client=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus me-1"></i>Dodaj</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Rejestracja</th><th>Marka / Model</th><th>Rok</th><th>VIN</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($clientVehicles as $v): ?>
                        <tr>
                            <td class="fw-bold"><?= h($v['registration']) ?></td>
                            <td><?= h($v['make'] . ' ' . $v['model_name']) ?></td>
                            <td><?= h($v['year'] ?? '—') ?></td>
                            <td><?= h($v['vin'] ?? '—') ?></td>
                            <td><a href="vehicles.php?action=edit&id=<?= $v['id'] ?>" class="btn btn-xs btn-link p-0"><i class="fas fa-edit"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientVehicles)): ?><tr><td colspan="5" class="text-muted text-center">Brak pojazdów</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-car me-2 text-success"></i>Ostatnie montaże</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Pojazd</th><th>Urządzenie</th><th>Data montażu</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($clientInstallations as $inst): ?>
                        <tr>
                            <td><?= h($inst['registration']) ?></td>
                            <td><a href="devices.php?action=view&id=<?= $inst['device_id'] ?>"><?= h($inst['serial_number']) ?></a> <?= h($inst['model_name']) ?></td>
                            <td><?= formatDate($inst['installation_date']) ?></td>
                            <td><?= getStatusBadge($inst['status'], 'installation') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientInstallations)): ?><tr><td colspan="4" class="text-muted text-center">Brak montaży</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:700px">
    <div class="card-header"><i class="fas fa-user me-2"></i><?= $action === 'add' ? 'Dodaj klienta' : 'Edytuj klienta' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $client['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nazwa firmy</label>
                    <input type="text" name="company_name" class="form-control" value="<?= h($client['company_name'] ?? '') ?>" placeholder="Opcjonalne">
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Imię i nazwisko kontaktu</label>
                    <input type="text" name="contact_name" class="form-control" required value="<?= h($client['contact_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= h($client['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input type="tel" name="phone" class="form-control" value="<?= h($client['phone'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Adres</label>
                    <input type="text" name="address" class="form-control" value="<?= h($client['address'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kod pocztowy</label>
                    <input type="text" name="postal_code" class="form-control" value="<?= h($client['postal_code'] ?? '') ?>" placeholder="00-000">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Miasto</label>
                    <input type="text" name="city" class="form-control" value="<?= h($client['city'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">NIP</label>
                    <input type="text" name="nip" class="form-control" value="<?= h($client['nip'] ?? '') ?>" placeholder="000-000-00-00">
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="2"><?= h($client['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($client['active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Aktywny</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz' ?></button>
                    <a href="clients.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
