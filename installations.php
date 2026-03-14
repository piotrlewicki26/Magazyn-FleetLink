<?php
/**
 * FleetLink Magazyn - Installation Management (Montaż/Demontaż)
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();
$action = sanitize($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'installations.php'); }
    $postAction         = sanitize($_POST['action'] ?? '');
    $deviceId           = (int)($_POST['device_id'] ?? 0);
    $vehicleId          = (int)($_POST['vehicle_id'] ?? 0);
    $clientId           = (int)($_POST['client_id'] ?? 0) ?: null;
    $technicianId       = (int)($_POST['technician_id'] ?? 0) ?: null;
    $installationDate   = sanitize($_POST['installation_date'] ?? '');
    $uninstallationDate = sanitize($_POST['uninstallation_date'] ?? '') ?: null;
    $status             = sanitize($_POST['status'] ?? 'aktywna');
    // $locationInVehicle is a single string used for both 'add' (shared across all devices in batch) and 'edit'.
    $locationInVehicle    = is_array($_POST['location_in_vehicle'] ?? null) ? '' : sanitize($_POST['location_in_vehicle'] ?? '');
    $installationAddress  = sanitize($_POST['installation_address'] ?? '');
    $notes                = sanitize($_POST['notes'] ?? '');
    $currentUser        = getCurrentUser();

    if (!$technicianId) $technicianId = $currentUser['id'];

    // AJAX: quick-create client
    if ($postAction === 'quick_add_client') {
        header('Content-Type: application/json');
        $contactName = sanitize($_POST['contact_name'] ?? '');
        $companyName = sanitize($_POST['company_name'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $email       = sanitize($_POST['email'] ?? '');
        if (empty($contactName)) { echo json_encode(['error' => 'Imię i nazwisko kontaktu jest wymagane.']); exit; }
        $db->prepare("INSERT INTO clients (contact_name, company_name, phone, email) VALUES (?,?,?,?)")
           ->execute([$contactName, $companyName, $phone, $email]);
        $newClientId = $db->lastInsertId();
        echo json_encode(['id' => $newClientId, 'label' => ($companyName ? $companyName . ' — ' : '') . $contactName]);
        exit;
    }

    if ($postAction === 'add') {
        // Per-row arrays
        $deviceModes          = is_array($_POST['device_mode'] ?? null)          ? $_POST['device_mode']          : ['auto'];
        $modelIds             = is_array($_POST['model_id'] ?? null)             ? $_POST['model_id']             : [0];
        $deviceIdsManual      = is_array($_POST['device_id_manual'] ?? null)     ? $_POST['device_id_manual']     : [0];
        $vehicleRegistrations = is_array($_POST['vehicle_registration'] ?? null) ? $_POST['vehicle_registration'] : [''];

        if (empty($deviceModes) || empty($installationDate)) {
            flashError('Wybierz co najmniej jedno urządzenie i podaj datę montażu.');
            redirect(getBaseUrl() . 'installations.php?action=add');
        }

        $allocatedDeviceIds       = []; // track devices allocated in this batch
        $allocatedInstallationIds = []; // track created installation IDs for print
        $vehicleCache             = []; // registration plate → vehicle_id (avoid duplicate INSERTs)
        $db->beginTransaction();
        try {
            foreach ($deviceModes as $idx => $mode) {
                $mode = ($mode === 'manual') ? 'manual' : 'auto';

                // Per-row vehicle registration
                $reg = strtoupper(trim(sanitize($vehicleRegistrations[$idx] ?? '')));
                if (empty($reg)) {
                    throw new Exception('Wiersz ' . ($idx + 1) . ': numer rejestracyjny pojazdu jest wymagany.');
                }

                // Find or auto-create vehicle for this row
                if (isset($vehicleCache[$reg])) {
                    $rowVehicleId = $vehicleCache[$reg];
                } else {
                    $vStmt = $db->prepare("SELECT id FROM vehicles WHERE registration=? LIMIT 1");
                    $vStmt->execute([$reg]);
                    $vRow = $vStmt->fetch();
                    if ($vRow) {
                        $rowVehicleId = $vRow['id'];
                    } else {
                        $db->prepare("INSERT INTO vehicles (registration, client_id) VALUES (?,?)")
                           ->execute([$reg, $clientId]);
                        $rowVehicleId = $db->lastInsertId();
                    }
                    $vehicleCache[$reg] = $rowVehicleId;
                }

                if ($mode === 'manual') {
                    $dId = (int)($deviceIdsManual[$idx] ?? 0);
                    if (!$dId) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': nie wybrano urządzenia.');
                    }
                    $devStmt = $db->prepare("SELECT id, model_id, status FROM devices WHERE id=? AND status IN ('nowy','sprawny') LIMIT 1");
                    $devStmt->execute([$dId]);
                    $devRow = $devStmt->fetch();
                    if (!$devRow) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': wybrane urządzenie jest niedostępne lub zmieniło status.');
                    }
                    if (in_array($dId, $allocatedDeviceIds)) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': to urządzenie zostało już wybrane w tym montażu.');
                    }
                } else {
                    $mId = (int)($modelIds[$idx] ?? 0);
                    if (!$mId) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': nie wybrano modelu urządzenia.');
                    }
                    if (!empty($allocatedDeviceIds)) {
                        $placeholders = implode(',', array_fill(0, count($allocatedDeviceIds), '?'));
                        $devStmt = $db->prepare("SELECT id, model_id, status FROM devices WHERE model_id=? AND status IN ('nowy','sprawny') AND id NOT IN ($placeholders) LIMIT 1");
                        $devStmt->execute(array_merge([$mId], $allocatedDeviceIds));
                    } else {
                        $devStmt = $db->prepare("SELECT id, model_id, status FROM devices WHERE model_id=? AND status IN ('nowy','sprawny') LIMIT 1");
                        $devStmt->execute([$mId]);
                    }
                    $devRow = $devStmt->fetch();
                    if (!$devRow) {
                        throw new Exception('Wiersz ' . ($idx + 1) . ': brak dostępnych urządzeń dla wybranego modelu.');
                    }
                    $dId = $devRow['id'];
                }

                // Check not already actively installed
                $checkStmt = $db->prepare("SELECT id FROM installations WHERE device_id=? AND status='aktywna' LIMIT 1");
                $checkStmt->execute([$dId]);
                if ($checkStmt->fetch()) {
                    throw new Exception('Wiersz ' . ($idx + 1) . ': urządzenie jest już aktywnie zamontowane.');
                }

                // Create installation record
                $db->prepare("INSERT INTO installations (device_id, vehicle_id, client_id, technician_id, installation_date, uninstallation_date, status, location_in_vehicle, installation_address, notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$dId, $rowVehicleId, $clientId, $technicianId, $installationDate, $uninstallationDate, $status, $locationInVehicle, $installationAddress, $notes]);
                $allocatedInstallationIds[] = (int)$db->lastInsertId();

                // Update device status + auto inventory adjust
                $oldStatus = $devRow['status'];
                $db->prepare("UPDATE devices SET status='zamontowany' WHERE id=?")->execute([$dId]);
                adjustInventoryForStatusChange($db, $devRow['model_id'], $oldStatus, 'zamontowany');

                $allocatedDeviceIds[] = $dId;
            }
            // Tag all installations in a multi-device batch with a shared batch_id
            if (count($allocatedInstallationIds) > 1) {
                $batchId = $allocatedInstallationIds[0];
                $phBatch = implode(',', array_fill(0, count($allocatedInstallationIds), '?'));
                $db->prepare("UPDATE installations SET batch_id=? WHERE id IN ($phBatch)")
                   ->execute(array_merge([$batchId], $allocatedInstallationIds));
            }
            $db->commit();
            $n = count($allocatedDeviceIds);
            // Process accessory pickups submitted with the installation form
            $accPickupIds  = array_map('intval', (array)($_POST['inst_acc'] ?? []));
            $accPickupQtys = array_map('intval', (array)($_POST['inst_acc_qty'] ?? []));
            $accPickupNotes= (array)($_POST['inst_acc_note'] ?? []);
            $firstInstId   = $allocatedInstallationIds[0] ?? null;
            $curUser       = getCurrentUser();
            if ($firstInstId && !empty($accPickupIds)) {
                try {
                    foreach ($accPickupIds as $ai => $acid) {
                        $aqty = max(0, (int)($accPickupQtys[$ai] ?? 0));
                        if (!$acid || !$aqty) continue;
                        $noteVal = sanitize($accPickupNotes[$ai] ?? '');
                        $db->prepare("INSERT INTO accessory_issues (accessory_id, installation_id, user_id, quantity, notes) VALUES (?,?,?,?,?)")
                           ->execute([$acid, $firstInstId, $curUser['id'], $aqty, $noteVal ?: null]);
                    }
                } catch (Exception $e) { /* non-fatal: continue */ }
            }
            flashSuccess('Zarejestrowano ' . $n . ' montaż' . ($n === 1 ? '' : 'e') . ' pomyślnie.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
            redirect(getBaseUrl() . 'installations.php?action=add');
        }
        // Redirect to print order (IDs passed via URL so the page survives a refresh)
        redirect(getBaseUrl() . 'installations.php?action=print_batch&ids=' . implode(',', $allocatedInstallationIds));

    } elseif ($postAction === 'uninstall') {
        $instId = (int)($_POST['id'] ?? 0);
        $uninstDate = sanitize($_POST['uninstallation_date'] ?? date('Y-m-d'));
        $devId = (int)($_POST['device_id'] ?? 0);
        $db->beginTransaction();
        try {
            // Fetch device info inside transaction to avoid stale data
            $devInfoStmt = $db->prepare("SELECT model_id, status FROM devices WHERE id=? FOR UPDATE");
            $devInfoStmt->execute([$devId]);
            $devInfo = $devInfoStmt->fetch();
            $db->prepare("UPDATE installations SET status='zakonczona', uninstallation_date=? WHERE id=?")->execute([$uninstDate, $instId]);
            $db->prepare("UPDATE devices SET status='sprawny' WHERE id=?")->execute([$devId]);
            // Restore device to stock
            if ($devInfo) {
                adjustInventoryForStatusChange($db, $devInfo['model_id'], $devInfo['status'], 'sprawny');
            }
            $db->commit();
            flashSuccess('Demontaż zarejestrowany.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php?action=view&id=' . $instId);

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE installations SET vehicle_id=?, client_id=?, technician_id=?, installation_date=?, uninstallation_date=?, status=?, location_in_vehicle=?, installation_address=?, notes=? WHERE id=?")
           ->execute([$vehicleId, $clientId, $technicianId, $installationDate, $uninstallationDate, $status, $locationInVehicle, $installationAddress, $notes, $editId]);
        flashSuccess('Montaż zaktualizowany.');
        redirect(getBaseUrl() . 'installations.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        if (!isAdmin()) { flashError('Kasowanie zleceń jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'installations.php'); }
        $delId = (int)($_POST['id'] ?? 0);
        $devId = (int)($_POST['device_id'] ?? 0);
        $db->beginTransaction();
        try {
            // Fetch device info inside transaction to avoid stale data
            if ($devId) {
                $delDevStmt = $db->prepare("SELECT model_id, status FROM devices WHERE id=? FOR UPDATE");
                $delDevStmt->execute([$devId]);
                $delDevInfo = $delDevStmt->fetch();
            } else {
                $delDevInfo = false;
            }
            $db->prepare("DELETE FROM installations WHERE id=?")->execute([$delId]);
            if ($devId) {
                $db->prepare("UPDATE devices SET status='sprawny' WHERE id=? AND status='zamontowany'")->execute([$devId]);
                // Restore device to stock
                if ($delDevInfo) {
                    adjustInventoryForStatusChange($db, $delDevInfo['model_id'], $delDevInfo['status'], 'sprawny');
                }
            }
            $db->commit();
            flashSuccess('Montaż usunięty.');
        } catch (PDOException $e) {
            $db->rollBack();
            flashError('Nie można usunąć — powiązane rekordy istnieją.');
        }
        redirect(getBaseUrl() . 'installations.php');

    } elseif ($postAction === 'delete_batch') {
        if (!isAdmin()) { flashError('Kasowanie grup jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'installations.php'); }
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if (!$batchId) { flashError('Nieprawidłowy identyfikator grupy.'); redirect(getBaseUrl() . 'installations.php'); }
        $db->beginTransaction();
        try {
            $batchInstStmt = $db->prepare("SELECT id, device_id FROM installations WHERE batch_id=? OR (id=? AND batch_id IS NULL)");
            $batchInstStmt->execute([$batchId, $batchId]);
            $batchInsts = $batchInstStmt->fetchAll();
            if (empty($batchInsts)) { throw new Exception('Grupa montażu nie istnieje.'); }
            foreach ($batchInsts as $binst) {
                $bdevId = (int)$binst['device_id'];
                if ($bdevId) {
                    $bdevInfoStmt = $db->prepare("SELECT model_id, status FROM devices WHERE id=? FOR UPDATE");
                    $bdevInfoStmt->execute([$bdevId]);
                    $bdevInfo = $bdevInfoStmt->fetch();
                    if ($bdevInfo && $bdevInfo['status'] === 'zamontowany') {
                        $db->prepare("UPDATE devices SET status='sprawny' WHERE id=?")->execute([$bdevId]);
                        adjustInventoryForStatusChange($db, $bdevInfo['model_id'], 'zamontowany', 'sprawny');
                    }
                }
                $db->prepare("DELETE FROM installations WHERE id=?")->execute([$binst['id']]);
            }
            $db->commit();
            $deleted = count($batchInsts);
            flashSuccess('Usunięto grupę montażu (' . $deleted . ' urządzeń).');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php');

    } elseif ($postAction === 'accessory_issue') {
        $instId  = (int)($_POST['installation_id'] ?? 0);
        $accId   = (int)($_POST['accessory_id'] ?? 0);
        $qty     = max(1, (int)($_POST['quantity'] ?? 1));
        $notes   = sanitize($_POST['notes'] ?? '');
        $curUser = getCurrentUser();
        if (!$instId || !$accId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'installations.php?action=view&id=' . $instId); }
        try {
            // Check stock
            $remStmt = $db->prepare("SELECT a.quantity_initial, COALESCE((SELECT SUM(ai.quantity) FROM accessory_issues ai WHERE ai.accessory_id = a.id),0) AS issued FROM accessories a WHERE a.id=?");
            $remStmt->execute([$accId]);
            $accRow = $remStmt->fetch();
            if (!$accRow) { throw new Exception('Akcesorium nie istnieje.'); }
            $remaining = (int)$accRow['quantity_initial'] - (int)$accRow['issued'];
            if ($qty > $remaining) { throw new Exception('Niewystarczający stan: dostępne ' . $remaining . ' szt.'); }
            $db->prepare("INSERT INTO accessory_issues (accessory_id, installation_id, user_id, quantity, notes) VALUES (?,?,?,?,?)")
               ->execute([$accId, $instId, $curUser['id'], $qty, $notes]);
            flashSuccess('Wydano ' . $qty . ' szt. z magazynu.');
        } catch (Exception $e) {
            flashError($e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php?action=view&id=' . $instId);

    } elseif ($postAction === 'accessory_return') {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $instId  = (int)($_POST['installation_id'] ?? 0);
        if (!$issueId) { flashError('Błąd.'); redirect(getBaseUrl() . 'installations.php?action=view&id=' . $instId); }
        try {
            $db->prepare("DELETE FROM accessory_issues WHERE id=?")->execute([$issueId]);
            flashSuccess('Wydanie cofnięte — akcesorium zwrócono do stanu magazynowego.');
        } catch (Exception $e) { flashError($e->getMessage()); }
        redirect(getBaseUrl() . 'installations.php?action=view&id=' . $instId);
    }
}

if ($action === 'view' && $id) {
    $stmt = $db->prepare("
        SELECT i.*,
               d.serial_number, d.imei,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make, v.model_name as vehicle_model,
               c.contact_name, c.company_name, c.phone as client_phone, c.email as client_email,
               u.name as technician_name
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN users u ON u.id=i.technician_id
        WHERE i.id=?
    ");
    $stmt->execute([$id]);
    $installation = $stmt->fetch();
    if (!$installation) { flashError('Montaż nie istnieje.'); redirect(getBaseUrl() . 'installations.php'); }

    $installServices = $db->prepare("
        SELECT s.*, u.name as tech_name FROM services s LEFT JOIN users u ON u.id=s.technician_id
        WHERE s.installation_id=? ORDER BY s.created_at DESC
    ");
    $installServices->execute([$id]);
    $services = $installServices->fetchAll();

    // Fetch existing PP (Protokół Przekazania) for this installation
    $existingPP = null;
    try {
        $ppStmt = $db->prepare("SELECT id, protocol_number FROM protocols WHERE installation_id=? AND type='PP' ORDER BY id DESC LIMIT 1");
        $ppStmt->execute([$id]);
        $existingPP = $ppStmt->fetch() ?: null;
    } catch (Exception $e) { $existingPP = null; }

    // Accessories issued to this installation
    $installAccessories = [];
    try {
        $accStmt = $db->prepare("
            SELECT ai.id as issue_id, ai.quantity, ai.notes as issue_notes, ai.issued_at,
                   a.name as accessory_name, u.name as user_name
            FROM accessory_issues ai
            JOIN accessories a ON a.id = ai.accessory_id
            JOIN users u ON u.id = ai.user_id
            WHERE ai.installation_id = ?
            ORDER BY ai.issued_at DESC
        ");
        $accStmt->execute([$id]);
        $installAccessories = $accStmt->fetchAll();
    } catch (Exception $e) { $installAccessories = []; }

    // Available accessories for issuing
    $availableAccessories = [];
    try {
        $aaStmt = $db->query("
            SELECT a.id, a.name,
                   (a.quantity_initial - COALESCE((SELECT SUM(ai2.quantity) FROM accessory_issues ai2 WHERE ai2.accessory_id = a.id),0)) AS remaining
            FROM accessories a
            WHERE a.active = 1
            ORDER BY a.name
        ");
        $availableAccessories = $aaStmt->fetchAll();
    } catch (Exception $e) { $availableAccessories = []; }
}

if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM installations WHERE id=?");
    $stmt->execute([$id]);
    $installation = $stmt->fetch();
    if (!$installation) { flashError('Montaż nie istnieje.'); redirect(getBaseUrl() . 'installations.php'); }
}

// Data for selects
// Available device models (for the add form — model selector)
$availableModels = $db->query("
    SELECT m.id as model_id, m.name as model_name, mf.name as manufacturer_name,
           COUNT(d.id) as available_count
    FROM models m
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    JOIN devices d ON d.model_id=m.id AND d.status IN ('nowy','sprawny')
    GROUP BY m.id
    HAVING available_count > 0
    ORDER BY mf.name, m.name
")->fetchAll();

// Individual available devices (for manual selection in the add form)
$availableDevices = $db->query("
    SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d
    JOIN models m ON m.id=d.model_id
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status IN ('nowy','sprawny')
    ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();

$clients  = $db->query("SELECT id, contact_name, company_name, address, city, postal_code FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
$users    = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();

// Available accessories for forms (add/view)
if (!isset($availableAccessories)) {
    $availableAccessories = [];
    try {
        $aaStmt2 = $db->query("
            SELECT a.id, a.name,
                   (a.quantity_initial - COALESCE((SELECT SUM(ai2.quantity) FROM accessory_issues ai2 WHERE ai2.accessory_id = a.id),0)) AS remaining
            FROM accessories a WHERE a.active = 1 ORDER BY a.name
        ");
        $availableAccessories = $aaStmt2->fetchAll();
    } catch (Exception $e) { $availableAccessories = []; }
}

$installations = [];
$installationGroups = []; // processed for grouped display
if ($action === 'list') {
    $filterStatus = sanitize($_GET['status'] ?? '');
    $search = sanitize($_GET['search'] ?? '');
    $sql = "
        SELECT i.id, i.device_id, i.batch_id, i.installation_date, i.uninstallation_date, i.status,
               d.serial_number, m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make,
               c.contact_name, c.company_name,
               u.name as technician_name
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN users u ON u.id=i.technician_id
        WHERE 1=1
    ";
    $params = [];
    if ($filterStatus) { $sql .= " AND i.status=?"; $params[] = $filterStatus; }
    if ($search) {
        $sql .= " AND (d.serial_number LIKE ? OR v.registration LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
        $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
    }
    $sql .= " ORDER BY i.installation_date DESC, i.batch_id, i.id";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $installations = $stmt->fetchAll();
    } catch (PDOException $e) {
        // batch_id column does not exist yet (migration not run) — fall back gracefully
        $sqlFallback = str_replace(
            ['i.device_id, i.batch_id,', 'i.batch_id, i.id'],
            ['i.device_id, NULL as batch_id,', 'i.id'],
            $sql
        );
        $stmt = $db->prepare($sqlFallback);
        $stmt->execute($params);
        $installations = $stmt->fetchAll();
    }

    // Group by batch_id in PHP
    $seenBatches = [];
    foreach ($installations as $inst) {
        $bid = $inst['batch_id'];
        if ($bid !== null) {
            if (!isset($seenBatches[$bid])) {
                $seenBatches[$bid] = count($installationGroups);
                $installationGroups[] = ['is_batch' => true, 'items' => [$inst], 'ids' => [$inst['id']]];
            } else {
                $idx = $seenBatches[$bid];
                $installationGroups[$idx]['items'][] = $inst;
                $installationGroups[$idx]['ids'][]   = $inst['id'];
            }
        } else {
            $installationGroups[] = ['is_batch' => false, 'items' => [$inst], 'ids' => [$inst['id']]];
        }
    }
}

// Fetch data for print-batch view
$batchInstallations = [];
if ($action === 'print_batch') {
    // IDs are passed in the URL so the print page survives a browser refresh
    $rawIds   = sanitize($_GET['ids'] ?? '');
    $batchIds = array_filter(array_map('intval', explode(',', $rawIds)));
    if (!empty($batchIds)) {
        $ph = implode(',', array_fill(0, count($batchIds), '?'));
        $batchSql = "
            SELECT i.id, i.installation_date, i.status, i.location_in_vehicle, i.installation_address, i.notes,
                   d.serial_number, d.imei, d.sim_number,
                   m.name as model_name, mf.name as manufacturer_name,
                   v.registration, v.make, v.model_name as vehicle_model,
                   c.contact_name, c.company_name, c.phone as client_phone,
                   c.address as client_address, c.city as client_city, c.postal_code as client_postal_code,
                   u.name as technician_name
            FROM installations i
            JOIN devices d ON d.id = i.device_id
            JOIN models m ON m.id = d.model_id
            JOIN manufacturers mf ON mf.id = m.manufacturer_id
            JOIN vehicles v ON v.id = i.vehicle_id
            LEFT JOIN clients c ON c.id = i.client_id
            LEFT JOIN users u ON u.id = i.technician_id
            WHERE i.id IN ($ph)
            ORDER BY i.id
        ";
        try {
            $batchStmt = $db->prepare($batchSql);
            $batchStmt->execute($batchIds);
            $batchInstallations = $batchStmt->fetchAll();
        } catch (PDOException $e) {
            // installation_address column may not exist yet — fall back without it
            $batchSqlFallback = str_replace(
                'i.location_in_vehicle, i.installation_address,',
                'i.location_in_vehicle, NULL as installation_address,',
                $batchSql
            );
            $batchStmt = $db->prepare($batchSqlFallback);
            $batchStmt->execute($batchIds);
            $batchInstallations = $batchStmt->fetchAll();
        }
    }
}

// Accessories data for print_batch view
$batchAccessories = [];
if ($action === 'print_batch' && !empty($batchIds)) {
    try {
        $phAcc = implode(',', array_fill(0, count($batchIds), '?'));
        $batchAccStmt = $db->prepare("
            SELECT ai.quantity, ai.notes, ai.issued_at,
                   a.name AS accessory_name, u.name AS user_name
            FROM accessory_issues ai
            JOIN accessories a ON a.id = ai.accessory_id
            JOIN users u ON u.id = ai.user_id
            WHERE ai.installation_id IN ($phAcc)
            ORDER BY a.name, ai.issued_at
        ");
        $batchAccStmt->execute($batchIds);
        $batchAccessories = $batchAccStmt->fetchAll();
    } catch (Exception $e) { $batchAccessories = []; }
}

// Fetch installation-related protocols for the Protocols tab
$installationProtocols = [];
if ($action === 'list') {
    try {
        $installationProtocols = $db->query("
            SELECT p.id, p.type, p.protocol_number, p.date,
                   u.name as technician_name, v.registration, d.serial_number
            FROM protocols p
            LEFT JOIN users u ON u.id=p.technician_id
            LEFT JOIN installations i ON i.id=p.installation_id
            LEFT JOIN vehicles v ON v.id=i.vehicle_id
            LEFT JOIN devices d ON d.id=i.device_id
            WHERE p.installation_id IS NOT NULL
            ORDER BY p.date DESC, p.id DESC
        ")->fetchAll();
    } catch (Exception $e) { $installationProtocols = []; }
}

$activePage = 'installations';
$pageTitle = 'Montaże';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-car me-2 text-primary"></i>Montaże / Demontaże</h1>
    <?php if ($action === 'list'): ?>
    <a href="installations.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowy montaż</a>
    <?php else: ?>
    <a href="installations.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<ul class="nav nav-tabs mb-3" id="installTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-montaze" data-bs-toggle="tab" data-bs-target="#pane-montaze" type="button" role="tab">
            <i class="fas fa-car me-1"></i>Montaże
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-protokoly" data-bs-toggle="tab" data-bs-target="#pane-protokoly" type="button" role="tab">
            <i class="fas fa-clipboard-check me-1"></i>Protokoły
        </button>
    </li>
</ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="pane-montaze" role="tabpanel">
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (nr seryjny, rejestracja, klient...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <option value="aktywna" <?= ($_GET['status'] ?? '') === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
                    <option value="zakonczona" <?= ($_GET['status'] ?? '') === 'zakonczona' ? 'selected' : '' ?>>Zakończona</option>
                    <option value="anulowana" <?= ($_GET['status'] ?? '') === 'anulowana' ? 'selected' : '' ?>>Anulowana</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="installations.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Montaże (<?= count($installationGroups) ?> pozycji / <?= count($installations) ?> urządzeń)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Data montażu</th><th>Urządzenie / Zlecenie</th><th>Pojazd</th><th>Klient</th><th>Technik</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($installationGroups as $gi => $group): ?>
                <?php $first = $group['items'][0]; ?>
                <?php if ($group['is_batch'] && count($group['items']) > 1): ?>
                <!-- Batch row -->
                <tr class="table-info batch-header-row" data-batch-toggle="batch-<?= $gi ?>">
                    <td><?= formatDate($first['installation_date']) ?></td>
                    <td>
                        <span class="badge bg-primary me-1"><?= count($group['items']) ?> urządzeń</span>
                        <span class="text-muted small">Zlecenie grupowe</span>
                        <div class="small text-muted mt-1">
                            <?= h(implode(', ', array_column($group['items'], 'serial_number'))) ?>
                        </div>
                    </td>
                    <td>
                        <?php $regs = array_unique(array_column($group['items'], 'registration')); ?>
                        <?= h(implode(', ', $regs)) ?>
                    </td>
                    <td><?= h($first['company_name'] ?: $first['contact_name'] ?? '—') ?></td>
                    <td><?= h($first['technician_name'] ?? '—') ?></td>
                    <td><?= getStatusBadge($first['status'], 'installation') ?></td>
                    <td>
                        <a href="installations.php?action=print_batch&ids=<?= implode(',', $group['ids']) ?>"
                           class="btn btn-sm btn-outline-dark btn-action" title="Drukuj zlecenie montażu"><i class="fas fa-print"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action"
                                onclick="toggleBatchRows('batch-<?= $gi ?>', this)"
                                title="Rozwiń / zwiń urządzenia">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_batch">
                            <input type="hidden" name="batch_id" value="<?= $group['ids'][0] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń całą grupę montażu (<?= count($group['items']) ?> urządzeń)? Urządzenia zostaną oznaczone jako sprawne.">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Batch child rows (collapsed by default) -->
                <?php foreach ($group['items'] as $inst): ?>
                <tr class="batch-child-row d-none" data-batch-group="batch-<?= $gi ?>">
                    <td class="ps-4 text-muted small"><?= formatDate($inst['installation_date']) ?></td>
                    <td class="ps-4">
                        <a href="devices.php?action=view&id=<?= $inst['device_id'] ?? '' ?>"><?= h($inst['serial_number']) ?></a>
                        <br><small class="text-muted"><?= h($inst['manufacturer_name'] . ' ' . $inst['model_name']) ?></small>
                    </td>
                    <td><?= h($inst['registration']) ?><br><small class="text-muted"><?= h($inst['make']) ?></small></td>
                    <td><?= h($inst['company_name'] ?: $inst['contact_name'] ?? '—') ?></td>
                    <td><?= h($inst['technician_name'] ?? '—') ?></td>
                    <td><?= getStatusBadge($inst['status'], 'installation') ?></td>
                    <td>
                        <a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <?php if ($inst['status'] === 'aktywna'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-action"
                                onclick="showUninstallModal(<?= $inst['id'] ?>, <?= $inst['device_id'] ?? 0 ?>, '<?= h($inst['serial_number']) ?>')">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                            <input type="hidden" name="device_id" value="<?= $inst['device_id'] ?? 0 ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń montaż #<?= $inst['id'] ?>? Urządzenie zostanie oznaczone jako sprawne."><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <!-- Single / non-batch row -->
                <?php $inst = $first; ?>
                <tr>
                    <td><?= formatDate($inst['installation_date']) ?></td>
                    <td>
                        <a href="devices.php?action=view&id=<?= $inst['device_id'] ?? '' ?>"><?= h($inst['serial_number']) ?></a>
                        <br><small class="text-muted"><?= h($inst['manufacturer_name'] . ' ' . $inst['model_name']) ?></small>
                    </td>
                    <td><?= h($inst['registration']) ?><br><small class="text-muted"><?= h($inst['make']) ?></small></td>
                    <td><?= h($inst['company_name'] ?: $inst['contact_name'] ?? '—') ?></td>
                    <td><?= h($inst['technician_name'] ?? '—') ?></td>
                    <td><?= getStatusBadge($inst['status'], 'installation') ?></td>
                    <td>
                        <a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <?php if ($inst['status'] === 'aktywna'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-action"
                                onclick="showUninstallModal(<?= $inst['id'] ?>, <?= $inst['device_id'] ?? 0 ?>, '<?= h($inst['serial_number']) ?>')">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                            <input type="hidden" name="device_id" value="<?= $inst['device_id'] ?? 0 ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń montaż #<?= $inst['id'] ?>? Urządzenie zostanie oznaczone jako sprawne."><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($installationGroups)): ?><tr><td colspan="7" class="text-center text-muted p-3">Brak montaży.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function toggleBatchRows(groupKey, btn) {
    var rows = document.querySelectorAll('[data-batch-group="' + groupKey + '"]');
    var icon = btn.querySelector('i');
    rows.forEach(function(r) { r.classList.toggle('d-none'); });
    if (icon) {
        icon.classList.toggle('fa-chevron-down');
        icon.classList.toggle('fa-chevron-up');
    }
}
</script>

</div><!-- /pane-montaze -->
<div class="tab-pane fade" id="pane-protokoly" role="tabpanel">
<div class="card">
    <div class="card-header">Protokoły montaży (<?= count($installationProtocols) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Nr protokołu</th><th>Typ</th><th>Data</th><th>Pojazd</th><th>Urządzenie</th><th>Technik</th><th>Akcje</th></tr></thead>
            <tbody>
                <?php
                    $typeLabel = ['PP' => 'Przekazania', 'PU' => 'Uruchomienia', 'PS' => 'Serwisowy'];
                    $typeColor = ['PP' => 'primary', 'PU' => 'success', 'PS' => 'warning'];
                    foreach ($installationProtocols as $ip):
                ?>
                <tr>
                    <td class="fw-bold"><a href="protocols.php?action=view&id=<?= $ip['id'] ?>"><?= h($ip['protocol_number']) ?></a></td>
                    <td><span class="badge bg-<?= $typeColor[$ip['type']] ?? 'secondary' ?>"><?= $typeLabel[$ip['type']] ?? h($ip['type']) ?></span></td>
                    <td><?= formatDate($ip['date']) ?></td>
                    <td><?= h($ip['registration'] ?? '—') ?></td>
                    <td><?= h($ip['serial_number'] ?? '—') ?></td>
                    <td><?= h($ip['technician_name'] ?? '—') ?></td>
                    <td>
                        <a href="protocols.php?action=view&id=<?= $ip['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="protocols.php?action=print&id=<?= $ip['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary btn-action"><i class="fas fa-print"></i></a>
                        <?php if (isAdmin()): ?>
                        <form method="POST" action="protocols.php" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $ip['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń protokół <?= h($ip['protocol_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($installationProtocols)): ?><tr><td colspan="7" class="text-center text-muted p-3">Brak protokołów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /pane-protokoly -->
</div><!-- /tab-content -->

<?php elseif ($action === 'view' && isset($installation)): ?>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Szczegóły montażu</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted">Status</th><td><?= getStatusBadge($installation['status'], 'installation') ?></td></tr>
                    <tr><th class="text-muted">Data montażu</th><td><?= formatDate($installation['installation_date']) ?></td></tr>
                    <tr><th class="text-muted">Data demontażu</th><td><?= formatDate($installation['uninstallation_date']) ?></td></tr>
                    <tr><th class="text-muted">Pojazd</th><td><a href="vehicles.php"><?= h($installation['registration']) ?></a><br><?= h($installation['make'] . ' ' . $installation['vehicle_model']) ?></td></tr>
                    <tr><th class="text-muted">Klient</th><td><?= $installation['contact_name'] ? h($installation['company_name'] ?: $installation['contact_name']) : '—' ?></td></tr>
                    <tr><th class="text-muted">Adres instalacji</th><td><?= h($installation['installation_address'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Urządzenie</th><td><a href="devices.php?action=view&id=<?= $installation['device_id'] ?>"><?= h($installation['serial_number']) ?></a><br><small><?= h($installation['manufacturer_name'] . ' ' . $installation['model_name']) ?></small></td></tr>
                    <tr><th class="text-muted">IMEI</th><td><?= h($installation['imei'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Technik</th><td><?= h($installation['technician_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Miejsce montażu</th><td><?= h($installation['location_in_vehicle'] ?? '—') ?></td></tr>
                </table>
                <?php if ($installation['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= h($installation['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <a href="installations.php?action=edit&id=<?= $installation['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <?php if ($installation['status'] === 'aktywna'): ?>
                <button onclick="showUninstallModal(<?= $installation['id'] ?>, <?= $installation['device_id'] ?>, '<?= h($installation['serial_number']) ?>')" class="btn btn-sm btn-warning"><i class="fas fa-minus-circle me-1"></i>Demontaż</button>
                <?php endif; ?>
                <a href="services.php?action=add&installation=<?= $installation['id'] ?>&device=<?= $installation['device_id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-wrench me-1"></i>Serwis</a>
                <a href="protocols.php?action=add&installation=<?= $installation['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clipboard me-1"></i>Protokół</a>
                <a href="installations.php?action=print_batch&ids=<?= $installation['id'] ?>" class="btn btn-sm btn-outline-dark"><i class="fas fa-print me-1"></i>Drukuj</a>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-wrench me-2 text-warning"></i>Serwisy tego montażu</span>
                <a href="services.php?action=add&installation=<?= $installation['id'] ?>&device=<?= $installation['device_id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-plus me-1"></i>Nowy serwis</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Typ</th><th>Zaplanowany</th><th>Zrealizowany</th><th>Status</th><th>Koszt</th><th>Technik</th></tr></thead>
                    <tbody>
                        <?php foreach ($services as $svc): ?>
                        <tr>
                            <td><?= h(ucfirst($svc['type'])) ?></td>
                            <td><?= formatDate($svc['planned_date']) ?></td>
                            <td><?= formatDate($svc['completed_date']) ?></td>
                            <td><?= getStatusBadge($svc['status'], 'service') ?></td>
                            <td><?= $svc['cost'] > 0 ? formatMoney($svc['cost']) : '—' ?></td>
                            <td><?= h($svc['tech_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($services)): ?><tr><td colspan="6" class="text-muted text-center">Brak serwisów</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Accessories section -->
<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-toolbox me-2 text-warning"></i>Akcesoria użyte przy tym montażu</span>
        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#issueAccModal">
            <i class="fas fa-plus me-1"></i>Wydaj akcesorium
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>Akcesorium</th><th class="text-center">Ilość</th><th>Pobrał</th><th>Data pobrania</th><th>Uwagi</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($installAccessories as $ia): ?>
                <tr>
                    <td class="fw-semibold"><?= h($ia['accessory_name']) ?></td>
                    <td class="text-center fw-bold"><?= (int)$ia['quantity'] ?> szt</td>
                    <td><?= h($ia['user_name']) ?></td>
                    <td class="text-muted small"><?= formatDateTime($ia['issued_at']) ?></td>
                    <td class="text-muted small"><?= h($ia['issue_notes'] ?? '') ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="accessory_return">
                            <input type="hidden" name="issue_id" value="<?= $ia['issue_id'] ?>">
                            <input type="hidden" name="installation_id" value="<?= $installation['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Cofnąć wydanie tego akcesorium?" title="Cofnij wydanie / zwróć do magazynu">
                                <i class="fas fa-undo"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($installAccessories)): ?>
                <tr><td colspan="6" class="text-center text-muted p-2">Brak akcesoriów przypisanych do tego zlecenia.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Issue Accessory Modal -->
<div class="modal fade" id="issueAccModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="accessory_issue">
                <input type="hidden" name="installation_id" value="<?= $installation['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-toolbox me-2 text-warning"></i>Wydaj akcesorium ze stanu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($availableAccessories)): ?>
                    <div class="alert alert-warning">Brak dostępnych akcesoriów. <a href="inventory.php?action=accessories">Dodaj akcesoria w magazynie.</a></div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label required-star">Akcesorium</label>
                        <select name="accessory_id" class="form-select" required>
                            <option value="">— wybierz —</option>
                            <?php foreach ($availableAccessories as $aa): ?>
                            <option value="<?= $aa['id'] ?>" <?= (int)$aa['remaining'] <= 0 ? 'disabled' : '' ?>>
                                <?= h($aa['name']) ?> (dostępne: <?= max(0,(int)$aa['remaining']) ?> szt.)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-star">Ilość (szt.)</label>
                        <input type="number" name="quantity" class="form-control" required min="1" value="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Uwagi</label>
                        <input type="text" name="notes" class="form-control" placeholder="Opcjonalne">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <?php if (!empty($availableAccessories)): ?>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-check me-2"></i>Wydaj z magazynu</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:1400px">
    <div class="card-header">
        <i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i>
        <?= $action === 'add' ? 'Nowy montaż' : 'Edytuj montaż' ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $installation['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $installation['vehicle_id'] ?? 0 ?>"><?php endif; ?>
            <div class="row g-3">
                <?php if ($action === 'add' && !empty($availableAccessories)): ?><div class="col-lg-8"><div class="row g-3"><?php endif; ?>
                <?php if ($action === 'add'): ?>
                <!-- Multi-device selection rows -->
                <div class="col-12">
                    <label class="form-label required-star">Urządzenia GPS do montażu</label>

                    <?php if (empty($availableModels) && empty($availableDevices)): ?>
                    <div class="alert alert-warning py-2 mb-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>Brak dostępnych urządzeń w magazynie.
                        <a href="devices.php?action=add">Dodaj urządzenia</a> lub sprawdź stan magazynu.
                    </div>
                    <?php endif; ?>

                    <div id="deviceRowsContainer" class="d-flex flex-column gap-2 mb-2">
                        <!-- First device row (index 0), pre-populated from ?device=X if provided -->
                        <?php
                        $preDeviceId = (int)($_GET['device'] ?? 0);
                        $preMode     = ($preDeviceId > 0) ? 'manual' : 'auto';
                        ?>
                        <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                            <div class="row g-2 align-items-center">
                                <div class="col-auto">
                                    <span class="row-num badge bg-secondary">1</span>
                                </div>
                                <div class="col-auto">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="device_mode[0]" id="dm_auto_0" value="auto"
                                               <?= $preMode === 'auto' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-secondary" for="dm_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                        <input type="radio" class="btn-check" name="device_mode[0]" id="dm_manual_0" value="manual"
                                               <?= $preMode === 'manual' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-primary" for="dm_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                                    </div>
                                </div>
                                <div class="col col-mode-auto" <?= $preMode === 'manual' ? 'style="display:none"' : '' ?>>
                                    <select name="model_id[0]" class="form-select form-select-sm">
                                        <option value="">— wybierz model —</option>
                                        <?php foreach ($availableModels as $m): ?>
                                        <option value="<?= $m['model_id'] ?>">
                                            <?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col col-mode-manual" <?= $preMode === 'auto' ? 'style="display:none"' : '' ?>>
                                    <select name="device_id_manual[0]" class="form-select form-select-sm ts-device" data-ts-init="0">
                                        <option value="">— wybierz urządzenie —</option>
                                        <?php
                                        $currentGroup = '';
                                        foreach ($availableDevices as $dev):
                                            $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                            if ($grp !== $currentGroup) {
                                                if ($currentGroup) echo '</optgroup>';
                                                echo '<optgroup label="' . h($grp) . '">';
                                                $currentGroup = $grp;
                                            }
                                        ?>
                                        <option value="<?= $dev['id'] ?>" <?= $preDeviceId === $dev['id'] ? 'selected' : '' ?>>
                                            <?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?>
                                        </option>
                                        <?php endforeach; if ($currentGroup) echo '</optgroup>'; ?>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <input type="text" name="vehicle_registration[0]" class="form-control form-control-sm"
                                           required placeholder="Nr rej. pojazdu"
                                           aria-label="Numer rejestracyjny pojazdu"
                                           style="text-transform:uppercase;min-width:130px">
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none"
                                            title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div><!-- #deviceRowsContainer -->

                    <button type="button" id="addDeviceRowBtn" class="btn btn-sm btn-outline-success"
                            <?= (empty($availableModels) && empty($availableDevices)) ? 'disabled' : '' ?>>
                        <i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie
                    </button>
                </div><!-- .col-12 device section -->
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label">Klient</label>
                    <div class="input-group">
                        <select name="client_id" id="clientSelect" class="form-select">
                            <option value="">— brak przypisania —</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($installation['client_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($action === 'add'): ?>
                        <button type="button" class="btn btn-outline-success" id="quickAddClientBtn"
                                title="Dodaj nowego klienta" data-bs-toggle="tooltip">
                            <i class="fas fa-user-plus"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Adres instalacji</label>
                    <input type="text" name="installation_address" id="installationAddressField" class="form-control"
                           value="<?= h($installation['installation_address'] ?? '') ?>"
                           placeholder="Adres miejsca montażu (opcjonalne)">
                    <div class="form-text">Automatycznie uzupełniany adresem klienta po jego wyborze. Możesz edytować.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Technik</label>
                    <select name="technician_id" class="form-select">
                        <option value="">— aktualny użytkownik —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($installation['technician_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Data montażu</label>
                    <input type="date" name="installation_date" class="form-control" required value="<?= h($installation['installation_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Data demontażu</label>
                    <input type="date" name="uninstallation_date" class="form-control" value="<?= h($installation['uninstallation_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="aktywna" <?= ($installation['status'] ?? 'aktywna') === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
                        <option value="zakonczona" <?= ($installation['status'] ?? '') === 'zakonczona' ? 'selected' : '' ?>>Zakończona</option>
                        <option value="anulowana" <?= ($installation['status'] ?? '') === 'anulowana' ? 'selected' : '' ?>>Anulowana</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Miejsce montażu w pojeździe</label>
                    <input type="text" name="location_in_vehicle" class="form-control" value="<?= h($installation['location_in_vehicle'] ?? '') ?>" placeholder="np. pod deską rozdzielczą">
                </div>
                <div class="col-12">
                    <label class="form-label">Uwagi</label>
                    <textarea name="notes" class="form-control" rows="3"><?= h($installation['notes'] ?? '') ?></textarea>
                </div>
                <?php if ($action === 'add' && !empty($availableAccessories)): ?>
                </div></div><!-- /inner row g-3 + /col-lg-8 -->
                <div class="col-lg-4">
                    <div class="card bg-light border-0 h-100">
                        <div class="card-header bg-warning bg-opacity-25 py-2 d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-toolbox me-2 text-warning"></i>Akcesoria do pobrania z magazynu (opcjonalnie)</span>
                            <button type="button" class="btn btn-outline-warning btn-sm" id="instAddAccRow">
                                <i class="fas fa-plus me-1"></i>Dodaj pozycję
                            </button>
                        </div>
                        <div class="card-body pb-1" id="instAccContainer">
                            <div class="inst-acc-row row g-2 align-items-center mb-2">
                                <div class="col-md-5">
                                    <select name="inst_acc[]" class="form-select form-select-sm">
                                        <option value="">— nie pobieraj —</option>
                                        <?php foreach ($availableAccessories as $ia2): $rem2 = (int)$ia2['remaining']; ?>
                                        <option value="<?= $ia2['id'] ?>" <?= $rem2 <= 0 ? 'disabled' : '' ?>>
                                            <?= h($ia2['name']) ?> (dost.: <?= max(0,$rem2) ?> szt.)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="inst_acc_qty[]" class="form-control form-control-sm" min="1" value="1" placeholder="Ilość">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="inst_acc_note[]" class="form-control form-control-sm" placeholder="Uwagi do pobrania">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-outline-danger btn-sm inst-acc-remove" disabled><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /col-lg-4 accessories -->
                <?php endif; ?>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Zarejestruj montaż' : 'Zapisz zmiany' ?></button>
                    <a href="installations.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php if ($action === 'add'): ?>
<script>
(function () {
    // Embed client address data so JS can auto-fill the installation address field
    var clientAddresses = <?= json_encode(array_reduce($clients, function ($carry, $c) {
        $parts = array_filter([$c['address'] ?? '', trim(($c['postal_code'] ?? '') . ' ' . ($c['city'] ?? ''))]);
        $carry[(string)$c['id']] = implode(', ', $parts);
        return $carry;
    }, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var sel  = document.getElementById('clientSelect');
    var addr = document.getElementById('installationAddressField');
    if (sel && addr) {
        sel.addEventListener('change', function () {
            var val = this.value;
            // Only auto-fill if the field is empty or was previously auto-filled
            if (val && clientAddresses[val]) {
                addr.value = clientAddresses[val];
            } else if (!val) {
                addr.value = '';
            }
        });
    }

    // Accessories dynamic rows
    var accOpts = <?= json_encode(array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'rem' => max(0,(int)$a['remaining'])], $availableAccessories ?? [])) ?>;
    function buildAccOpts() {
        var html = '<option value="">— nie pobieraj —</option>';
        accOpts.forEach(function(a) {
            html += '<option value="' + a.id + '"' + (a.rem <= 0 ? ' disabled' : '') + '>' + a.name.replace(/</g,'&lt;') + ' (dost.: ' + a.rem + ' szt.)</option>';
        });
        return html;
    }
    var addBtn = document.getElementById('instAddAccRow');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            var container = document.getElementById('instAccContainer');
            var div = document.createElement('div');
            div.className = 'inst-acc-row row g-2 align-items-center mb-2';
            div.innerHTML = '<div class="col-md-5"><select name="inst_acc[]" class="form-select form-select-sm">' + buildAccOpts() + '</select></div>' +
                '<div class="col-md-2"><input type="number" name="inst_acc_qty[]" class="form-control form-control-sm" min="1" value="1" placeholder="Ilość"></div>' +
                '<div class="col-md-4"><input type="text" name="inst_acc_note[]" class="form-control form-control-sm" placeholder="Uwagi do pobrania"></div>' +
                '<div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm inst-acc-remove"><i class="fas fa-times"></i></button></div>';
            container.appendChild(div);
            updateRemoveAccBtns();
        });
    }
    document.addEventListener('click', function(e) {
        if (e.target.closest('.inst-acc-remove')) {
            e.target.closest('.inst-acc-row').remove();
            updateRemoveAccBtns();
        }
    });
    function updateRemoveAccBtns() {
        var rows = document.querySelectorAll('#instAccContainer .inst-acc-row');
        rows.forEach(function(r) { var b = r.querySelector('.inst-acc-remove'); if(b) b.disabled = rows.length <= 1; });
    }
}());
</script>
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($action === 'print_batch'): ?>
<?php
$firstRow       = $batchInstallations[0] ?? [];
$clientLabel    = $firstRow ? ($firstRow['company_name'] ?: ($firstRow['contact_name'] ?: '—')) : '—';
$technicianName = $firstRow['technician_name'] ?? '—';
$installDate    = $firstRow['installation_date'] ?? date('Y-m-d');
$batchFirstId   = $firstRow['id'] ?? 0;
$batchCount     = count($batchInstallations);
$orderNumber    = sprintf('ZM/%s/%04d-%d', date('Y', strtotime($installDate ?: 'now')), $batchFirstId, $batchCount);
$companyName    = '';
$companyAddr    = '';
$companyPhone   = '';
try {
    $cfg = [];
    $settingsStmt = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('company_name','company_address','company_city','company_postal_code','company_phone')");
    foreach ($settingsStmt->fetchAll() as $s) { $cfg[$s['key']] = $s['value']; }
    $companyName  = $cfg['company_name'] ?? '';
    $companyAddr  = trim(($cfg['company_address'] ?? '') . ', ' . ($cfg['company_postal_code'] ?? '') . ' ' . ($cfg['company_city'] ?? ''), ', ');
    $companyPhone = $cfg['company_phone'] ?? '';
} catch (Exception $e) {}
$clientAddrParts = array_filter([
    $firstRow['client_address'] ?? '',
    trim(($firstRow['client_postal_code'] ?? '') . ' ' . ($firstRow['client_city'] ?? '')),
]);
$clientAddrFull = implode(', ', $clientAddrParts);
$installAddr = $firstRow['installation_address'] ?? '';
?>
<style>
/* ── Print order styles ─────────────────────────── */
.print-doc {
    background: #fff;
    color: #1a1a2e;
    font-family: 'DM Sans','Segoe UI',system-ui,sans-serif;
    max-width: 900px;
    margin: 0 auto;
}
.print-doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 18px;
    margin-bottom: 22px;
    border-bottom: 3px solid #2563eb;
}
.print-doc-logo { font-size: 1.5rem; font-weight: 800; color: #1a1a2e; letter-spacing: -0.5px; }
.print-doc-logo span { color: #2563eb; }
.print-doc-title { font-size: 1.25rem; font-weight: 700; color: #2563eb; letter-spacing: 1px; text-transform: uppercase; }
.print-doc-meta { font-size: 0.83rem; color: #666; margin-top: 2px; }
.print-section-label {
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
    color: #2563eb; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;
}
.print-section-label::after { content:''; flex:1; height:1px; background: #e0e7ff; }
.print-info-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 24px; }
.print-info-box { background: #f8faff; border: 1px solid #e0e7ff; border-radius: 8px; padding: 12px 14px; }
.print-info-box .label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #2563eb; margin-bottom: 4px; }
.print-info-box .value { font-size: 0.9rem; font-weight: 600; color: #1a1a2e; }
.print-info-box .sub   { font-size: 0.78rem; color: #666; margin-top: 2px; }
.print-device-table { width:100%; border-collapse: collapse; margin-bottom: 24px; font-size: 0.82rem; }
.print-device-table thead th {
    background: #2563eb; color: #fff; font-weight: 700; padding: 9px 10px;
    text-align: left; font-size: 0.73rem; text-transform: uppercase; letter-spacing: 0.5px;
}
.print-device-table thead th:first-child { border-radius: 6px 0 0 0; }
.print-device-table thead th:last-child  { border-radius: 0 6px 0 0; }
.print-device-table tbody tr:nth-child(even) { background: #f8faff; }
.print-device-table tbody td { padding: 8px 10px; border-bottom: 1px solid #e0e7ff; vertical-align: top; }
.print-device-table tbody tr:last-child td { border-bottom: none; }
.print-sig-row { display: flex; gap: 32px; margin-top: 40px; }
.print-sig-box { flex:1; text-align: center; }
.print-sig-line { border-top: 2px solid #1a1a2e; padding-top: 6px; margin-top: 52px; font-size: 0.78rem; color: #444; }
.print-footer { text-align: center; font-size: 0.72rem; color: #999; margin-top: 30px; padding-top: 12px; border-top: 1px solid #e0e7ff; }
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .navbar, footer { display: none !important; }
    .container-fluid { padding: 0 !important; }
    .print-doc { max-width: 100%; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h5 class="mb-0"><i class="fas fa-file-alt me-2 text-primary"></i>Zlecenie montażu — podgląd wydruku</h5>
    <div>
        <button type="button" class="btn btn-primary me-2" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Drukuj / PDF
        </button>
        <a href="installations.php" class="btn btn-outline-secondary">
            <i class="fas fa-list me-1"></i>Lista montaży
        </a>
    </div>
</div>

<div class="print-doc p-4 card">
    <!-- ── Header ─────────────────────────── -->
    <div class="print-doc-header">
        <div>
            <?php if ($companyName): ?>
            <div class="print-doc-logo"><?= h($companyName) ?></div>
            <?php if ($companyAddr): ?><div style="font-size:.82rem;color:#666;margin-top:3px"><?= h($companyAddr) ?></div><?php endif; ?>
            <?php if ($companyPhone): ?><div style="font-size:.82rem;color:#666">Tel: <?= h($companyPhone) ?></div><?php endif; ?>
            <?php else: ?>
            <div class="print-doc-logo">Fleet<span>Link</span></div>
            <div style="font-size:.82rem;color:#666">System zarządzania urządzeniami GPS</div>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div class="print-doc-title">Zlecenie montażu</div>
            <div class="print-doc-meta">Nr zlecenia: <strong><?= h($orderNumber) ?></strong></div>
            <div class="print-doc-meta">Data: <strong><?= formatDate($installDate) ?></strong></div>
            <div class="print-doc-meta">Urządzeń: <strong><?= $batchCount ?></strong></div>
        </div>
    </div>

    <!-- ── Info grid ──────────────────────── -->
    <div class="print-info-grid">
        <div class="print-info-box">
            <div class="label">Klient</div>
            <div class="value"><?= h($clientLabel) ?></div>
            <?php if ($firstRow && $firstRow['client_phone']): ?>
            <div class="sub"><i class="fas fa-phone me-1" style="color:#2563eb;font-size:.7rem"></i><?= h($firstRow['client_phone']) ?></div>
            <?php endif; ?>
            <?php if ($clientAddrFull): ?>
            <div class="sub"><i class="fas fa-map-marker-alt me-1" style="color:#2563eb;font-size:.7rem"></i><?= h($clientAddrFull) ?></div>
            <?php endif; ?>
        </div>
        <div class="print-info-box">
            <div class="label">Adres instalacji</div>
            <div class="value"><?= $installAddr ? h($installAddr) : '<span style="color:#999">—</span>' ?></div>
        </div>
        <div class="print-info-box">
            <div class="label">Technik</div>
            <div class="value"><?= h($technicianName) ?></div>
            <div class="sub">Data montażu: <?= formatDate($installDate) ?></div>
        </div>
    </div>

    <!-- ── Devices table ──────────────────── -->
    <div class="print-section-label">Wykaz urządzeń</div>
    <table class="print-device-table">
        <thead>
            <tr>
                <th style="width:32px">#</th>
                <th>Model urządzenia</th>
                <th>Nr seryjny</th>
                <th>IMEI</th>
                <th>Nr SIM</th>
                <th>Rejestracja</th>
                <th>Pojazd</th>
                <th>Miejsce montażu</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batchInstallations as $i => $bi): ?>
            <tr>
                <td style="color:#2563eb;font-weight:700"><?= $i + 1 ?></td>
                <td><?= h($bi['manufacturer_name'] . ' ' . $bi['model_name']) ?></td>
                <td style="font-weight:700"><?= h($bi['serial_number']) ?></td>
                <td style="color:#666;font-size:.78rem"><?= h($bi['imei'] ?? '—') ?></td>
                <td style="color:#666;font-size:.78rem"><?= h($bi['sim_number'] ?? '—') ?></td>
                <td style="font-weight:700"><?= h($bi['registration']) ?></td>
                <td style="color:#666"><?= h(trim($bi['make'] . ' ' . ($bi['vehicle_model'] ?? ''))) ?: '—' ?></td>
                <td style="color:#666"><?= h($bi['location_in_vehicle'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($batchInstallations)): ?>
            <tr><td colspan="8" style="text-align:center;color:#999;padding:16px">Brak danych.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ── Notes ─────────────────────────── -->
    <?php if ($firstRow && $firstRow['notes']): ?>
    <div class="print-section-label">Uwagi</div>
    <div style="font-size:.87rem;color:#333;margin-bottom:24px;padding:10px 14px;background:#f8faff;border-radius:6px;border:1px solid #e0e7ff">
        <?= h($firstRow['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- ── Accessories ──────────────────── -->
    <?php if (!empty($batchAccessories)): ?>
    <div class="print-section-label">Materiały eksploatacyjne / Akcesoria</div>
    <table class="print-device-table" style="margin-bottom:24px">
        <thead>
            <tr>
                <th style="width:32px">#</th>
                <th>Akcesorium</th>
                <th style="width:80px;text-align:center">Ilość</th>
                <th>Wydał</th>
                <th>Uwagi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batchAccessories as $ai => $bacc): ?>
            <tr>
                <td style="color:#2563eb;font-weight:700"><?= $ai + 1 ?></td>
                <td style="font-weight:600"><?= h($bacc['accessory_name']) ?></td>
                <td style="text-align:center;font-weight:700"><?= (int)$bacc['quantity'] ?> szt</td>
                <td style="color:#666"><?= h($bacc['user_name']) ?></td>
                <td style="color:#666;font-size:.78rem"><?= h($bacc['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Signatures ─────────────────────── -->
    <div class="print-sig-row">
        <div class="print-sig-box">
            <div class="print-sig-line">Podpis technika<br><strong><?= h($technicianName) ?></strong></div>
        </div>
        <div class="print-sig-box">
            <div class="print-sig-line">Podpis klienta / odbiór<br><strong><?= h($clientLabel) ?></strong></div>
        </div>
    </div>

    <div class="print-footer">
        Dokument wygenerowany przez <?= $companyName ? h($companyName) : 'FleetLink Magazyn' ?> &mdash; <?= date('d.m.Y H:i') ?>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="uninstallModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="uninstall">
                <input type="hidden" name="id" id="uninstallId">
                <input type="hidden" name="device_id" id="uninstallDeviceId">
                <div class="modal-header">
                    <h5 class="modal-title text-warning"><i class="fas fa-minus-circle me-2"></i>Demontaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Rejestracja demontażu urządzenia: <strong id="uninstallSerial"></strong></p>
                    <label class="form-label">Data demontażu</label>
                    <input type="date" name="uninstallation_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check me-1"></i>Zatwierdź demontaż</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function showUninstallModal(id, deviceId, serial) {
    document.getElementById('uninstallId').value = id;
    document.getElementById('uninstallDeviceId').value = deviceId;
    document.getElementById('uninstallSerial').textContent = serial;
    new bootstrap.Modal(document.getElementById('uninstallModal')).show();
}
</script>

<!-- Quick Add Client Modal -->
<div class="modal fade" id="quickClientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2 text-success"></i>Nowy klient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="quickClientError" class="alert alert-danger d-none"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-star">Imię i nazwisko kontaktu</label>
                        <input type="text" id="qc_contact_name" class="form-control" placeholder="Jan Kowalski">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nazwa firmy</label>
                        <input type="text" id="qc_company_name" class="form-control" placeholder="Firma Sp. z o.o.">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefon</label>
                        <input type="text" id="qc_phone" class="form-control" placeholder="+48 123 456 789">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input type="email" id="qc_email" class="form-control" placeholder="kontakt@firma.pl">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success" id="quickClientSave">
                    <i class="fas fa-save me-2"></i>Zapisz klienta
                </button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('quickAddClientBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        new bootstrap.Modal(document.getElementById('quickClientModal')).show();
    });
    document.getElementById('quickClientSave').addEventListener('click', function () {
        var contactName = document.getElementById('qc_contact_name').value.trim();
        var companyName = document.getElementById('qc_company_name').value.trim();
        var phone       = document.getElementById('qc_phone').value.trim();
        var email       = document.getElementById('qc_email').value.trim();
        var errEl       = document.getElementById('quickClientError');
        if (!contactName) { errEl.textContent = 'Imię i nazwisko kontaktu jest wymagane.'; errEl.classList.remove('d-none'); return; }
        errEl.classList.add('d-none');
        var fd = new FormData();
        fd.append('action', 'quick_add_client');
        var csrfEl = document.querySelector('input[name="csrf_token"]');
        if (!csrfEl) { errEl.textContent = 'Błąd sesji. Odśwież stronę i spróbuj ponownie.'; errEl.classList.remove('d-none'); return; }
        fd.append('csrf_token', csrfEl.value);
        fd.append('contact_name', contactName);
        fd.append('company_name', companyName);
        fd.append('phone', phone);
        fd.append('email', email);
        fetch('installations.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
                var sel = document.getElementById('clientSelect');
                var opt = new Option(data.label, data.id, true, true);
                sel.appendChild(opt);
                bootstrap.Modal.getInstance(document.getElementById('quickClientModal')).hide();
                // Reset modal fields
                ['qc_contact_name','qc_company_name','qc_phone','qc_email'].forEach(function (id) { document.getElementById(id).value = ''; });
            })
            .catch(function () { errEl.textContent = 'Błąd połączenia z serwerem.'; errEl.classList.remove('d-none'); });
    });
}());
</script>

<!-- Hidden template for new device rows — cloned by JavaScript -->
<?php if ($action === 'add'): ?>
<template id="deviceRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <span class="row-num badge bg-secondary">__NUM__</span>
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="dm_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="dm_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="dm_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="dm_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny wybór</label>
                </div>
            </div>
            <div class="col col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($availableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm ts-device">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $tplGroup = '';
                    foreach ($availableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $tplGroup) {
                            if ($tplGroup) echo '</optgroup>';
                            echo '<optgroup label="' . h($grp) . '">';
                            $tplGroup = $grp;
                        }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?><?= $dev['sim_number'] ? ' (' . h($dev['sim_number']) . ')' : '' ?></option>
                    <?php endforeach; if ($tplGroup) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-auto">
                <input type="text" name="vehicle_registration[__IDX__]" class="form-control form-control-sm"
                       required placeholder="Nr rej. pojazdu"
                       aria-label="Numer rejestracyjny pojazdu"
                       style="text-transform:uppercase;min-width:130px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn"
                        title="Usuń urządzenie z montażu"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>

<script>
// All available devices for manual selection — used by syncDeviceDropdowns()
window.flDevices = <?= json_encode(array_values(array_map(function($d) {
    $text = $d['serial_number'];
    if ($d['imei'])       $text .= ' [' . $d['imei'] . ']';
    if ($d['sim_number']) $text .= ' (' . $d['sim_number'] . ')';
    return ['value' => (string)$d['id'], 'text' => $text];
}, $availableDevices))) ?>;
</script>

<script>
(function () {
    var container = document.getElementById('deviceRowsContainer');
    var addBtn    = document.getElementById('addDeviceRowBtn');
    if (!container || !addBtn) return;

    var rowCounter = 1; // Row 0 is already rendered by PHP

    // ── Sync device dropdowns: hide devices already selected in other rows ──
    function syncDeviceDropdowns() {
        var rows = Array.from(container.querySelectorAll('.device-row'));

        // Build map: row element → currently selected device id (string)
        var rowValues = new Map();
        rows.forEach(function (row) {
            var sel = row.querySelector('select.ts-device');
            if (!sel || !sel.tomselect) return;
            var val = sel.tomselect.getValue() || '';
            rowValues.set(row, val);
        });

        // For each row rebuild the available options
        rows.forEach(function (row) {
            var sel = row.querySelector('select.ts-device');
            if (!sel || !sel.tomselect) return;
            var ts    = sel.tomselect;
            var myVal = rowValues.get(row) || '';

            // IDs taken by OTHER rows
            var othersTaken = new Set();
            rowValues.forEach(function (val, r) {
                if (r !== row && val) othersTaken.add(val);
            });

            // Single pass: add back freed options and remove taken ones
            (window.flDevices || []).forEach(function (dev) {
                if (othersTaken.has(dev.value)) {
                    if (ts.options[dev.value]) ts.removeOption(dev.value);
                } else {
                    if (!ts.options[dev.value]) ts.addOption({ value: dev.value, text: dev.text });
                }
            });

            ts.refreshOptions(false);

            // Restore this row's selection (removeOption clears the value too)
            if (myVal && ts.options[myVal]) {
                ts.setValue(myVal, true);
            }
        });
    }

    // ── Tom Select helpers ──────────────────────────────────
    function initTomSelectOnRow(row) {
        row.querySelectorAll('select.ts-device').forEach(function (sel) {
            if (sel.tomselect) return; // already initialized
            if (typeof TomSelect === 'undefined') return;
            new TomSelect(sel, {
                placeholder: '— szukaj urządzenia —',
                allowEmptyOption: true,
                maxOptions: null,
                searchField: ['text', 'value'],
                render: {
                    option: function (data, escape) {
                        return '<div>' + escape(data.text) + '</div>';
                    }
                }
            });
        });
    }

    function destroyTomSelectOnRow(row) {
        row.querySelectorAll('select.ts-device').forEach(function (sel) {
            if (sel.tomselect) sel.tomselect.destroy();
        });
    }

    function updateRowNumbers() {
        var rows = container.querySelectorAll('.device-row');
        rows.forEach(function (row, i) {
            var numEl = row.querySelector('.row-num');
            if (numEl) numEl.textContent = i + 1;
            var removeBtn = row.querySelector('.remove-row-btn');
            if (removeBtn) removeBtn.style.display = rows.length > 1 ? '' : 'none';
        });
    }

    function applyModeToRow(row, mode) {
        var autoCol   = row.querySelector('.col-mode-auto');
        var manualCol = row.querySelector('.col-mode-manual');
        if (autoCol)   autoCol.style.display   = (mode === 'auto')   ? '' : 'none';
        if (manualCol) manualCol.style.display = (mode === 'manual') ? '' : 'none';
        // Initialize TomSelect lazily — only when the manual column becomes visible
        // so that TomSelect never initializes on a hidden element (prevents dark/broken dropdown)
        if (mode === 'manual') {
            initTomSelectOnRow(row);
            // After init, apply current exclusions so this row doesn't show already-taken devices
            syncDeviceDropdowns();
        }
    }

    // Event delegation – mode toggle
    container.addEventListener('change', function (e) {
        if (e.target.type === 'radio' && e.target.name && e.target.name.startsWith('device_mode')) {
            applyModeToRow(e.target.closest('.device-row'), e.target.value);
        }
        // Device selection changed in any manual row — re-sync all rows
        if (e.target.classList.contains('ts-device') || e.target.closest('select.ts-device')) {
            syncDeviceDropdowns();
        }
    });

    // Event delegation – remove row
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-row-btn');
        if (btn) {
            var row = btn.closest('.device-row');
            destroyTomSelectOnRow(row);
            row.remove();
            updateRowNumbers();
            // Removed row may have held a device — free it in remaining rows
            syncDeviceDropdowns();
        }
    });

    // Add new device row
    addBtn.addEventListener('click', function () {
        var tpl = document.getElementById('deviceRowTemplate');
        if (!tpl) return;
        var idx   = rowCounter++;
        var clone = tpl.content.cloneNode(true);
        // Replace __IDX__ and __NUM__ in all relevant attributes
        clone.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/__IDX__/g, idx);
        });
        clone.querySelectorAll('[id]').forEach(function (el) {
            el.id = el.id.replace(/__IDX__/g, idx);
        });
        clone.querySelectorAll('[for]').forEach(function (el) {
            el.htmlFor = el.htmlFor.replace(/__IDX__/g, idx);
        });
        container.appendChild(clone);
        // TomSelect is initialized lazily in applyModeToRow when user switches to manual mode
        updateRowNumbers();
    });

    // Init: apply mode to first row, update remove-button visibility
    // TomSelect is initialized lazily inside applyModeToRow when mode === 'manual'
    container.querySelectorAll('.device-row').forEach(function (row) {
        var checked = row.querySelector('.btn-check:checked');
        if (checked) applyModeToRow(row, checked.value);
    });
    updateRowNumbers();
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
