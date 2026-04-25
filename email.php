<?php
/**
 * FleetLink Magazyn - Email Sending
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();
$user = getCurrentUser();
$preOfferId = (int)($_GET['offer'] ?? 0);

$preOffer = null;
if ($preOfferId) {
    $stmt = $db->prepare("SELECT o.*, c.contact_name, c.email as client_email FROM offers o LEFT JOIN clients c ON c.id=o.client_id WHERE o.id=?");
    $stmt->execute([$preOfferId]);
    $preOffer = $stmt->fetch();
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Błąd bezpieczeństwa.';
    } else {
        $to        = sanitize($_POST['to'] ?? '');
        $toName    = sanitize($_POST['to_name'] ?? '');
        $subject   = sanitize($_POST['subject'] ?? '');
        $message   = sanitize($_POST['message'] ?? '');
        $template  = sanitize($_POST['template'] ?? 'general');

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = 'Podaj poprawny adres e-mail odbiorcy.';
        } elseif (empty($subject)) {
            $error = 'Podaj temat wiadomości.';
        } elseif (empty($message)) {
            $error = 'Wpisz treść wiadomości.';
        } else {
            $body = getEmailTemplate($template, [
                'MESSAGE'     => nl2br(h($message)),
                'SENDER_NAME' => $user['name'],
                'DATE'        => date('d.m.Y'),
                'OFFER_NUMBER'=> sanitize($_POST['offer_number'] ?? ''),
            ]);

            if (sendAppEmail($to, $toName, $subject, $body)) {
                $success = "Wiadomość została wysłana do $to.";
            } else {
                $error = 'Nie udało się wysłać wiadomości. Sprawdź konfigurację e-mail w ustawieniach.';
            }
        }
    }
}

// Recent email log
$emailLog = $db->query("SELECT * FROM email_log ORDER BY sent_at DESC LIMIT 20")->fetchAll();

// Client list for quick select
$clients = $db->query("SELECT id, contact_name, company_name, email FROM clients WHERE active=1 AND email IS NOT NULL AND email != '' ORDER BY company_name, contact_name")->fetchAll();

$activePage = 'email';
$pageTitle = 'Wyślij e-mail';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-envelope me-2 text-primary"></i>Wyślij e-mail</h1>
</div>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">Nowa wiadomość</div>
            <div class="card-body">
                <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= h($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required-star">Adres e-mail odbiorcy</label>
                            <input type="email" name="to" class="form-control" required value="<?= h($_POST['to'] ?? $preOffer['client_email'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Lub wybierz klienta</label>
                            <select class="form-select" onchange="document.querySelector('[name=to]').value=this.value.split('|')[0]; document.querySelector('[name=to_name]').value=this.value.split('|')[1]||''">
                                <option value="">— wybierz —</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?= h($c['email']) ?>|<?= h($c['company_name'] ?: $c['contact_name']) ?>">
                                    <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nazwa odbiorcy</label>
                            <input type="text" name="to_name" class="form-control" value="<?= h($_POST['to_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Szablon</label>
                            <select name="template" class="form-select" onchange="applyTemplate(this.value)">
                                <option value="general" <?= ($_POST['template'] ?? '') === 'general' ? 'selected' : '' ?>>Ogólna</option>
                                <option value="offer" <?= ($_POST['template'] ?? '') === 'offer' ? 'selected' : '' ?>>Oferta</option>
                                <option value="service_reminder" <?= ($_POST['template'] ?? '') === 'service_reminder' ? 'selected' : '' ?>>Przypomnienie o serwisie</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label required-star">Temat</label>
                            <input type="text" name="subject" class="form-control" required id="emailSubject"
                                   value="<?= h($_POST['subject'] ?? ($preOffer ? 'Oferta nr ' . $preOffer['offer_number'] : '')) ?>">
                        </div>
                        <?php if ($preOffer): ?>
                        <input type="hidden" name="offer_number" value="<?= h($preOffer['offer_number']) ?>">
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label required-star">Treść wiadomości</label>
                            <textarea name="message" class="form-control" id="emailMessage" rows="8"><?= h($_POST['message'] ?? '') ?></textarea>
                            <small class="text-muted">Treść zostanie osadzona w szablonie HTML z nagłówkiem firmy.</small>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2"></i>Wyślij wiadomość
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><i class="fas fa-history me-2"></i>Historia wysłanych (ostatnie 20)</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Odbiorca</th><th>Temat</th><th>Status</th><th>Data</th></tr></thead>
                    <tbody>
                        <?php foreach ($emailLog as $log): ?>
                        <tr>
                            <td class="small"><?= h($log['recipient']) ?></td>
                            <td class="small"><?= h(mb_substr($log['subject'], 0, 30)) ?><?= mb_strlen($log['subject']) > 30 ? '...' : '' ?></td>
                            <td>
                                <?php if ($log['status'] === 'sent'): ?>
                                <span class="badge bg-success">Wysłano</span>
                                <?php else: ?>
                                <span class="badge bg-danger" title="<?= h($log['status']) ?>">Błąd</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= formatDateTime($log['sent_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($emailLog)): ?><tr><td colspan="4" class="text-muted text-center">Brak historii</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const templates = {
    general: { subject: '', message: 'Szanowni Państwo,\n\n\n\nZ poważaniem,' },
    offer: { subject: 'Oferta handlowa — FleetLink System GPS', message: 'Szanowni Państwo,\n\nW załączeniu przesyłamy naszą ofertę dotyczącą urządzeń GPS do lokalizacji pojazdów.\n\nProsimy o zapoznanie się z przedstawioną propozycją i ewentualny kontakt w przypadku pytań.\n\nZ poważaniem,' },
    service_reminder: { subject: 'Przypomnienie o serwisie urządzenia GPS', message: 'Szanowni Państwo,\n\nInformujemy, że nadchodzi termin serwisu urządzenia GPS zamontowanego w Państwa pojeździe.\n\nProsimy o kontakt w celu ustalenia terminu.\n\nZ poważaniem,' }
};
function applyTemplate(name) {
    const tpl = templates[name];
    if (tpl) {
        if (!document.getElementById('emailSubject').value || confirm('Zastąpić temat szablonem?')) {
            document.getElementById('emailSubject').value = tpl.subject;
        }
        if (!document.getElementById('emailMessage').value || confirm('Zastąpić treść szablonem?')) {
            document.getElementById('emailMessage').value = tpl.message;
        }
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
