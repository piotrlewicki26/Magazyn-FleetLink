<?php
/**
 * FleetLink System GPS - Integration API
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
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

function apiRequireIntegrationToken(PDO $db): void {
    $cfgStmt = $db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('api_enabled','api_token')");
    $cfgStmt->execute();
    $cfgRows = $cfgStmt->fetchAll();
    $cfg = [];
    foreach ($cfgRows as $row) {
        $cfg[$row['key']] = (string)$row['value'];
    }

    if (($cfg['api_enabled'] ?? '0') !== '1') {
        apiJson(403, ['ok' => false, 'error' => 'API jest wyłączone w ustawieniach systemu.']);
    }
    $expectedToken = trim((string)($cfg['api_token'] ?? ''));
    if ($expectedToken === '') {
        apiJson(503, ['ok' => false, 'error' => 'Brak skonfigurowanego tokenu API w ustawieniach.']);
    }

    $authHeader = apiAuthHeader();
    if (stripos($authHeader, 'Bearer ') !== 0) {
        apiJson(401, ['ok' => false, 'error' => 'Wymagany token API w nagłówku żądania.']);
    }
    $providedToken = trim((string)substr($authHeader, 7));
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        apiJson(401, ['ok' => false, 'error' => 'Nieprawidłowy token API.']);
    }
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

$apiContentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody ?: '', true);
$jsonError = json_last_error();
if (strpos($apiContentType, 'application/json') !== false && trim((string)$rawBody) !== '' && $jsonError !== JSON_ERROR_NONE) {
    apiJson(400, ['ok' => false, 'error' => 'Nieprawidłowy JSON w treści żądania.']);
}
$input = is_array($decoded) ? $decoded : $_POST;
apiRequireIntegrationToken($db);
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

    $stmt = $db->prepare("
        INSERT INTO vehicles (client_id, registration, make, model_name, year, vin, notes, active)
        SELECT ?,?,?,?,?,?,?,?
        WHERE NOT EXISTS (
            SELECT 1
            FROM vehicles
            WHERE registration = ? AND client_id = ?
            LIMIT 1
        )
    ");
    $stmt->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active, $registration, $clientId]);
    if ((int)$stmt->rowCount() < 1) {
        apiJson(409, ['ok' => false, 'error' => 'Pojazd o tym numerze rejestracyjnym już istnieje dla tego klienta.']);
    }
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
    ]);
}

apiJson(400, ['ok' => false, 'error' => 'Nieobsługiwana akcja API.']);
