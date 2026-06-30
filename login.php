<?php
/**
 * FleetLink System GPS - Login Page
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
    <title>FleetLink — System GPS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= getBaseUrl() ?>assets/css/style.css">
</head>
<body class="public-landing-page">

<!-- ── Nawigacja ────────────────────────────────────────── -->
<nav class="public-navbar navbar navbar-expand-lg sticky-top">
    <div class="container-xl">
        <a class="navbar-brand" href="<?= getBaseUrl() ?>login.php">
            <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.png" alt="FleetLink" height="36">
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <button class="btn btn-primary px-4 fw-semibold"
                    data-bs-toggle="modal" data-bs-target="#loginModal">
                <i class="fas fa-sign-in-alt me-2"></i>Zaloguj się
            </button>
        </div>
    </div>
</nav>

<!-- ── Hero ─────────────────────────────────────────────── -->
<section class="public-hero">
    <div class="container-xl">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="public-hero-badge mb-3 d-inline-block">
                    <i class="fas fa-satellite-dish me-2"></i>System GPS FleetLink
                </span>
                <h1 class="public-hero-title">System serwisowy<br>FleetLink</h1>
                <p class="public-hero-lead">Montaż, serwis i demontaż urządzeń GPS. Wypełnij formularz zgłoszenia online — skontaktujemy się z Tobą, aby ustalić szczegóły.</p>
                <div class="d-flex gap-3 flex-wrap mt-4">
                    <button type="button" class="btn btn-primary btn-lg px-5 fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#requestModal">
                        <i class="fas fa-file-alt me-2"></i>Formularz zgłoszenia
                    </button>
                    <a href="#status" class="btn btn-outline-secondary btn-lg px-4">
                        <i class="fas fa-search me-2"></i>Sprawdź status
                    </a>
                </div>
            </div>
            <div class="col-lg-6 d-none d-lg-flex justify-content-end">
                <div class="public-hero-icons">
                    <div class="public-hero-icon-item"><i class="fas fa-tools fa-2x"></i><span>Serwis</span></div>
                    <div class="public-hero-icon-item"><i class="fas fa-car-side fa-2x"></i><span>Montaż</span></div>
                    <div class="public-hero-icon-item"><i class="fas fa-location-dot fa-2x"></i><span>Demontaż</span></div>
                    <div class="public-hero-icon-item"><i class="fas fa-headset fa-2x"></i><span>Wsparcie</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Sprawdź status ────────────────────────────────────── -->
<section class="public-status-section" id="status">
    <div class="container-xl">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6">
                <div class="public-status-card h-100">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="public-section-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-search"></i>
                        </div>
                        <div>
                            <h2 class="h4 fw-bold mb-0">Sprawdź status zgłoszenia</h2>
                            <p class="text-muted mb-0 small">Wpisz numer zgłoszenia otrzymany w potwierdzeniu e-mail</p>
                        </div>
                    </div>
                    <form method="GET" action="<?= getBaseUrl() ?>login.php" class="d-flex gap-3 flex-column flex-sm-row" id="statusCheckForm">
                        <input type="hidden" name="tab" value="status">
                        <div class="flex-grow-1">
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fas fa-hashtag text-muted"></i></span>
                                <input type="text" name="check_number" id="checkNumberInput"
                                       class="form-control"
                                       value="<?= h($_GET['check_number'] ?? '') ?>"
                                       placeholder="np. ZGL/2025/06/0001"
                                       autocomplete="off">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg px-5 fw-semibold">
                            <i class="fas fa-search me-2"></i>Sprawdź
                        </button>
                    </form>

                    <?php
                    $checkNumber = sanitize($_GET['check_number'] ?? '');
                    $tabParam    = sanitize($_GET['tab'] ?? '');
                    if ($checkNumber !== '' && $tabParam === 'status'):
                        $db = getDb();
                        ensurePublicRequestsTable($db);
                        $chkStmt = $db->prepare("SELECT id, request_number, request_type, status, first_name, last_name, company_name, preferred_date, created_at FROM public_requests WHERE request_number = ? LIMIT 1");
                        $chkStmt->execute([$checkNumber]);
                        $chkRow = $chkStmt->fetch();
                        if ($chkRow):
                            $statusMap = [
                                'nowe'                     => ['primary',   'Nowe'],
                                'zweryfikowane'             => ['info',      'Zweryfikowane'],
                                'w_realizacji'             => ['warning',   'W realizacji'],
                                'zamienione_na_zlecenie'   => ['success',   'Zlecenie utworzone'],
                                'odrzucone'                => ['danger',    'Odrzucone'],
                            ];
                            $si = $statusMap[$chkRow['status']] ?? ['secondary', $chkRow['status']];
                    ?>
                    <div class="alert alert-success mt-4 mb-0 public-status-result">
                        <div class="d-flex gap-3 align-items-start flex-wrap">
                            <i class="fas fa-circle-check fa-2x text-success mt-1"></i>
                            <div class="flex-grow-1">
                                <div class="fw-bold fs-5 mb-2"><?= h($chkRow['request_number']) ?></div>
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">Typ zgłoszenia</small>
                                        <span class="fw-semibold"><?= h(getPublicRequestTypeLabel($chkRow['request_type'])) ?></span>
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">Status</small>
                                        <span class="badge bg-<?= $si[0] ?> fs-6"><?= h($si[1]) ?></span>
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">Firma</small>
                                        <span class="fw-semibold"><?= h($chkRow['company_name']) ?></span>
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted d-block">Data zgłoszenia</small>
                                        <span class="fw-semibold"><?= h(substr($chkRow['created_at'], 0, 10)) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mt-4 mb-0">
                        <i class="fas fa-triangle-exclamation me-2"></i>Nie znaleziono zgłoszenia o podanym numerze. Sprawdź, czy numer jest wpisany poprawnie.
                    </div>
                    <?php endif; endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="public-status-card public-action-card h-100">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="public-section-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <div>
                            <h2 class="h4 fw-bold mb-0">Zleć usługę</h2>
                            <p class="text-muted mb-0 small">Otwórz formularz zgłoszenia i przekaż szczegóły serwisu, montażu lub demontażu</p>
                        </div>
                    </div>
                    <p class="public-section-lead text-start mx-0 mb-4">
                        Wypełnij formularz, a nasz zespół skontaktuje się z Tobą w celu ustalenia szczegółów.
                    </p>
                    <div class="mt-auto">
                        <button type="button" class="btn btn-primary btn-lg px-5 fw-semibold"
                                data-bs-toggle="modal" data-bs-target="#requestModal">
                            <i class="fas fa-file-alt me-2"></i>Formularz zgłoszenia
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Footer ────────────────────────────────────────────── -->
<footer class="public-footer">
    <div class="container-xl d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <span class="fw-semibold">FleetLink</span>
        <span class="text-muted small">System GPS &mdash; v<?= defined('APP_VERSION') ? h(APP_VERSION) : '1.2.0' ?></span>
        <a href="https://www.fleetlink.pl" class="text-muted small text-decoration-none">www.fleetlink.pl</a>
    </div>
</footer>

<!-- ── Modal logowania ───────────────────────────────────── -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;overflow:hidden">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div class="d-flex align-items-center gap-3">
                    <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.png" alt="FleetLink" height="36">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="loginModalLabel">Logowanie</h5>
                        <p class="text-muted small mb-0">FleetLink System GPS</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <?php if ($error): ?>
                <div class="alert alert-danger py-2">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= h($error) ?>
                </div>
                <?php endif; ?>
                <form method="POST" autocomplete="on" novalidate>
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold small">Adres e-mail</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
                            <input type="email" id="email" name="email" class="form-control form-control-lg"
                                   value="<?= h($_POST['email'] ?? '') ?>" required
                                   placeholder="twoj@email.pl">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold small">Hasło</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" id="password" name="password" class="form-control form-control-lg"
                                   required placeholder="••••••••">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass()">
                                <i class="fas fa-eye" id="passIcon"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                        <i class="fas fa-sign-in-alt me-2"></i>Zaloguj się
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal formularza zgłoszenia ───────────────────────── -->
<div class="modal fade" id="requestModal" tabindex="-1" aria-labelledby="requestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl public-request-modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-1" id="requestModalLabel">Formularz zgłoszenia</h5>
                    <p class="text-muted small mb-0">Serwis, montaż lub demontaż urządzeń GPS</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body p-0">
                <iframe
                    src="<?= getBaseUrl() ?>public_request.php?embed=1"
                    title="Formularz zgłoszenia FleetLink"
                    class="public-request-modal-frame"
                    loading="lazy"></iframe>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('passIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($error): ?>
    // Open login modal automatically when there is a login error
    var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
    loginModal.show();
    document.getElementById('loginModal').addEventListener('shown.bs.modal', function () {
        document.getElementById('email').focus();
    });
    <?php else: ?>
    // Auto-open modal and focus email if redirected here deliberately
    document.getElementById('loginModal').addEventListener('shown.bs.modal', function () {
        document.getElementById('email').focus();
    });
    <?php endif; ?>
});
</script>
</body>
</html>
