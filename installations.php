<?php
/**
 * FleetLink System GPS - Installation Management (Montaż/Demontaż)
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
            // Send notification email to the current user
            if (!empty($curUser['email'])) {
                try {
                    $techName = $curUser['name'];
                    $vehicleList = implode(', ', array_values(array_unique($vehicleRegistrations)));
                    $body = getEmailTemplate('installation_created', [
                        'COUNT'      => (string)$n,
                        'DATE'       => date('d.m.Y', strtotime($installationDate)),
                        'TECHNICIAN' => $techName,
                        'VEHICLES'   => $vehicleList ?: '—',
                        'ADDRESS'    => $installationAddress ?: '—',
                        'NOTES'      => $notes ?: '—',
                        'SENDER_NAME' => $curUser['name'],
                    ]);
                    sendAppEmail($curUser['email'], $curUser['name'], 'Nowy montaż — FleetLink System GPS', $body);
                } catch (Exception $emailEx) { /* non-fatal */ }
            }
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

    } elseif ($postAction === 'add_disassembly') {
        // Schedule disassembly: mark device status as 'do_demontazu'
        $disDeviceId    = (int)($_POST['device_id'] ?? 0);
        $disTechnicianId= (int)($_POST['technician_id'] ?? 0) ?: getCurrentUser()['id'];
        $disDate        = sanitize($_POST['disassembly_date'] ?? date('Y-m-d'));
        $disNotes       = sanitize($_POST['notes'] ?? '');
        if (!$disDeviceId) { flashError('Nie wybrano urządzenia.'); redirect(getBaseUrl() . 'installations.php?action=demontaze'); }
        $db->beginTransaction();
        try {
            $devCheck = $db->prepare("SELECT id, model_id, status FROM devices WHERE id=? LIMIT 1");
            $devCheck->execute([$disDeviceId]);
            $devRow = $devCheck->fetch();
            if (!$devRow) { throw new Exception('Urządzenie nie istnieje.'); }
            if ($devRow['status'] === 'do_demontazu') { throw new Exception('Urządzenie jest już oznaczone do demontażu.'); }
            $oldStatus = $devRow['status'];
            $db->prepare("UPDATE devices SET status='do_demontazu' WHERE id=?")->execute([$disDeviceId]);
            adjustInventoryForStatusChange($db, $devRow['model_id'], $oldStatus, 'do_demontazu');
            // Update active installation notes/technician if exists
            if ($disNotes || $disTechnicianId) {
                $db->prepare("UPDATE installations SET technician_id=?, notes=CONCAT(COALESCE(notes,''), IF(notes IS NULL OR notes='', '', '\n'), ?) WHERE device_id=? AND status='aktywna'")
                   ->execute([$disTechnicianId, $disNotes ? '[Demontaż zaplanowany: ' . $disNotes . ']' : '[Demontaż zaplanowany]', $disDeviceId]);
            }
            $db->commit();
            flashSuccess('Urządzenie zostało oznaczone do demontażu.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php?action=demontaze');

    } elseif ($postAction === 'complete_disassembly') {
        // Complete disassembly: mark device as sprawny, close active installation
        $disDeviceId = (int)($_POST['device_id'] ?? 0);
        $disInstId   = (int)($_POST['installation_id'] ?? 0);
        $disDate     = sanitize($_POST['disassembly_date'] ?? date('Y-m-d'));
        if (!$disDeviceId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'installations.php?action=demontaze'); }
        $db->beginTransaction();
        try {
            $devInfo = $db->prepare("SELECT model_id, status FROM devices WHERE id=? FOR UPDATE");
            $devInfo->execute([$disDeviceId]);
            $devRow = $devInfo->fetch();
            if (!$devRow) { throw new Exception('Urządzenie nie istnieje.'); }
            // Close active installation
            if ($disInstId) {
                $db->prepare("UPDATE installations SET status='zakonczona', uninstallation_date=? WHERE id=? AND status='aktywna'")
                   ->execute([$disDate, $disInstId]);
            } else {
                $db->prepare("UPDATE installations SET status='zakonczona', uninstallation_date=? WHERE device_id=? AND status='aktywna'")
                   ->execute([$disDate, $disDeviceId]);
            }
            // Change device status back to sprawny (available for reinstallation)
            $db->prepare("UPDATE devices SET status='sprawny' WHERE id=?")->execute([$disDeviceId]);
            adjustInventoryForStatusChange($db, $devRow['model_id'], $devRow['status'], 'sprawny');
            $db->commit();
            flashSuccess('Demontaż zakończony. Urządzenie jest teraz dostępne do ponownego montażu.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'installations.php?action=demontaze');
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

    // All devices for the protocol modal (PS service device pickers)
    $allDevicesForProto = $db->query("
        SELECT d.id, d.serial_number, d.imei, m.name as model_name, mf.name as manufacturer_name
        FROM devices d
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        ORDER BY mf.name, m.name, d.serial_number
    ")->fetchAll();
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

// Fetch "Moje Montaże" — installations assigned to the current user
$myInstallations = [];
$myInstallationGroups = [];
if ($action === 'my') {
    $currentUser = getCurrentUser();
    $myUserId    = (int)$currentUser['id'];
    $mySearch    = sanitize($_GET['search'] ?? '');
    $myStatus    = sanitize($_GET['status'] ?? '');
    $mySql = "
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
        WHERE i.technician_id=?
    ";
    $myParams = [$myUserId];
    if ($myStatus) { $mySql .= " AND i.status=?"; $myParams[] = $myStatus; }
    if ($mySearch) {
        $mySql .= " AND (d.serial_number LIKE ? OR v.registration LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
        $myParams = array_merge($myParams, ["%$mySearch%","%$mySearch%","%$mySearch%","%$mySearch%"]);
    }
    $mySql .= " ORDER BY i.installation_date DESC, i.batch_id, i.id";
    try {
        $myStmt = $db->prepare($mySql);
        $myStmt->execute($myParams);
        $myInstallations = $myStmt->fetchAll();
    } catch (PDOException $e) {
        $mySqlFallback = str_replace(
            ['i.device_id, i.batch_id,', 'i.batch_id, i.id'],
            ['i.device_id, NULL as batch_id,', 'i.id'],
            $mySql
        );
        $myStmt = $db->prepare($mySqlFallback);
        $myStmt->execute($myParams);
        $myInstallations = $myStmt->fetchAll();
    }
    $mySeenBatches = [];
    foreach ($myInstallations as $inst) {
        $bid = $inst['batch_id'];
        if ($bid !== null) {
            if (!isset($mySeenBatches[$bid])) {
                $mySeenBatches[$bid] = count($myInstallationGroups);
                $myInstallationGroups[] = ['is_batch' => true, 'items' => [$inst], 'ids' => [$inst['id']]];
            } else {
                $idx = $mySeenBatches[$bid];
                $myInstallationGroups[$idx]['items'][] = $inst;
                $myInstallationGroups[$idx]['ids'][]   = $inst['id'];
            }
        } else {
            $myInstallationGroups[] = ['is_batch' => false, 'items' => [$inst], 'ids' => [$inst['id']]];
        }
    }
}

// Fetch "Demontaże" — devices scheduled for disassembly (status = 'do_demontazu')
$disassemblyDevices   = [];
$disassemblyInstalled = []; // device_id => active installation info
if ($action === 'demontaze') {
    $disSearch = sanitize($_GET['search'] ?? '');
    $disSql = "
        SELECT d.id as device_id, d.serial_number, d.imei, d.sim_number, d.status as device_status,
               m.name as model_name, mf.name as manufacturer_name,
               i.id as installation_id, i.installation_date, i.status as inst_status,
               v.registration, v.make,
               c.contact_name, c.company_name,
               u.name as technician_name
        FROM devices d
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        LEFT JOIN installations i ON i.device_id=d.id AND i.status='aktywna'
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN users u ON u.id=i.technician_id
        WHERE d.status='do_demontazu'
    ";
    $disParams = [];
    if ($disSearch) {
        $disSql .= " AND (d.serial_number LIKE ? OR v.registration LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
        $disParams = ["%$disSearch%","%$disSearch%","%$disSearch%","%$disSearch%"];
    }
    $disSql .= " ORDER BY i.installation_date DESC, d.id";
    $disStmt = $db->prepare($disSql);
    $disStmt->execute($disParams);
    $disassemblyDevices = $disStmt->fetchAll();

    // For "Nowy demontaż" modal — list of active installations to pick from
    $activeInstForDis = $db->query("
        SELECT i.id, i.installation_date, d.id as device_id, d.serial_number, d.status as device_status,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration, c.contact_name, c.company_name
        FROM installations i
        JOIN devices d ON d.id=i.device_id AND d.status NOT IN ('do_demontazu','wycofany','sprzedany')
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        WHERE i.status='aktywna'
        ORDER BY i.installation_date DESC
    ")->fetchAll();
}

$activePage = 'installations';
$pageTitle = 'Montaże';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>
        <?php if ($action === 'my'): ?>
        <i class="fas fa-user-check me-2 text-primary"></i>Moje Montaże
        <?php elseif ($action === 'demontaze'): ?>
        <i class="fas fa-tools me-2 text-warning"></i>Demontaże
        <?php else: ?>
        <i class="fas fa-car me-2 text-primary"></i>Montaże / Demontaże
        <?php endif; ?>
    </h1>
    <?php if ($action === 'list'): ?>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#instListAddModal"><i class="fas fa-plus me-2"></i>Nowy montaż</button>
    <?php elseif ($action === 'my'): ?>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#instListAddModal"><i class="fas fa-plus me-2"></i>Nowy montaż</button>
        <a href="installations.php" class="btn btn-outline-secondary"><i class="fas fa-list me-1"></i>Wszystkie montaże</a>
    </div>
    <?php elseif ($action === 'demontaze'): ?>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-warning text-white" data-bs-toggle="modal" data-bs-target="#newDisassemblyModal"><i class="fas fa-plus me-2"></i>Nowy demontaż</button>
        <a href="installations.php" class="btn btn-outline-secondary"><i class="fas fa-list me-1"></i>Lista montaży</a>
    </div>
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
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showInstPreview(<?= htmlspecialchars(json_encode([
                                    'id'                 => $inst['id'],
                                    'status'             => $inst['status'],
                                    'installation_date'  => $inst['installation_date'],
                                    'uninstallation_date'=> $inst['uninstallation_date'] ?? null,
                                    'serial_number'      => $inst['serial_number'],
                                    'manufacturer_name'  => $inst['manufacturer_name'],
                                    'model_name'         => $inst['model_name'],
                                    'registration'       => $inst['registration'],
                                    'make'               => $inst['make'],
                                    'client'             => $inst['company_name'] ?: $inst['contact_name'] ?? '',
                                    'technician_name'    => $inst['technician_name'] ?? '',
                                ]), ENT_QUOTES) ?>)"
                                title="Podgląd montażu">
                            <i class="fas fa-eye"></i>
                        </button>
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
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showInstPreview(<?= htmlspecialchars(json_encode([
                                    'id'                 => $inst['id'],
                                    'status'             => $inst['status'],
                                    'installation_date'  => $inst['installation_date'],
                                    'uninstallation_date'=> $inst['uninstallation_date'] ?? null,
                                    'serial_number'      => $inst['serial_number'],
                                    'manufacturer_name'  => $inst['manufacturer_name'],
                                    'model_name'         => $inst['model_name'],
                                    'registration'       => $inst['registration'],
                                    'make'               => $inst['make'],
                                    'client'             => $inst['company_name'] ?: $inst['contact_name'] ?? '',
                                    'technician_name'    => $inst['technician_name'] ?? '',
                                ]), ENT_QUOTES) ?>)"
                                title="Podgląd montażu">
                            <i class="fas fa-eye"></i>
                        </button>
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

function showInstPreview(data) {
    var statusMap = {
        'aktywna':    '<span class="badge bg-success">Aktywna</span>',
        'zakonczona': '<span class="badge bg-secondary">Zakończona</span>',
        'anulowana':  '<span class="badge bg-danger">Anulowana</span>'
    };
    var statusBadge = statusMap[data.status] || ('<span class="badge bg-secondary">' + data.status + '</span>');
    var formatDate = function(d) { return d ? d.split('-').reverse().join('.') : '—'; };

    document.getElementById('instPreviewTitle').textContent = 'Montaż #' + data.id;
    document.getElementById('instPreviewBody').innerHTML =
        '<table class="table table-sm table-borderless mb-0">' +
        '<tr><th class="text-muted" style="width:40%">Status</th><td>' + statusBadge + '</td></tr>' +
        '<tr><th class="text-muted">Data montażu</th><td>' + formatDate(data.installation_date) + '</td></tr>' +
        '<tr><th class="text-muted">Data demontażu</th><td>' + formatDate(data.uninstallation_date) + '</td></tr>' +
        '<tr><th class="text-muted">Urządzenie</th><td><strong>' + data.serial_number + '</strong><br><small class="text-muted">' + data.manufacturer_name + ' ' + data.model_name + '</small></td></tr>' +
        '<tr><th class="text-muted">Pojazd</th><td>' + data.registration + '<br><small class="text-muted">' + data.make + '</small></td></tr>' +
        '<tr><th class="text-muted">Klient</th><td>' + (data.client || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Technik</th><td>' + (data.technician_name || '—') + '</td></tr>' +
        '</table>';

    document.getElementById('instPreviewViewBtn').href = 'installations.php?action=view&id=' + data.id;
    document.getElementById('instPreviewPrintBtn').href = 'installations.php?action=print_batch&ids=' + data.id;

    var modal = new bootstrap.Modal(document.getElementById('instPreviewModal'));
    modal.show();
}
</script>

<!-- Installation Preview Modal -->
<div class="modal fade" id="instPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="instPreviewTitle"><i class="fas fa-car me-2 text-primary"></i>Podgląd montażu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="instPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
                <a id="instPreviewPrintBtn" href="#" target="_blank" class="btn btn-outline-dark btn-sm"><i class="fas fa-print me-1"></i>Drukuj</a>
                <a id="instPreviewViewBtn" href="#" class="btn btn-info btn-sm text-white"><i class="fas fa-eye me-1"></i>Otwórz pełny widok</a>
            </div>
        </div>
    </div>
</div>

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

<?php elseif ($action === 'my'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <input type="hidden" name="action" value="my">
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
                <a href="installations.php?action=my" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Moje montaże (<?= count($myInstallationGroups) ?> pozycji / <?= count($myInstallations) ?> urządzeń)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Data montażu</th><th>Urządzenie / Zlecenie</th><th>Pojazd</th><th>Klient</th><th>Technik</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($myInstallationGroups as $gi => $group): ?>
                <?php $first = $group['items'][0]; ?>
                <?php if ($group['is_batch'] && count($group['items']) > 1): ?>
                <tr class="table-info batch-header-row" data-batch-toggle="mybatch-<?= $gi ?>">
                    <td><?= formatDate($first['installation_date']) ?></td>
                    <td>
                        <span class="badge bg-primary me-1"><?= count($group['items']) ?> urządzeń</span>
                        <span class="text-muted small">Zlecenie grupowe</span>
                        <div class="small text-muted mt-1"><?= h(implode(', ', array_column($group['items'], 'serial_number'))) ?></div>
                    </td>
                    <td><?= h(implode(', ', array_unique(array_column($group['items'], 'registration')))) ?></td>
                    <td><?= h($first['company_name'] ?: $first['contact_name'] ?? '—') ?></td>
                    <td><?= h($first['technician_name'] ?? '—') ?></td>
                    <td><?= getStatusBadge($first['status'], 'installation') ?></td>
                    <td>
                        <a href="installations.php?action=print_batch&ids=<?= implode(',', $group['ids']) ?>"
                           class="btn btn-sm btn-outline-dark btn-action" title="Drukuj zlecenie"><i class="fas fa-print"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action"
                                onclick="toggleBatchRows('mybatch-<?= $gi ?>', this)" title="Rozwiń / zwiń">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </td>
                </tr>
                <?php foreach ($group['items'] as $inst): ?>
                <tr class="batch-child-row d-none" data-batch-group="mybatch-<?= $gi ?>">
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
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showInstPreview(<?= htmlspecialchars(json_encode(['id'=>$inst['id'],'status'=>$inst['status'],'installation_date'=>$inst['installation_date'],'uninstallation_date'=>$inst['uninstallation_date']??null,'serial_number'=>$inst['serial_number'],'manufacturer_name'=>$inst['manufacturer_name'],'model_name'=>$inst['model_name'],'registration'=>$inst['registration'],'make'=>$inst['make'],'client'=>$inst['company_name']?:$inst['contact_name']??'','technician_name'=>$inst['technician_name']??'']),ENT_QUOTES) ?>)"
                                title="Podgląd"><i class="fas fa-eye"></i></button>
                        <?php if ($inst['status'] === 'aktywna'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-action"
                                onclick="showUninstallModal(<?= $inst['id'] ?>, <?= $inst['device_id'] ?? 0 ?>, '<?= h($inst['serial_number']) ?>')">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                        <?php endif; ?>
                        <a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="btn btn-sm btn-outline-secondary btn-action" title="Otwórz"><i class="fas fa-external-link-alt"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
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
                        <button type="button" class="btn btn-sm btn-outline-info btn-action"
                                onclick="showInstPreview(<?= htmlspecialchars(json_encode(['id'=>$inst['id'],'status'=>$inst['status'],'installation_date'=>$inst['installation_date'],'uninstallation_date'=>$inst['uninstallation_date']??null,'serial_number'=>$inst['serial_number'],'manufacturer_name'=>$inst['manufacturer_name'],'model_name'=>$inst['model_name'],'registration'=>$inst['registration'],'make'=>$inst['make'],'client'=>$inst['company_name']?:$inst['contact_name']??'','technician_name'=>$inst['technician_name']??'']),ENT_QUOTES) ?>)"
                                title="Podgląd"><i class="fas fa-eye"></i></button>
                        <?php if ($inst['status'] === 'aktywna'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-action"
                                onclick="showUninstallModal(<?= $inst['id'] ?>, <?= $inst['device_id'] ?? 0 ?>, '<?= h($inst['serial_number']) ?>')">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                        <?php endif; ?>
                        <a href="installations.php?action=view&id=<?= $inst['id'] ?>" class="btn btn-sm btn-outline-secondary btn-action" title="Otwórz"><i class="fas fa-external-link-alt"></i></a>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($myInstallationGroups)): ?><tr><td colspan="7" class="text-center text-muted p-3">Brak montaży przypisanych do Ciebie.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function toggleBatchRows(groupKey, btn) {
    var rows = document.querySelectorAll('[data-batch-group="' + groupKey + '"]');
    var icon = btn.querySelector('i');
    rows.forEach(function(r) { r.classList.toggle('d-none'); });
    if (icon) { icon.classList.toggle('fa-chevron-down'); icon.classList.toggle('fa-chevron-up'); }
}
</script>

<!-- Installation Preview Modal (reused for my view) -->
<div class="modal fade" id="instPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="instPreviewTitle"><i class="fas fa-car me-2 text-primary"></i>Podgląd montażu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="instPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
                <a id="instPreviewPrintBtn" href="#" target="_blank" class="btn btn-outline-dark btn-sm"><i class="fas fa-print me-1"></i>Drukuj</a>
                <a id="instPreviewViewBtn" href="#" class="btn btn-info btn-sm text-white"><i class="fas fa-eye me-1"></i>Otwórz pełny widok</a>
            </div>
        </div>
    </div>
</div>
<script>
function showInstPreview(data) {
    var statusMap = {
        'aktywna':    '<span class="badge bg-success">Aktywna</span>',
        'zakonczona': '<span class="badge bg-secondary">Zakończona</span>',
        'anulowana':  '<span class="badge bg-danger">Anulowana</span>'
    };
    var statusBadge = statusMap[data.status] || ('<span class="badge bg-secondary">' + data.status + '</span>');
    var formatDate = function(d) { return d ? d.split('-').reverse().join('.') : '—'; };
    document.getElementById('instPreviewTitle').textContent = 'Montaż #' + data.id;
    document.getElementById('instPreviewBody').innerHTML =
        '<table class="table table-sm table-borderless mb-0">' +
        '<tr><th class="text-muted" style="width:40%">Status</th><td>' + statusBadge + '</td></tr>' +
        '<tr><th class="text-muted">Data montażu</th><td>' + formatDate(data.installation_date) + '</td></tr>' +
        '<tr><th class="text-muted">Data demontażu</th><td>' + formatDate(data.uninstallation_date) + '</td></tr>' +
        '<tr><th class="text-muted">Urządzenie</th><td><strong>' + data.serial_number + '</strong><br><small class="text-muted">' + data.manufacturer_name + ' ' + data.model_name + '</small></td></tr>' +
        '<tr><th class="text-muted">Pojazd</th><td>' + data.registration + '<br><small class="text-muted">' + data.make + '</small></td></tr>' +
        '<tr><th class="text-muted">Klient</th><td>' + (data.client || '—') + '</td></tr>' +
        '<tr><th class="text-muted">Technik</th><td>' + (data.technician_name || '—') + '</td></tr>' +
        '</table>';
    document.getElementById('instPreviewViewBtn').href = 'installations.php?action=view&id=' + data.id;
    document.getElementById('instPreviewPrintBtn').href = 'installations.php?action=print_batch&ids=' + data.id;
    new bootstrap.Modal(document.getElementById('instPreviewModal')).show();
}
</script>

<?php elseif ($action === 'demontaze'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <input type="hidden" name="action" value="demontaze">
            <div class="col-md-5">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Szukaj (nr seryjny, rejestracja, klient...)" value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="installations.php?action=demontaze" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="fas fa-tools text-warning me-1"></i>
        Urządzenia do demontażu (<?= count($disassemblyDevices) ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Urządzenie</th><th>Pojazd</th><th>Klient</th><th>Technik</th><th>Data montażu</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($disassemblyDevices as $dd): ?>
                <tr>
                    <td>
                        <a href="devices.php?action=view&id=<?= $dd['device_id'] ?>"><?= h($dd['serial_number']) ?></a>
                        <br><small class="text-muted"><?= h($dd['manufacturer_name'] . ' ' . $dd['model_name']) ?></small>
                        <?php if ($dd['imei']): ?><br><small class="text-muted">IMEI: <?= h($dd['imei']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?= $dd['registration'] ? h($dd['registration']) : '<span class="text-muted">—</span>' ?>
                        <?php if ($dd['make']): ?><br><small class="text-muted"><?= h($dd['make']) ?></small><?php endif; ?>
                    </td>
                    <td><?= h($dd['company_name'] ?: $dd['contact_name'] ?? '—') ?></td>
                    <td><?= h($dd['technician_name'] ?? '—') ?></td>
                    <td><?= $dd['installation_date'] ? formatDate($dd['installation_date']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= getStatusBadge($dd['device_status'], 'device') ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action"
                                title="Zakończ demontaż"
                                onclick="showCompleteDisassemblyModal(<?= $dd['device_id'] ?>, '<?= h($dd['serial_number']) ?>', <?= (int)($dd['installation_id'] ?? 0) ?>)">
                            <i class="fas fa-check-circle"></i>
                        </button>
                        <a href="devices.php?action=view&id=<?= $dd['device_id'] ?>" class="btn btn-sm btn-outline-secondary btn-action" title="Szczegóły urządzenia">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <?php if ($dd['installation_id']): ?>
                        <a href="installations.php?action=view&id=<?= $dd['installation_id'] ?>" class="btn btn-sm btn-outline-info btn-action" title="Otwórz montaż">
                            <i class="fas fa-car"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($disassemblyDevices)): ?>
                <tr><td colspan="7" class="text-center text-muted p-3"><i class="fas fa-check-circle text-success me-2"></i>Brak urządzeń do demontażu.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Complete Disassembly Modal -->
<div class="modal fade" id="completeDisassemblyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="complete_disassembly">
                <input type="hidden" name="device_id" id="cdDeviceId" value="">
                <input type="hidden" name="installation_id" id="cdInstallationId" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2 text-success"></i>Zakończ demontaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Urządzenie: <strong id="cdSerialNumber"></strong></p>
                    <p class="text-muted small mb-3">Po zakończeniu demontażu urządzenie wróci do stanu magazynowego jako <strong>Sprawne</strong> i będzie dostępne do ponownego montażu.</p>
                    <div class="mb-3">
                        <label class="form-label required-star">Data demontażu</label>
                        <input type="date" name="disassembly_date" id="cdDate" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Zakończ demontaż</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- New Disassembly Modal -->
<div class="modal fade" id="newDisassemblyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_disassembly">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tools me-2 text-warning"></i>Nowy demontaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required-star">Urządzenie do demontażu</label>
                            <select name="device_id" class="form-select" required id="disassemblyDeviceSelect">
                                <option value="">— wybierz urządzenie z aktywnego montażu —</option>
                                <?php foreach ($activeInstForDis ?? [] as $ai): ?>
                                <option value="<?= $ai['device_id'] ?>">
                                    <?= h($ai['serial_number']) ?> — <?= h($ai['manufacturer_name'] . ' ' . $ai['model_name']) ?>
                                    | Rejestracja: <?= h($ai['registration']) ?>
                                    <?= ($ai['company_name'] ?: $ai['contact_name']) ? '| ' . h($ai['company_name'] ?: $ai['contact_name']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($activeInstForDis ?? [])): ?>
                            <div class="form-text text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Brak aktywnych montaży do zaplanowania demontażu.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik wykonujący demontaż</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= getCurrentUser()['id'] == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planowana data demontażu</label>
                            <input type="date" name="disassembly_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Powód demontażu, dodatkowe informacje..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-warning text-white" <?= empty($activeInstForDis ?? []) ? 'disabled' : '' ?>>
                        <i class="fas fa-tools me-1"></i>Zaplanuj demontaż
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCompleteDisassemblyModal(deviceId, serial, installationId) {
    document.getElementById('cdDeviceId').value = deviceId;
    document.getElementById('cdInstallationId').value = installationId;
    document.getElementById('cdSerialNumber').textContent = serial;
    document.getElementById('cdDate').value = new Date().toISOString().split('T')[0];
    new bootstrap.Modal(document.getElementById('completeDisassemblyModal')).show();
}
</script>


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
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addProtocolModal"><i class="fas fa-clipboard me-1"></i>Protokół</button>
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

<!-- Add Protocol Modal -->
<div class="modal fade" id="addProtocolModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="protocols.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="batch_ref" value="inst:<?= $installation['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clipboard me-2 text-secondary"></i>Nowy protokół</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Typ protokołu</label>
                            <select name="type" id="protoModalType" class="form-select" required>
                                <option value="PP" selected>PP — Protokół Przekazania</option>
                                <option value="PU">PU — Protokół Uruchomienia</option>
                                <option value="PS">PS — Protokół Serwisowy</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data</label>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= getCurrentUser()['id'] == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Podpis klienta</label>
                            <input type="text" name="client_signature" class="form-control" placeholder="np. Jan Kowalski">
                        </div>

                        <!-- PS-specific section -->
                        <div id="protoModalPsSection" class="col-12" style="display:none">
                            <hr class="my-1">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-warning-emphasis"><i class="fas fa-tools me-1"></i>Serwis dotyczy urządzenia</label>
                                    <select name="service_device_id" class="form-select">
                                        <option value="">— wybierz urządzenie —</option>
                                        <?php foreach ($allDevicesForProto as $dev): ?>
                                        <option value="<?= $dev['id'] ?>" <?= $dev['id'] == $installation['device_id'] ? 'selected' : '' ?>>
                                            <?= h($dev['manufacturer_name'] . ' ' . $dev['model_name'] . ' — ' . $dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-warning-emphasis"><i class="fas fa-wrench me-1"></i>Typ czynności</label>
                                    <select name="service_type" id="protoModalSvcType" class="form-select">
                                        <option value="">— wybierz —</option>
                                        <option value="przeglad">Przegląd</option>
                                        <option value="naprawa">Naprawa</option>
                                        <option value="wymiana">Wymiana</option>
                                        <option value="aktualizacja">Aktualizacja firmware</option>
                                        <option value="inne">Inne</option>
                                    </select>
                                </div>
                                <div id="protoModalReplWrapper" class="col-12" style="display:none">
                                    <label class="form-label fw-semibold text-danger"><i class="fas fa-exchange-alt me-1"></i>Urządzenie zastępcze</label>
                                    <select name="replacement_device_id" class="form-select">
                                        <option value="">— wybierz —</option>
                                        <?php foreach ($allDevicesForProto as $dev): ?>
                                        <option value="<?= $dev['id'] ?>">
                                            <?= h($dev['manufacturer_name'] . ' ' . $dev['model_name'] . ' — ' . $dev['serial_number']) ?><?= $dev['imei'] ? ' [' . h($dev['imei']) . ']' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <hr class="my-1">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Uwagi / Zakres prac</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save me-1"></i>Utwórz protokół</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function() {
    var typeEl = document.getElementById('protoModalType');
    var psSection = document.getElementById('protoModalPsSection');
    var svcTypeEl = document.getElementById('protoModalSvcType');
    var replWrapper = document.getElementById('protoModalReplWrapper');
    if (typeEl) {
        typeEl.addEventListener('change', function() {
            psSection.style.display = this.value === 'PS' ? '' : 'none';
        });
    }
    if (svcTypeEl) {
        svcTypeEl.addEventListener('change', function() {
            replWrapper.style.display = this.value === 'wymiana' ? '' : 'none';
        });
    }
})();
</script>

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
$orderNumber    = sprintf('PP/%s/%04d', date('Y', strtotime($installDate ?: 'now')), $batchFirstId);
$companyName    = '';
$companyAddr    = '';
$companyPhone   = '';
$companyEmail   = '';
try {
    $cfg = [];
    $settingsStmt = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('company_name','company_address','company_city','company_postal_code','company_phone','company_email')");
    foreach ($settingsStmt->fetchAll() as $s) { $cfg[$s['key']] = $s['value']; }
    $companyName  = $cfg['company_name'] ?? '';
    $companyAddr  = trim(($cfg['company_address'] ?? '') . ', ' . ($cfg['company_postal_code'] ?? '') . ' ' . ($cfg['company_city'] ?? ''), ', ');
    $companyPhone = $cfg['company_phone'] ?? '';
    $companyEmail = $cfg['company_email'] ?? '';
} catch (Exception $e) {}
$clientAddrParts = array_filter([
    $firstRow['client_address'] ?? '',
    trim(($firstRow['client_postal_code'] ?? '') . ' ' . ($firstRow['client_city'] ?? '')),
]);
$clientAddrFull = implode(', ', $clientAddrParts);
$installAddr = $firstRow['installation_address'] ?? '';
?>
<style>
/* ── Protokół przekazania – profesjonalne style druku ─── */
* { box-sizing: border-box; }
body.print-page { background: #f0f4f8; }
.print-doc {
    background: #fff;
    color: #111827;
    font-family: 'DM Sans','Segoe UI','Helvetica Neue',Arial,sans-serif;
    max-width: 800px;
    margin: 0 auto;
    border-radius: 10px;
    box-shadow: 0 4px 32px rgba(37,99,235,.10);
}
/* Header */
.pd-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 24px 28px 18px;
    border-bottom: 3px solid #2563eb;
    background: linear-gradient(135deg,#f8faff 0%,#e8f0ff 100%);
    border-radius: 10px 10px 0 0;
}
.pd-logo-img { height: 40px; display: block; margin-bottom: 6px; }
.pd-logo { font-size: 1.5rem; font-weight: 900; color: #111827; letter-spacing: -1px; }
.pd-logo span { color: #2563eb; }
.pd-logo-sub { font-size: .76rem; color: #6b7280; margin-top: 2px; line-height: 1.5; }
.pd-title-block { text-align: right; }
.pd-doc-type { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: #2563eb; margin-bottom: 4px; }
.pd-doc-number { font-size: 1.3rem; font-weight: 800; color: #111827; letter-spacing: -0.5px; }
.pd-doc-meta { font-size: .8rem; color: #6b7280; margin-top: 3px; }
/* Info grid */
.pd-info-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0;
    border-bottom: 1px solid #e5e7eb;
}
.pd-info-cell {
    padding: 14px 18px;
    border-right: 1px solid #e5e7eb;
}
.pd-info-cell:last-child { border-right: none; }
.pd-info-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #2563eb; margin-bottom: 5px; }
.pd-info-value { font-size: .88rem; font-weight: 700; color: #111827; }
.pd-info-sub { font-size: .76rem; color: #6b7280; margin-top: 2px; }
/* Section heading */
.pd-section {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px 6px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #2563eb;
}
.pd-section::after { content:''; flex:1; height:2px; background:#e5e7eb; }
/* Device table */
.pd-device-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 8px;
    font-size: .82rem;
}
.pd-device-table thead tr {
    background: #1d4ed8;
    color: #fff;
}
.pd-device-table thead th {
    padding: 9px 10px;
    font-weight: 700;
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
    text-align: left;
    white-space: nowrap;
}
.pd-device-table tbody tr.dev-main-row td {
    padding: 9px 10px 3px;
    border-bottom: none;
    vertical-align: middle;
    font-weight: 600;
}
.pd-device-table tbody tr.dev-detail-row td {
    padding: 2px 10px 9px;
    border-bottom: 1px solid #e5e7eb;
    font-size: .76rem;
    color: #6b7280;
    vertical-align: top;
}
.pd-device-table tbody tr.dev-main-row:nth-child(4n+1),
.pd-device-table tbody tr.dev-detail-row:nth-child(4n+2) { background: #f8faff; }
.pd-device-table tbody tr:last-child td { border-bottom: none; }
.dev-num { color: #2563eb; font-weight: 800; font-size: .88rem; }
.dev-reg { font-family: 'Courier New', monospace; font-weight: 800; font-size: .9rem; letter-spacing: 1px; color: #111827; background: #f0f4ff; padding: 1px 5px; border-radius: 3px; }
.dev-imei { font-family: 'Courier New', monospace; font-size: .78rem; color: #374151; }
.dev-serial { font-family: 'Courier New', monospace; font-size: .78rem; font-weight: 700; color: #111827; }
.detail-chip { display: inline-flex; align-items: center; gap: 4px; background: #f3f4f6; border-radius: 4px; padding: 2px 6px; margin: 2px 3px 2px 0; font-size: .73rem; color: #374151; }
.detail-chip i { font-size: .63rem; color: #9ca3af; }
/* Accessories table */
.pd-acc-table { width:100%; border-collapse:collapse; font-size:.8rem; margin-bottom:8px; }
.pd-acc-table thead th { background:#374151; color:#fff; padding:8px 10px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; text-align:left; }
.pd-acc-table tbody td { padding:7px 10px; border-bottom:1px solid #e5e7eb; }
.pd-acc-table tbody tr:nth-child(even) { background:#f9fafb; }
.pd-acc-table tbody tr:last-child td { border-bottom:none; }
/* Statement box */
.pd-statement { padding:12px 18px; margin:8px 0; background:#f8faff; border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb; font-size:.8rem; color:#374151; line-height:1.6; }
/* Signatures */
.pd-sig-section { padding: 20px 28px 16px; }
.pd-sig-row { display: flex; gap: 20px; }
.pd-sig-box { flex: 1; border: 1px solid #d1d5db; border-radius: 7px; padding: 14px 16px; }
.pd-sig-box-title { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 6px; }
.pd-sig-line { border-top: 2px solid #111827; margin-top: 48px; padding-top: 7px; font-size: .78rem; color: #374151; font-weight: 600; }
.pd-sig-date { font-size: .73rem; color: #9ca3af; margin-top: 3px; }
/* Footer */
.pd-footer {
    text-align: center;
    font-size: .7rem;
    color: #9ca3af;
    padding: 12px 18px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
    border-radius: 0 0 10px 10px;
}
.pd-footer strong { color: #6b7280; }
/* Print media */
@media print {
    @page { size: A4; margin: 14mm 14mm; }
    body { background: #fff !important; margin: 0 !important; }
    body.print-page { background: #fff !important; }
    .no-print { display: none !important; }
    .navbar, footer, .sidebar, .page-header { display: none !important; }
    .container-fluid { padding: 0 !important; }
    .print-doc { max-width: 100% !important; box-shadow: none !important; border-radius: 0 !important; }
    .pd-header { background: #f8faff !important; border-radius: 0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pd-device-table thead tr { background: #1d4ed8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pd-device-table tbody tr.dev-main-row:nth-child(4n+1),
    .pd-device-table tbody tr.dev-detail-row:nth-child(4n+2) { background: #f8faff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pd-acc-table thead th { background: #374151 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pd-footer { background: #f9fafb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pd-statement { background: #f8faff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .dev-reg { background: #f0f4ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pd-device-table { page-break-inside: auto; }
    .pd-device-table tbody tr { page-break-inside: avoid; }
    .pd-sig-section { page-break-inside: avoid; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h5 class="mb-0"><i class="fas fa-file-contract me-2 text-primary"></i>Protokół przekazania — podgląd wydruku</h5>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Drukuj / PDF
        </button>
        <a href="installations.php" class="btn btn-outline-secondary">
            <i class="fas fa-list me-1"></i>Lista montaży
        </a>
    </div>
</div>

<div class="print-doc">
    <!-- ══ NAGŁÓWEK ══════════════════════════════════════════════════════ -->
    <div class="pd-header">
        <div>
            <img src="<?= getBaseUrl() ?>assets/fleetlink-logo-v2.png" alt="FleetLink" class="pd-logo-img">
            <?php if ($companyName): ?>
            <div class="pd-logo"><?= h($companyName) ?></div>
            <?php else: ?>
            <div class="pd-logo">Fleet<span>Link</span></div>
            <?php endif; ?>
            <div class="pd-logo-sub">
                <?php if ($companyAddr): ?><i class="fas fa-map-marker-alt me-1"></i><?= h($companyAddr) ?><?php endif; ?>
                <?php if ($companyPhone): ?><br><i class="fas fa-phone me-1"></i><?= h($companyPhone) ?><?php endif; ?>
                <?php if ($companyEmail): ?><br><i class="fas fa-envelope me-1"></i><?= h($companyEmail) ?><?php endif; ?>
            </div>
        </div>
        <div class="pd-title-block">
            <div class="pd-doc-type">Protokół przekazania</div>
            <div class="pd-doc-number"><?= h($orderNumber) ?></div>
            <div class="pd-doc-meta">Data sporządzenia: <strong><?= formatDate($installDate) ?></strong></div>
            <div class="pd-doc-meta">Liczba urządzeń: <strong><?= $batchCount ?></strong></div>
        </div>
    </div>

    <!-- ══ DANE STRON ════════════════════════════════════════════════════ -->
    <div class="pd-info-row">
        <div class="pd-info-cell">
            <div class="pd-info-label"><i class="fas fa-building me-1"></i>Przekazujący / Wykonawca</div>
            <div class="pd-info-value"><?= $companyName ? h($companyName) : 'FleetLink' ?></div>
            <?php if ($companyAddr): ?><div class="pd-info-sub"><i class="fas fa-map-marker-alt me-1" style="color:#2563eb"></i><?= h($companyAddr) ?></div><?php endif; ?>
            <?php if ($companyPhone): ?><div class="pd-info-sub"><i class="fas fa-phone me-1" style="color:#2563eb"></i><?= h($companyPhone) ?></div><?php endif; ?>
        </div>
        <div class="pd-info-cell">
            <div class="pd-info-label"><i class="fas fa-user me-1"></i>Odbiorca / Klient</div>
            <div class="pd-info-value"><?= h($clientLabel) ?></div>
            <?php if ($firstRow && $firstRow['contact_name'] && $firstRow['company_name']): ?>
            <div class="pd-info-sub"><i class="fas fa-user me-1" style="color:#2563eb"></i><?= h($firstRow['contact_name']) ?></div>
            <?php endif; ?>
            <?php if ($firstRow && $firstRow['client_phone']): ?><div class="pd-info-sub"><i class="fas fa-phone me-1" style="color:#2563eb"></i><?= h($firstRow['client_phone']) ?></div><?php endif; ?>
            <?php if ($clientAddrFull): ?><div class="pd-info-sub"><i class="fas fa-map-marker-alt me-1" style="color:#2563eb"></i><?= h($clientAddrFull) ?></div><?php endif; ?>
        </div>
        <div class="pd-info-cell">
            <div class="pd-info-label"><i class="fas fa-user-cog me-1"></i>Technik instalujący</div>
            <div class="pd-info-value"><?= h($technicianName) ?></div>
            <div class="pd-info-sub"><i class="fas fa-calendar me-1" style="color:#2563eb"></i>Data montażu: <strong><?= formatDate($installDate) ?></strong></div>
            <?php if ($installAddr): ?><div class="pd-info-sub"><i class="fas fa-warehouse me-1" style="color:#2563eb"></i><?= h($installAddr) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- ══ WYKAZ URZĄDZEŃ ════════════════════════════════════════════════ -->
    <div class="pd-section"><i class="fas fa-microchip"></i>Przekazywane urządzenia GPS</div>
    <table class="pd-device-table">
        <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>Model urządzenia</th>
                <th>Numer seryjny</th>
                <th>IMEI</th>
                <th>Nr rejestracyjny</th>
                <th>Data instalacji</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batchInstallations as $i => $bi):
                $vehicleStr = trim(($bi['make'] ?? '') . ' ' . ($bi['vehicle_model'] ?? ''));
                $hasSim     = !empty($bi['sim_number']);
                $hasLoc     = !empty($bi['location_in_vehicle']);
                $hasNotes   = !empty($bi['notes']);
                $hasVehicle = !empty($vehicleStr);
            ?>
            <tr class="dev-main-row">
                <td><span class="dev-num"><?= $i + 1 ?></span></td>
                <td><?= h($bi['manufacturer_name'] . ' ' . $bi['model_name']) ?></td>
                <td><span class="dev-serial"><?= h($bi['serial_number']) ?></span></td>
                <td><span class="dev-imei"><?= h($bi['imei'] ?? '—') ?></span></td>
                <td><span class="dev-reg"><?= h($bi['registration']) ?></span></td>
                <td style="white-space:nowrap"><?= formatDate($bi['installation_date']) ?></td>
            </tr>
            <tr class="dev-detail-row">
                <td></td>
                <td colspan="5">
                    <?php if ($hasVehicle): ?>
                    <span class="detail-chip"><i class="fas fa-car"></i><?= h($vehicleStr) ?></span>
                    <?php endif; ?>
                    <?php if ($hasSim): ?>
                    <span class="detail-chip"><i class="fas fa-sim-card"></i>SIM: <?= h($bi['sim_number']) ?></span>
                    <?php endif; ?>
                    <?php if ($hasLoc): ?>
                    <span class="detail-chip"><i class="fas fa-tools"></i><?= h($bi['location_in_vehicle']) ?></span>
                    <?php endif; ?>
                    <?php if ($hasNotes): ?>
                    <span class="detail-chip" style="color:#92400e;background:#fffbeb"><i class="fas fa-sticky-note" style="color:#d97706"></i><?= h($bi['notes']) ?></span>
                    <?php endif; ?>
                    <?php if (!$hasVehicle && !$hasSim && !$hasLoc && !$hasNotes): ?>
                    <span style="color:#d1d5db;font-size:.75rem">brak dodatkowych informacji</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($batchInstallations)): ?>
            <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px">Brak danych do wyświetlenia.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ══ AKCESORIA ════════════════════════════════════════════════════ -->
    <?php if (!empty($batchAccessories)): ?>
    <div class="pd-section"><i class="fas fa-box-open"></i>Materiały i akcesoria</div>
    <table class="pd-acc-table" style="margin:0 0 8px">
        <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>Akcesorium / Materiał</th>
                <th style="width:80px;text-align:center">Ilość</th>
                <th>Wydał</th>
                <th>Data wydania</th>
                <th>Uwagi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batchAccessories as $ai => $bacc): ?>
            <tr>
                <td style="color:#2563eb;font-weight:700"><?= $ai + 1 ?></td>
                <td style="font-weight:600"><?= h($bacc['accessory_name']) ?></td>
                <td style="text-align:center;font-weight:800"><?= (int)$bacc['quantity'] ?> szt.</td>
                <td><?= h($bacc['user_name']) ?></td>
                <td style="white-space:nowrap;color:#6b7280"><?= $bacc['issued_at'] ? formatDate(substr($bacc['issued_at'],0,10)) : '—' ?></td>
                <td style="color:#6b7280;font-size:.75rem"><?= h($bacc['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ══ OŚWIADCZENIE ═════════════════════════════════════════════════ -->
    <div class="pd-statement">
        <strong style="color:#111827">Oświadczenie:</strong>
        Strony potwierdzają, że wymienione powyżej urządzenia GPS zostały prawidłowo zainstalowane, przetestowane i przekazane w stanie sprawnym.
        Odbiorca przyjmuje urządzenia bez zastrzeżeń i potwierdza ich zgodność z zamówieniem.
    </div>

    <!-- ══ PODPISY ══════════════════════════════════════════════════════ -->
    <div class="pd-sig-section">
        <div class="pd-sig-row">
            <div class="pd-sig-box">
                <div class="pd-sig-box-title"><i class="fas fa-user-cog me-1"></i>Przekazujący / Technik</div>
                <div class="pd-sig-line">
                    <?= h($technicianName) ?><br>
                    <span style="font-size:.75rem;font-weight:400;color:#9ca3af">Imię i nazwisko / Podpis</span>
                </div>
                <div class="pd-sig-date">Data: <?= formatDate($installDate) ?></div>
            </div>
            <div class="pd-sig-box">
                <div class="pd-sig-box-title"><i class="fas fa-user me-1"></i>Odbierający / Klient</div>
                <div class="pd-sig-line">
                    <?= h($clientLabel) ?><br>
                    <span style="font-size:.75rem;font-weight:400;color:#9ca3af">Imię i nazwisko / Podpis / Pieczątka</span>
                </div>
                <div class="pd-sig-date">Data: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
            </div>
        </div>
    </div>

    <!-- ══ STOPKA ═══════════════════════════════════════════════════════ -->
    <div class="pd-footer">
        <strong><?= $companyName ? h($companyName) : 'FleetLink System GPS' ?></strong>
        &nbsp;&mdash;&nbsp;
        Dokument wygenerowany automatycznie &nbsp;&mdash;&nbsp;
        <?= date('d.m.Y, H:i') ?>
        &nbsp;&mdash;&nbsp;
        Nr protokołu: <strong><?= h($orderNumber) ?></strong>
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

<?php if ($action === 'list' || $action === 'my'): ?>
<!-- Modal: Nowy montaż (lista montaży) — wielourządzeniowy z TomSelect -->
<div class="modal fade" id="instListAddModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="installations.php" id="instListAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-car me-2 text-success"></i>Nowy montaż</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required-star">Urządzenia GPS do montażu</label>
                            <?php if (empty($availableModels) && empty($availableDevices)): ?>
                            <div class="alert alert-warning py-2">Brak dostępnych urządzeń w magazynie. <a href="devices.php?action=add">Dodaj urządzenia</a>.</div>
                            <?php endif; ?>
                            <div id="instListDevRowsContainer" class="d-flex flex-column gap-2 mb-2">
                                <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                                    <div class="row g-2 align-items-center flex-wrap">
                                        <div class="col-auto"><span class="row-num badge bg-secondary">1</span></div>
                                        <div class="col-auto">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="ilm_auto_0" value="auto" checked>
                                                <label class="btn btn-outline-secondary" for="ilm_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="ilm_manual_0" value="manual">
                                                <label class="btn btn-outline-primary" for="ilm_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny</label>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm col-mode-auto">
                                            <select name="model_id[0]" class="form-select form-select-sm">
                                                <option value="">— wybierz model —</option>
                                                <?php foreach ($availableModels as $m): ?>
                                                <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm col-mode-manual" style="display:none">
                                            <select name="device_id_manual[0]" class="form-select form-select-sm ts-device-il">
                                                <option value="">— wybierz urządzenie —</option>
                                                <?php
                                                $ilGroup0 = '';
                                                foreach ($availableDevices as $dev):
                                                    $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                                    if ($grp !== $ilGroup0) { if ($ilGroup0) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $ilGroup0 = $grp; }
                                                ?>
                                                <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' ['.h($dev['imei']).']' : '' ?><?= $dev['sim_number'] ? ' ('.h($dev['sim_number']).')' : '' ?></option>
                                                <?php endforeach; if ($ilGroup0) echo '</optgroup>'; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-auto">
                                            <input type="text" name="vehicle_registration[0]" class="form-control form-control-sm" required placeholder="Nr rej. pojazdu" style="text-transform:uppercase;min-width:130px">
                                        </div>
                                        <div class="col-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none" title="Usuń"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="instListAddRowBtn" class="btn btn-sm btn-outline-success"><i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie</button>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="instListClientSel" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($clients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="instListQCBtn" title="Dodaj nowego klienta"><i class="fas fa-user-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adres instalacji</label>
                            <input type="text" name="installation_address" id="instListAddrFld" class="form-control" placeholder="Automatycznie z klienta lub wpisz ręcznie">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data montażu</label>
                            <input type="date" name="installation_date" id="instListDateFld" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="aktywna" selected>Aktywna</option>
                                <option value="zakonczona">Zakończona</option>
                                <option value="anulowana">Anulowana</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Miejsce montażu w pojeździe</label>
                            <input type="text" name="location_in_vehicle" class="form-control" placeholder="np. pod deską rozdzielczą">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success btn-sm" <?= (empty($availableModels) && empty($availableDevices)) ? 'disabled' : '' ?>><i class="fas fa-car me-1"></i>Zarejestruj montaż</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick-add klienta (montaż z listy) -->
<div class="modal fade" id="instListQCModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="instListQCErr"></div>
                <div class="mb-2"><label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label><input type="text" id="instListQCName" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Nazwa firmy</label><input type="text" id="instListQCCompany" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Telefon</label><input type="text" id="instListQCPhone" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">E-mail</label><input type="email" id="instListQCEmail" class="form-control form-control-sm"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="instListQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<template id="instListDevRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center flex-wrap">
            <div class="col-auto"><span class="row-num badge bg-secondary">__NUM__</span></div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="ilm_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="ilm_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="ilm_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="ilm_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny</label>
                </div>
            </div>
            <div class="col-12 col-sm col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($availableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm ts-device-il">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $ilTplGroup = '';
                    foreach ($availableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $ilTplGroup) { if ($ilTplGroup) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $ilTplGroup = $grp; }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' ['.h($dev['imei']).']' : '' ?><?= $dev['sim_number'] ? ' ('.h($dev['sim_number']).')' : '' ?></option>
                    <?php endforeach; if ($ilTplGroup) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-12 col-sm-auto">
                <input type="text" name="vehicle_registration[__IDX__]" class="form-control form-control-sm" required placeholder="Nr rej. pojazdu" style="text-transform:uppercase;min-width:130px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" title="Usuń"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>

<script>
window.flIlDevices = <?= json_encode(array_values(array_map(function($d) {
    $t = $d['serial_number'];
    if ($d['imei'])       $t .= ' [' . $d['imei'] . ']';
    if ($d['sim_number']) $t .= ' (' . $d['sim_number'] . ')';
    return ['value' => (string)$d['id'], 'text' => $t];
}, $availableDevices))) ?>;
window.flIlClientAddresses = <?= json_encode(array_reduce($clients, function($c, $cl) {
    $parts = array_filter([$cl['address'] ?? '', trim(($cl['postal_code'] ?? '') . ' ' . ($cl['city'] ?? ''))]);
    $c[(string)$cl['id']] = implode(', ', $parts);
    return $c;
}, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
(function () {
    var container  = document.getElementById('instListDevRowsContainer');
    var addBtn     = document.getElementById('instListAddRowBtn');
    var rowCounter = 1;
    if (!container || !addBtn) return;

    function ilSyncDropdowns() {
        var rows = Array.from(container.querySelectorAll('.device-row'));
        var rowVals = new Map();
        rows.forEach(function (row) {
            var sel = row.querySelector('select.ts-device-il');
            if (!sel || !sel.tomselect) return;
            rowVals.set(row, sel.tomselect.getValue() || '');
        });
        rows.forEach(function (row) {
            var sel = row.querySelector('select.ts-device-il');
            if (!sel || !sel.tomselect) return;
            var ts = sel.tomselect, myVal = rowVals.get(row) || '';
            var taken = new Set();
            rowVals.forEach(function (v, r) { if (r !== row && v) taken.add(v); });
            (window.flIlDevices || []).forEach(function (dev) {
                if (taken.has(dev.value)) { if (ts.options[dev.value]) ts.removeOption(dev.value); }
                else { if (!ts.options[dev.value]) ts.addOption({ value: dev.value, text: dev.text }); }
            });
            ts.refreshOptions(false);
            if (myVal && ts.options[myVal]) ts.setValue(myVal, true);
        });
    }
    function ilInitTS(row) {
        row.querySelectorAll('select.ts-device-il').forEach(function (sel) {
            if (sel.tomselect || typeof TomSelect === 'undefined') return;
            new TomSelect(sel, { placeholder: '— szukaj urządzenia —', allowEmptyOption: true, maxOptions: null, searchField: ['text','value'] });
        });
    }
    function ilDestroyTS(row) {
        row.querySelectorAll('select.ts-device-il').forEach(function (sel) { if (sel.tomselect) sel.tomselect.destroy(); });
    }
    function ilApplyMode(row, mode) {
        var ac = row.querySelector('.col-mode-auto'), mc = row.querySelector('.col-mode-manual');
        if (ac) ac.style.display = mode === 'auto'   ? '' : 'none';
        if (mc) mc.style.display = mode === 'manual' ? '' : 'none';
        if (mode === 'manual') { ilInitTS(row); ilSyncDropdowns(); }
    }
    function ilUpdateNums() {
        var rows = container.querySelectorAll('.device-row');
        rows.forEach(function (row, i) {
            var n = row.querySelector('.row-num'); if (n) n.textContent = i + 1;
            var b = row.querySelector('.remove-row-btn'); if (b) b.style.display = rows.length > 1 ? '' : 'none';
        });
    }
    container.addEventListener('change', function (e) {
        if (e.target.type === 'radio' && (e.target.name || '').startsWith('device_mode'))
            ilApplyMode(e.target.closest('.device-row'), e.target.value);
        if (e.target.classList.contains('ts-device-il') || e.target.closest('select.ts-device-il'))
            ilSyncDropdowns();
    });
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-row-btn');
        if (btn) { var row = btn.closest('.device-row'); ilDestroyTS(row); row.remove(); ilUpdateNums(); ilSyncDropdowns(); }
    });
    addBtn.addEventListener('click', function () {
        var tpl = document.getElementById('instListDevRowTemplate'); if (!tpl) return;
        var idx = rowCounter++, clone = tpl.content.cloneNode(true);
        clone.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/__IDX__/g, idx); });
        clone.querySelectorAll('[id]').forEach(function (el)   { el.id   = el.id.replace(/__IDX__/g, idx); });
        clone.querySelectorAll('[for]').forEach(function (el)  { el.htmlFor = el.htmlFor.replace(/__IDX__/g, idx); });
        container.appendChild(clone); ilUpdateNums();
    });

    var modal = document.getElementById('instListAddModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function () {
            Array.from(container.querySelectorAll('.device-row')).forEach(function (row, i) {
                if (i > 0) { ilDestroyTS(row); row.remove(); }
            });
            rowCounter = 1;
            var firstRow = container.querySelector('.device-row');
            if (firstRow) {
                var reg = firstRow.querySelector('input[name="vehicle_registration[0]"]'); if (reg) reg.value = '';
                var ar = firstRow.querySelector('input[value="auto"]'); if (ar) { ar.checked = true; ilApplyMode(firstRow, 'auto'); }
                var ms = firstRow.querySelector('select[name="model_id[0]"]'); if (ms) ms.value = '';
                ilDestroyTS(firstRow);
            }
            document.getElementById('instListClientSel').value = '';
            document.getElementById('instListAddrFld').value = '';
            document.getElementById('instListDateFld').value = new Date().toISOString().slice(0, 10);
            ilUpdateNums();
        });
    }

    var cliSel = document.getElementById('instListClientSel');
    if (cliSel) cliSel.addEventListener('change', function () {
        var v = this.value, addr = document.getElementById('instListAddrFld');
        if (addr) addr.value = (v && window.flIlClientAddresses && window.flIlClientAddresses[v]) ? window.flIlClientAddresses[v] : '';
    });

    var qcBtn = document.getElementById('instListQCBtn');
    if (qcBtn) qcBtn.addEventListener('click', function () {
        ['instListQCName','instListQCCompany','instListQCPhone','instListQCEmail'].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
        var err = document.getElementById('instListQCErr'); if (err) err.classList.add('d-none');
        new bootstrap.Modal(document.getElementById('instListQCModal')).show();
    });
    var qcSave = document.getElementById('instListQCSaveBtn');
    if (qcSave) qcSave.addEventListener('click', function () {
        var name = (document.getElementById('instListQCName').value || '').trim();
        var errEl = document.getElementById('instListQCErr');
        if (!name) { errEl.textContent = 'Imię i nazwisko kontaktu jest wymagane.'; errEl.classList.remove('d-none'); return; }
        errEl.classList.add('d-none');
        var fd = new FormData();
        fd.append('action', 'quick_add_client');
        fd.append('csrf_token', document.querySelector('#instListAddForm input[name="csrf_token"]').value);
        fd.append('contact_name', name);
        fd.append('company_name', document.getElementById('instListQCCompany').value);
        fd.append('phone', document.getElementById('instListQCPhone').value);
        fd.append('email', document.getElementById('instListQCEmail').value);
        fetch('installations.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
                var sel = document.getElementById('instListClientSel');
                var opt = document.createElement('option'); opt.value = data.id; opt.textContent = data.label; opt.selected = true;
                sel.appendChild(opt); sel.dispatchEvent(new Event('change'));
                bootstrap.Modal.getInstance(document.getElementById('instListQCModal')).hide();
            })
            .catch(function () { errEl.textContent = 'Błąd połączenia z serwerem.'; errEl.classList.remove('d-none'); });
    });

    container.querySelectorAll('.device-row').forEach(function (row) {
        var checked = row.querySelector('.btn-check:checked'); if (checked) ilApplyMode(row, checked.value);
    });
    ilUpdateNums();
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
<style>
/* Mobile fix: TomSelect dropdown scrollable in modal */
@media (max-width: 767.98px) {
    .ts-dropdown { max-height: 200px; overflow-y: auto; }
    #instListAddModal .modal-body { overflow-y: auto; }
    #instListAddModal .device-row .col-12 { width: 100%; }
}
</style>
