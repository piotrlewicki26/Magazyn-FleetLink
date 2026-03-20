<?php
/**
 * FleetLink Magazyn - Authentication & Session Management
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . getBaseUrl() . 'login.php');
        exit;
    }
    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regenerated']) || time() - $_SESSION['last_regenerated'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $_SESSION['flash_error'] = 'Brak uprawnień do tej strony.';
        header('Location: ' . getBaseUrl() . 'dashboard.php');
        exit;
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'name'     => $_SESSION['user_name'] ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'role'     => $_SESSION['user_role'] ?? 'user',
    ];
}

function loginUser(array $user) {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['last_regenerated'] = time();
}

function logoutUser() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

function checkLoginAttempts($email) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $key = md5(strtolower($email));
    $now = time();
    // Clean old attempts (older than 15 minutes)
    if (isset($_SESSION['login_attempts'][$key])) {
        $_SESSION['login_attempts'][$key] = array_filter(
            $_SESSION['login_attempts'][$key],
            fn($t) => ($now - $t) < 900
        );
    }
    $attempts = count($_SESSION['login_attempts'][$key] ?? []);
    return $attempts < 5;
}

function recordLoginAttempt($email) {
    $key = md5(strtolower($email));
    $_SESSION['login_attempts'][$key][] = time();
}

function clearLoginAttempts($email) {
    $key = md5(strtolower($email));
    unset($_SESSION['login_attempts'][$key]);
}
