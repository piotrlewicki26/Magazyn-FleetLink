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
                'MESSAGE'     => '<strong>To jest wiadomość testowa z FleetLink System GPS.</strong><br>Jeśli ją widzisz, konfiguracja e-mail działa poprawnie.',
                'SENDER_NAME' => getCurrentUser()['name'] ?? 'FleetLink System GPS',
                'DATE'        => date('d.m.Y H:i'),
            ]);
            if (sendAppEmail($testTo, '', 'Testowa wiadomość — FleetLink System GPS', $body)) {
                flashSuccess("Testowa wiadomość została wysłana na adres {$testTo}.");
            } else {
                flashError('Nie udało się wysłać wiadomości testowej. Sprawdź ustawienia SMTP poniżej.');
            }
        }
        redirect(getBaseUrl() . 'settings.php');
    }

    // Email templates save action (separate form)
    if ($postAction === 'save_templates') {
        $tplStmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $allTplKeys = ['email_tpl_general', 'email_tpl_offer', 'email_tpl_service_reminder', 'email_tpl_installation_created', 'email_tpl_service_created'];
        foreach ($allTplKeys as $tplKey) {
            $val = $_POST[$tplKey] ?? '';
            $tplStmt->execute([$tplKey, $val, $val]);
        }
        flashSuccess('Szablony e-mail zostały zapisane.');
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
        // Email templates (raw HTML - do not sanitize to preserve tags)
        'email_tpl_general'          => $_POST['email_tpl_general'] ?? '',
        'email_tpl_offer'            => $_POST['email_tpl_offer'] ?? '',
        'email_tpl_service_reminder' => $_POST['email_tpl_service_reminder'] ?? '',
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

<?php
$tplDefaults = getEmailTemplateDefaults();
$emailTplFields = [
    ['key' => 'email_tpl_general',              'defKey' => 'general',               'label' => 'Ogólny (protokoły, wiadomości)',       'desc' => '{{APP_NAME}}, {{MESSAGE}}, {{SENDER_NAME}}, {{DATE}}'],
    ['key' => 'email_tpl_offer',                'defKey' => 'offer',                 'label' => 'Oferta handlowa',                       'desc' => '{{APP_NAME}}, {{OFFER_NUMBER}}, {{DATE}}, {{MESSAGE}}, {{SENDER_NAME}}'],
    ['key' => 'email_tpl_service_reminder',     'defKey' => 'service_reminder',      'label' => 'Przypomnienie o serwisie',              'desc' => '{{APP_NAME}}, {{VEHICLE}}, {{DATE}}, {{DESCRIPTION}}, {{SENDER_NAME}}'],
    ['key' => 'email_tpl_installation_created', 'defKey' => 'installation_created',  'label' => 'Powiadomienie — nowy montaż',           'desc' => '{{APP_NAME}}, {{COUNT}}, {{DATE}}, {{TECHNICIAN}}, {{VEHICLES}}, {{ADDRESS}}, {{NOTES}}, {{SENDER_NAME}}'],
    ['key' => 'email_tpl_service_created',      'defKey' => 'service_created',       'label' => 'Powiadomienie — nowy serwis',           'desc' => '{{APP_NAME}}, {{SERVICE_TYPE}}, {{DEVICE}}, {{DATE}}, {{TECHNICIAN}}, {{STATUS}}, {{DESCRIPTION}}, {{SENDER_NAME}}'],
];
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

<!-- ─── Tab navigation ─── -->
<ul class="nav nav-tabs mb-0" id="settingsTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-firma-btn" data-bs-toggle="tab" data-bs-target="#tab-firma" type="button" role="tab">
            <i class="fas fa-building me-1"></i>Firma
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-email-btn" data-bs-toggle="tab" data-bs-target="#tab-email" type="button" role="tab">
            <i class="fas fa-envelope me-1"></i>E-mail &amp; SMTP
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-templates-btn" data-bs-toggle="tab" data-bs-target="#tab-templates" type="button" role="tab">
            <i class="fas fa-file-alt me-1"></i>Szablony
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-schemas-btn" data-bs-toggle="tab" data-bs-target="#tab-schemas" type="button" role="tab">
            <i class="fas fa-sitemap me-1"></i>Schematy
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-system-btn" data-bs-toggle="tab" data-bs-target="#tab-system" type="button" role="tab">
            <i class="fas fa-info-circle me-1"></i>System
        </button>
    </li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white p-0 mb-4" id="settingsTabContent">

    <!-- ══════════════════════════════════════════ TAB: FIRMA ══════════════════════════════════════════ -->
    <div class="tab-pane fade show active p-4" id="tab-firma" role="tabpanel">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="row g-4">
                <!-- Left: company data -->
                <div class="col-lg-7">
                    <h6 class="fw-bold text-uppercase text-muted small mb-3"><i class="fas fa-id-card me-2"></i>Dane firmy</h6>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nazwa firmy</label>
                            <input type="text" name="company_name" class="form-control" value="<?= h($settings['company_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
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
                </div>

                <!-- Right: documents -->
                <div class="col-lg-5">
                    <h6 class="fw-bold text-uppercase text-muted small mb-3"><i class="fas fa-file-invoice me-2"></i>Dokumenty i faktury</h6>
                    <div class="mb-3">
                        <label class="form-label">Stawka VAT (%)</label>
                        <input type="number" name="vat_rate" class="form-control" style="max-width:100px"
                               value="<?= h($settings['vat_rate'] ?? '23') ?>" min="0" max="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Stopka ofert</label>
                        <textarea name="offer_footer" class="form-control" rows="3"><?= h($settings['offer_footer'] ?? '') ?></textarea>
                        <small class="text-muted">Tekst widoczny na dole każdej wygenerowanej oferty.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Szablon umowy</label>
                        <textarea name="contract_template" class="form-control" rows="4"><?= h($settings['contract_template'] ?? '') ?></textarea>
                        <small class="text-muted">Treść umowy serwisowej — używana w module Umów.</small>
                    </div>
                </div>
            </div>

            <hr class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz dane firmy</button>
        </form>
    </div>

    <!-- ══════════════════════════════════════════ TAB: E-MAIL & SMTP ══════════════════════════════════════════ -->
    <div class="tab-pane fade p-4" id="tab-email" role="tabpanel">
        <div class="row g-4">

            <!-- SMTP config form -->
            <div class="col-lg-8">
                <div class="card border shadow-none h-100">
                    <div class="card-header bg-transparent fw-semibold">
                        <i class="fas fa-server me-2 text-primary"></i>Konfiguracja SMTP
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="save">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtpEnabled"
                                           value="1" <?= !empty($settings['smtp_enabled']) && $settings['smtp_enabled'] === '1' ? 'checked' : '' ?>
                                           onchange="document.getElementById('smtpFields').style.display=this.checked?'block':'none'">
                                    <label class="form-check-label" for="smtpEnabled"><strong>Używaj SMTP</strong></label>
                                </div>
                                <small class="text-muted">Gdy wyłączone, system używa funkcji <code>mail()</code> serwera PHP.</small>
                            </div>
                            <div id="smtpFields" style="display:<?= (!empty($settings['smtp_enabled']) && $settings['smtp_enabled'] === '1') ? 'block' : 'none' ?>">
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="form-label">Serwer SMTP</label>
                                        <input type="text" name="smtp_host" class="form-control"
                                               value="<?= h($settings['smtp_host'] ?? '') ?>" placeholder="mail.twojadomena.pl">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Port</label>
                                        <input type="number" name="smtp_port" class="form-control"
                                               value="<?= h($settings['smtp_port'] ?? '587') ?>" placeholder="587">
                                        <small class="text-muted">587 (TLS), 465 (SSL), 25 (bez szyfrowania)</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Login SMTP</label>
                                        <input type="text" name="smtp_user" class="form-control"
                                               value="<?= h($settings['smtp_user'] ?? '') ?>" placeholder="login@domena.pl" autocomplete="off">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Hasło SMTP</label>
                                        <input type="password" name="smtp_pass" class="form-control"
                                               placeholder="Zostaw puste, aby nie zmieniać" autocomplete="new-password">
                                        <small class="text-muted">Puste = zachowaj dotychczasowe.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Adres nadawcy (From)</label>
                                        <input type="email" name="smtp_from" class="form-control"
                                               value="<?= h($settings['smtp_from'] ?? '') ?>" placeholder="noreply@twojadomena.pl">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Nazwa nadawcy</label>
                                        <input type="text" name="smtp_from_name" class="form-control"
                                               value="<?= h($settings['smtp_from_name'] ?? 'FleetLink System GPS') ?>" placeholder="FleetLink System GPS">
                                    </div>
                                </div>
                            </div>
                            <!-- hidden fields required by the save action for non-SMTP settings -->
                            <input type="hidden" name="company_name"         value="<?= h($settings['company_name'] ?? '') ?>">
                            <input type="hidden" name="company_address"      value="<?= h($settings['company_address'] ?? '') ?>">
                            <input type="hidden" name="company_city"         value="<?= h($settings['company_city'] ?? '') ?>">
                            <input type="hidden" name="company_phone"        value="<?= h($settings['company_phone'] ?? '') ?>">
                            <input type="hidden" name="company_email"        value="<?= h($settings['company_email'] ?? '') ?>">
                            <input type="hidden" name="company_nip"          value="<?= h($settings['company_nip'] ?? '') ?>">
                            <input type="hidden" name="company_bank_account" value="<?= h($settings['company_bank_account'] ?? '') ?>">
                            <input type="hidden" name="offer_footer"         value="<?= h($settings['offer_footer'] ?? '') ?>">
                            <input type="hidden" name="contract_template"    value="<?= h($settings['contract_template'] ?? '') ?>">
                            <input type="hidden" name="vat_rate"             value="<?= h($settings['vat_rate'] ?? '23') ?>">
                            <?php foreach ($schemaFields as $sf): ?>
                            <input type="hidden" name="<?= h($sf['key']) ?>" value="<?= h($settings[$sf['key']] ?? $sf['default']) ?>">
                            <?php endforeach; ?>
                            <hr class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz konfigurację SMTP</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Test email -->
            <div class="col-lg-4">
                <div class="card border shadow-none">
                    <div class="card-header bg-transparent fw-semibold">
                        <i class="fas fa-paper-plane me-2 text-success"></i>Test wysyłki
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Wyślij testową wiadomość, aby sprawdzić poprawność konfiguracji SMTP.</p>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="test_email">
                            <div class="mb-3">
                                <label class="form-label">Adres e-mail do testu</label>
                                <input type="email" name="test_email_to" class="form-control" placeholder="adres@email.pl" required
                                       value="<?= h($settings['company_email'] ?? '') ?>">
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-paper-plane me-2"></i>Wyślij testową wiadomość
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════ TAB: SZABLONY ══════════════════════════════════════════ -->
    <div class="tab-pane fade p-4" id="tab-templates" role="tabpanel">
        <p class="text-muted small mb-3">
            Edytuj treść HTML każdego szablonu wiadomości e-mail. Zostaw puste, aby używać domyślnego szablonu systemowego.
            Zmienna <code>{{SENDER_NAME}}</code> jest zastępowana imieniem zalogowanego użytkownika, <code>{{APP_NAME}}</code> — nazwą aplikacji.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_templates">

            <!-- accordion for templates -->
            <div class="accordion" id="tplAccordion">
            <?php foreach ($emailTplFields as $idx => $tf):
                $defVal = $tplDefaults[$tf['defKey']] ?? '';
                $curVal = $settings[$tf['key']] ?? $defVal;
                $collapseId = 'tpl-collapse-' . $idx;
                $headingId  = 'tpl-heading-'  . $idx;
            ?>
            <div class="accordion-item">
                <h2 class="accordion-header" id="<?= $headingId ?>">
                    <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>"
                            type="button" data-bs-toggle="collapse"
                            data-bs-target="#<?= $collapseId ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>">
                        <strong><?= h($tf['label']) ?></strong>
                    </button>
                </h2>
                <div id="<?= $collapseId ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>">
                    <div class="accordion-body">
                        <div class="mb-2">
                            <textarea name="<?= h($tf['key']) ?>" class="form-control font-monospace" rows="12"
                                      style="font-size:.78rem"><?= h($curVal) ?></textarea>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><strong>Zmienne:</strong> <?= h($tf['desc']) ?></small>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="resetTpl(this, <?= htmlspecialchars(json_encode($defVal), ENT_QUOTES) ?>)">
                                <i class="fas fa-undo me-1"></i>Przywróć domyślny
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz szablony</button>
            </div>
        </form>
    </div>

    <!-- ══════════════════════════════════════════ TAB: SCHEMATY ══════════════════════════════════════════ -->
    <div class="tab-pane fade p-4" id="tab-schemas" role="tabpanel">
        <p class="text-muted small mb-3">Hasła dostępu do schematów okablowania poszczególnych urządzeń. Używane przy generowaniu dokumentów PDF.</p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <div class="row g-3">
                <?php foreach ($schemaFields as $sf): ?>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label"><?= h($sf['label']) ?></label>
                    <input type="text" name="<?= h($sf['key']) ?>" class="form-control font-monospace"
                           value="<?= h($settings[$sf['key']] ?? $sf['default']) ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <!-- hidden fields for unchanged sections -->
            <input type="hidden" name="company_name"         value="<?= h($settings['company_name'] ?? '') ?>">
            <input type="hidden" name="company_address"      value="<?= h($settings['company_address'] ?? '') ?>">
            <input type="hidden" name="company_city"         value="<?= h($settings['company_city'] ?? '') ?>">
            <input type="hidden" name="company_phone"        value="<?= h($settings['company_phone'] ?? '') ?>">
            <input type="hidden" name="company_email"        value="<?= h($settings['company_email'] ?? '') ?>">
            <input type="hidden" name="company_nip"          value="<?= h($settings['company_nip'] ?? '') ?>">
            <input type="hidden" name="company_bank_account" value="<?= h($settings['company_bank_account'] ?? '') ?>">
            <input type="hidden" name="offer_footer"         value="<?= h($settings['offer_footer'] ?? '') ?>">
            <input type="hidden" name="contract_template"    value="<?= h($settings['contract_template'] ?? '') ?>">
            <input type="hidden" name="vat_rate"             value="<?= h($settings['vat_rate'] ?? '23') ?>">
            <input type="hidden" name="smtp_enabled"         value="<?= h($settings['smtp_enabled'] ?? '0') ?>">
            <input type="hidden" name="smtp_host"            value="<?= h($settings['smtp_host'] ?? '') ?>">
            <input type="hidden" name="smtp_port"            value="<?= h($settings['smtp_port'] ?? '587') ?>">
            <input type="hidden" name="smtp_user"            value="<?= h($settings['smtp_user'] ?? '') ?>">
            <input type="hidden" name="smtp_from"            value="<?= h($settings['smtp_from'] ?? '') ?>">
            <input type="hidden" name="smtp_from_name"       value="<?= h($settings['smtp_from_name'] ?? 'FleetLink System GPS') ?>">
            <hr class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Zapisz hasła schematów</button>
        </form>
    </div>

    <!-- ══════════════════════════════════════════ TAB: SYSTEM ══════════════════════════════════════════ -->
    <div class="tab-pane fade p-4" id="tab-system" role="tabpanel">
        <div class="row g-4">
            <div class="col-md-6">
                <h6 class="fw-bold text-uppercase text-muted small mb-3"><i class="fas fa-info-circle me-2"></i>Informacje o systemie</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted pe-3" style="width:180px">Wersja aplikacji</th><td><span class="badge bg-primary"><?= defined('APP_VERSION') ? h(APP_VERSION) : '1.0.0' ?></span></td></tr>
                    <tr><th class="text-muted pe-3">Wersja PHP</th><td><?= phpversion() ?></td></tr>
                    <tr><th class="text-muted pe-3">Strefa czasowa</th><td><?= defined('APP_TIMEZONE') ? h(APP_TIMEZONE) : 'Europe/Warsaw' ?></td></tr>
                    <tr><th class="text-muted pe-3">Baza danych</th><td><?= defined('DB_NAME') ? h(DB_HOST . ' / ' . DB_NAME) : '—' ?></td></tr>
                    <tr><th class="text-muted pe-3">Ścieżka aplikacji</th><td><small class="text-muted"><?= h(__DIR__) ?></small></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="fw-bold text-uppercase text-muted small mb-3"><i class="fas fa-globe me-2"></i>Producent systemu</h6>
                <p class="mb-1"><strong>FleetLink System GPS</strong></p>
                <p class="mb-1 text-muted small">Profesjonalne systemy lokalizacji GPS dla flot pojazdów.</p>
                <a href="https://www.fleetlink.pl" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-2">
                    <i class="fas fa-external-link-alt me-1"></i>www.fleetlink.pl
                </a>
            </div>
        </div>
    </div>

</div><!-- /.tab-content -->

<script>
function resetTpl(btn, defaultHtml) {
    var ta = btn.closest('.accordion-body').querySelector('textarea');
    if (ta && confirm('Przywrócić domyślny szablon? Obecna treść zostanie zastąpiona.')) {
        ta.value = defaultHtml;
    }
}
// Restore active tab from sessionStorage
(function() {
    var stored = sessionStorage.getItem('settingsActiveTab');
    if (stored) {
        var el = document.querySelector('[data-bs-target="' + stored + '"]');
        if (el) { bootstrap.Tab.getOrCreateInstance(el).show(); }
    }
    document.querySelectorAll('#settingsTabs [data-bs-toggle="tab"]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function(e) {
            sessionStorage.setItem('settingsActiveTab', e.target.dataset.bsTarget);
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
