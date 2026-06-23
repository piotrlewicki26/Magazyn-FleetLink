<?php
/**
 * FleetLink Magazyn - Login Page
 */
define('IN_APP', true);

$configFile = __DIR__ . '/includes/config.php';
if (!file_exists($configFile)) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Warsaw');

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(getBaseUrl() . 'dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $token    = $_POST['csrf_token'] ?? '';

    if (!verifyCsrfToken($token)) {
        $error = 'Nieprawidłowe żądanie. Odśwież stronę i spróbuj ponownie.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Podaj adres e-mail i hasło.';
    } elseif (!checkLoginAttempts($email)) {
        $error = 'Zbyt wiele nieudanych prób logowania. Poczekaj 15 minut i spróbuj ponownie.';
    } else {
        $db = getDb();
        $stmt = $db->prepare("SELECT id, name, email, password, role, active FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['active'] && password_verify($password, $user['password'])) {
            clearLoginAttempts($email);
            loginUser($user);
            $redirect = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            if (!empty($redirect) && strpos($redirect, '/') === 0) {
                redirect($redirect);
            } else {
                redirect(getBaseUrl() . 'dashboard.php');
            }
        } else {
            recordLoginAttempt($email);
            $error = 'Nieprawidłowy e-mail lub hasło.';
            // Artificial delay to prevent brute force
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie — FleetLink Magazyn</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= getBaseUrl() ?>assets/css/style.css">
</head>
<body class="login-wrapper">
<div class="container">
    <div class="login-card card mx-auto">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="fas fa-satellite-dish login-logo mb-3"></i>
                <h2 class="fw-bold">FleetLink Magazyn</h2>
                <p class="text-muted">System zarządzania urządzeniami GPS</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i><?= h($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on" novalidate>
                <?= csrfField() ?>
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">Adres e-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
                        <input type="email" id="email" name="email" class="form-control form-control-lg"
                               value="<?= h($_POST['email'] ?? '') ?>" required autofocus
                               placeholder="twoj@email.pl">
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Hasło</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                        <input type="password" id="password" name="password" class="form-control form-control-lg"
                               required placeholder="••••••••">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePass()">
                            <i class="fas fa-eye" id="passIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Zaloguj się
                </button>
            </form>
        </div>
        <div class="card-footer text-center text-muted small py-3">
            FleetLink Magazyn v<?= defined('APP_VERSION') ? h(APP_VERSION) : '1.0.0' ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass() {
    const input = document.getElementById('password');
    const icon = document.getElementById('passIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
</body>
</html>
