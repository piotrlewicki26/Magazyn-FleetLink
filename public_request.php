<?php
/**
 * FleetLink System GPS - Public request form
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

$embedded = ($_GET['embed'] ?? '') === '1';
$db = getDb();
ensurePublicRequestsTable($db);

$error = '';
$submittedNumber = sanitize($_GET['submitted'] ?? '');
$submittedType = sanitize($_GET['type'] ?? '');

$formData = [
    'request_type' => 'serwis',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'company_name' => '',
    'nip' => '',
    'email' => '',
    'service_address' => '',
    'description' => '',
    'preferred_date' => '',
    'vehicle_registration' => '',
    'vehicle_vin' => '',
    'vehicle_details' => '',
    'consent_contact' => '0',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($formData) as $field) {
        if ($field === 'consent_contact') {
            $formData[$field] = !empty($_POST[$field]) ? '1' : '0';
            continue;
        }
        $formData[$field] = sanitize($_POST[$field] ?? '');
    }

    $honeypot = trim((string)($_POST['website'] ?? ''));
    $clientIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $validTypes = ['serwis', 'montaz', 'demontaz', 'inna'];

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowe żądanie. Odśwież stronę i spróbuj ponownie.';
    } elseif (!in_array($formData['request_type'], $validTypes, true)) {
        $error = 'Wybierz poprawny typ zgłoszenia.';
    } elseif ($honeypot !== '') {
        $error = 'Nie udało się wysłać formularza. Spróbuj ponownie.';
    } elseif (
        $formData['first_name'] === '' ||
        $formData['last_name'] === '' ||
        $formData['phone'] === '' ||
        $formData['company_name'] === '' ||
        $formData['email'] === '' ||
        $formData['service_address'] === '' ||
        $formData['description'] === ''
    ) {
        $error = 'Wypełnij wszystkie wymagane pola formularza.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Podaj poprawny adres e-mail.';
    } elseif (mb_strlen($formData['phone']) < 7 || mb_strlen($formData['phone']) > 30) {
        $error = 'Podaj poprawny numer telefonu.';
    } elseif ($formData['nip'] !== '' && !preg_match('/^[0-9\-\s]{10,20}$/', $formData['nip'])) {
        $error = 'Podaj poprawny numer NIP.';
    } elseif ($formData['consent_contact'] !== '1') {
        $error = 'Zgoda na kontakt jest wymagana do obsługi zgłoszenia.';
    } else {
        foreach (['first_name', 'last_name', 'company_name', 'service_address', 'vehicle_details'] as $field) {
            if (mb_strlen($formData[$field]) > 255) {
                $error = 'Jedno z pól tekstowych przekracza dopuszczalną długość.';
                break;
            }
        }
        if ($error === '' && mb_strlen($formData['description']) > 4000) {
            $error = 'Opis zgłoszenia jest zbyt długi.';
        }
    }

    if ($error === '' && $clientIp !== '') {
        $rateStmt = $db->prepare("SELECT COUNT(*) FROM public_requests WHERE submit_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $rateStmt->execute([$clientIp]);
        if ((int)$rateStmt->fetchColumn() >= 5) {
            $error = 'Przekroczono limit zgłoszeń z tego adresu. Spróbuj ponownie za godzinę.';
        }
    }

    if ($error === '') {
        $insertStmt = $db->prepare("
            INSERT INTO public_requests (
                request_number, request_type, status, first_name, last_name, phone, company_name, nip, email,
                service_address, description, preferred_date, vehicle_registration, vehicle_vin, vehicle_details,
                consent_contact, submit_ip, submit_user_agent
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $requestNumber = '';
        $newRequestId = 0;

        try {
            $inserted = false;
            for ($attempt = 0; $attempt < 5 && !$inserted; $attempt++) {
                $requestNumber = generatePublicRequestNumber($formData['preferred_date'] ?: null);
                try {
                    $insertStmt->execute([
                        $requestNumber,
                        $formData['request_type'],
                        'nowe',
                        $formData['first_name'],
                        $formData['last_name'],
                        $formData['phone'],
                        $formData['company_name'],
                        $formData['nip'] ?: null,
                        $formData['email'],
                        $formData['service_address'],
                        $formData['description'],
                        $formData['preferred_date'] ?: null,
                        $formData['vehicle_registration'] ?: null,
                        $formData['vehicle_vin'] ?: null,
                        $formData['vehicle_details'] ?: null,
                        1,
                        $clientIp ?: null,
                        $userAgent ?: null,
                    ]);
                    $inserted = true;
                    $newRequestId = (int)$db->lastInsertId();
                } catch (PDOException $e) {
                    $sqlState = $e->getCode();
                    $driverErrorCode = (int)($e->errorInfo[1] ?? 0);
                    // 1062 = MySQL duplicate key, 1555/2067 = SQLite duplicate/unique constraint variants.
                    $isDuplicate = $sqlState === '23000' || in_array($driverErrorCode, [1062, 1555, 2067], true);
                    if (!$isDuplicate || $attempt === 4) {
                        throw $e;
                    }
                }
            }

            $requestUrl = getBaseUrl() . 'public_requests.php?action=view&id=' . $newRequestId;
            $clientName = trim($formData['first_name'] . ' ' . $formData['last_name']);
            $preferredDateLabel = $formData['preferred_date'] ? formatDate($formData['preferred_date']) : 'Do ustalenia';
            $vehicleLabelParts = array_filter([
                $formData['vehicle_registration'] ?: '',
                $formData['vehicle_vin'] ?: '',
                $formData['vehicle_details'] ?: '',
            ]);
            $vehicleLabel = $vehicleLabelParts ? implode(' / ', $vehicleLabelParts) : 'Nie podano';

            try {
                $confirmationBody = getEmailTemplate('public_request_confirmation', [
                    'REQUEST_NUMBER' => $requestNumber,
                    'REQUEST_TYPE' => getPublicRequestTypeLabel($formData['request_type']),
                    'COMPANY_NAME' => $formData['company_name'],
                    'SERVICE_ADDRESS' => $formData['service_address'],
                    'PREFERRED_DATE' => $preferredDateLabel,
                    'DESCRIPTION' => $formData['description'],
                    'SENDER_NAME' => defined('APP_NAME') ? APP_NAME : 'FleetLink System GPS',
                ]);
                sendAppEmail(
                    $formData['email'],
                    $clientName,
                    'Potwierdzenie zgłoszenia ' . $requestNumber . ' — FleetLink System GPS',
                    $confirmationBody
                );
            } catch (Exception $e) {}

            try {
                $internalBody = getEmailTemplate('public_request_internal', [
                    'REQUEST_NUMBER' => $requestNumber,
                    'REQUEST_TYPE' => getPublicRequestTypeLabel($formData['request_type']),
                    'CLIENT_NAME' => $clientName,
                    'COMPANY_NAME' => $formData['company_name'],
                    'PHONE' => $formData['phone'],
                    'EMAIL' => $formData['email'],
                    'SERVICE_ADDRESS' => $formData['service_address'],
                    'PREFERRED_DATE' => $preferredDateLabel,
                    'VEHICLE' => $vehicleLabel,
                    'DESCRIPTION' => $formData['description'],
                    'REQUEST_URL' => $requestUrl,
                    'SENDER_NAME' => defined('APP_NAME') ? APP_NAME : 'FleetLink System GPS',
                ]);
                foreach (getPublicRequestRecipients($db) as $recipient) {
                    sendAppEmail(
                        $recipient['email'],
                        $recipient['name'] ?? '',
                        'Nowe zgłoszenie ' . $requestNumber . ' — FleetLink System GPS',
                        $internalBody
                    );
                }
            } catch (Exception $e) {}

            $redirectUrl = getBaseUrl() . 'public_request.php?submitted=' . urlencode($requestNumber) . '&type=' . urlencode($formData['request_type']);
            if ($embedded) {
                $redirectUrl .= '&embed=1';
            }
            redirect($redirectUrl);
        } catch (Exception $e) {
            $error = 'Nie udało się zapisać zgłoszenia. Spróbuj ponownie.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zgłoszenie usługi — FleetLink System GPS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= getBaseUrl() ?>assets/css/style.css">
</head>
<body class="<?= $embedded ? 'public-form-embed' : 'public-landing-page' ?>">

<?php if (!$embedded): ?>
<!-- ── Nawigacja ────────────────────────────────────────── -->
<nav class="public-navbar navbar navbar-expand-lg sticky-top">
    <div class="container-xl">
        <a class="navbar-brand" href="<?= getBaseUrl() ?>login.php">
            <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.png" alt="FleetLink" height="36">
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <a href="<?= getBaseUrl() ?>login.php#status" class="btn btn-outline-secondary btn-sm px-3">
                <i class="fas fa-search me-1"></i>Status zgłoszenia
            </a>
            <button class="btn btn-primary px-4 fw-semibold"
                    data-bs-toggle="modal" data-bs-target="#loginModal">
                <i class="fas fa-sign-in-alt me-2"></i>Zaloguj się
            </button>
        </div>
    </div>
</nav>
<?php endif; ?>

<div class="public-form-page">
    <div class="container-xl py-5">
        <div class="public-request-shell mx-auto">

            <!-- ── Nagłówek sekcji ─── -->
            <div class="public-form-header text-white mb-0">
                <div class="row align-items-center g-3">
                    <div class="col">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div class="public-form-icon">
                                <i class="fas fa-file-signature fa-lg"></i>
                            </div>
                            <h1 class="h3 fw-bold mb-0">Zgłoś serwis, montaż lub demontaż</h1>
                        </div>
                        <p class="mb-0 text-white-50">Wypełnij formularz online. Po złożeniu zgłoszenia otrzymasz potwierdzenie na podany adres e-mail — nasz zespół skontaktuje się z Tobą w celu ustalenia szczegółów.</p>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-lg public-form-card">
                <div class="card-body p-4 p-lg-5">

                    <?php if ($submittedNumber !== ''): ?>
                    <div class="alert alert-success public-request-success mb-4">
                        <div class="d-flex gap-3 align-items-start">
                            <i class="fas fa-circle-check fa-2x text-success mt-1 flex-shrink-0"></i>
                            <div>
                                <h2 class="h5 fw-bold mb-2">Zgłoszenie przyjęte pomyślnie</h2>
                                <p class="mb-1">Numer zgłoszenia: <strong><?= h($submittedNumber) ?></strong></p>
                                <p class="mb-2">Typ: <strong><?= h(getPublicRequestTypeLabel($submittedType ?: 'serwis')) ?></strong></p>
                                <p class="mb-0 small text-muted">Potwierdzenie zostało wysłane na Twój adres e-mail. Możesz sprawdzić status zgłoszenia <a href="<?= getBaseUrl() ?>login.php?tab=status&check_number=<?= urlencode($submittedNumber) ?>#status"<?= $embedded ? ' target="_top"' : '' ?>>tutaj</a>.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger mb-4">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= h($error) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <?= csrfField() ?>
                        <input type="text" name="website" value="" class="d-none" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <!-- ── Sekcja 1: Szczegóły usługi ── -->
                        <div class="public-form-section-label">
                            <span class="public-form-section-num">1</span>
                            Szczegóły usługi
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Rodzaj usługi <span class="text-danger">*</span></label>
                                <select name="request_type" class="form-select" required>
                                    <?php foreach (['serwis' => 'Serwis GPS', 'montaz' => 'Montaż GPS', 'demontaz' => 'Demontaż GPS', 'inna' => 'Inna'] as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $formData['request_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="preferred_date" class="form-label fw-semibold">Preferowany termin</label>
                                <input type="date" id="preferred_date" name="preferred_date" class="form-control" value="<?= h($formData['preferred_date']) ?>" min="<?= h(date('Y-m-d')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Adres wykonania usługi <span class="text-danger">*</span></label>
                                <input type="text" name="service_address" class="form-control" value="<?= h($formData['service_address']) ?>" maxlength="255" required placeholder="Ulica, numer, kod, miasto">
                            </div>
                        </div>

                        <!-- ── Sekcja 2: Dane pojazdu ── -->
                        <div class="public-form-section-label">
                            <span class="public-form-section-num">2</span>
                            Dane pojazdu
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Numer rejestracyjny</label>
                                <input type="text" name="vehicle_registration" class="form-control" value="<?= h($formData['vehicle_registration']) ?>" placeholder="np. WI 1234A">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Opis pojazdu</label>
                                <input type="text" name="vehicle_details" class="form-control" value="<?= h($formData['vehicle_details']) ?>" maxlength="255" placeholder="np. Mercedes Sprinter 2022">
                            </div>
                        </div>

                        <!-- ── Sekcja 3: Dane kontaktowe ── -->
                        <div class="public-form-section-label">
                            <span class="public-form-section-num">3</span>
                            Dane kontaktowe
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Imię <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" value="<?= h($formData['first_name']) ?>" maxlength="100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nazwisko <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" value="<?= h($formData['last_name']) ?>" maxlength="100" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Telefon <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control" value="<?= h($formData['phone']) ?>" maxlength="30" required placeholder="+48 500 000 000">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Adres e-mail <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" value="<?= h($formData['email']) ?>" maxlength="150" required placeholder="kontakt@firma.pl">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">NIP</label>
                                <input type="text" name="nip" class="form-control" value="<?= h($formData['nip']) ?>" maxlength="20" placeholder="np. 123-456-78-90">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Nazwa firmy <span class="text-danger">*</span></label>
                                <input type="text" name="company_name" class="form-control" value="<?= h($formData['company_name']) ?>" maxlength="150" required placeholder="Pełna nazwa firmy">
                            </div>
                        </div>

                        <!-- ── Sekcja 4: Opis ── -->
                        <div class="public-form-section-label">
                            <span class="public-form-section-num">4</span>
                            Opis zgłoszenia
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <textarea name="description" class="form-control" rows="5" maxlength="4000" required placeholder="Opisz zakres usługi, objawy usterki lub dodatkowe oczekiwania. Im więcej szczegółów, tym lepiej możemy się przygotować."><?= h($formData['description']) ?></textarea>
                            </div>
                        </div>

                        <!-- ── Zgoda + wyślij ── -->
                        <div class="public-request-check mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="consent_contact" name="consent_contact" value="1" <?= $formData['consent_contact'] === '1' ? 'checked' : '' ?> required>
                                <label class="form-check-label" for="consent_contact">
                                    Wyrażam zgodę na kontakt telefoniczny lub e-mail w celu obsługi zgłoszenia. <span class="text-danger">*</span>
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center justify-content-between">
                            <p class="text-muted small mb-0">Pola oznaczone <span class="text-danger">*</span> są wymagane.</p>
                            <button type="submit" class="btn btn-primary btn-lg px-5 fw-semibold">
                                <i class="fas fa-paper-plane me-2"></i>Wyślij zgłoszenie
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$embedded): ?>
<!-- ── Footer ────────────────────────────────────────────── -->
<footer class="public-footer">
    <div class="container-xl d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <span class="fw-semibold">FleetLink</span>
        <span class="text-muted small">System GPS &mdash; v<?= defined('APP_VERSION') ? h(APP_VERSION) : '1.2.0' ?></span>
        <a href="https://www.fleetlink.pl" class="text-muted small text-decoration-none">www.fleetlink.pl</a>
    </div>
</footer>

<!-- ── Modal logowania ───────────────────────────────────── -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel2" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;overflow:hidden">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div class="d-flex align-items-center gap-3">
                    <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.png" alt="FleetLink" height="36">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="loginModalLabel2">Logowanie</h5>
                        <p class="text-muted small mb-0">FleetLink System GPS</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <form method="POST" action="<?= getBaseUrl() ?>login.php" autocomplete="on" novalidate>
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label for="login_email" class="form-label fw-semibold small">Adres e-mail</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
                            <input type="email" id="login_email" name="email" class="form-control form-control-lg"
                                   required placeholder="twoj@email.pl">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="login_password" class="form-label fw-semibold small">Hasło</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" id="login_password" name="password" class="form-control form-control-lg"
                                   required placeholder="••••••••">
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
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
