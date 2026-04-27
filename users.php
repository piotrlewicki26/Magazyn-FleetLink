<?php
/**
 * FleetLink System GPS - User Management (Admin only)
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireAdmin(); // Admin only

$db = getDb();
$action = sanitize($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);
$currentUser = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'users.php'); }
    $postAction = sanitize($_POST['action'] ?? '');
    $name       = sanitize($_POST['name'] ?? '');
    $email      = sanitize($_POST['email'] ?? '');
    $role       = sanitize($_POST['role'] ?? 'user');
    $phone      = sanitize($_POST['phone'] ?? '');
    $active     = isset($_POST['active']) ? 1 : 0;
    $password   = $_POST['password'] ?? '';
    $password2  = $_POST['password2'] ?? '';

    $validRoles = ['admin','technician','user'];
    if (!in_array($role, $validRoles)) $role = 'user';

    if ($postAction === 'add') {
        if (empty($name) || empty($email) || empty($password)) { flashError('Imię, e-mail i hasło są wymagane.'); redirect(getBaseUrl() . 'users.php?action=add'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flashError('Nieprawidłowy e-mail.'); redirect(getBaseUrl() . 'users.php?action=add'); }
        if (strlen($password) < 8) { flashError('Hasło minimum 8 znaków.'); redirect(getBaseUrl() . 'users.php?action=add'); }
        if ($password !== $password2) { flashError('Hasła nie są identyczne.'); redirect(getBaseUrl() . 'users.php?action=add'); }

        try {
            $db->prepare("INSERT INTO users (name, email, password, role, phone, active) VALUES (?,?,?,?,?,?)")
               ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $phone, $active]);
            flashSuccess("Użytkownik $name został dodany.");
        } catch (PDOException $e) {
            flashError('E-mail jest już zajęty.');
        }
        redirect(getBaseUrl() . 'users.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        if (empty($name) || empty($email) || !$editId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'users.php?action=edit&id=' . $editId); }
        // Prevent removing last admin
        if ($editId === $currentUser['id'] && $role !== 'admin') {
            $adminCount = $db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            if ($adminCount <= 1) { flashError('Nie możesz usunąć ostatniego administratora.'); redirect(getBaseUrl() . 'users.php'); }
        }

        if (!empty($password)) {
            if (strlen($password) < 8) { flashError('Hasło minimum 8 znaków.'); redirect(getBaseUrl() . 'users.php?action=edit&id=' . $editId); }
            if ($password !== $password2) { flashError('Hasła nie są identyczne.'); redirect(getBaseUrl() . 'users.php?action=edit&id=' . $editId); }
            $db->prepare("UPDATE users SET name=?, email=?, password=?, role=?, phone=?, active=? WHERE id=?")
               ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $phone, $active, $editId]);
        } else {
            $db->prepare("UPDATE users SET name=?, email=?, role=?, phone=?, active=? WHERE id=?")
               ->execute([$name, $email, $role, $phone, $active, $editId]);
        }
        flashSuccess('Użytkownik zaktualizowany.');
        redirect(getBaseUrl() . 'users.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId === $currentUser['id']) { flashError('Nie możesz usunąć własnego konta.'); redirect(getBaseUrl() . 'users.php'); }
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$delId]);
        flashSuccess('Użytkownik usunięty.');
        redirect(getBaseUrl() . 'users.php');
    }
}

$user = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) { flashError('Użytkownik nie istnieje.'); redirect(getBaseUrl() . 'users.php'); }
}

$users = [];
if ($action === 'list') {
    $users = $db->query("SELECT * FROM users ORDER BY role, name")->fetchAll();
}

$activePage = 'users';
$pageTitle = 'Użytkownicy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-users-cog me-2 text-primary"></i>Zarządzanie użytkownikami</h1>
    <?php if ($action === 'list'): ?>
    <a href="users.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Dodaj użytkownika</a>
    <?php else: ?>
    <a href="users.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">Użytkownicy (<?= count($users) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Imię i nazwisko</th><th>E-mail</th><th>Telefon</th><th>Rola</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr <?= $u['id'] === $currentUser['id'] ? 'class="table-primary"' : '' ?>>
                    <td class="fw-semibold">
                        <?= h($u['name']) ?>
                        <?php if ($u['id'] === $currentUser['id']): ?><span class="badge bg-secondary ms-1">To Ty</span><?php endif; ?>
                    </td>
                    <td><?= h($u['email']) ?></td>
                    <td><?= h($u['phone'] ?? '—') ?></td>
                    <td>
                        <?php
                        $roleColors = ['admin' => 'danger', 'technician' => 'warning', 'user' => 'secondary'];
                        $roleLabels = ['admin' => 'Administrator', 'technician' => 'Technik', 'user' => 'Użytkownik'];
                        ?>
                        <span class="badge bg-<?= $roleColors[$u['role']] ?? 'secondary' ?>"><?= $roleLabels[$u['role']] ?? h($u['role']) ?></span>
                    </td>
                    <td><?= $u['active'] ? '<span class="badge bg-success">Aktywny</span>' : '<span class="badge bg-secondary">Nieaktywny</span>' ?></td>
                    <td>
                        <a href="users.php?action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <?php if ($u['id'] !== $currentUser['id']): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń użytkownika <?= h($u['name']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:600px">
    <div class="card-header"><i class="fas fa-user me-2"></i><?= $action === 'add' ? 'Dodaj użytkownika' : 'Edytuj: ' . h($user['name'] ?? '') ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $user['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-star">Imię i nazwisko</label>
                    <input type="text" name="name" class="form-control" required value="<?= h($user['name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">E-mail</label>
                    <input type="email" name="email" class="form-control" required value="<?= h($user['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input type="tel" name="phone" class="form-control" value="<?= h($user['phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rola</label>
                    <select name="role" class="form-select">
                        <option value="user" <?= ($user['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>Użytkownik</option>
                        <option value="technician" <?= ($user['role'] ?? '') === 'technician' ? 'selected' : '' ?>>Technik</option>
                        <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Hasło <?= $action === 'edit' ? '(pozostaw puste, aby nie zmieniać)' : '' ?></label>
                    <input type="password" name="password" class="form-control" <?= $action === 'add' ? 'required' : '' ?> minlength="8" autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Powtórz hasło</label>
                    <input type="password" name="password2" class="form-control" <?= $action === 'add' ? 'required' : '' ?> minlength="8" autocomplete="new-password">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($user['active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Aktywny</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Dodaj' : 'Zapisz' ?></button>
                    <a href="users.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
