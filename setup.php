<?php
/**
 * FleetLink Magazyn - Setup Wizard
 * First-run configuration for MySQL and admin user
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already configured, redirect to login
if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
    if (defined('CONFIG_INSTALLED') && CONFIG_INSTALLED) {
        header('Location: login.php');
        exit;
    }
}

$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_db') {
        // Step 1: Test DB connection
        $dbHost = trim($_POST['db_host'] ?? '');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';

        try {
            $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $_SESSION['setup'] = compact('dbHost', 'dbName', 'dbUser', 'dbPass');
            $step = 2;
            $success = 'Połączenie z bazą danych nawiązane pomyślnie!';
        } catch (PDOException $e) {
            $error = 'Błąd połączenia: ' . htmlspecialchars($e->getMessage());
            $step = 1;
        }
    } elseif ($action === 'install_db') {
        // Step 2: Install database schema
        if (empty($_SESSION['setup'])) {
            $step = 1;
            $error = 'Sesja wygasła. Zacznij od nowa.';
        } else {
            $setup = $_SESSION['setup'];
            try {
                $dsn = 'mysql:host=' . $setup['dbHost'] . ';dbname=' . $setup['dbName'] . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $setup['dbUser'], $setup['dbPass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $sql = file_get_contents(__DIR__ . '/includes/schema.sql');
                // Execute each statement
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^SET\s+NAMES/i', $stmt)) {
                        $pdo->exec($stmt);
                    } elseif (preg_match('/^SET\s+/i', $stmt)) {
                        try { $pdo->exec($stmt); } catch (Exception $e) {}
                    }
                }
                $step = 3;
                $success = 'Struktura bazy danych utworzona pomyślnie!';
            } catch (PDOException $e) {
                $error = 'Błąd instalacji bazy danych: ' . htmlspecialchars($e->getMessage());
                $step = 2;
            }
        }
    } elseif ($action === 'create_admin') {
        // Step 3: Create admin user and config
        if (empty($_SESSION['setup'])) {
            $step = 1;
            $error = 'Sesja wygasła. Zacznij od nowa.';
        } else {
            $adminName  = trim($_POST['admin_name'] ?? '');
            $adminEmail = trim($_POST['admin_email'] ?? '');
            $adminPass  = $_POST['admin_pass'] ?? '';
            $adminPass2 = $_POST['admin_pass2'] ?? '';
            $mailFrom   = trim($_POST['mail_from'] ?? '');
            $mailHost   = trim($_POST['mail_host'] ?? '');
            $mailPort   = (int)($_POST['mail_port'] ?? 587);
            $mailUser   = trim($_POST['mail_user'] ?? '');
            $mailPass   = $_POST['mail_pass'] ?? '';
            $mailSmtp   = !empty($_POST['mail_smtp']) ? 'true' : 'false';

            if (empty($adminName) || empty($adminEmail) || empty($adminPass)) {
                $error = 'Wypełnij wszystkie pola administratora.';
                $step = 3;
            } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Podaj poprawny adres e-mail.';
                $step = 3;
            } elseif (strlen($adminPass) < 8) {
                $error = 'Hasło musi mieć minimum 8 znaków.';
                $step = 3;
            } elseif ($adminPass !== $adminPass2) {
                $error = 'Hasła nie są identyczne.';
                $step = 3;
            } else {
                $setup = $_SESSION['setup'];
                try {
                    $dsn = 'mysql:host=' . $setup['dbHost'] . ';dbname=' . $setup['dbName'] . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, $setup['dbUser'], $setup['dbPass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                    $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, 'admin', 1)");
                    $stmt->execute([$adminName, $adminEmail, $hashedPass]);

                    // Generate config.php
                    $configTemplate = file_get_contents(__DIR__ . '/includes/config.template.php');
                    $config = str_replace(
                        ['{{DB_HOST}}', '{{DB_NAME}}', '{{DB_USER}}', '{{DB_PASS}}',
                         '{{MAIL_FROM}}', '{{MAIL_HOST}}', '{{MAIL_PORT}}', '{{MAIL_USER}}', '{{MAIL_PASS}}', '{{MAIL_SMTP}}'],
                        [
                            addslashes($setup['dbHost']),
                            addslashes($setup['dbName']),
                            addslashes($setup['dbUser']),
                            addslashes($setup['dbPass']),
                            addslashes($mailFrom ?: $adminEmail),
                            addslashes($mailHost),
                            $mailPort,
                            addslashes($mailUser),
                            addslashes($mailPass),
                            $mailSmtp,
                        ],
                        $configTemplate
                    );

                    if (file_put_contents(__DIR__ . '/includes/config.php', $config) === false) {
                        $error = 'Nie można zapisać pliku konfiguracyjnego. Sprawdź uprawnienia do katalogu includes/.';
                        $step = 3;
                    } else {
                        unset($_SESSION['setup']);
                        $step = 4;
                        $success = 'Instalacja zakończona pomyślnie!';
                    }
                } catch (PDOException $e) {
                    $error = 'Błąd podczas tworzenia administratora: ' . htmlspecialchars($e->getMessage());
                    $step = 3;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalacja - FleetLink Magazyn</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%); min-height: 100vh; display: flex; align-items: center; }
        .setup-card { max-width: 600px; width: 100%; margin: 20px auto; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .setup-header { background: linear-gradient(135deg, #0d6efd, #0dcaf0); color: white; padding: 30px; border-radius: 12px 12px 0 0; text-align: center; }
        .step-indicator { display: flex; justify-content: center; gap: 10px; margin-top: 15px; }
        .step-dot { width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; color: white; }
        .step-dot.active { background: white; color: #0d6efd; }
        .step-dot.done { background: rgba(255,255,255,0.7); }
    </style>
</head>
<body>
<div class="container">
    <div class="card setup-card">
        <div class="setup-header">
            <i class="fas fa-satellite-dish fa-3x mb-3"></i>
            <h2 class="mb-0">FleetLink Magazyn</h2>
            <p class="mb-0 opacity-75">Kreator instalacji</p>
            <div class="step-indicator">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                <div class="step-dot <?= $i < $step ? 'done' : ($i === $step ? 'active' : '') ?>">
                    <?php if ($i < $step): ?><i class="fas fa-check fa-xs"></i><?php else: echo $i; endif; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= $error ?></div>
            <?php endif; ?>
            <?php if ($success && $step < 4): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= $success ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
            <h4 class="mb-3"><i class="fas fa-database me-2 text-primary"></i>Krok 1: Konfiguracja bazy danych</h4>
            <p class="text-muted small">Podaj dane dostępowe do bazy MySQL (cyberfolks lub inny hosting).</p>
            <form method="POST">
                <input type="hidden" name="action" value="test_db">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Serwer MySQL (host)</label>
                    <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required placeholder="np. mysql.cyberfolks.pl lub localhost">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nazwa bazy danych</label>
                    <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required placeholder="np. twoja_baza">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Użytkownik bazy danych</label>
                    <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required placeholder="np. twoj_user">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Hasło bazy danych</label>
                    <input type="password" name="db_pass" class="form-control" placeholder="Hasło do bazy danych">
                </div>
                <div class="alert alert-info small">
                    <i class="fas fa-info-circle me-1"></i>
                    Dane te znajdziesz w panelu hostingowym. W przypadku cyberfolks.pl: Panel → MySQL → Bazy danych.
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-plug me-2"></i>Testuj połączenie i kontynuuj
                </button>
            </form>

            <?php elseif ($step === 2): ?>
            <h4 class="mb-3"><i class="fas fa-table me-2 text-primary"></i>Krok 2: Instalacja tabel</h4>
            <p class="text-muted">Zostaną utworzone wszystkie niezbędne tabele w bazie danych.</p>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Uwaga:</strong> Jeśli tabele już istnieją, nie zostaną nadpisane (używamy IF NOT EXISTS).
            </div>
            <ul class="list-group list-group-flush mb-3">
                <?php foreach (['users', 'manufacturers', 'models', 'devices', 'inventory', 'clients', 'vehicles', 'installations', 'services', 'offers', 'contracts', 'protocols', 'email_log', 'settings'] as $table): ?>
                <li class="list-group-item d-flex align-items-center">
                    <i class="fas fa-table me-2 text-primary"></i> <?= $table ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <form method="POST">
                <input type="hidden" name="action" value="install_db">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-download me-2"></i>Utwórz tabele i kontynuuj
                </button>
            </form>

            <?php elseif ($step === 3): ?>
            <h4 class="mb-3"><i class="fas fa-user-shield me-2 text-primary"></i>Krok 3: Konto administratora</h4>
            <form method="POST">
                <input type="hidden" name="action" value="create_admin">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Imię i nazwisko</label>
                    <input type="text" name="admin_name" class="form-control" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required placeholder="Jan Kowalski">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Adres e-mail</label>
                    <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Hasło (min. 8 znaków)</label>
                        <input type="password" name="admin_pass" class="form-control" required minlength="8">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Powtórz hasło</label>
                        <input type="password" name="admin_pass2" class="form-control" required>
                    </div>
                </div>
                <hr>
                <h6 class="text-muted mb-3"><i class="fas fa-envelope me-2"></i>Konfiguracja e-mail (opcjonalne)</h6>
                <div class="mb-3">
                    <label class="form-label">Adres nadawcy (From)</label>
                    <input type="email" name="mail_from" class="form-control" value="<?= htmlspecialchars($_POST['mail_from'] ?? '') ?>" placeholder="noreply@twojadomena.pl">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="mail_smtp" id="mailSmtp" <?= !empty($_POST['mail_smtp']) ? 'checked' : '' ?> onchange="toggleSmtp()">
                    <label class="form-check-label" for="mailSmtp">Użyj SMTP (zamiast mail())</label>
                </div>
                <div id="smtpFields" style="display:<?= !empty($_POST['mail_smtp']) ? 'block' : 'none' ?>">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Serwer SMTP</label>
                            <input type="text" name="mail_host" class="form-control" value="<?= htmlspecialchars($_POST['mail_host'] ?? '') ?>" placeholder="mail.twojadomena.pl">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Port</label>
                            <input type="number" name="mail_port" class="form-control" value="<?= htmlspecialchars($_POST['mail_port'] ?? '587') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Login SMTP</label>
                        <input type="text" name="mail_user" class="form-control" value="<?= htmlspecialchars($_POST['mail_user'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Hasło SMTP</label>
                        <input type="password" name="mail_pass" class="form-control">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-user-check me-2"></i>Utwórz administratora i zakończ instalację
                </button>
            </form>
            <script>function toggleSmtp() { document.getElementById('smtpFields').style.display = document.getElementById('mailSmtp').checked ? 'block' : 'none'; }</script>

            <?php elseif ($step === 4): ?>
            <div class="text-center py-4">
                <i class="fas fa-check-circle fa-5x text-success mb-3"></i>
                <h3 class="text-success">Instalacja zakończona!</h3>
                <p class="text-muted">FleetLink Magazyn został pomyślnie zainstalowany i skonfigurowany.</p>
                <div class="alert alert-warning text-start">
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong>Ważne z powodów bezpieczeństwa:</strong><br>
                    Zalecamy usunięcie lub zablokowanie pliku <code>setup.php</code> po zakończeniu instalacji.
                </div>
                <a href="login.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-sign-in-alt me-2"></i>Przejdź do logowania
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-center text-muted small py-3">
            FleetLink Magazyn v1.0.0 &mdash; System zarządzania urządzeniami GPS
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
