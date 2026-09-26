<?php
/**
 * FleetLink System GPS - My Account
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Handle POST (password change or name update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'account.php');
    }
    $postAction = sanitize($_POST['action'] ?? '');

    if ($postAction === 'update_name') {
        $newName = sanitize($_POST['name'] ?? '');
        if (empty($newName)) {
            flashError('Imię i nazwisko nie może być puste.');
        } else {
            $db->prepare("UPDATE users SET name=? WHERE id=?")->execute([$newName, $userId]);
            $_SESSION['user_name'] = $newName;
            flashSuccess('Dane zostały zaktualizowane.');
        }
        redirect(getBaseUrl() . 'account.php');
    }

    if ($postAction === 'change_password') {
        $oldPwd  = $_POST['old_password'] ?? '';
        $newPwd  = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($oldPwd, $row['password'])) {
            flashError('Aktualne hasło jest nieprawidłowe.');
        } elseif (strlen($newPwd) < 8) {
            flashError('Nowe hasło musi mieć co najmniej 8 znaków.');
        } elseif ($newPwd !== $confirm) {
            flashError('Nowe hasła nie są zgodne.');
        } else {
            $hash = password_hash($newPwd, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $userId]);
            flashSuccess('Hasło zostało zmienione.');
        }
        redirect(getBaseUrl() . 'account.php');
    }
}

// Fetch full user details
$stmt = $db->prepare("SELECT id, name, email, role, active, created_at FROM users WHERE id=?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Fetch recent activity (last 10 installations and services)
$recentInstalls = $db->prepare("
    SELECT i.id, i.installation_date, v.registration, d.serial_number, m.name as model_name
    FROM installations i
    JOIN devices d ON d.id=i.device_id
    JOIN models m ON m.id=d.model_id
    JOIN vehicles v ON v.id=i.vehicle_id
    WHERE i.technician_id=?
    ORDER BY i.installation_date DESC, i.id DESC
    LIMIT 5
");
$recentInstalls->execute([$userId]);
$recentInstallations = $recentInstalls->fetchAll();

$recentServicesStmt = $db->prepare("
    SELECT s.id, s.planned_date, s.type, s.status, d.serial_number, m.name as model_name
    FROM services s
    JOIN devices d ON d.id=s.device_id
    JOIN models m ON m.id=d.model_id
    WHERE s.technician_id=?
    ORDER BY s.planned_date DESC, s.id DESC
    LIMIT 5
");
$recentServicesStmt->execute([$userId]);
$recentServices = $recentServicesStmt->fetchAll();

$activePage = '';
$pageTitle  = 'Moje konto';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-circle me-2 text-primary"></i>Moje konto</h1>
</div>

<div class="row g-4">
    <!-- Profile info -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="fas fa-id-card me-2"></i>Profil</div>
            <div class="card-body text-center py-4">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                     style="width:80px;height:80px;background:linear-gradient(135deg,#2563eb,#00cfff);font-size:2rem;color:#fff;font-weight:700">
                    <?= mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1)) ?>
                </div>
                <h5 class="fw-bold mb-1"><?= h($user['name']) ?></h5>
                <p class="text-muted mb-2"><?= h($user['email']) ?></p>
                <span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : 'bg-secondary' ?>">
                    <?= $user['role'] === 'admin' ? 'Administrator' : 'Użytkownik' ?>
                </span>
                <div class="mt-3 text-muted small">
                    <i class="fas fa-calendar-alt me-1"></i>Konto od: <?= formatDate($user['created_at']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit name -->
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-edit me-2"></i>Edytuj dane</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_name">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Imię i nazwisko</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= h($user['name']) ?>" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" class="form-control" value="<?= h($user['email']) ?>" disabled>
                            <div class="form-text">E-mail można zmienić tylko przez administratora.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz zmiany</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change password -->
        <div class="card">
            <div class="card-header"><i class="fas fa-lock me-2"></i>Zmień hasło</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required-star">Aktualne hasło</label>
                            <input type="password" name="old_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-star">Nowe hasło</label>
                            <input type="password" name="new_password" class="form-control" required
                                   autocomplete="new-password" minlength="8">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-star">Potwierdź hasło</label>
                            <input type="password" name="confirm_password" class="form-control" required
                                   autocomplete="new-password" minlength="8">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary"><i class="fas fa-key me-2"></i>Zmień hasło</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Recent activity -->
    <?php if (!empty($recentInstallations)): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-car me-2"></i>Ostatnie montaże</div>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead><tr><th>Data</th><th>Pojazd</th><th>Urządzenie</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentInstallations as $ri): ?>
                        <tr>
                            <td><?= formatDate($ri['installation_date']) ?></td>
                            <td><?= h($ri['registration']) ?></td>
                            <td>
                                <a href="installations.php?action=view&id=<?= $ri['id'] ?>">
                                    <?= h($ri['serial_number']) ?>
                                </a>
                                <br><small class="text-muted"><?= h($ri['model_name']) ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentServices)): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-wrench me-2"></i>Ostatnie serwisy</div>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead><tr><th>Data</th><th>Typ</th><th>Urządzenie</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentServices as $rs): ?>
                        <tr>
                            <td><?= formatDate($rs['planned_date']) ?></td>
                            <td><span class="badge bg-secondary"><?= h(ucfirst($rs['type'])) ?></span></td>
                            <td>
                                <a href="services.php?action=view&id=<?= $rs['id'] ?>">
                                    <?= h($rs['serial_number']) ?>
                                </a>
                                <br><small class="text-muted"><?= h($rs['model_name']) ?></small>
                            </td>
                            <td><?= getStatusBadge($rs['status'], 'service') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
