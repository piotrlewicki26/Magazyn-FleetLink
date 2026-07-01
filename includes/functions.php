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

function ensureDeviceChangeLogTable(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db->query("SELECT 1 FROM device_change_log LIMIT 1");
        return;
    } catch (Exception $e) {}

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS device_change_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                changed_by_user_id INTEGER DEFAULT NULL,
                source_type TEXT DEFAULT NULL,
                source_id INTEGER DEFAULT NULL,
                field_name TEXT NOT NULL,
                old_value TEXT DEFAULT NULL,
                new_value TEXT DEFAULT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_device_change_log_device ON device_change_log(device_id, changed_at DESC)");
    } else {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `device_change_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `device_id` INT UNSIGNED NOT NULL,
                `changed_by_user_id` INT UNSIGNED DEFAULT NULL,
                `source_type` VARCHAR(50) DEFAULT NULL,
                `source_id` INT UNSIGNED DEFAULT NULL,
                `field_name` VARCHAR(100) NOT NULL,
                `old_value` TEXT DEFAULT NULL,
                `new_value` TEXT DEFAULT NULL,
                `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_device_change_log_device` (`device_id`, `changed_at`),
                CONSTRAINT `fk_device_change_log_device` FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_device_change_log_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

function logDeviceFieldChange(PDO $db, int $deviceId, string $fieldName, $oldValue, $newValue, ?int $changedByUserId = null, string $sourceType = 'manual', ?int $sourceId = null): void {
    $oldNorm = $oldValue === null ? null : (string)$oldValue;
    $newNorm = $newValue === null ? null : (string)$newValue;
    if ($oldValue === $newValue) return;
    if (is_numeric($oldNorm) && is_numeric($newNorm) && (float)$oldNorm === (float)$newNorm) return;
    if ($oldNorm === $newNorm) return;

    try {
        ensureDeviceChangeLogTable($db);
        $stmt = $db->prepare("
            INSERT INTO device_change_log (device_id, changed_by_user_id, source_type, source_id, field_name, old_value, new_value)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $deviceId,
            $changedByUserId ?: null,
            $sourceType,
            $sourceId,
            $fieldName,
            $oldNorm,
            $newNorm
        ]);
    } catch (Exception $e) {
        // non-fatal history logging
    }
}

function updateDeviceFieldsWithHistory(PDO $db, int $deviceId, array $changes, ?int $changedByUserId = null, string $sourceType = 'manual', ?int $sourceId = null): bool {
    if (empty($changes)) return true;
    $beforeStmt = $db->prepare("SELECT * FROM devices WHERE id=? LIMIT 1");
    $beforeStmt->execute([$deviceId]);
    $before = $beforeStmt->fetch();
    if (!$before) return false;

    $setParts = [];
    $params = [];
    foreach ($changes as $field => $value) {
        $setParts[] = $field . "=?";
        $params[] = $value;
    }
    $params[] = $deviceId;
    $sql = "UPDATE devices SET " . implode(',', $setParts) . " WHERE id=?";
    $db->prepare($sql)->execute($params);

    $afterStmt = $db->prepare("SELECT * FROM devices WHERE id=? LIMIT 1");
    $afterStmt->execute([$deviceId]);
    $after = $afterStmt->fetch();
    if (!$after) return true;

    foreach (array_keys($changes) as $field) {
        $oldValue = $before[$field] ?? null;
        $newValue = $after[$field] ?? null;
        logDeviceFieldChange($db, $deviceId, (string)$field, $oldValue, $newValue, $changedByUserId, $sourceType, $sourceId);
    }
    return true;
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
            'w_serwisie' => ['warning-orange', 'W serwisie'],
            'uszkodzony' => ['dark', 'Uszkodzony'],
            'zamontowany'=> ['primary', 'Zamontowany'],
            'wycofany'   => ['dark', 'Wycofany'],
            'sprzedany'  => ['danger', 'Sprzedany'],
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
            'archiwum'    => ['dark', 'Archiwum'],
        ],
        'public_request' => [
            'nowe'                   => ['primary', 'Nowe'],
            'zweryfikowane'          => ['info', 'Zweryfikowane'],
            'w_realizacji'           => ['warning', 'W realizacji'],
            'zamienione_na_zlecenie' => ['success', 'Zamienione na zlecenie'],
            'odrzucone'              => ['danger', 'Odrzucone'],
            'archiwum'               => ['dark', 'Archiwum'],
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
        return '<span class="badge" style="background:#6f42c1;color:#fff">' . h($item[1]) . '</span>';
    }
    if ($item[0] === 'warning-orange') {
        return '<span class="badge" style="background:#e67e22;color:#fff">' . h($item[1]) . '</span>';
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

function generateOrderNumber($referenceDate = null) {
    $db = getDb();
    $timestamp = ($referenceDate ? strtotime((string)$referenceDate) : false) ?: time();
    $year  = date('Y', $timestamp);
    $month = date('m', $timestamp);
    $prefix = sprintf('ZL/%s/%s/', $year, $month);
    $stmt  = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(order_number, 4) AS UNSIGNED)), 0) FROM work_orders WHERE order_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $nextNumber = (int)$stmt->fetchColumn() + 1;
    return sprintf('%s%04d', $prefix, $nextNumber);
}

if (!defined('SERVICE_ORDER_PREFIX')) {
    define('SERVICE_ORDER_PREFIX', 'ZS');
}

function generateServiceOrderNumber($referenceDate = null) {
    $db = getDb();
    $timestamp = ($referenceDate ? strtotime((string)$referenceDate) : false) ?: time();
    $year  = date('Y', $timestamp);
    $month = date('m', $timestamp);
    $prefix = sprintf('%s/%s/%s/', SERVICE_ORDER_PREFIX, $year, $month);
    $stmt = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(order_number, 4) AS UNSIGNED)), 0) FROM services WHERE order_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $nextNumber = (int)$stmt->fetchColumn() + 1;
    return sprintf('%s%04d', $prefix, $nextNumber);
}

function ensurePublicRequestsTable(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $db->query("SELECT 1 FROM public_requests LIMIT 1");
        // Table exists – ensure 'archiwum' is in the status ENUM
        try {
            $db->exec("ALTER TABLE `public_requests` MODIFY COLUMN `status` ENUM('nowe','zweryfikowane','w_realizacji','zamienione_na_zlecenie','odrzucone','archiwum') NOT NULL DEFAULT 'nowe'");
        } catch (Exception $e) {}
        return;
    } catch (Exception $e) {}

    $db->exec("
        CREATE TABLE IF NOT EXISTS `public_requests` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `request_number` VARCHAR(30) NOT NULL UNIQUE,
          `request_type` ENUM('serwis','montaz','demontaz') NOT NULL DEFAULT 'serwis',
          `status` ENUM('nowe','zweryfikowane','w_realizacji','zamienione_na_zlecenie','odrzucone','archiwum') NOT NULL DEFAULT 'nowe',
          `first_name` VARCHAR(100) NOT NULL,
          `last_name` VARCHAR(100) NOT NULL,
          `phone` VARCHAR(30) NOT NULL,
          `company_name` VARCHAR(150) NOT NULL,
          `nip` VARCHAR(20) DEFAULT NULL,
          `email` VARCHAR(150) NOT NULL,
          `service_address` VARCHAR(255) NOT NULL,
          `description` TEXT NOT NULL,
          `preferred_date` DATE DEFAULT NULL,
          `vehicle_registration` VARCHAR(20) DEFAULT NULL,
          `vehicle_vin` VARCHAR(30) DEFAULT NULL,
          `vehicle_details` VARCHAR(255) DEFAULT NULL,
          `consent_contact` TINYINT(1) NOT NULL DEFAULT 0,
          `admin_notes` TEXT DEFAULT NULL,
          `client_id` INT UNSIGNED DEFAULT NULL,
          `technician_id` INT UNSIGNED DEFAULT NULL,
          `internal_order_id` INT UNSIGNED DEFAULT NULL,
          `archived` TINYINT(1) NOT NULL DEFAULT 0,
          `converted_by` INT UNSIGNED DEFAULT NULL,
          `submit_ip` VARCHAR(45) DEFAULT NULL,
          `submit_user_agent` VARCHAR(255) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_public_requests_status` (`status`),
          KEY `idx_public_requests_type` (`request_type`),
          KEY `idx_public_requests_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function generatePublicRequestNumber($referenceDate = null): string {
    $db = getDb();
    ensurePublicRequestsTable($db);
    $timestamp = ($referenceDate ? strtotime((string)$referenceDate) : false) ?: time();
    $year  = date('Y', $timestamp);
    $month = date('m', $timestamp);
    $prefix = sprintf('ZGL/%s/%s/', $year, $month);
    $stmt = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(request_number, 4) AS UNSIGNED)), 0) FROM public_requests WHERE request_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $nextNumber = (int)$stmt->fetchColumn() + 1;
    return sprintf('%s%04d', $prefix, $nextNumber);
}

function getPublicRequestTypeLabel(string $type): string {
    $map = [
        'serwis'   => 'Serwis',
        'montaz'   => 'Montaż',
        'demontaz' => 'Demontaż',
    ];
    return $map[$type] ?? ucfirst((string)$type);
}

function getPublicRequestRecipients(PDO $db): array {
    $recipients = [];

    try {
        $settingsRows = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('company_email','smtp_from','smtp_from_name')")->fetchAll();
        $settings = [];
        foreach ($settingsRows as $row) {
            $settings[$row['key']] = trim((string)$row['value']);
        }
        foreach (['company_email', 'smtp_from'] as $emailKey) {
            $email = $settings[$emailKey] ?? '';
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = [
                    'email' => $email,
                    'name'  => $settings['smtp_from_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FleetLink System GPS'),
                ];
            }
        }
    } catch (Exception $e) {}

    try {
        $usersStmt = $db->query("SELECT name, email FROM users WHERE active = 1 AND email IS NOT NULL AND email <> ''");
        foreach ($usersStmt->fetchAll() as $user) {
            $email = trim((string)($user['email'] ?? ''));
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = [
                    'email' => $email,
                    'name'  => trim((string)($user['name'] ?? '')),
                ];
            }
        }
    } catch (Exception $e) {}

    return array_values($recipients);
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
        . '<td style="font-size:11px;color:#999">FleetLink</td>'
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

        'public_request_confirmation' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#0d6efd;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Potwierdzenie zgłoszenia</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Dziękujemy za przesłanie zgłoszenia.</p>
<p>Potwierdzamy przyjęcie zgłoszenia <strong>{{REQUEST_NUMBER}}</strong>.</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Typ zgłoszenia</strong></td><td style="padding:6px 10px">{{REQUEST_TYPE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Firma</strong></td><td style="padding:6px 10px">{{COMPANY_NAME}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Adres usługi</strong></td><td style="padding:6px 10px">{{SERVICE_ADDRESS}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Preferowany termin</strong></td><td style="padding:6px 10px">{{PREFERRED_DATE}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Opis zgłoszenia</strong></td><td style="padding:6px 10px">{{DESCRIPTION}}</td></tr>
</table>
<p>Nasz zespół skontaktuje się z Państwem po weryfikacji zgłoszenia.</p>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>FleetLink - System GPS</strong></p>
' . $footer . '
</div></body></html>',

        'public_request_internal' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#198754;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Nowe zgłoszenie publiczne</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>W systemie pojawiło się nowe zgłoszenie od klienta.</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Numer zgłoszenia</strong></td><td style="padding:6px 10px"><strong>{{REQUEST_NUMBER}}</strong></td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Typ</strong></td><td style="padding:6px 10px">{{REQUEST_TYPE}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Klient</strong></td><td style="padding:6px 10px">{{CLIENT_NAME}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Firma</strong></td><td style="padding:6px 10px">{{COMPANY_NAME}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Telefon</strong></td><td style="padding:6px 10px">{{PHONE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>E-mail</strong></td><td style="padding:6px 10px">{{EMAIL}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Adres usługi</strong></td><td style="padding:6px 10px">{{SERVICE_ADDRESS}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Preferowany termin</strong></td><td style="padding:6px 10px">{{PREFERRED_DATE}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Pojazd</strong></td><td style="padding:6px 10px">{{VEHICLE}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Opis</strong></td><td style="padding:6px 10px">{{DESCRIPTION}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Panel</strong></td><td style="padding:6px 10px">{{REQUEST_URL}}</td></tr>
</table>
<br><p style="margin-top:20px">Z poważaniem,<br><strong>{{SENDER_NAME}}</strong></p>
' . $footer . '
</div></body></html>',

        'service_updated' => '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
<div style="background:#fd7e14;padding:16px 24px;border-radius:6px 6px 0 0">
  <h2 style="color:#fff;margin:0;font-size:20px">{{APP_NAME}} &mdash; Zmienione zlecenie serwisowe</h2>
</div>
<div style="padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px">
<p>Witaj <strong>{{SENDER_NAME}}</strong>,</p>
<p>Zlecenie serwisowe zostało zaktualizowane.</p>
<table style="border-collapse:collapse;width:100%;margin:12px 0">
  <tr><td style="padding:6px 10px;color:#555;width:40%"><strong>Nr zlecenia</strong></td><td style="padding:6px 10px">{{ORDER_NUMBER}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Urządzenie</strong></td><td style="padding:6px 10px">{{DEVICE}}</td></tr>
  <tr><td style="padding:6px 10px;color:#555"><strong>Technik</strong></td><td style="padding:6px 10px">{{TECHNICIAN}}</td></tr>
  <tr style="background:#f8f9fa"><td style="padding:6px 10px;color:#555"><strong>Zmienione pola</strong></td><td style="padding:6px 10px">{{CHANGES}}</td></tr>
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

    try {
        $stmt = $db->query("SELECT COUNT(*) FROM work_orders WHERE status IN ('nowe','w_trakcie')");
        $stats['active_installations'] = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $stmt = $db->query("SELECT COUNT(*) FROM installations WHERE status = 'aktywna'");
        $stats['active_installations'] = (int)$stmt->fetchColumn();
    }

    $stmt = $db->query("SELECT COUNT(*) FROM services WHERE status IN ('zaplanowany','w_trakcie')");
    $stats['pending_services'] = (int)$stmt->fetchColumn();

    try {
        ensurePublicRequestsTable($db);
        $stmt = $db->query("SELECT COUNT(*) FROM public_requests WHERE status IN ('nowe','zweryfikowane')");
        $stats['public_requests_new'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('FleetLink getDashboardStats public_requests: ' . $e->getMessage());
        $stats['public_requests_new'] = 0;
    }

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
