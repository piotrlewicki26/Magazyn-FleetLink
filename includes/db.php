<?php
/**
 * FleetLink System GPS - Database Connection
 */

if (!defined('DB_HOST')) {
    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
        header('Location: ' . getBaseUrl() . 'setup.php');
        exit;
    }
    require_once $configPath;
}

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(dirname($script), '/\\');
    return $protocol . '://' . $host . $dir . '/';
}

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:20px;color:red;"><h2>Błąd połączenia z bazą danych</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>');
        }
        // Auto-apply schema migrations that may not have been run manually.
        // Each block is idempotent – it checks before altering.
        try {
            // v7: BLE device fields (ble_id, major, minor, mac_address)
            $chk = $pdo->query("SHOW COLUMNS FROM `devices` LIKE 'ble_id'");
            if ($chk && $chk->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `devices`
                    ADD COLUMN `ble_id`      VARCHAR(100)      DEFAULT NULL AFTER `sim_number`,
                    ADD COLUMN `major`       SMALLINT UNSIGNED DEFAULT NULL AFTER `ble_id`,
                    ADD COLUMN `minor`       SMALLINT UNSIGNED DEFAULT NULL AFTER `major`,
                    ADD COLUMN `mac_address` VARCHAR(17)       DEFAULT NULL AFTER `minor`");
            }
        } catch (PDOException $e) { /* devices table may not exist yet (fresh install) */ }
    }
    return $pdo;
}
