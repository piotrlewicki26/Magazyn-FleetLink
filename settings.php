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
    ];

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

            <h6 class="fw-bold text-muted mb-3"><i class="fas fa-file-invoice me-2"></i>Ustawienia ofert</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">Standardowa stawka VAT (%)</label>
                    <input type="number" name="vat_rate" class="form-control" value="<?= h($settings['vat_rate'] ?? '23') ?>" min="0" max="100">
                </div>
                <div class="col-12">
                    <label class="form-label">Stopka oferty</label>
                    <textarea name="offer_footer" class="form-control" rows="2"><?= h($settings['offer_footer'] ?? '') ?></textarea>
                </div>
            </div>

            <h6 class="fw-bold text-muted mb-3"><i class="fas fa-file-signature me-2"></i>Szablon umowy</h6>
            <div class="mb-4">
                <label class="form-label">Domyślna treść umowy</label>
                <textarea name="contract_template" class="form-control" rows="8"><?= h($settings['contract_template'] ?? '') ?></textarea>
                <small class="text-muted">Ten szablon będzie automatycznie wklejany przy tworzeniu nowej umowy.</small>
            </div>

            <hr>
            <div class="alert alert-info small">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Konfiguracja e-mail</strong> jest przechowywana w pliku <code>includes/config.php</code>.
                Aby zmienić ustawienia e-mail, uruchom ponownie kreator <a href="setup.php">setup.php</a> (po usunięciu pliku config.php)
                lub ręcznie edytuj plik konfiguracyjny.
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz ustawienia</button>
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
