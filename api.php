<?php
/**
 * FleetLink System GPS - Integration API
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function apiJson(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function apiAuthHeader(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (!empty($_SERVER['Authorization'])) return (string)$_SERVER['Authorization'];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) return (string)$headers['Authorization'];
    }
    return '';
}

function apiRequireAdmin(PDO $db): array {
    $authHeader = apiAuthHeader();
    if (stripos($authHeader, 'Basic ') !== 0) {
        header('WWW-Authenticate: Basic realm="FleetLink API"');
        apiJson(401, ['ok' => false, 'error' => 'Brak poprawnej autoryzacji Basic.']);
    }

    $decoded = base64_decode(substr($authHeader, 6), true);
    if ($decoded === false || strpos($decoded, ':') === false) {
        apiJson(401, ['ok' => false, 'error' => 'Nieprawidłowy format nagłówka Authorization.']);
    }
    [$email, $password] = explode(':', $decoded, 2);
    $email = trim((string)$email);
    $password = (string)$password;

    if ($email === '' || $password === '') {
        apiJson(401, ['ok' => false, 'error' => 'Brak loginu lub hasła API.']);
    }

    $userStmt = $db->prepare("SELECT id, name, email, role, password, active FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    if (!$user || (int)($user['active'] ?? 0) !== 1 || !password_verify($password, (string)$user['password'])) {
        apiJson(401, ['ok' => false, 'error' => 'Nieprawidłowe dane logowania API.']);
    }

    if ((string)($user['role'] ?? '') !== 'admin') {
        apiJson(403, ['ok' => false, 'error' => 'Brak uprawnień API. Wymagana rola Administrator.']);
    }

    return $user;
}

$db = getDb();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    apiJson(200, [
        'ok' => true,
        'service' => 'fleetlink-api',
        'version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
        'actions' => ['create_company', 'add_vehicle', 'activate_vehicle'],
    ]);
}
if ($method !== 'POST') {
    apiJson(405, ['ok' => false, 'error' => 'Dozwolone metody: GET, POST.']);
}

$apiUser = apiRequireAdmin($db);
$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody ?: '', true);
$input = is_array($decoded) ? $decoded : $_POST;
$action = sanitize($input['action'] ?? '');

if ($action === '') {
    apiJson(400, ['ok' => false, 'error' => 'Pole action jest wymagane.']);
}

if ($action === 'create_company') {
    $companyName = sanitize($input['company_name'] ?? '');
    $contactName = sanitize($input['contact_name'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    $address = sanitize($input['address'] ?? '');
    $city = sanitize($input['city'] ?? '');
    $postalCode = sanitize($input['postal_code'] ?? '');
    $nip = sanitize($input['nip'] ?? '');
    $notes = sanitize($input['notes'] ?? '');
    $active = (int)($input['active'] ?? 1) ? 1 : 0;

    if ($companyName === '' && $contactName === '') {
        apiJson(422, ['ok' => false, 'error' => 'Podaj company_name lub contact_name.']);
    }
    if ($contactName === '') $contactName = $companyName;
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiJson(422, ['ok' => false, 'error' => 'Nieprawidłowy adres e-mail.']);
    }

    $stmt = $db->prepare("INSERT INTO clients (company_name, contact_name, email, phone, address, city, postal_code, nip, notes, active) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$companyName, $contactName, $email, $phone, $address, $city, $postalCode, $nip, $notes, $active]);
    $newId = (int)$db->lastInsertId();

    apiJson(201, [
        'ok' => true,
        'action' => 'create_company',
        'company' => [
            'id' => $newId,
            'company_name' => $companyName,
            'contact_name' => $contactName,
            'active' => $active,
        ],
        'by_user' => ['id' => (int)$apiUser['id'], 'email' => (string)$apiUser['email']],
    ]);
}

if ($action === 'add_vehicle') {
    $clientId = (int)($input['client_id'] ?? 0);
    $registration = strtoupper(sanitize($input['registration'] ?? ''));
    $make = sanitize($input['make'] ?? '');
    $modelName = sanitize($input['model_name'] ?? '');
    $year = (int)($input['year'] ?? 0) ?: null;
    $vin = strtoupper(sanitize($input['vin'] ?? ''));
    $notes = sanitize($input['notes'] ?? '');
    $active = (int)($input['active'] ?? 1) ? 1 : 0;

    if ($clientId <= 0 || $registration === '') {
        apiJson(422, ['ok' => false, 'error' => 'Pola client_id i registration są wymagane.']);
    }

    $clientCheck = $db->prepare("SELECT id FROM clients WHERE id = ? LIMIT 1");
    $clientCheck->execute([$clientId]);
    if (!$clientCheck->fetch()) {
        apiJson(404, ['ok' => false, 'error' => 'Klient nie istnieje.']);
    }

    $dupCheck = $db->prepare("SELECT id FROM vehicles WHERE registration = ? AND client_id = ? LIMIT 1");
    $dupCheck->execute([$registration, $clientId]);
    if ($dupCheck->fetch()) {
        apiJson(409, ['ok' => false, 'error' => 'Pojazd o tym numerze rejestracyjnym już istnieje dla tego klienta.']);
    }

    $stmt = $db->prepare("INSERT INTO vehicles (client_id, registration, make, model_name, year, vin, notes, active) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active]);
    $newId = (int)$db->lastInsertId();

    apiJson(201, [
        'ok' => true,
        'action' => 'add_vehicle',
        'vehicle' => [
            'id' => $newId,
            'client_id' => $clientId,
            'registration' => $registration,
            'active' => $active,
        ],
        'by_user' => ['id' => (int)$apiUser['id'], 'email' => (string)$apiUser['email']],
    ]);
}

if ($action === 'activate_vehicle') {
    $vehicleId = (int)($input['vehicle_id'] ?? 0);
    if ($vehicleId <= 0) {
        apiJson(422, ['ok' => false, 'error' => 'Pole vehicle_id jest wymagane.']);
    }

    $vehStmt = $db->prepare("SELECT id, client_id, registration, active FROM vehicles WHERE id = ? LIMIT 1");
    $vehStmt->execute([$vehicleId]);
    $vehicle = $vehStmt->fetch();
    if (!$vehicle) {
        apiJson(404, ['ok' => false, 'error' => 'Pojazd nie istnieje.']);
    }

    if ((int)$vehicle['active'] === 1) {
        apiJson(200, [
            'ok' => true,
            'action' => 'activate_vehicle',
            'vehicle' => [
                'id' => (int)$vehicle['id'],
                'client_id' => (int)$vehicle['client_id'],
                'registration' => (string)$vehicle['registration'],
                'active' => 1,
            ],
            'message' => 'Pojazd był już aktywny.',
        ]);
    }

    $db->prepare("UPDATE vehicles SET active = 1 WHERE id = ?")->execute([$vehicleId]);

    apiJson(200, [
        'ok' => true,
        'action' => 'activate_vehicle',
        'vehicle' => [
            'id' => (int)$vehicle['id'],
            'client_id' => (int)$vehicle['client_id'],
            'registration' => (string)$vehicle['registration'],
            'active' => 1,
        ],
        'by_user' => ['id' => (int)$apiUser['id'], 'email' => (string)$apiUser['email']],
    ]);
}

apiJson(400, ['ok' => false, 'error' => 'Nieobsługiwana akcja API.']);

