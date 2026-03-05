<?php
/**
 * FleetLink Magazyn - Helper Functions
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
    $html = '';
    if (!empty($_SESSION['flash_success'])) {
        $html .= '<div class="alert alert-success alert-dismissible fade show" role="alert">'
               . h($_SESSION['flash_success'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['flash_success']);
    }
    if (!empty($_SESSION['flash_error'])) {
        $html .= '<div class="alert alert-danger alert-dismissible fade show" role="alert">'
               . h($_SESSION['flash_error'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['flash_error']);
    }
    return $html;
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
        ],
        'installation' => [
            'aktywna'    => ['success', 'Aktywna'],
            'zakonczona' => ['secondary', 'Zakończona'],
            'anulowana'  => ['danger', 'Anulowana'],
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

function sendSmtpEmail($to, $toName, $subject, $body, $replyTo = null) {
    // Simple SMTP implementation using fsockopen
    // Supports port 465 (implicit SSL/TLS) and port 587 (STARTTLS)
    $host     = defined('MAIL_HOST') ? MAIL_HOST : 'localhost';
    $port     = defined('MAIL_PORT') ? (int)MAIL_PORT : 587;
    $user     = defined('MAIL_USER') ? MAIL_USER : '';
    $pass     = defined('MAIL_PASS') ? MAIL_PASS : '';
    $from     = defined('MAIL_FROM') ? MAIL_FROM : '';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'FleetLink';

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

function getEmailTemplate($name, $vars = []) {
    $templates = [
        'offer' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
<h2 style="color:#0d6efd">{{APP_NAME}}</h2>
<p>Szanowni Państwo,</p>
<p>W załączeniu przesyłamy ofertę nr <strong>{{OFFER_NUMBER}}</strong> z dnia {{DATE}}.</p>
<p>{{MESSAGE}}</p>
<p>W razie pytań prosimy o kontakt.</p>
<br><p>Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
<hr style="border:1px solid #eee"><p style="font-size:11px;color:#999">FleetLink Magazyn - System zarządzania urządzeniami GPS</p>
</body></html>',
        'service_reminder' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
<h2 style="color:#0d6efd">{{APP_NAME}} - Przypomnienie o serwisie</h2>
<p>Szanowni Państwo,</p>
<p>Informujemy, że dla pojazdu <strong>{{VEHICLE}}</strong> zaplanowany jest serwis urządzenia GPS.</p>
<p><strong>Data:</strong> {{DATE}}<br><strong>Opis:</strong> {{DESCRIPTION}}</p>
<p>Prosimy o kontakt w celu potwierdzenia terminu.</p>
<br><p>Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
<hr style="border:1px solid #eee"><p style="font-size:11px;color:#999">FleetLink Magazyn</p>
</body></html>',
        'general' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
<h2 style="color:#0d6efd">{{APP_NAME}}</h2>
<p>{{MESSAGE}}</p>
<br><p>Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
<hr style="border:1px solid #eee"><p style="font-size:11px;color:#999">FleetLink Magazyn</p>
</body></html>',
    ];
    $tpl = $templates[$name] ?? $templates['general'];
    $defaultVars = ['APP_NAME' => defined('APP_NAME') ? APP_NAME : 'FleetLink Magazyn'];
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
