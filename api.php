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
    try {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        echo '{"ok":false,"error":"Błąd serializacji odpowiedzi JSON."}';
    }
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

function hasVehiclesApiUniqueIndex(PDO $db): bool {
    try {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $idxRows = $db->query("PRAGMA index_list('vehicles')")->fetchAll();
            foreach ($idxRows as $idx) {
                if ((int)($idx['unique'] ?? 0) !== 1) continue;
                $idxName = (string)($idx['name'] ?? '');
                if ($idxName === '') continue;
                $idxCols = $db->query("PRAGMA index_info(" . $db->quote($idxName) . ")")->fetchAll();
                $colNames = array_map(static fn(array $c): string => (string)($c['name'] ?? ''), $idxCols);
                sort($colNames);
                if ($colNames === ['client_id', 'registration']) {
                    return true;
                }
            }
            return false;
        }

        $existsStmt = $db->prepare("
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'vehicles'
              AND non_unique = 0
            GROUP BY index_name
            HAVING COUNT(*) = 2
               AND SUM(CASE WHEN column_name='client_id' THEN 1 ELSE 0 END) = 1
               AND SUM(CASE WHEN column_name='registration' THEN 1 ELSE 0 END) = 1
            LIMIT 1
        ");
        $existsStmt->execute();
        return (bool)$existsStmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
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
    if ($authHeader === '') {
        apiJson(401, ['ok' => false, 'error' => 'Wymagany token API w nagłówku żądania.']);
    }
    $authHeaderTrim = trim((string)$authHeader);
    if (stripos($authHeaderTrim, 'Bearer ') === 0) {
        $providedToken = trim((string)substr($authHeaderTrim, 7));
    } elseif (preg_match('/^\S+\s+/', $authHeaderTrim)) {
        apiJson(401, ['ok' => false, 'error' => 'Nieobsługiwany schemat autoryzacji API.']);
    } else {
        $providedToken = $authHeaderTrim;
    }
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        apiJson(401, ['ok' => false, 'error' => 'Nieprawidłowy token API.']);
    }
}

$db = getDb();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    apiRequireIntegrationToken($db);
    apiJson(200, [
        'ok' => true,
        'service' => 'fleetlink-api',
    ]);
}
if ($method !== 'POST') {
    apiJson(405, ['ok' => false, 'error' => 'Dozwolone metody: GET, POST.']);
}

$apiContentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody ?: '', true);
$jsonError = json_last_error();
$hasRawBody = trim((string)$rawBody) !== '';
$isJsonContentType = strpos($apiContentType, 'application/json') !== false;
if ($hasRawBody) {
    if ($isJsonContentType) {
        if ($jsonError !== JSON_ERROR_NONE) {
            apiJson(400, ['ok' => false, 'error' => 'Nieprawidłowy JSON w treści żądania.']);
        }
        if (!is_array($decoded)) {
            apiJson(400, ['ok' => false, 'error' => 'Body JSON musi być obiektem.']);
        }
        $input = $decoded;
    } elseif (strpos($apiContentType, 'application/x-www-form-urlencoded') !== false || strpos($apiContentType, 'multipart/form-data') !== false) {
        $input = $_POST;
    } else {
        apiJson(415, ['ok' => false, 'error' => 'Nieobsługiwany typ treści żądania.']);
    }
} else {
    if ($isJsonContentType) {
        apiJson(400, ['ok' => false, 'error' => 'Puste body JSON.']);
    }
    $input = $_POST;
}
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
    if (!hasVehiclesApiUniqueIndex($db)) {
        apiJson(500, ['ok' => false, 'error' => 'Brak wymaganego unikalnego indeksu dla pojazdów (client_id + registration).']);
    }

    $clientCheck = $db->prepare("SELECT id, active FROM clients WHERE id = ? LIMIT 1");
    $clientCheck->execute([$clientId]);
    $client = $clientCheck->fetch();
    if (!$client) {
        apiJson(404, ['ok' => false, 'error' => 'Klient nie istnieje.']);
    }
    if ((int)($client['active'] ?? 0) !== 1) {
        apiJson(409, ['ok' => false, 'error' => 'Nie można dodać pojazdu — przypisana firma jest nieaktywna.']);
    }

    try {
        $stmt = $db->prepare("INSERT INTO vehicles (client_id, registration, make, model_name, year, vin, notes, active) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$clientId, $registration, $make, $modelName, $year, $vin, $notes, $active]);
    } catch (PDOException $e) {
        $sqlState = (string)$e->getCode();
        $driverErrorCode = (int)($e->errorInfo[1] ?? 0);
        $isDuplicate = in_array($sqlState, ['23000', '23505'], true) || in_array($driverErrorCode, [1062, 1555, 2067], true);
        if ($isDuplicate) {
            apiJson(409, ['ok' => false, 'error' => 'Pojazd o tym numerze rejestracyjnym już istnieje dla tego klienta.']);
        }
        apiJson(500, ['ok' => false, 'error' => 'Nie udało się dodać pojazdu.']);
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

    $vehStmt = $db->prepare("
        SELECT v.id, v.client_id, v.registration, v.active, c.id AS client_exists, COALESCE(c.active, 0) AS client_active
        FROM vehicles v
        LEFT JOIN clients c ON c.id = v.client_id
        WHERE v.id = ?
        LIMIT 1
    ");
    $vehStmt->execute([$vehicleId]);
    $vehicle = $vehStmt->fetch();
    if (!$vehicle) {
        apiJson(404, ['ok' => false, 'error' => 'Pojazd nie istnieje.']);
    }
    if ((int)$vehicle['client_id'] <= 0) {
        apiJson(409, ['ok' => false, 'error' => 'Nie można aktywować pojazdu — brak przypisanej firmy.']);
    }
    if ((int)$vehicle['client_id'] > 0 && empty($vehicle['client_exists'])) {
        apiJson(409, ['ok' => false, 'error' => 'Nie można aktywować pojazdu — przypisany klient nie istnieje.']);
    }
    if ((int)$vehicle['client_id'] > 0 && (int)$vehicle['client_active'] !== 1) {
        apiJson(409, ['ok' => false, 'error' => 'Nie można aktywować pojazdu — przypisana firma jest nieaktywna.']);
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
