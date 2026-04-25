<?php
/**
 * FleetLink Magazyn - Application Settings (Admin only)
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireAdmin();

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'settings.php'); }

    $postAction = sanitize($_POST['action'] ?? 'save');

    // Test email action
    if ($postAction === 'test_email') {
        $testTo = sanitize($_POST['test_email_to'] ?? '');
        if (!$testTo || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            flashError('Podaj poprawny adres e-mail do testu.');
        } else {
            require_once __DIR__ . '/includes/functions.php';
            $body = getEmailTemplate('general', [
                'MESSAGE'     => '<strong>To jest wiadomość testowa z FleetLink Magazyn.</strong><br>Jeśli ją widzisz, konfiguracja e-mail działa poprawnie.',
                'SENDER_NAME' => 'FleetLink Magazyn',
                'DATE'        => date('d.m.Y H:i'),
            ]);
            if (sendAppEmail($testTo, '', 'Testowa wiadomość — FleetLink Magazyn', $body)) {
                flashSuccess("Testowa wiadomość została wysłana na adres {$testTo}.");
            } else {
                flashError('Nie udało się wysłać wiadomości testowej. Sprawdź ustawienia SMTP poniżej.');
            }
        }
        redirect(getBaseUrl() . 'settings.php');
    }

    $settingsToSave = [
        'company_name'      => sanitize($_POST['company_name'] ?? ''),
        'company_address'   => sanitize($_POST['company_address'] ?? ''),
        'company_city'      => sanitize($_POST['company_city'] ?? ''),
        'company_phone'     => sanitize($_POST['company_phone'] ?? ''),
        'company_email'     => sanitize($_POST['company_email'] ?? ''),
        'company_nip'       => sanitize($_POST['company_nip'] ?? ''),
        'company_bank_account' => sanitize($_POST['company_bank_account'] ?? ''),
        'offer_footer'      => sanitize($_POST['offer_footer'] ?? ''),
        'contract_template' => sanitize($_POST['contract_template'] ?? ''),
        'vat_rate'          => (string)(int)($_POST['vat_rate'] ?? 23),
        // Email / SMTP settings
        'smtp_enabled'      => !empty($_POST['smtp_enabled']) ? '1' : '0',
        'smtp_host'         => sanitize($_POST['smtp_host'] ?? ''),
        'smtp_port'         => (string)(int)($_POST['smtp_port'] ?? 587),
        'smtp_user'         => sanitize($_POST['smtp_user'] ?? ''),
        'smtp_from'         => sanitize($_POST['smtp_from'] ?? ''),
        'smtp_from_name'    => sanitize($_POST['smtp_from_name'] ?? ''),
        // Schema document passwords
        'schema_allcan300_pass'         => sanitize($_POST['schema_allcan300_pass'] ?? 'Pj0;Gm6$.g2rnd9'),
        'schema_cancontrol_pass'        => sanitize($_POST['schema_cancontrol_pass'] ?? ''),
        'schema_cancontrol6cimmo_pass'  => sanitize($_POST['schema_cancontrol6cimmo_pass'] ?? ''),
        'schema_cancontrol6c_pass'      => sanitize($_POST['schema_cancontrol6c_pass'] ?? ''),
        'schema_cancontroldtc_pass'     => sanitize($_POST['schema_cancontroldtc_pass'] ?? ''),
        'schema_cancontrolimmo_pass'    => sanitize($_POST['schema_cancontrolimmo_pass'] ?? ''),
        'schema_cancontrolimmop1_pass'  => sanitize($_POST['schema_cancontrolimmop1_pass'] ?? ''),
        'schema_fmb140allcan_pass'      => sanitize($_POST['schema_fmb140allcan_pass'] ?? ''),
        'schema_fmb140lvcan_pass'       => sanitize($_POST['schema_fmb140lvcan_pass'] ?? ''),
        'schema_fmc150_pass'            => sanitize($_POST['schema_fmc150_pass'] ?? ''),
        'schema_lvcan200_pass'          => sanitize($_POST['schema_lvcan200_pass'] ?? ''),
        'schema_lvcan200dtc_pass'       => sanitize($_POST['schema_lvcan200dtc_pass'] ?? ''),
    ];
    // Only save password if provided (to avoid wiping it when left blank)
    if (!empty($_POST['smtp_pass'])) {
        $settingsToSave['smtp_pass'] = $_POST['smtp_pass'];
    }

    $stmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
    foreach ($settingsToSave as $key => $value) {
        $stmt->execute([$key, $value, $value]);
    }

    flashSuccess('Ustawienia zostały zapisane.');
    redirect(getBaseUrl() . 'settings.php');
}

// Load settings
$settings = [];
foreach ($db->query("SELECT `key`, `value` FROM settings")->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

$activePage = 'settings';
$pageTitle = 'Ustawienia';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-sliders-h me-2 text-primary"></i>Ustawienia aplikacji</h1>
</div>

<div class="card" style="max-width:800px">
    <div class="card-header">Konfiguracja firmy i aplikacji</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>

            <h6 class="fw-bold text-muted mb-3 mt-2"><i class="fas fa-building me-2"></i>Dane firmy</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Nazwa firmy</label>
                    <input type="text" name="company_name" class="form-control" value="<?= h($settings['company_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">NIP</label>
                    <input type="text" name="company_nip" class="form-control" value="<?= h($settings['company_nip'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Adres</label>
                    <input type="text" name="company_address" class="form-control" value="<?= h($settings['company_address'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Miasto</label>
                    <input type="text" name="company_city" class="form-control" value="<?= h($settings['company_city'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input type="tel" name="company_phone" class="form-control" value="<?= h($settings['company_phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-mail firmowy</label>
                    <input type="email" name="company_email" class="form-control" value="<?= h($settings['company_email'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Numer konta bankowego</label>
                    <input type="text" name="company_bank_account" class="form-control" value="<?= h($settings['company_bank_account'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <h6 class="fw-bold text-muted mb-3"><i class="fas fa-envelope me-2"></i>Konfiguracja e-mail (SMTP)</h6>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtpEnabled"
                               value="1" <?= !empty($settings['smtp_enabled']) && $settings['smtp_enabled'] === '1' ? 'checked' : '' ?>
                               onchange="document.getElementById('smtpFields').style.display=this.checked?'block':'none'">
                        <label class="form-check-label" for="smtpEnabled">Używaj SMTP do wysyłania e-maili</label>
                    </div>
                    <small class="text-muted">Gdy wyłączone, system używa funkcji <code>mail()</code> serwera PHP.</small>
                </div>
                <div id="smtpFields" style="display:<?= (!empty($settings['smtp_enabled']) && $settings['smtp_enabled'] === '1') ? 'block' : 'none' ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Serwer SMTP</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?= h($settings['smtp_host'] ?? '') ?>" placeholder="mail.twojadomena.pl">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Port</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?= h($settings['smtp_port'] ?? '587') ?>" placeholder="587">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Login SMTP</label>
                            <input type="text" name="smtp_user" class="form-control" value="<?= h($settings['smtp_user'] ?? '') ?>" placeholder="login@domena.pl" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hasło SMTP</label>
                            <input type="password" name="smtp_pass" class="form-control" placeholder="Zostaw puste, aby nie zmieniać" autocomplete="new-password">
                            <small class="text-muted">Zostaw puste, aby zachować dotychczasowe hasło.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adres nadawcy (From)</label>
                            <input type="email" name="smtp_from" class="form-control" value="<?= h($settings['smtp_from'] ?? '') ?>" placeholder="noreply@twojadomena.pl">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nazwa nadawcy</label>
                            <input type="text" name="smtp_from_name" class="form-control" value="<?= h($settings['smtp_from_name'] ?? 'FleetLink Magazyn') ?>" placeholder="FleetLink Magazyn">
                        </div>
                    </div>
                </div>
            </div>

            <hr>
            <h6 class="fw-bold text-muted mb-3"><i class="fas fa-sitemap me-2"></i>Schematy — hasła dostępu</h6>
            <?php
            $schemaFields = [
                ['key' => 'schema_allcan300_pass',        'label' => 'ALL-CAN 300',          'default' => 'Pj0;Gm6$.g2rnd9'],
                ['key' => 'schema_cancontrol_pass',       'label' => 'CAN-CONTROL',           'default' => "3dmU~I{_@;W'OVL"],
                ['key' => 'schema_cancontrol6cimmo_pass', 'label' => 'CAN-CONTROL 6C IMMO',   'default' => "f_n8n}G'sK+j4fx"],
                ['key' => 'schema_cancontrol6c_pass',     'label' => 'CAN-CONTROL 6C',        'default' => 'q1{nGYAfw3-!5y#'],
                ['key' => 'schema_cancontroldtc_pass',    'label' => 'CAN-CONTROL DTC',       'default' => "Hm!oo7jW-#kgxu'"],
                ['key' => 'schema_cancontrolimmo_pass',   'label' => 'CAN-CONTROL IMMO',      'default' => 'F6evA;eIYTji~f('],
                ['key' => 'schema_cancontrolimmop1_pass', 'label' => 'CAN-CONTROL IMMO P1',   'default' => '#F+B9Q1OJS#uSI@'],
                ['key' => 'schema_fmb140allcan_pass',     'label' => 'FMB 140 ALL-CAN',       'default' => 'EmNj+l%3g!aaSqQ'],
                ['key' => 'schema_fmb140lvcan_pass',      'label' => 'FMB 140 LV-CAN',        'default' => 'IrL@nhJuyvdD=96'],
                ['key' => 'schema_fmc150_pass',           'label' => 'FMC 150',               'default' => 'i-evHv6#hu5I(ei'],
                ['key' => 'schema_lvcan200_pass',         'label' => 'LV-CAN200',             'default' => ',J8RPt%_EgEFzOY'],
                ['key' => 'schema_lvcan200dtc_pass',      'label' => 'LV-CAN200 DTC',         'default' => "W.#2}~MaqY]]w'D"],
            ];
            ?>
            <div class="row g-3 mb-4">
                <?php foreach ($schemaFields as $sf): ?>
                <div class="col-md-6">
                    <label class="form-label"><?= h($sf['label']) ?> — hasło</label>
                    <input type="text" name="<?= h($sf['key']) ?>" class="form-control"
                           value="<?= h($settings[$sf['key']] ?? $sf['default']) ?>">
                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz ustawienia</button>
        </form>
    </div>
</div>

<!-- Test Email Card -->
<div class="card mt-3" style="max-width:800px">
    <div class="card-header"><i class="fas fa-paper-plane me-2 text-success"></i>Testuj konfigurację e-mail</div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="test_email">
            <div class="col-md-8">
                <label class="form-label">Adres e-mail do testu</label>
                <input type="email" name="test_email_to" class="form-control" placeholder="adres@email.pl" required
                       value="<?= h($settings['company_email'] ?? '') ?>">
                <small class="text-muted">Zostanie wysłana prosta wiadomość testowa na podany adres.</small>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-success w-100"><i class="fas fa-paper-plane me-2"></i>Wyślij testową wiadomość</button>
            </div>
        </form>
    </div>
</div>

<!-- Version info -->
<div class="card mt-3" style="max-width:800px">
    <div class="card-header">Informacje o systemie</div>
    <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
            <tr><th class="text-muted" style="width:200px">Wersja aplikacji</th><td><?= defined('APP_VERSION') ? h(APP_VERSION) : '1.0.0' ?></td></tr>
            <tr><th class="text-muted">Wersja PHP</th><td><?= phpversion() ?></td></tr>
            <tr><th class="text-muted">Strefa czasowa</th><td><?= defined('APP_TIMEZONE') ? h(APP_TIMEZONE) : 'Europe/Warsaw' ?></td></tr>
            <tr><th class="text-muted">Baza danych</th><td><?= defined('DB_NAME') ? h(DB_HOST . ' / ' . DB_NAME) : '—' ?></td></tr>
            <tr><th class="text-muted">Ścieżka aplikacji</th><td><?= h(__DIR__) ?></td></tr>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
