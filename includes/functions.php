<?php
/**
 * FleetLink System GPS - Helper Functions
 */

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sanitize($str) {
    return trim(strip_tags((string)$str));
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function flashSuccess($msg) {
    $_SESSION['flash_success'] = $msg;
}

function flashError($msg) {
    $_SESSION['flash_error'] = $msg;
}

function renderFlash() {
    $html = '<div id="fl-toast-container" aria-live="polite" aria-atomic="true"></div>';
    $toasts = '';
    if (!empty($_SESSION['flash_success'])) {
        $toasts .= '<div class="fl-toast fl-toast-success" role="alert">'
                 . '<i class="fas fa-check-circle me-2"></i>'
                 . h($_SESSION['flash_success'])
                 . '<button class="fl-toast-close" aria-label="Zamknij">×</button></div>';
        unset($_SESSION['flash_success']);
    }
    if (!empty($_SESSION['flash_error'])) {
        $toasts .= '<div class="fl-toast fl-toast-error" role="alert">'
                 . '<i class="fas fa-exclamation-circle me-2"></i>'
                 . h($_SESSION['flash_error'])
                 . '<button class="fl-toast-close" aria-label="Zamknij">×</button></div>';
        unset($_SESSION['flash_error']);
    }
    if ($toasts) {
        $html .= '<script>document.addEventListener("DOMContentLoaded",function(){'
               . 'var c=document.getElementById("fl-toast-container");'
               . 'c.innerHTML=' . json_encode($toasts) . ';'
               . 'c.querySelectorAll(".fl-toast").forEach(function(t){'
               . '  var b=t.querySelector(".fl-toast-close");'
               . '  if(b)b.onclick=function(){t.classList.add("fl-toast-hide");setTimeout(function(){t.remove();},300);};'
               . '  setTimeout(function(){t.classList.add("fl-toast-hide");setTimeout(function(){t.remove();},300);},5000);'
               . '});'
               . '});</script>';
    }
    return $html;
}

function formatPolishDate($timestamp = null) {
    if ($timestamp === null) $timestamp = time();
    $daysPL   = ['Niedziela','Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota'];
    $monthsPL = [1=>'Stycznia','Lutego','Marca','Kwietnia','Maja','Czerwca',
                    'Lipca','Sierpnia','Września','Października','Listopada','Grudnia'];
    return $daysPL[(int)date('w', $timestamp)] . ', ' . (int)date('j', $timestamp)
         . ' ' . $monthsPL[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}


function formatDate($date, $format = 'd.m.Y') {
    if (empty($date) || $date === '0000-00-00') return '—';
    try {
        return (new DateTime($date))->format($format);
    } catch (Exception $e) {
        return $date;
    }
}

function formatDateTime($datetime, $format = 'd.m.Y H:i') {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
    try {
        return (new DateTime($datetime))->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

function formatMoney($amount) {
    return number_format((float)$amount, 2, ',', ' ') . ' zł';
}

function getStatusBadge($status, $type = 'device') {
    $map = [
        'device' => [
            'nowy'       => ['success', 'Nowy'],
            'sprawny'    => ['success', 'Sprawny'],
            'w_serwisie' => ['warning', 'W serwisie'],
            'uszkodzony' => ['danger', 'Uszkodzony'],
            'zamontowany'=> ['primary', 'Zamontowany'],
            'wycofany'   => ['secondary', 'Wycofany'],
            'sprzedany'  => ['info', 'Sprzedany'],
            'dzierżawa'    => ['purple', 'Dzierżawa'],
            'do_demontazu' => ['warning-orange', 'Do demontażu'],
        ],
        'installation' => [
            'aktywna'    => ['success', 'Aktywna'],
            'zakonczona' => ['secondary', 'Zakończona'],
            'anulowana'  => ['danger', 'Anulowana'],
            'archiwum'   => ['dark', 'Archiwum'],
        ],
        'service' => [
            'zaplanowany' => ['info', 'Zaplanowany'],
            'w_trakcie'   => ['warning', 'W trakcie'],
            'zakończony'  => ['success', 'Zakończony'],
            'anulowany'   => ['secondary', 'Anulowany'],
        ],
        'offer' => [
            'robocza'    => ['secondary', 'Robocza'],
            'wyslana'    => ['info', 'Wysłana'],
            'zaakceptowana' => ['success', 'Zaakceptowana'],
            'odrzucona'  => ['danger', 'Odrzucona'],
            'anulowana'  => ['dark', 'Anulowana'],
        ],
    ];
    $item = $map[$type][$status] ?? ['secondary', ucfirst($status)];
    // 'purple' is a custom color not in Bootstrap – render inline
    if ($item[0] === 'purple') {
        return '<span class="badge" style="background:#6f42c1">' . h($item[1]) . '</span>';
    }
    if ($item[0] === 'warning-orange') {
        return '<span class="badge" style="background:#e67e22">' . h($item[1]) . '</span>';
    }
    return '<span class="badge bg-' . $item[0] . '">' . h($item[1]) . '</span>';
}

function paginate($total, $perPage, $currentPage, $url) {
    if ($total <= $perPage) return '';
    $totalPages = (int)ceil($total / $perPage);
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination pagination-sm justify-content-center">';
    $separator = strpos($url, '?') !== false ? '&' : '?';

    // Previous
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h($url . $separator . 'page=' . ($currentPage - 1)) . '">&laquo;</a></li>';
    }

    // Pages
    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);
    if ($start > 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . h($url . $separator . 'page=' . $i) . '">' . $i . '</a></li>';
    }
    if ($end < $totalPages) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';

    // Next
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h($url . $separator . 'page=' . ($currentPage + 1)) . '">&raquo;</a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

function generateOfferNumber() {
    $db = getDb();
    $year = date('Y');
    $month = date('m');
    $stmt = $db->prepare("SELECT COUNT(*) FROM offers WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?");
    $stmt->execute([$year, $month]);
    $count = (int)$stmt->fetchColumn() + 1;
    return sprintf('OF/%s/%s/%04d', $year, $month, $count);
}

function generateOrderNumber() {
    $db = getDb();
    $year  = date('Y');
    $month = date('m');
    $stmt  = $db->prepare("SELECT COUNT(*) FROM work_orders WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?");
    $stmt->execute([$year, $month]);
    $count = (int)$stmt->fetchColumn() + 1;
    return sprintf('ZL/%s/%s/%04d', $year, $month, $count);
}

function generateProtocolNumber($type = 'PP') {
    $db = getDb();
    $year = date('Y');
    $stmt = $db->prepare("SELECT COUNT(*) FROM protocols WHERE YEAR(created_at) = ? AND type = ?");
    $stmt->execute([$year, $type]);
    $count = (int)$stmt->fetchColumn() + 1;
    return sprintf('%s/%s/%04d', $type, $year, $count);
}

function sendAppEmail($to, $toName, $subject, $body, $replyTo = null) {
    require_once __DIR__ . '/config.php';

    // Check DB settings first (override config.php constants)
    $dbSmtpEnabled = false;
    $dbSmtpSettings = [];
    try {
        $db = getDb();
        $rows = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'")->fetchAll();
        foreach ($rows as $r) { $dbSmtpSettings[$r['key']] = $r['value']; }
        $dbSmtpEnabled = !empty($dbSmtpSettings['smtp_enabled']) && $dbSmtpSettings['smtp_enabled'] === '1'
                      && !empty($dbSmtpSettings['smtp_host']);
    } catch (Exception $e) {
        // DB not available — fall through to config.php constants below
        error_log('FleetLink: could not load SMTP settings from DB: ' . $e->getMessage());
    }

    if ($dbSmtpEnabled) {
        return sendSmtpEmail($to, $toName, $subject, $body, $replyTo, $dbSmtpSettings);
    }

    if (defined('MAIL_SMTP') && MAIL_SMTP) {
        return sendSmtpEmail($to, $toName, $subject, $body, $replyTo);
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    if ($replyTo) {
        $headers .= "Reply-To: $replyTo\r\n";
    }
    $headers .= "X-Mailer: FleetLink/1.0\r\n";

    $result = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    logEmail($to, $subject, $result ? 'sent' : 'failed');
    return $result;
}

function sendSmtpEmail($to, $toName, $subject, $body, $replyTo = null, $dbSettings = []) {
    // Simple SMTP implementation using fsockopen
    // Supports port 465 (implicit SSL/TLS) and port 587 (STARTTLS)
    $host     = !empty($dbSettings['smtp_host']) ? $dbSettings['smtp_host'] : (defined('MAIL_HOST') ? MAIL_HOST : 'localhost');
    $port     = !empty($dbSettings['smtp_port']) ? (int)$dbSettings['smtp_port'] : (defined('MAIL_PORT') ? (int)MAIL_PORT : 587);
    $user     = !empty($dbSettings['smtp_user']) ? $dbSettings['smtp_user'] : (defined('MAIL_USER') ? MAIL_USER : '');
    $pass     = !empty($dbSettings['smtp_pass']) ? $dbSettings['smtp_pass'] : (defined('MAIL_PASS') ? MAIL_PASS : '');
    $from     = !empty($dbSettings['smtp_from']) ? $dbSettings['smtp_from'] : (defined('MAIL_FROM') ? MAIL_FROM : '');
    $fromName = !empty($dbSettings['smtp_from_name']) ? $dbSettings['smtp_from_name'] : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'FleetLink');

    try {
        // Port 465 uses implicit SSL; port 587/25 use plain + optional STARTTLS
        $useImplicitSsl = ($port === 465);
        $socketHost = $useImplicitSsl ? 'ssl://' . $host : $host;

        $smtp = fsockopen($socketHost, $port, $errno, $errstr, 10);
        if (!$smtp) {
            logEmail($to, $subject, 'failed: ' . $errstr);
            return false;
        }

        $ehlo = ($_SERVER['HTTP_HOST'] ?? 'localhost');

        fgets($smtp, 515); // Server greeting
        fputs($smtp, "EHLO $ehlo\r\n");
        $ehloResp = '';
        while ($line = fgets($smtp, 515)) {
            $ehloResp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }

        // STARTTLS for port 587
        if (!$useImplicitSsl && strpos($ehloResp, 'STARTTLS') !== false) {
            fputs($smtp, "STARTTLS\r\n");
            fgets($smtp, 515);
            stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($smtp, "EHLO $ehlo\r\n");
            while ($line = fgets($smtp, 515)) {
                if (isset($line[3]) && $line[3] === ' ') break;
            }
        }

        // AUTH LOGIN
        if (!empty($user)) {
            fputs($smtp, "AUTH LOGIN\r\n");
            fgets($smtp, 515);
            fputs($smtp, base64_encode($user) . "\r\n");
            fgets($smtp, 515);
            fputs($smtp, base64_encode($pass) . "\r\n");
            $authResp = fgets($smtp, 515);
            if (strpos($authResp, '235') === false) {
                fclose($smtp);
                logEmail($to, $subject, 'failed: SMTP AUTH error');
                return false;
            }
        }

        fputs($smtp, "MAIL FROM: <$from>\r\n");
        fgets($smtp, 515);
        fputs($smtp, "RCPT TO: <$to>\r\n");
        fgets($smtp, 515);
        fputs($smtp, "DATA\r\n");
        fgets($smtp, 515);

        $message  = "Date: " . date('r') . "\r\n";
        $message .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n";
        $message .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$to>\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "\r\n";
        $message .= chunk_split(base64_encode($body));
        $message .= "\r\n.\r\n";

        fputs($smtp, $message);
        fgets($smtp, 515);
        fputs($smtp, "QUIT\r\n");
        fclose($smtp);
        logEmail($to, $subject, 'sent');
        return true;
    } catch (Exception $e) {
        logEmail($to, $subject, 'failed: ' . $e->getMessage());
        return false;
    }
}

function logEmail($to, $subject, $status) {
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO email_log (recipient, subject, status, sent_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$to, $subject, $status]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
}

function getEmailTemplateDefaults() {
    $footer = '<hr style="border:1px solid #eee;margin-top:20px"><table style="width:100%"><tr>'
        . '<td style="font-size:11px;color:#999">{{APP_NAME}} &mdash; System GPS</td>'
        . '<td style="font-size:11px;color:#999;text-align:right"><a href="https://www.fleetlink.pl" style="color:#999;text-decoration:none">www.fleetlink.pl</a></td>'
        . '</tr></table>';
    return [
        'general' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#0d6efd;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}}</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>{{MESSAGE}}</p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',

        'offer' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#0d6efd;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}}</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Szanowni Państwo,</p>
<p>W załączeniu przesyłamy ofertę nr <strong>{{OFFER_NUMBER}}</strong> z dnia {{DATE}}.</p>
<p>{{MESSAGE}}</p>
<p>W razie pytań prosimy o kontakt.</p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',

        'service_reminder' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#0d6efd;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Przypomnienie o serwisie</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Szanowni Państwo,</p>
<p>Informujemy, że dla pojazdu <strong>{{VEHICLE}}</strong> zaplanowany jest serwis urządzenia GPS.</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Data serwisu</strong></td><td style="padding:6px 10px">{{DATE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Opis</strong></td><td style="padding:6px 10px">{{DESCRIPTION}}</td></tr>
</table>
<p>Prosimy o kontakt w celu potwierdzenia terminu.</p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',

        'installation_created' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#0d6efd;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Nowy montaż</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Witaj <strong>{{SENDER_NAME}}</strong>,</p>
<p>Pomyślnie zarejestrowano <strong>{{COUNT}}</strong> montaż/e w systemie.</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Data montażu</strong></td><td style="padding:6px 10px">{{DATE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Technik</strong></td><td style="padding:6px 10px">{{TECHNICIAN}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Pojazd(y)</strong></td><td style="padding:6px 10px">{{VEHICLES}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Adres montażu</strong></td><td style="padding:6px 10px">{{ADDRESS}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Uwagi</strong></td><td style="padding:6px 10px">{{NOTES}}</td></tr>
</table>
<p>Szczegóły dostępne są w panelu systemu.</p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',

        'service_created' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#0d6efd;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Nowy serwis</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Witaj <strong>{{SENDER_NAME}}</strong>,</p>
<p>Pomyślnie zarejestrowano nowy serwis w systemie.</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Typ serwisu</strong></td><td style="padding:6px 10px">{{SERVICE_TYPE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Urządzenie</strong></td><td style="padding:6px 10px">{{DEVICE}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Data zaplanowana</strong></td><td style="padding:6px 10px">{{DATE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Technik</strong></td><td style="padding:6px 10px">{{TECHNICIAN}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Status</strong></td><td style="padding:6px 10px">{{STATUS}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Opis</strong></td><td style="padding:6px 10px">{{DESCRIPTION}}</td></tr>
</table>
<p>Szczegóły dostępne są w panelu systemu.</p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',

        'order_created' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#198754;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Nowe zlecenie montażowe</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Witaj <strong>{{TECHNICIAN}}</strong>,</p>
<p>Zostało Ci przydzielone nowe zlecenie montażowe. Szczegóły poniżej:</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Nr zlecenia</strong></td><td style="padding:6px 10px"><strong>{{ORDER_NUMBER}}</strong></td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Data</strong></td><td style="padding:6px 10px">{{DATE}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Klient</strong></td><td style="padding:6px 10px">{{CLIENT}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Adres montażu</strong></td><td style="padding:6px 10px">{{ADDRESS}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Uwagi</strong></td><td style="padding:6px 10px">{{NOTES}}</td></tr>
</table>
<p>Urządzenia GPS do montażu wybierzesz z listy urządzeń w systemie, przypisując je do tego zlecenia.</p>
<p>Szczegóły zlecenia: <a href="{{ORDER_URL}}">{{ORDER_URL}}</a></p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',
    ];
}

function getEmailTemplate($name, $vars = []) {
    static $dbTpls = null;

    $defaults = getEmailTemplateDefaults();

    if ($dbTpls === null) {
        $dbTpls = [];
        try {
            $db = getDb();
            $rows = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'email_tpl_%'")->fetchAll();
            foreach ($rows as $r) {
                $tplKey = substr($r['key'], strlen('email_tpl_'));
                if ($r['value'] !== '') {
                    $dbTpls[$tplKey] = $r['value'];
                }
            }
        } catch (Exception $e) {}
    }

    $templates = array_merge($defaults, $dbTpls);
    $tpl = $templates[$name] ?? $templates['general'];
    $defaultVars = ['APP_NAME' => defined('APP_NAME') ? APP_NAME : 'FleetLink System GPS'];
    $vars = array_merge($defaultVars, $vars);
    foreach ($vars as $key => $val) {
        $tpl = str_replace('{{' . $key . '}}', h($val), $tpl);
    }
    return $tpl;
}

function getInventoryStats() {
    $db = getDb();
    $stmt = $db->query("
        SELECT 
            SUM(quantity) as total_stock,
            COUNT(DISTINCT model_id) as models_in_stock,
            SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock
        FROM inventory
    ");
    return $stmt->fetch() ?: ['total_stock' => 0, 'models_in_stock' => 0, 'out_of_stock' => 0];
}

function getDashboardStats() {
    $db = getDb();
    $stats = [];

    $stmt = $db->query("SELECT COUNT(*) FROM devices WHERE status != 'wycofany'");
    $stats['total_devices'] = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM installations WHERE status = 'aktywna'");
    $stats['active_installations'] = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM services WHERE status IN ('zaplanowany','w_trakcie')");
    $stats['pending_services'] = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM offers WHERE status IN ('robocza','wyslana')");
    $stats['active_offers'] = (int)$stmt->fetchColumn();

    $inventoryStats = getInventoryStats();
    $stats['total_stock'] = (int)($inventoryStats['total_stock'] ?? 0);

    return $stats;
}

/**
 * Automatically adjust the inventory count for a model when a device's
 * status transitions in/out of the "in-stock" statuses ('nowy', 'sprawny').
 *
 * Rules:
 *   old ∈ {nowy,sprawny} → new ∉ {nowy,sprawny} : stock -1  (device leaves stock)
 *   old ∉ {nowy,sprawny} → new ∈ {nowy,sprawny} : stock +1  (device returns to stock)
 *   no transition                                 : no change
 */
function adjustInventoryForStatusChange(PDO $db, int $modelId, string $oldStatus, string $newStatus): void {
    if ($oldStatus === $newStatus) return;

    $inStock    = ['nowy', 'sprawny'];
    $wasInStock = in_array($oldStatus, $inStock, true);
    $isInStock  = in_array($newStatus, $inStock, true);

    $delta = 0;
    if ($wasInStock && !$isInStock) {
        $delta = -1; // leaving stock
    } elseif (!$wasInStock && $isInStock) {
        $delta = 1;  // returning to stock
    }
    if ($delta === 0) return;

    try {
        // Ensure the inventory row exists for this model
        $db->prepare("INSERT INTO inventory (model_id, quantity, min_quantity) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE model_id=model_id")
           ->execute([$modelId]);

        if ($delta > 0) {
            $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE model_id = ?")
               ->execute([$delta, $modelId]);
        } else {
            // Do not let quantity go below 0
            $db->prepare("UPDATE inventory SET quantity = GREATEST(0, quantity + ?) WHERE model_id = ?")
               ->execute([$delta, $modelId]);
        }

        // Record the automatic movement — use first admin user or skip if none
        $adminStmt = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
        $adminRow  = $adminStmt ? $adminStmt->fetch() : false;
        if ($adminRow) {
            $db->prepare("INSERT INTO inventory_movements (model_id, user_id, type, quantity, reason, reference_type)
                          VALUES (?, ?, ?, ?, 'Automatyczna korekta statusu urządzenia', 'auto_status')")
               ->execute([$modelId, $adminRow['id'], $delta > 0 ? 'in' : 'out', abs($delta)]);
        }
    } catch (Exception $e) {
        // Silently ignore — inventory table may not exist yet on old installs
    }
}
