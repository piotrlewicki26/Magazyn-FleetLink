<?php
/**
 * FleetLink System GPS - Zarządzanie Zleceniami Montażowymi (Work Orders)
 * Zlecenie zawiera datę, klienta, adres instalacji i przypisanego technika.
 * Urządzenia GPS są przypisywane do zlecenia osobno z poziomu listy urządzeń.
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();

// Ensure work_orders table exists (auto-migration)
try {
    $db->query("SELECT 1 FROM work_orders LIMIT 1");
} catch (PDOException $e) {
    // Run migration
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `work_orders` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_number` VARCHAR(30) NOT NULL UNIQUE,
              `date` DATE NOT NULL,
              `client_id` INT UNSIGNED DEFAULT NULL,
              `installation_address` VARCHAR(255) DEFAULT NULL,
              `technician_id` INT UNSIGNED DEFAULT NULL,
              `status` ENUM('nowe','w_trakcie','zakonczone','anulowane','archiwum') NOT NULL DEFAULT 'nowe',
              `notes` TEXT DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        try {
            $db->exec("ALTER TABLE `installations` ADD COLUMN IF NOT EXISTS `work_order_id` INT UNSIGNED DEFAULT NULL AFTER `batch_id`");
        } catch (PDOException $ex2) { /* column may already exist */ }
    } catch (PDOException $migEx) { /* ignore */ }
}

// Ensure 'archiwum' is a valid status value (add to ENUM if missing)
try {
    $db->exec("ALTER TABLE `work_orders` MODIFY COLUMN `status` ENUM('nowe','w_trakcie','zakonczone','anulowane','archiwum') NOT NULL DEFAULT 'nowe'");
} catch (PDOException $e) { /* ignore if already correct or DB doesn't support */ }

// Also ensure work_order_id column exists on installations
try {
    $db->query("SELECT work_order_id FROM installations LIMIT 1");
} catch (PDOException $e) {
    try {
        $db->exec("ALTER TABLE `installations` ADD COLUMN `work_order_id` INT UNSIGNED DEFAULT NULL AFTER `batch_id`");
    } catch (PDOException $ex) { /* ignore */ }
}

// ── One-time migration: create work_orders for completed/archived installations
// that were created in the old "Montaże" list and have no work_order_id yet.
try {
    $orphanStmt = $db->query(
        "SELECT i.id, i.installation_date, i.client_id, i.technician_id, i.installation_address, i.notes
         FROM installations i
         WHERE i.status IN ('zakonczona','archiwum')
           AND (i.work_order_id IS NULL OR i.work_order_id = 0)"
    );
    $orphans = $orphanStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orphans as $inst) {
        // Generate a unique order number based on installation id
        $orderNum = 'MIG-' . $inst['id'] . '-' . substr(md5($inst['id'] . $inst['installation_date']), 0, 6);
        // Skip if already migrated (idempotent)
        $exists = $db->prepare("SELECT id FROM work_orders WHERE order_number=?");
        $exists->execute([$orderNum]);
        if ($exists->fetchColumn()) { continue; }
        $instDate = $inst['installation_date'] ?: date('Y-m-d');
        $db->prepare(
            "INSERT INTO work_orders (order_number, date, client_id, installation_address, technician_id, status, notes, created_by)
             VALUES (?,?,?,?,?,'zakonczone',?,NULL)"
        )->execute([
            $orderNum,
            $instDate,
            $inst['client_id'] ?: null,
            $inst['installation_address'] ?: null,
            $inst['technician_id'] ?: null,
            $inst['notes'] ?: null,
        ]);
        $newOrderId = (int)$db->lastInsertId();
        if ($newOrderId) {
            $db->prepare("UPDATE installations SET work_order_id=? WHERE id=?")->execute([$newOrderId, $inst['id']]);
        }
    }
} catch (PDOException $e) { /* ignore migration errors */ }

$action = sanitize($_GET['action'] ?? 'list');
$id     = (int)($_GET['id'] ?? 0);
$currentUser = getCurrentUser();

// ─── POST handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'orders.php');
    }
    $postAction = sanitize($_POST['action'] ?? '');

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
        $orderDate   = sanitize($_POST['date'] ?? '');
        $clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
        $techId      = (int)($_POST['technician_id'] ?? 0) ?: null;
        $address     = sanitize($_POST['installation_address'] ?? '');
        $notes       = sanitize($_POST['notes'] ?? '');
        $isAjax      = !empty($_POST['ajax']);

        if (!$techId) $techId = (int)$currentUser['id'];

        if (empty($orderDate)) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['error' => 'Data zlecenia jest wymagana.']); exit; }
            flashError('Data zlecenia jest wymagana.');
            redirect(getBaseUrl() . 'orders.php?action=add');
        }

        $orderNumber = generateOrderNumber();

        $db->prepare("INSERT INTO work_orders (order_number, date, client_id, installation_address, technician_id, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$orderNumber, $orderDate, $clientId, $address ?: null, $techId, 'nowe', $notes ?: null, $currentUser['id']]);
        $newOrderId = (int)$db->lastInsertId();

        // Send email notification to assigned technician
        try {
            $techStmt = $db->prepare("SELECT name, email FROM users WHERE id=? LIMIT 1");
            $techStmt->execute([$techId]);
            $techData = $techStmt->fetch();

            $clientLabel = '—';
            if ($clientId) {
                $clientStmt = $db->prepare("SELECT contact_name, company_name FROM clients WHERE id=? LIMIT 1");
                $clientStmt->execute([$clientId]);
                $cData = $clientStmt->fetch();
                if ($cData) $clientLabel = $cData['company_name'] ? $cData['company_name'] . ' — ' . $cData['contact_name'] : $cData['contact_name'];
            }

            $orderUrl = getBaseUrl() . 'orders.php?action=view&id=' . $newOrderId;
            $body = getEmailTemplate('order_created', [
                'ORDER_NUMBER' => $orderNumber,
                'DATE'         => date('d.m.Y', strtotime($orderDate)),
                'TECHNICIAN'   => $techData['name'] ?? '—',
                'CLIENT'       => $clientLabel,
                'ADDRESS'      => $address ?: '—',
                'NOTES'        => $notes ?: '—',
                'ORDER_URL'    => $orderUrl,
                'SENDER_NAME'  => $currentUser['name'],
            ]);

            if (!empty($techData['email'])) {
                sendAppEmail($techData['email'], $techData['name'] ?? '', 'Nowe zlecenie montażowe ' . $orderNumber . ' — FleetLink', $body);
            }
        } catch (Exception $emailEx) { /* non-fatal */ }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'      => true,
                'order_number' => $orderNumber,
                'order_id'     => $newOrderId,
                'client_id'    => $clientId,
                'client_label' => $clientLabel !== '—' ? $clientLabel : '',
                'date'         => $orderDate,
                'technician'   => $techData['name'] ?? '',
                'redirect'     => getBaseUrl() . 'orders.php?action=view&id=' . $newOrderId,
            ]);
            exit;
        }

        flashSuccess('Zlecenie ' . $orderNumber . ' zostało utworzone.');
        redirect(getBaseUrl() . 'orders.php?action=view&id=' . $newOrderId);

    } elseif ($postAction === 'edit') {
        $editId    = (int)($_POST['id'] ?? 0);
        $orderDate = sanitize($_POST['date'] ?? '');
        $clientId  = (int)($_POST['client_id'] ?? 0) ?: null;
        $techId    = (int)($_POST['technician_id'] ?? 0) ?: null;
        $address   = sanitize($_POST['installation_address'] ?? '');
        $notes     = sanitize($_POST['notes'] ?? '');
        $status    = sanitize($_POST['status'] ?? 'nowe');
        $allowed   = ['nowe','w_trakcie','zakonczone','anulowane','archiwum'];
        if (!in_array($status, $allowed)) $status = 'nowe';

        if (!$editId || empty($orderDate)) {
            flashError('Nieprawidłowe dane.');
            redirect(getBaseUrl() . 'orders.php');
        }

        // Block editing archived orders
        $archCheck = $db->prepare("SELECT status FROM work_orders WHERE id=?");
        $archCheck->execute([$editId]);
        $archRow = $archCheck->fetch();
        if ($archRow && $archRow['status'] === 'archiwum' && $status === 'archiwum') {
            flashError('Nie można edytować zarchiwizowanego zlecenia. Najpierw zmień status na Nowe.');
            redirect(getBaseUrl() . 'orders.php?action=view&id=' . $editId);
        }

        $db->prepare("UPDATE work_orders SET date=?, client_id=?, installation_address=?, technician_id=?, status=?, notes=? WHERE id=?")
           ->execute([$orderDate, $clientId, $address ?: null, $techId, $status, $notes ?: null, $editId]);
        flashSuccess('Zlecenie zaktualizowane.');
        redirect(getBaseUrl() . 'orders.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        if (!isAdmin()) { flashError('Kasowanie zleceń jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'orders.php'); }
        $delId = (int)($_POST['id'] ?? 0);
        if (!$delId) { flashError('Nieprawidłowy identyfikator.'); redirect(getBaseUrl() . 'orders.php'); }
        // Unlink associated installations
        $db->prepare("UPDATE installations SET work_order_id=NULL WHERE work_order_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM work_orders WHERE id=?")->execute([$delId]);
        flashSuccess('Zlecenie usunięte.');
        redirect(getBaseUrl() . 'orders.php');

    } elseif ($postAction === 'archive_group') {
        // Archive all orders in a group (passed as JSON array of IDs)
        $groupIds = json_decode($_POST['group_ids'] ?? '[]', true);
        if (!is_array($groupIds)) $groupIds = [];
        $groupIds = array_map('intval', $groupIds);
        $groupIds = array_filter($groupIds);
        if (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $db->prepare("UPDATE work_orders SET status='archiwum' WHERE id IN ($placeholders)")
               ->execute(array_values($groupIds));
        }
        flashSuccess('Wszystkie zlecenia grupy przeniesione do archiwum.');
        redirect(getBaseUrl() . 'orders.php');

    } elseif ($postAction === 'restore_group') {
        // Restore all orders in a group to 'nowe' status
        $groupIds = json_decode($_POST['group_ids'] ?? '[]', true);
        if (!is_array($groupIds)) $groupIds = [];
        $groupIds = array_map('intval', $groupIds);
        $groupIds = array_filter($groupIds);
        if (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $db->prepare("UPDATE work_orders SET status='nowe' WHERE id IN ($placeholders)")
               ->execute(array_values($groupIds));
        }
        flashSuccess('Zlecenia grupy przywrócone do statusu Nowe.');
        redirect(getBaseUrl() . 'orders.php?action=archive');

    } elseif ($postAction === 'delete_group') {
        if (!isAdmin()) { flashError('Kasowanie zleceń jest dostępne tylko dla Administratora.'); redirect(getBaseUrl() . 'orders.php'); }
        $groupIds = json_decode($_POST['group_ids'] ?? '[]', true);
        if (!is_array($groupIds)) $groupIds = [];
        $groupIds = array_map('intval', $groupIds);
        $groupIds = array_filter($groupIds);
        if (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $db->prepare("UPDATE installations SET work_order_id=NULL WHERE work_order_id IN ($placeholders)")
               ->execute(array_values($groupIds));
            $db->prepare("DELETE FROM work_orders WHERE id IN ($placeholders)")
               ->execute(array_values($groupIds));
        }
        flashSuccess('Grupa zleceń usunięta.');
        redirect(getBaseUrl() . 'orders.php');

    } elseif ($postAction === 'change_status') {
        $csId     = (int)($_POST['id'] ?? 0);
        $newSt    = sanitize($_POST['new_status'] ?? '');
        $isAjaxSt = !empty($_POST['ajax']);
        $allowed  = ['nowe','w_trakcie','zakonczone','anulowane','archiwum'];
        if (!$csId || !in_array($newSt, $allowed)) {
            if ($isAjaxSt) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nieprawidłowe dane.']); exit; }
            flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'orders.php');
        }
        $db->prepare("UPDATE work_orders SET status=? WHERE id=?")->execute([$newSt, $csId]);
        $flashMsg = 'Status zlecenia zmieniony.';

        // Auto-generate PP (Protokół Przekazania) for each linked installation when order is completed
        if ($newSt === 'zakonczone') {
            try {
                $linkedInst = $db->prepare("SELECT id, device_id, technician_id FROM installations WHERE work_order_id=? AND status='aktywna'");
                $linkedInst->execute([$csId]);
                $instRows = $linkedInst->fetchAll();
                $protoCount = 0;
                foreach ($instRows as $ir) {
                    $protocolNum = generateProtocolNumber('PP');
                    $protoTech   = $ir['technician_id'] ?? (int)$currentUser['id'];
                    $db->prepare("INSERT INTO protocols (installation_id, type, protocol_number, date, technician_id, notes) VALUES (?,?,?,?,?,?)")
                       ->execute([$ir['id'], 'PP', $protocolNum, date('Y-m-d'), $protoTech, 'Automatycznie wygenerowany po zakończeniu zlecenia.']);
                    $protoCount++;
                }
                if ($protoCount > 0) {
                    $protoLabel = $protoCount === 1 ? 'protokół' : ($protoCount <= 4 ? 'protokoły' : 'protokołów');
                    $flashMsg .= " Wygenerowano $protoCount $protoLabel przekazania (PP).";
                }
            } catch (Exception $protoEx) { /* non-fatal */ }
        }

        if ($isAjaxSt) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        flashSuccess($flashMsg);
        redirect(getBaseUrl() . 'orders.php?action=view&id=' . $csId);

    } elseif ($postAction === 'add_disassembly') {
        $disDeviceId     = (int)($_POST['device_id'] ?? 0);
        $disTechnicianId = (int)($_POST['technician_id'] ?? 0) ?: (int)$currentUser['id'];
        $disDate         = sanitize($_POST['disassembly_date'] ?? date('Y-m-d'));
        $disNotes        = sanitize($_POST['notes'] ?? '');
        if (!$disDeviceId) { flashError('Nie wybrano urządzenia.'); redirect(getBaseUrl() . 'orders.php?action=demontaze'); }
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
        redirect(getBaseUrl() . 'orders.php?action=demontaze');

    } elseif ($postAction === 'remove_device_from_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $instId  = (int)($_POST['installation_id'] ?? 0);
        if (!$orderId || !$instId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'orders.php'); }
        // Verify the order is not archived before allowing removal
        $orderCheck = $db->prepare("SELECT status FROM work_orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) { flashError('Zlecenie nie istnieje.'); redirect(getBaseUrl() . 'orders.php'); }
        if ($orderRow['status'] === 'archiwum') { flashError('Nie można edytować zarchiwizowanego zlecenia.'); redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId); }
        // Only unlink the installation from the order (do not delete the installation)
        $db->prepare("UPDATE installations SET work_order_id=NULL WHERE id=? AND work_order_id=?")
           ->execute([$instId, $orderId]);
        flashSuccess('Urządzenie zostało odłączone od zlecenia.');
        redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId);

    } elseif ($postAction === 'add_device_to_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $instId  = (int)($_POST['installation_id'] ?? 0);
        if (!$orderId || !$instId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'orders.php'); }
        // Verify the order is not archived
        $orderCheck = $db->prepare("SELECT status FROM work_orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) { flashError('Zlecenie nie istnieje.'); redirect(getBaseUrl() . 'orders.php'); }
        if ($orderRow['status'] === 'archiwum') { flashError('Nie można edytować zarchiwizowanego zlecenia.'); redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId); }
        // Assign the installation to this order (installation must be active and not already in another order)
        $instCheck = $db->prepare("SELECT id, status, work_order_id FROM installations WHERE id=?");
        $instCheck->execute([$instId]);
        $instRow = $instCheck->fetch();
        if (!$instRow) { flashError('Montaż nie istnieje.'); redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId); }
        if ($instRow['status'] !== 'aktywna') { flashError('Wybrany montaż nie jest aktywny.'); redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId); }
        if ($instRow['work_order_id'] && $instRow['work_order_id'] != $orderId) { flashError('Ten montaż jest już przypisany do innego zlecenia.'); redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId); }
        $db->prepare("UPDATE installations SET work_order_id=? WHERE id=?")
           ->execute([$orderId, $instId]);
        flashSuccess('Urządzenie zostało dodane do zlecenia.');
        redirect(getBaseUrl() . 'orders.php?action=view&id=' . $orderId);

    } elseif ($postAction === 'complete_disassembly') {
        $disDeviceId = (int)($_POST['device_id'] ?? 0);
        $disInstId   = (int)($_POST['installation_id'] ?? 0);
        $disDate     = sanitize($_POST['disassembly_date'] ?? date('Y-m-d'));
        if (!$disDeviceId) { flashError('Nieprawidłowe dane.'); redirect(getBaseUrl() . 'orders.php?action=demontaze'); }
        $db->beginTransaction();
        try {
            $devInfo = $db->prepare("SELECT model_id, status FROM devices WHERE id=? FOR UPDATE");
            $devInfo->execute([$disDeviceId]);
            $devRow = $devInfo->fetch();
            if (!$devRow) { throw new Exception('Urządzenie nie istnieje.'); }
            if ($disInstId) {
                $db->prepare("UPDATE installations SET status='zakonczona', uninstallation_date=? WHERE id=? AND status='aktywna'")->execute([$disDate, $disInstId]);
            } else {
                $db->prepare("UPDATE installations SET status='zakonczona', uninstallation_date=? WHERE device_id=? AND status='aktywna'")->execute([$disDate, $disDeviceId]);
            }
            $db->prepare("UPDATE devices SET status='sprawny' WHERE id=?")->execute([$disDeviceId]);
            adjustInventoryForStatusChange($db, $devRow['model_id'], $devRow['status'], 'sprawny');
            $db->commit();
            flashSuccess('Demontaż zakończony. Urządzenie jest teraz dostępne do ponownego montażu.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'orders.php?action=demontaze');
    }
}

// ─── Load data for views ─────────────────────────────────────────────────────

$clients = $db->query("SELECT id, contact_name, company_name, address, city, postal_code, phone FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
$users   = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$availableInstForOrder = [];

// Data needed for "Nowy protokół" modal (always loaded)
$modalInstSingle = $db->query("
    SELECT i.id, v.registration, d.serial_number, NULL as batch_id
    FROM installations i
    JOIN vehicles v ON v.id=i.vehicle_id
    JOIN devices d ON d.id=i.device_id
    WHERE i.status IN ('aktywna','zakonczona') AND (i.batch_id IS NULL)
    ORDER BY i.installation_date DESC LIMIT 50
")->fetchAll();
$modalInstBatches = [];
try {
    $modalInstBatches = $db->query("
        SELECT i.batch_id, MIN(i.id) as first_id, COUNT(i.id) as device_count,
               GROUP_CONCAT(DISTINCT v.registration ORDER BY v.registration SEPARATOR ', ') as registrations,
               MIN(i.installation_date) as installation_date
        FROM installations i JOIN vehicles v ON v.id=i.vehicle_id
        WHERE i.status IN ('aktywna','zakonczona') AND i.batch_id IS NOT NULL
        GROUP BY i.batch_id ORDER BY MIN(i.installation_date) DESC LIMIT 30
    ")->fetchAll();
} catch (PDOException $e) {}

$orders = [];
$totalOrders = 0;
$myOrders = [];
$myTotalOrders = 0;
$archiveOrders = [];
$archiveTotalOrders = 0;

if ($action === 'list') {
    $filterStatus = sanitize($_GET['status'] ?? '');
    $search = sanitize($_GET['search'] ?? '');
    $filterTech = (int)($_GET['technician'] ?? 0);
    $perPage = in_array((int)($_GET['per_page'] ?? 10), [10, 50, 100]) ? (int)($_GET['per_page'] ?? 10) : 10;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $countSql = "SELECT COUNT(*) FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        LEFT JOIN users u ON u.id=wo.technician_id
        WHERE wo.status != 'archiwum'";
    $listSql = "SELECT wo.id, wo.order_number, wo.date, wo.status, wo.client_id,
               wo.installation_address, wo.notes,
               c.contact_name, c.company_name, c.phone as client_phone,
               u.name as technician_name,
               (SELECT COUNT(*) FROM installations i WHERE i.work_order_id=wo.id) as device_count
        FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        LEFT JOIN users u ON u.id=wo.technician_id
        WHERE wo.status != 'archiwum'";
    $params = [];
    if ($filterStatus && $filterStatus !== 'archiwum') { $listSql .= " AND wo.status=?"; $countSql .= " AND wo.status=?"; $params[] = $filterStatus; }
    if ($filterTech)   { $listSql .= " AND wo.technician_id=?"; $countSql .= " AND wo.technician_id=?"; $params[] = $filterTech; }
    if ($search) {
        $clause = " AND (wo.order_number LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ? OR wo.installation_address LIKE ?)";
        $listSql .= $clause; $countSql .= $clause;
        $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
    }

    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalOrders = (int)$countStmt->fetchColumn();

    $listSql .= " ORDER BY wo.date DESC, wo.id DESC LIMIT ? OFFSET ?";

    $listStmt = $db->prepare($listSql);
    $listStmt->execute(array_merge($params, [$perPage, $offset]));
    $orders = $listStmt->fetchAll();

} elseif ($action === 'my') {
    $mySearch = sanitize($_GET['search'] ?? '');
    $myStatus = sanitize($_GET['status'] ?? '');
    $myPerPage = in_array((int)($_GET['per_page'] ?? 10), [10, 50, 100]) ? (int)($_GET['per_page'] ?? 10) : 10;
    $myPage = max(1, (int)($_GET['page'] ?? 1));
    $myOffset = ($myPage - 1) * $myPerPage;

    $myCountSql = "SELECT COUNT(*) FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        WHERE wo.technician_id=? AND wo.status != 'archiwum'";
    $mySql = "SELECT wo.id, wo.order_number, wo.date, wo.status, wo.client_id,
              wo.installation_address, wo.notes,
              c.contact_name, c.company_name, c.phone as client_phone,
              u.name as technician_name,
              (SELECT COUNT(*) FROM installations i WHERE i.work_order_id=wo.id) as device_count
       FROM work_orders wo
       LEFT JOIN clients c ON c.id=wo.client_id
       LEFT JOIN users u ON u.id=wo.technician_id
       WHERE wo.technician_id=? AND wo.status != 'archiwum'";
    $myParams = [(int)$currentUser['id']];
    $myCountParams = [(int)$currentUser['id']];
    if ($myStatus && $myStatus !== 'archiwum') { $mySql .= " AND wo.status=?"; $myCountSql .= " AND wo.status=?"; $myParams[] = $myStatus; $myCountParams[] = $myStatus; }
    if ($mySearch) {
        $clause = " AND (wo.order_number LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
        $mySql .= $clause; $myCountSql .= $clause;
        $myParams = array_merge($myParams, ["%$mySearch%","%$mySearch%","%$mySearch%"]);
        $myCountParams = array_merge($myCountParams, ["%$mySearch%","%$mySearch%","%$mySearch%"]);
    }
    $myCountStmt = $db->prepare($myCountSql);
    $myCountStmt->execute($myCountParams);
    $myTotalOrders = (int)$myCountStmt->fetchColumn();
    $mySql .= " ORDER BY wo.date DESC, wo.id DESC LIMIT ? OFFSET ?";
    $myStmt = $db->prepare($mySql);
    $myStmt->execute(array_merge($myParams, [$myPerPage, $myOffset]));
    $myOrders = $myStmt->fetchAll();

} elseif ($action === 'archive') {
    $archSearch = sanitize($_GET['search'] ?? '');
    $archTech = (int)($_GET['technician'] ?? 0);
    $archPerPage = in_array((int)($_GET['per_page'] ?? 10), [10, 50, 100]) ? (int)($_GET['per_page'] ?? 10) : 10;
    $archPage = max(1, (int)($_GET['page'] ?? 1));
    $archOffset = ($archPage - 1) * $archPerPage;

    $archCountSql = "SELECT COUNT(*) FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        LEFT JOIN users u ON u.id=wo.technician_id
        WHERE wo.status = 'archiwum'";
    $archSql = "SELECT wo.id, wo.order_number, wo.date, wo.status, wo.client_id,
               wo.installation_address, wo.notes,
               c.contact_name, c.company_name, c.phone as client_phone,
               u.name as technician_name,
               (SELECT COUNT(*) FROM installations i WHERE i.work_order_id=wo.id) as device_count
        FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        LEFT JOIN users u ON u.id=wo.technician_id
        WHERE wo.status = 'archiwum'";
    $archParams = [];
    if ($archTech) { $archSql .= " AND wo.technician_id=?"; $archCountSql .= " AND wo.technician_id=?"; $archParams[] = $archTech; }
    if ($archSearch) {
        $clause = " AND (wo.order_number LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ? OR wo.installation_address LIKE ?)";
        $archSql .= $clause; $archCountSql .= $clause;
        $archParams = array_merge($archParams, ["%$archSearch%","%$archSearch%","%$archSearch%","%$archSearch%"]);
    }
    $archCountStmt = $db->prepare($archCountSql);
    $archCountStmt->execute($archParams);
    $archiveTotalOrders = (int)$archCountStmt->fetchColumn();
    $archSql .= " ORDER BY wo.date DESC, wo.id DESC LIMIT ? OFFSET ?";
    $archStmt = $db->prepare($archSql);
    $archStmt->execute(array_merge($archParams, [$archPerPage, $archOffset]));
    $archiveOrders = $archStmt->fetchAll();

} elseif ($action === 'view' && $id) {
    $ordStmt = $db->prepare("
        SELECT wo.*,
               c.contact_name, c.company_name, c.phone as client_phone, c.email as client_email,
               c.address as client_address, c.city as client_city, c.postal_code as client_postal_code,
               c.nip as client_nip,
               u.name as technician_name, u.email as technician_email
        FROM work_orders wo
        LEFT JOIN clients c ON c.id=wo.client_id
        LEFT JOIN users u ON u.id=wo.technician_id
        WHERE wo.id=?
    ");
    $ordStmt->execute([$id]);
    $order = $ordStmt->fetch();
    if (!$order) { flashError('Zlecenie nie istnieje.'); redirect(getBaseUrl() . 'orders.php'); }

    // Assigned devices (installations linked to this order)
    $devStmt = $db->prepare("
        SELECT i.id as inst_id, i.installation_date, i.status as inst_status,
               i.location_in_vehicle, i.notes as inst_notes,
               d.id as device_id, d.serial_number, d.imei, d.sim_number,
               m.name as model_name, mf.name as manufacturer_name,
               v.registration, v.make, v.model_name as vehicle_model
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN manufacturers mf ON mf.id=m.manufacturer_id
        JOIN vehicles v ON v.id=i.vehicle_id
        WHERE i.work_order_id=?
        ORDER BY i.installation_date DESC, i.id
    ");
    $devStmt->execute([$id]);
    $orderDevices = $devStmt->fetchAll();

    // Active installations not yet assigned to any order (for "add device" modal)
    $availableInstForOrder = [];
    if ($order['status'] !== 'archiwum') {
        $availStmt = $db->prepare("
            SELECT i.id as inst_id, i.installation_date,
                   d.id as device_id, d.serial_number, d.imei,
                   m.name as model_name, mf.name as manufacturer_name,
                   v.registration, v.make,
                   c.contact_name, c.company_name
            FROM installations i
            JOIN devices d ON d.id=i.device_id
            JOIN models m ON m.id=d.model_id
            JOIN manufacturers mf ON mf.id=m.manufacturer_id
            JOIN vehicles v ON v.id=i.vehicle_id
            LEFT JOIN clients c ON c.id=i.client_id
            WHERE i.status='aktywna' AND (i.work_order_id IS NULL OR i.work_order_id=0)
            ORDER BY i.installation_date DESC, i.id DESC
            LIMIT 200
        ");
        $availStmt->execute();
        $availableInstForOrder = $availStmt->fetchAll();
    }

} elseif ($action === 'add') {
    // Pre-fill technician to current user
    $prefillTechId = (int)$currentUser['id'];

} elseif ($action === 'demontaze') {
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
    // For "Nowy demontaż" modal — list of active installations
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

} elseif ($action === 'protocols') {
    $protoSearch = sanitize($_GET['search'] ?? '');
    $protoType   = sanitize($_GET['type'] ?? '');
    $protoSql    = "SELECT p.id, p.protocol_number, p.type, p.date, p.notes,
               u.name as technician_name, v.registration, d.serial_number,
               m.name as model_name, mf.name as manufacturer_name
        FROM protocols p
        LEFT JOIN users u ON u.id=p.technician_id
        LEFT JOIN installations i ON i.id=p.installation_id
        LEFT JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN devices d ON d.id=i.device_id
        LEFT JOIN models m ON m.id=d.model_id
        LEFT JOIN manufacturers mf ON mf.id=m.manufacturer_id
        WHERE p.installation_id IS NOT NULL";
    $protoParams = [];
    if ($protoType && in_array($protoType, ['PP','PU','PS'])) {
        $protoSql .= " AND p.type=?"; $protoParams[] = $protoType;
    }
    if ($protoSearch) {
        $protoSql .= " AND (p.protocol_number LIKE ? OR v.registration LIKE ? OR d.serial_number LIKE ?)";
        $protoParams = array_merge($protoParams, ["%$protoSearch%","%$protoSearch%","%$protoSearch%"]);
    }
    $protoSql .= " ORDER BY p.date DESC, p.id DESC";
    $protoStmt = $db->prepare($protoSql);
    $protoStmt->execute($protoParams);
    $protocols = $protoStmt->fetchAll();
}

// Status badge helper
function orderStatusBadge($status) {
    $map = [
        'nowe'      => ['primary', 'Nowe'],
        'w_trakcie' => ['warning', 'W trakcie'],
        'zakonczone'=> ['success', 'Zakończone'],
        'anulowane' => ['danger', 'Anulowane'],
        'archiwum'  => ['secondary', 'Archiwum'],
    ];
    $item = $map[$status] ?? ['secondary', ucfirst($status)];
    return '<span class="badge bg-' . $item[0] . '">' . h($item[1]) . '</span>';
}

$activePage = 'orders';
$pageTitleMap = [
    'my'        => 'Moje Zlecenia',
    'add'       => 'Nowe zlecenie',
    'demontaze' => 'Demontaże',
    'protocols' => 'Protokoły montaży',
    'archive'   => 'Archiwum zleceń',
];
$pageTitle = $pageTitleMap[$action] ?? 'Zlecenia montażowe';

// ── AJAX view: return order details fragment for modal ──────────────────
if ($action === 'view' && $id && !empty($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($order)) {
        echo '<p class="text-danger p-3">Zlecenie nie istnieje.</p>';
        exit;
    }
    ?>
    <div class="row g-3">
        <div class="col-md-5">
            <table class="table table-sm mb-0">
                <tr><th class="text-muted ps-0" style="width:45%">Nr zlecenia</th><td class="fw-bold"><?= h($order['order_number']) ?></td></tr>
                <tr><th class="text-muted ps-0">Data</th><td><?= formatDate($order['date']) ?></td></tr>
                <tr><th class="text-muted ps-0">Status</th><td><?= orderStatusBadge($order['status']) ?></td></tr>
                <tr><th class="text-muted ps-0">Technik</th><td><?= h($order['technician_name'] ?? '—') ?>
                    <?php if ($order['technician_email']): ?><br><small class="text-muted"><?= h($order['technician_email']) ?></small><?php endif; ?>
                </td></tr>
                <tr><th class="text-muted ps-0">Adres instalacji</th><td><?= h($order['installation_address'] ?? '—') ?></td></tr>
                <?php if ($order['notes']): ?><tr><th class="text-muted ps-0">Uwagi</th><td><?= nl2br(h($order['notes'])) ?></td></tr><?php endif; ?>
            </table>
            <?php if ($order['client_id']): ?>
            <hr>
            <p class="fw-semibold mb-1"><i class="fas fa-user me-1 text-muted"></i>Klient</p>
            <table class="table table-sm mb-0">
                <?php if ($order['company_name']): ?><tr><th class="text-muted ps-0" style="width:45%">Firma</th><td class="fw-semibold"><?= h($order['company_name']) ?></td></tr><?php endif; ?>
                <tr><th class="text-muted ps-0">Kontakt</th><td><?= h($order['contact_name'] ?? '—') ?></td></tr>
                <?php if ($order['client_phone']): ?><tr><th class="text-muted ps-0">Telefon</th><td><a href="tel:<?= h($order['client_phone']) ?>"><?= h($order['client_phone']) ?></a></td></tr><?php endif; ?>
                <?php if ($order['client_email']): ?><tr><th class="text-muted ps-0">E-mail</th><td><a href="mailto:<?= h($order['client_email']) ?>"><?= h($order['client_email']) ?></a></td></tr><?php endif; ?>
            </table>
            <a href="<?= getBaseUrl() ?>clients.php?action=view&id=<?= $order['client_id'] ?>" class="btn btn-sm btn-outline-primary mt-2">
                <i class="fas fa-external-link-alt me-1"></i>Karta klienta
            </a>
            <?php endif; ?>
            <?php if (!in_array($order['status'], ['zakonczone','anulowane'])): ?>
            <hr>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($order['status'] === 'nowe'): ?>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?= $order['id'] ?>"><input type="hidden" name="new_status" value="w_trakcie"><button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-play me-1"></i>Rozpocznij</button></form>
                <?php endif; ?>
                <?php if (in_array($order['status'], ['nowe','w_trakcie'])): ?>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?= $order['id'] ?>"><input type="hidden" name="new_status" value="zakonczone"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>Zakończ</button></form>
                <form method="POST" onsubmit="return confirm('Anulować zlecenie?')"><?= csrfField() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?= $order['id'] ?>"><input type="hidden" name="new_status" value="anulowane"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-ban me-1"></i>Anuluj zlecenie</button></form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (in_array($order['status'], ['zakonczone','anulowane','archiwum'])): ?>
            <hr>
            <div class="d-flex flex-wrap gap-2">
                <form method="POST" onsubmit="return confirm('Przywrócić zlecenie do statusu Nowe?')"><?= csrfField() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?= $order['id'] ?>"><input type="hidden" name="new_status" value="nowe"><button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-undo me-1"></i>Przywróć do Nowych</button></form>
                <?php if ($order['status'] === 'archiwum'): ?>
                <form method="POST" onsubmit="return confirm('Zmienić status na W trakcie?')"><?= csrfField() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?= $order['id'] ?>"><input type="hidden" name="new_status" value="w_trakcie"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-play me-1"></i>W trakcie</button></form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-7">
            <p class="fw-semibold mb-2"><i class="fas fa-microchip me-1 text-muted"></i>Przypisane urządzenia GPS (<?= count($orderDevices) ?>)</p>
            <?php if (empty($orderDevices)): ?>
            <div class="text-muted text-center py-3">
                <i class="fas fa-microchip fa-2x mb-2 d-block opacity-25"></i>
                Brak urządzeń. Przejdź do <a href="<?= getBaseUrl() ?>devices.php">listy urządzeń</a> i użyj przycisku <strong>Montaż</strong>.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Urządzenie</th><th>Pojazd</th><th>Data montażu</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($orderDevices as $dev): ?>
                        <tr>
                            <td><?= h($dev['manufacturer_name'] . ' ' . $dev['model_name']) ?><br><small class="text-muted"><?= h($dev['serial_number']) ?></small></td>
                            <td><?= h($dev['registration']) ?></td>
                            <td><?= formatDate($dev['installation_date']) ?></td>
                            <td><?= getStatusBadge($dev['inst_status'], 'installation') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="mt-3 d-flex gap-2">
                <a href="<?= getBaseUrl() ?>orders.php?action=view&id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-external-link-alt me-1"></i>Pełny widok zlecenia
                </a>
                <a href="<?= getBaseUrl() ?>devices.php" class="btn btn-sm btn-outline-success">
                    <i class="fas fa-plus me-1"></i>Przypisz urządzenie
                </a>
            </div>
        </div>
    </div>
    <?php
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>
        <?php if ($action === 'my'): ?>
        <i class="fas fa-user-check me-2 text-primary"></i>Moje Zlecenia
        <?php elseif ($action === 'add'): ?>
        <i class="fas fa-plus-circle me-2 text-success"></i>Nowe zlecenie
        <?php elseif ($action === 'view'): ?>
        <i class="fas fa-clipboard-list me-2 text-primary"></i>Zlecenie <?= h($order['order_number']) ?>
        <?php elseif ($action === 'demontaze'): ?>
        <i class="fas fa-tools me-2 text-warning"></i>Demontaże
        <?php elseif ($action === 'protocols'): ?>
        <i class="fas fa-clipboard-check me-2 text-info"></i>Protokoły montaży
        <?php elseif ($action === 'archive'): ?>
        <i class="fas fa-archive me-2 text-secondary"></i>Archiwum zleceń
        <?php else: ?>
        <i class="fas fa-clipboard-list me-2 text-primary"></i>Zlecenia
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (in_array($action, ['list','my','demontaze','protocols','archive'])): ?>
        <?php if ($action === 'list'): ?>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus me-2"></i>Nowe zlecenie</button>
        <?php endif; ?>
        <?php if ($action === 'demontaze'): ?>
        <button type="button" class="btn btn-warning text-white" data-bs-toggle="modal" data-bs-target="#newDisassemblyModal"><i class="fas fa-plus me-2"></i>Nowy demontaż</button>
        <?php elseif ($action === 'protocols'): ?>
        <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#newProtocolFromOrderModal"><i class="fas fa-plus me-2"></i>Nowy protokół</button>
        <?php endif; ?>
        <?php elseif ($action === 'add'): ?>
        <a href="orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
        <?php elseif ($action === 'view'): ?>
        <a href="orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
        <?php if ($order['status'] !== 'archiwum'): ?>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editOrderModal"><i class="fas fa-edit me-1"></i>Edytuj</button>
        <?php endif; ?>
        <?php else: ?>
        <a href="orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($action, ['list','my','demontaze','protocols','archive'])): ?>
<!-- ── TABS NAWIGACJA ──────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $action === 'list' ? 'active' : '' ?>" href="orders.php">
            <i class="fas fa-clipboard-list me-1"></i>Zlecenia
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $action === 'my' ? 'active' : '' ?>" href="orders.php?action=my">
            <i class="fas fa-user-check me-1"></i>Moje zlecenia
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $action === 'archive' ? 'active' : '' ?>" href="orders.php?action=archive">
            <i class="fas fa-archive me-1"></i>Archiwum
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $action === 'demontaze' ? 'active' : '' ?>" href="orders.php?action=demontaze">
            <i class="fas fa-tools me-1"></i>Demontaże
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $action === 'protocols' ? 'active' : '' ?>" href="orders.php?action=protocols">
            <i class="fas fa-clipboard-check me-1"></i>Protokoły
        </a>
    </li>
</ul>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- ── LISTA ZLECEŃ ──────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj (nr zlecenia, klient, adres...)"
                       value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <option value="nowe"       <?= ($_GET['status'] ?? '') === 'nowe'       ? 'selected' : '' ?>>Nowe</option>
                    <option value="w_trakcie"  <?= ($_GET['status'] ?? '') === 'w_trakcie'  ? 'selected' : '' ?>>W trakcie</option>
                    <option value="zakonczone" <?= ($_GET['status'] ?? '') === 'zakonczone' ? 'selected' : '' ?>>Zakończone</option>
                    <option value="anulowane"  <?= ($_GET['status'] ?? '') === 'anulowane'  ? 'selected' : '' ?>>Anulowane</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="technician" class="form-select form-select-sm">
                    <option value="">Wszyscy technicy</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($_GET['technician'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="per_page" class="form-select form-select-sm" style="width:auto">
                    <option value="10"  <?= ($perPage ?? 10) == 10  ? 'selected' : '' ?>>10 / stronę</option>
                    <option value="50"  <?= ($perPage ?? 10) == 50  ? 'selected' : '' ?>>50 / stronę</option>
                    <option value="100" <?= ($perPage ?? 10) == 100 ? 'selected' : '' ?>>100 / stronę</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="orders.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-clipboard-list me-2"></i>Zlecenia (<?= $totalOrders ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr zlecenia</th>
                    <th>Data</th>
                    <th>Klient</th>
                    <th>Adres instalacji</th>
                    <th>Technik</th>
                    <th>Urządzenia</th>
                    <th>Status</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Group orders by client_id (all orders of same client together)
                $groupedOrders = [];
                foreach ($orders as $ord) {
                    $groupKey = $ord['client_id'] ?? '0';
                    if (!isset($groupedOrders[$groupKey])) {
                        $groupedOrders[$groupKey] = ['orders' => [], 'client_id' => $ord['client_id']];
                    }
                    $groupedOrders[$groupKey]['orders'][] = $ord;
                }
                $groupIdx = 0;
                foreach ($groupedOrders as $gKey => $group):
                    $groupOrders = $group['orders'];
                    $isGroup = count($groupOrders) > 1;
                    if ($isGroup):
                        // Group header row
                        $groupIdx++;
                        $firstOrd = $groupOrders[0];
                        $clientLabel = $firstOrd['company_name'] ? $firstOrd['company_name'] : ($firstOrd['contact_name'] ?? '—');
                        $totalDevices = array_sum(array_column($groupOrders, 'device_count'));
                        $groupRowId = 'grp' . $groupIdx;
                        $groupIdsJson = htmlspecialchars(json_encode(array_column($groupOrders, 'id')), ENT_QUOTES);
                        $groupOrdersForModal = array_map(function($o) {
                            return ['id'=>$o['id'],'order_number'=>$o['order_number'],'date'=>$o['date'],'technician_name'=>$o['technician_name']??'','device_count'=>(int)$o['device_count'],'status'=>$o['status']];
                        }, $groupOrders);
                        $groupOrdersModalJson = htmlspecialchars(json_encode($groupOrdersForModal), ENT_QUOTES);
                ?>
                <tr class="table-light fw-semibold" data-group-id="<?= h($groupRowId) ?>">
                    <td colspan="2" class="text-muted small" style="cursor:pointer" onclick="toggleGroupRows('<?= h($groupRowId) ?>')">
                        <i class="fas fa-layer-group me-1"></i>Klient: <?= h($clientLabel) ?>
                        <span class="badge bg-secondary ms-1"><?= count($groupOrders) ?></span>
                        <i class="fas fa-chevron-down ms-2 group-toggle-icon" id="icon-<?= h($groupRowId) ?>" style="font-size:.75rem"></i>
                    </td>
                    <td style="cursor:pointer" onclick="toggleGroupRows('<?= h($groupRowId) ?>')"><?= h($clientLabel) ?></td>
                    <td colspan="2" class="text-muted small" style="cursor:pointer" onclick="toggleGroupRows('<?= h($groupRowId) ?>')"><?= count($groupOrders) ?> zleceń</td>
                    <td style="cursor:pointer" onclick="toggleGroupRows('<?= h($groupRowId) ?>')"><span class="badge bg-info"><?= $totalDevices ?> łącznie</span></td>
                    <td></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" title="Podgląd grupy (modal)"
                                data-orders="<?= $groupOrdersModalJson ?>"
                                data-client="<?= h($clientLabel) ?>"
                                onclick="openGroupPreviewModal(this.dataset.orders, this.dataset.client)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Przenieść wszystkie zlecenia tej grupy do archiwum?')" onclick="event.stopPropagation()">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="archive_group">
                            <input type="hidden" name="group_ids" value="<?= $groupIdsJson ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary btn-action" title="Archiwizuj grupę"><i class="fas fa-archive"></i></button>
                        </form>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć wszystkie (<?= count($groupOrders) ?>) zlecenia tej grupy?')" onclick="event.stopPropagation()">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_ids" value="<?= $groupIdsJson ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" title="Usuń grupę"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endif;
                    foreach ($groupOrders as $ord):
                ?>
                <tr class="<?= $isGroup ? 'ps-3 d-none' : '' ?>" <?= $isGroup ? 'data-group="' . h($groupRowId) . '"' : '' ?>>
                    <td class="fw-semibold <?= $isGroup ? 'ps-4' : '' ?>">
                        <?= $isGroup ? '<span class="text-muted me-1">↳</span>' : '' ?>
                        <a href="#" onclick="openOrderModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>); return false;">
                            <?= h($ord['order_number']) ?>
                        </a>
                    </td>
                    <td><?= formatDate($ord['date']) ?></td>
                    <td>
                        <?php if ($ord['company_name']): ?>
                        <div class="fw-semibold"><?= h($ord['company_name']) ?></div>
                        <small class="text-muted"><?= h($ord['contact_name'] ?? '') ?></small>
                        <?php else: ?>
                        <?= h($ord['contact_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($ord['installation_address'] ?? '—') ?></td>
                    <td><?= h($ord['technician_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($ord['device_count'] > 0): ?>
                        <span class="badge bg-success"><?= (int)$ord['device_count'] ?></span>
                        <?php else: ?>
                        <span class="badge bg-secondary">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= orderStatusBadge($ord['status']) ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" title="Podgląd zlecenia"
                                onclick="openOrderModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php if (!in_array($ord['status'], ['zakonczone','archiwum','anulowane'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action" title="Zakończ zlecenie"
                                onclick="quickChangeStatus(<?= $ord['id'] ?>, 'zakonczone', <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-check"></i>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action" title="Przenieś do archiwum"
                                onclick="quickChangeStatus(<?= $ord['id'] ?>, 'archiwum', <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-archive"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Brak zleceń. <a href="#" data-bs-toggle="modal" data-bs-target="#newOrderModal">Utwórz pierwsze zlecenie</a>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$_listUrl = rtrim('orders.php?' . http_build_query(array_filter(['search' => $_GET['search'] ?? '', 'status' => $_GET['status'] ?? '', 'technician' => $_GET['technician'] ?? '', 'per_page' => $perPage != 10 ? $perPage : ''])), '?');
echo paginate($totalOrders, $perPage, $page, $_listUrl);
?>

<?php elseif ($action === 'my'): ?>
<!-- ── MOJE ZLECENIA ──────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="action" value="my">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj (nr zlecenia, klient...)"
                       value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <option value="nowe"       <?= ($_GET['status'] ?? '') === 'nowe'       ? 'selected' : '' ?>>Nowe</option>
                    <option value="w_trakcie"  <?= ($_GET['status'] ?? '') === 'w_trakcie'  ? 'selected' : '' ?>>W trakcie</option>
                    <option value="zakonczone" <?= ($_GET['status'] ?? '') === 'zakonczone' ? 'selected' : '' ?>>Zakończone</option>
                    <option value="anulowane"  <?= ($_GET['status'] ?? '') === 'anulowane'  ? 'selected' : '' ?>>Anulowane</option>
                </select>
            </div>
            <div class="col-auto">
                <select name="per_page" class="form-select form-select-sm" style="width:auto">
                    <option value="10"  <?= ($myPerPage ?? 10) == 10  ? 'selected' : '' ?>>10 / stronę</option>
                    <option value="50"  <?= ($myPerPage ?? 10) == 50  ? 'selected' : '' ?>>50 / stronę</option>
                    <option value="100" <?= ($myPerPage ?? 10) == 100 ? 'selected' : '' ?>>100 / stronę</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="orders.php?action=my" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <i class="fas fa-user-check me-2"></i>Moje zlecenia — <?= h($currentUser['name']) ?> (<?= $myTotalOrders ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr zlecenia</th>
                    <th>Data</th>
                    <th>Klient</th>
                    <th>Adres instalacji</th>
                    <th>Technik</th>
                    <th>Urządzenia</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Group myOrders by client_id (separate group per client)
                $myGroupedOrders = [];
                foreach ($myOrders as $ord) {
                    $groupKey = $ord['client_id'] ?? '0';
                    if (!isset($myGroupedOrders[$groupKey])) {
                        $myGroupedOrders[$groupKey] = ['orders' => [], 'client_id' => $ord['client_id']];
                    }
                    $myGroupedOrders[$groupKey]['orders'][] = $ord;
                }
                $myGroupIdx = 0;
                foreach ($myGroupedOrders as $gKey => $group):
                    $groupOrders = $group['orders'];
                    $isGroup = count($groupOrders) > 1;
                    if ($isGroup):
                        $myGroupIdx++;
                        $firstOrd = $groupOrders[0];
                        $clientLabel = $firstOrd['company_name'] ? $firstOrd['company_name'] : ($firstOrd['contact_name'] ?? '—');
                        $totalDevices = array_sum(array_column($groupOrders, 'device_count'));
                        $myGroupRowId = 'mygrp' . $myGroupIdx;
                        $myGroupIdsJson = htmlspecialchars(json_encode(array_column($groupOrders, 'id')), ENT_QUOTES);
                        $myGroupOrdersForModal = array_map(function($o) {
                            return ['id'=>$o['id'],'order_number'=>$o['order_number'],'date'=>$o['date'],'technician_name'=>$o['technician_name']??'','device_count'=>(int)$o['device_count'],'status'=>$o['status']];
                        }, $groupOrders);
                        $myGroupOrdersModalJson = htmlspecialchars(json_encode($myGroupOrdersForModal), ENT_QUOTES);
                ?>
                <tr class="table-light fw-semibold" data-group-id="<?= h($myGroupRowId) ?>">
                    <td colspan="2" class="text-muted small" style="cursor:pointer" onclick="toggleGroupRows('<?= h($myGroupRowId) ?>')">
                        <i class="fas fa-layer-group me-1"></i>Klient: <?= h($clientLabel) ?>
                        <span class="badge bg-secondary ms-1"><?= count($groupOrders) ?></span>
                        <i class="fas fa-chevron-down ms-2 group-toggle-icon" id="icon-<?= h($myGroupRowId) ?>" style="font-size:.75rem"></i>
                    </td>
                    <td style="cursor:pointer" onclick="toggleGroupRows('<?= h($myGroupRowId) ?>')"><?= h($clientLabel) ?></td>
                    <td colspan="2" class="text-muted small" style="cursor:pointer" onclick="toggleGroupRows('<?= h($myGroupRowId) ?>')"><?= count($groupOrders) ?> zleceń</td>
                    <td style="cursor:pointer" onclick="toggleGroupRows('<?= h($myGroupRowId) ?>')"><span class="badge bg-info"><?= $totalDevices ?> łącznie</span></td>
                    <td></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" title="Podgląd grupy (modal)"
                                data-orders="<?= $myGroupOrdersModalJson ?>"
                                data-client="<?= h($clientLabel) ?>"
                                onclick="openGroupPreviewModal(this.dataset.orders, this.dataset.client)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Przenieść wszystkie zlecenia tej grupy do archiwum?')" onclick="event.stopPropagation()">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="archive_group">
                            <input type="hidden" name="group_ids" value="<?= $myGroupIdsJson ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary btn-action" title="Archiwizuj grupę"><i class="fas fa-archive"></i></button>
                        </form>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć wszystkie (<?= count($groupOrders) ?>) zlecenia tej grupy?')" onclick="event.stopPropagation()">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_ids" value="<?= $myGroupIdsJson ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" title="Usuń grupę"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endif;
                    foreach ($groupOrders as $ord):
                ?>
                <tr class="<?= $isGroup ? 'ps-3 d-none' : '' ?>" <?= $isGroup ? 'data-group="' . h($myGroupRowId) . '"' : '' ?>>
                    <td class="fw-semibold <?= $isGroup ? 'ps-4' : '' ?>">
                        <?= $isGroup ? '<span class="text-muted me-1">↳</span>' : '' ?>
                        <a href="#" onclick="openOrderModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>); return false;">
                            <?= h($ord['order_number']) ?>
                        </a>
                    </td>
                    <td><?= formatDate($ord['date']) ?></td>
                    <td>
                        <?php if ($ord['company_name']): ?>
                        <div class="fw-semibold"><?= h($ord['company_name']) ?></div>
                        <small class="text-muted"><?= h($ord['contact_name'] ?? '') ?></small>
                        <?php else: ?>
                        <?= h($ord['contact_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($ord['installation_address'] ?? '—') ?></td>
                    <td><?= h($ord['technician_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($ord['device_count'] > 0): ?>
                        <span class="badge bg-success"><?= (int)$ord['device_count'] ?></span>
                        <?php else: ?>
                        <span class="badge bg-secondary">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= orderStatusBadge($ord['status']) ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" title="Podgląd zlecenia"
                                onclick="openOrderModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php if (!in_array($ord['status'], ['zakonczone','archiwum','anulowane'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action" title="Zakończ zlecenie"
                                onclick="quickChangeStatus(<?= $ord['id'] ?>, 'zakonczone', <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-check"></i>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action" title="Przenieś do archiwum"
                                onclick="quickChangeStatus(<?= $ord['id'] ?>, 'archiwum', <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-archive"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                <?php if (empty($myOrders)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nie masz przypisanych zleceń.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$_myUrl = rtrim('orders.php?' . http_build_query(array_filter(['action' => 'my', 'search' => $_GET['search'] ?? '', 'status' => $_GET['status'] ?? '', 'per_page' => $myPerPage != 10 ? $myPerPage : ''])), '?');
echo paginate($myTotalOrders, $myPerPage, $myPage, $_myUrl);
?>

<?php elseif ($action === 'archive'): ?>
<!-- ── ARCHIWUM ZLECEŃ ──────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="action" value="archive">
            <div class="col-md-3">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj (nr zlecenia, klient, adres...)"
                       value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="technician" class="form-select form-select-sm">
                    <option value="">Wszyscy technicy</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($_GET['technician'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="per_page" class="form-select form-select-sm" style="width:auto">
                    <option value="10"  <?= ($archPerPage ?? 10) == 10  ? 'selected' : '' ?>>10 / stronę</option>
                    <option value="50"  <?= ($archPerPage ?? 10) == 50  ? 'selected' : '' ?>>50 / stronę</option>
                    <option value="100" <?= ($archPerPage ?? 10) == 100 ? 'selected' : '' ?>>100 / stronę</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="orders.php?action=archive" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-archive me-2"></i>Archiwum zleceń (<?= $archiveTotalOrders ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr zlecenia</th>
                    <th>Data</th>
                    <th>Klient</th>
                    <th>Adres instalacji</th>
                    <th>Technik</th>
                    <th>Urządzenia</th>
                    <th>Status</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Group archive orders by client_id
                $archGroupedOrders = [];
                foreach ($archiveOrders as $ord) {
                    $groupKey = $ord['client_id'] ?? '0';
                    if (!isset($archGroupedOrders[$groupKey])) {
                        $archGroupedOrders[$groupKey] = ['orders' => [], 'client_id' => $ord['client_id']];
                    }
                    $archGroupedOrders[$groupKey]['orders'][] = $ord;
                }
                $archGroupIdx = 0;
                foreach ($archGroupedOrders as $gKey => $group):
                    $groupOrders = $group['orders'];
                    $isGroup = count($groupOrders) > 1;
                    if ($isGroup):
                        $archGroupIdx++;
                        $firstOrd = $groupOrders[0];
                        $clientLabel = $firstOrd['company_name'] ? $firstOrd['company_name'] : ($firstOrd['contact_name'] ?? '—');
                        $totalDevices = array_sum(array_column($groupOrders, 'device_count'));
                        $archGroupRowId = 'archgrp' . $archGroupIdx;
                        $archGroupIdsJson = htmlspecialchars(json_encode(array_column($groupOrders, 'id')), ENT_QUOTES);
                        $archGroupOrdersForModal = array_map(function($o) {
                            return ['id'=>$o['id'],'order_number'=>$o['order_number'],'date'=>$o['date'],'technician_name'=>$o['technician_name']??'','device_count'=>(int)$o['device_count'],'status'=>$o['status']];
                        }, $groupOrders);
                        $archGroupOrdersModalJson = htmlspecialchars(json_encode($archGroupOrdersForModal), ENT_QUOTES);
                ?>
                <tr class="table-light fw-semibold" data-group-id="<?= h($archGroupRowId) ?>">
                    <td colspan="2" class="text-muted small" style="cursor:pointer" onclick="toggleGroupRows('<?= h($archGroupRowId) ?>')">
                        <i class="fas fa-layer-group me-1"></i>Klient: <?= h($clientLabel) ?>
                        <span class="badge bg-secondary ms-1"><?= count($groupOrders) ?></span>
                        <i class="fas fa-chevron-down ms-2 group-toggle-icon" id="icon-<?= h($archGroupRowId) ?>" style="font-size:.75rem"></i>
                    </td>
                    <td style="cursor:pointer" onclick="toggleGroupRows('<?= h($archGroupRowId) ?>')"><?= h($clientLabel) ?></td>
                    <td colspan="2" class="text-muted small" style="cursor:pointer" onclick="toggleGroupRows('<?= h($archGroupRowId) ?>')"><?= count($groupOrders) ?> zleceń</td>
                    <td style="cursor:pointer" onclick="toggleGroupRows('<?= h($archGroupRowId) ?>')"><span class="badge bg-info"><?= $totalDevices ?> łącznie</span></td>
                    <td></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" title="Podgląd grupy"
                                data-orders="<?= $archGroupOrdersModalJson ?>"
                                data-client="<?= h($clientLabel) ?>"
                                onclick="openGroupPreviewModal(this.dataset.orders, this.dataset.client)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Przywrócić wszystkie zlecenia tej grupy do statusu Nowe?')" onclick="event.stopPropagation()">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="restore_group">
                            <input type="hidden" name="group_ids" value="<?= $archGroupIdsJson ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success btn-action" title="Przywróć grupę (Nowe)"><i class="fas fa-undo"></i></button>
                        </form>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć wszystkie (<?= count($groupOrders) ?>) zlecenia tej grupy?')" onclick="event.stopPropagation()">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_ids" value="<?= $archGroupIdsJson ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" title="Usuń grupę"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endif;
                    foreach ($groupOrders as $ord):
                ?>
                <tr class="<?= $isGroup ? 'ps-3 d-none' : '' ?>" <?= $isGroup ? 'data-group="' . h($archGroupRowId) . '"' : '' ?>>
                    <td class="fw-semibold <?= $isGroup ? 'ps-4' : '' ?>">
                        <?= $isGroup ? '<span class="text-muted me-1">↳</span>' : '' ?>
                        <a href="#" onclick="openOrderModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>); return false;">
                            <?= h($ord['order_number']) ?>
                        </a>
                    </td>
                    <td><?= formatDate($ord['date']) ?></td>
                    <td>
                        <?php if ($ord['company_name']): ?>
                        <div class="fw-semibold"><?= h($ord['company_name']) ?></div>
                        <small class="text-muted"><?= h($ord['contact_name'] ?? '') ?></small>
                        <?php else: ?>
                        <?= h($ord['contact_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($ord['installation_address'] ?? '—') ?></td>
                    <td><?= h($ord['technician_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($ord['device_count'] > 0): ?>
                        <span class="badge bg-success"><?= (int)$ord['device_count'] ?></span>
                        <?php else: ?>
                        <span class="badge bg-secondary">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= orderStatusBadge($ord['status']) ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" title="Podgląd zlecenia"
                                onclick="openOrderModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action" title="Zmień status"
                                onclick="openArchiveStatusModal(<?= $ord['id'] ?>, <?= htmlspecialchars(json_encode($ord['order_number']), ENT_QUOTES) ?>, '<?= h($ord['status']) ?>')">
                            <i class="fas fa-exchange-alt"></i>
                        </button>
                        <?php if (isAdmin()): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć zlecenie <?= h($ord['order_number']) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $ord['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" title="Usuń"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                <?php if (empty($archiveOrders)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Brak zarchiwizowanych zleceń.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$_archUrl = rtrim('orders.php?' . http_build_query(array_filter(['action' => 'archive', 'search' => $_GET['search'] ?? '', 'technician' => $_GET['technician'] ?? '', 'per_page' => $archPerPage != 10 ? $archPerPage : ''])), '?');
echo paginate($archiveTotalOrders, $archPerPage, $archPage, $_archUrl);
?>

<?php elseif ($action === 'add'): ?>
<!-- ── NOWE ZLECENIE ──────────────────────────────────────────────── -->
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
    <div class="card-header"><i class="fas fa-plus-circle me-2 text-success"></i>Dane zlecenia</div>
    <div class="card-body">
        <form method="POST" id="addOrderForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="row g-3">
                <!-- Data zlecenia -->
                <div class="col-md-6">
                    <label class="form-label required-star">Data zlecenia</label>
                    <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <!-- Technik -->
                <div class="col-md-6">
                    <label class="form-label required-star">Technik</label>
                    <select name="technician_id" class="form-select" required>
                        <option value="">— wybierz technika —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $u['id'] == ($prefillTechId ?? 0) ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Klient -->
                <div class="col-md-12">
                    <label class="form-label">Klient</label>
                    <div class="input-group">
                        <select name="client_id" id="orderClientSelect" class="form-select">
                            <option value="">— brak przypisania —</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>"
                                    data-address="<?= h(trim(($cl['address'] ?? '') . ' ' . ($cl['city'] ?? ''))) ?>"
                                    data-phone="<?= h($cl['phone'] ?? '') ?>">
                                <?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-success" id="orderQuickClientBtn" title="Dodaj nowego klienta">
                            <i class="fas fa-user-plus"></i>
                        </button>
                    </div>
                </div>
                <!-- Adres instalacji -->
                <div class="col-md-12">
                    <label class="form-label">Adres miejsca instalacji</label>
                    <input type="text" name="installation_address" id="orderAddressField" class="form-control"
                           placeholder="Automatycznie z danych klienta lub wpisz ręcznie">
                </div>
                <!-- Uwagi -->
                <div class="col-12">
                    <label class="form-label">Uwagi / opis zlecenia</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Dodatkowe informacje dla technika..."></textarea>
                </div>
                <div class="col-12">
                    <div class="alert alert-info py-2 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Urządzenia GPS</strong> będą przypisane do zlecenia z poziomu
                        <a href="devices.php" class="alert-link">listy urządzeń</a> — technik wybierze je samodzielnie.
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Utwórz zlecenie</button>
                <a href="orders.php" class="btn btn-outline-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<!-- Quick-add client inline -->
<div class="modal fade" id="orderQuickClientModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="orderQCErr"></div>
                <div class="mb-2">
                    <label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label>
                    <input type="text" id="orderQCName" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Nazwa firmy</label>
                    <input type="text" id="orderQCCompany" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Telefon</label>
                    <input type="text" id="orderQCPhone" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">E-mail</label>
                    <input type="email" id="orderQCEmail" class="form-control form-control-sm">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="orderQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-fill address from client
document.getElementById('orderClientSelect').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var addr = opt ? (opt.getAttribute('data-address') || '') : '';
    var addrField = document.getElementById('orderAddressField');
    if (addr && !addrField.dataset.manuallyEdited) {
        addrField.value = addr;
    }
});
document.getElementById('orderAddressField').addEventListener('input', function() {
    this.dataset.manuallyEdited = '1';
});

// Quick add client modal
document.getElementById('orderQuickClientBtn').addEventListener('click', function() {
    var modal = new bootstrap.Modal(document.getElementById('orderQuickClientModal'));
    modal.show();
});
document.getElementById('orderQCSaveBtn').addEventListener('click', function() {
    var name = document.getElementById('orderQCName').value.trim();
    var company = document.getElementById('orderQCCompany').value.trim();
    var phone = document.getElementById('orderQCPhone').value.trim();
    var email = document.getElementById('orderQCEmail').value.trim();
    var errEl = document.getElementById('orderQCErr');
    if (!name) { errEl.textContent = 'Imię i nazwisko jest wymagane.'; errEl.classList.remove('d-none'); return; }
    errEl.classList.add('d-none');

    var fd = new FormData();
    fd.append('action', 'quick_add_client');
    fd.append('contact_name', name);
    fd.append('company_name', company);
    fd.append('phone', phone);
    fd.append('email', email);
    fd.append('csrf_token', '<?= generateCsrfToken() ?>');

    fetch('orders.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
            var sel = document.getElementById('orderClientSelect');
            var opt = new Option(data.label, data.id, true, true);
            sel.add(opt);
            bootstrap.Modal.getInstance(document.getElementById('orderQuickClientModal')).hide();
        })
        .catch(() => { errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none'); });
});
</script>

<?php elseif ($action === 'view' && isset($order)): ?>
<!-- ── SZCZEGÓŁY ZLECENIA ─────────────────────────────────────────── -->
<div class="row g-3">
    <!-- Dane zlecenia -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-info-circle me-2"></i>Dane zlecenia</span>
                <?= orderStatusBadge($order['status']) ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted ps-3" style="width:45%">Nr zlecenia</th><td class="fw-bold"><?= h($order['order_number']) ?></td></tr>
                    <tr><th class="text-muted ps-3">Data</th><td><?= formatDate($order['date']) ?></td></tr>
                    <tr><th class="text-muted ps-3">Technik</th><td>
                        <?= h($order['technician_name'] ?? '—') ?>
                        <?php if ($order['technician_email']): ?>
                        <br><small class="text-muted"><?= h($order['technician_email']) ?></small>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th class="text-muted ps-3">Adres instalacji</th><td><?= h($order['installation_address'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted ps-3">Uwagi</th><td><?= nl2br(h($order['notes'] ?? '—')) ?></td></tr>
                    <tr><th class="text-muted ps-3">Utworzone</th><td><?= formatDateTime($order['created_at']) ?></td></tr>
                </table>
            </div>
            <?php if ($order['status'] !== 'zakonczone' && $order['status'] !== 'anulowane'): ?>
            <div class="card-footer d-flex flex-wrap gap-2 py-2">
                <?php if ($order['status'] === 'nowe'): ?>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="new_status" value="w_trakcie">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="fas fa-play me-1"></i>Rozpocznij
                    </button>
                </form>
                <?php endif; ?>
                <?php if (in_array($order['status'], ['nowe','w_trakcie'])): ?>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="new_status" value="zakonczone">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-check me-1"></i>Zakończ
                    </button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('Anulować zlecenie?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="new_status" value="anulowane">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-ban me-1"></i>Anuluj zlecenie
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (in_array($order['status'], ['zakonczone','anulowane','archiwum'])): ?>
            <div class="card-footer d-flex flex-wrap gap-2 py-2">
                <form method="POST" class="d-inline" onsubmit="return confirm('Przywrócić zlecenie do statusu Nowe?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="new_status" value="nowe">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-undo me-1"></i>Przywróć do Nowych
                    </button>
                </form>
                <?php if ($order['status'] === 'archiwum'): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Zmienić status na W trakcie?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="new_status" value="w_trakcie">
                    <button type="submit" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-play me-1"></i>W trakcie
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Klient -->
        <?php if ($order['client_id']): ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-user me-2"></i>Klient</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <?php if ($order['company_name']): ?>
                    <tr><th class="text-muted ps-3" style="width:45%">Firma</th><td class="fw-semibold"><?= h($order['company_name']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted ps-3">Kontakt</th><td><?= h($order['contact_name'] ?? '—') ?></td></tr>
                    <?php if ($order['client_phone']): ?>
                    <tr><th class="text-muted ps-3">Telefon</th><td><a href="tel:<?= h($order['client_phone']) ?>"><?= h($order['client_phone']) ?></a></td></tr>
                    <?php endif; ?>
                    <?php if ($order['client_email']): ?>
                    <tr><th class="text-muted ps-3">E-mail</th><td><a href="mailto:<?= h($order['client_email']) ?>"><?= h($order['client_email']) ?></a></td></tr>
                    <?php endif; ?>
                    <?php if ($order['client_nip']): ?>
                    <tr><th class="text-muted ps-3">NIP</th><td><?= h($order['client_nip']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($order['client_address']): ?>
                    <tr><th class="text-muted ps-3">Adres</th><td><?= h($order['client_address']) ?><?= $order['client_city'] ? ', ' . h($order['client_city']) : '' ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="card-footer py-2">
                <a href="clients.php?action=view&id=<?= $order['client_id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-external-link-alt me-1"></i>Otwórz kartę klienta
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Przypisane urządzenia -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-microchip me-2"></i>Przypisane urządzenia GPS (<?= count($orderDevices) ?>)</span>
                <?php if ($order['status'] !== 'archiwum'): ?>
                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#addDeviceToOrderModal">
                    <i class="fas fa-plus me-1"></i>Dodaj urządzenie
                </button>
                <?php endif; ?>
            </div>
            <?php if (empty($orderDevices)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="fas fa-microchip fa-2x mb-2 d-block text-muted opacity-25"></i>
                Brak przypisanych urządzeń.<br>
                <?php if ($order['status'] !== 'archiwum'): ?>
                <small>Użyj przycisku <strong>Dodaj urządzenie</strong> powyżej, aby przypisać aktywny montaż do tego zlecenia.</small>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Urządzenie</th>
                            <th>Nr seryjny / IMEI</th>
                            <th>Pojazd</th>
                            <th>Data montażu</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderDevices as $dev): ?>
                        <tr>
                            <td class="fw-semibold"><?= h($dev['manufacturer_name'] . ' ' . $dev['model_name']) ?></td>
                            <td>
                                <?= h($dev['serial_number']) ?>
                                <?php if ($dev['imei']): ?><br><small class="text-muted">IMEI: <?= h($dev['imei']) ?></small><?php endif; ?>
                                <?php if ($dev['sim_number']): ?><br><small class="text-muted">SIM: <?= h($dev['sim_number']) ?></small><?php endif; ?>
                            </td>
                            <td><?= h($dev['registration']) ?><?= $dev['make'] ? '<br><small class="text-muted">' . h($dev['make']) . '</small>' : '' ?></td>
                            <td><?= formatDate($dev['installation_date']) ?></td>
                            <td><?= getStatusBadge($dev['inst_status'], 'installation') ?></td>
                            <td>
                                <a href="devices.php?action=view&id=<?= $dev['device_id'] ?>" class="btn btn-sm btn-outline-secondary btn-action" title="Szczegóły urządzenia">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <a href="installations.php?action=view&id=<?= $dev['inst_id'] ?>" class="btn btn-sm btn-outline-primary btn-action" title="Szczegóły montażu">
                                    <i class="fas fa-car"></i>
                                </a>
                                <?php if ($order['status'] !== 'archiwum'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Odłączyć urządzenie <?= h($dev['serial_number']) ?> od tego zlecenia?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove_device_from_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <input type="hidden" name="installation_id" value="<?= $dev['inst_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-action" title="Odłącz od zlecenia">
                                        <i class="fas fa-unlink"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($order['status'] !== 'archiwum'): ?>
<!-- Add Device to Order Modal -->
<div class="modal fade" id="addDeviceToOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_device_to_order">
                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2 text-success"></i>Dodaj urządzenie do zlecenia <?= h($order['order_number']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($availableInstForOrder)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Brak aktywnych montaży nieprzypisanych do żadnego zlecenia.
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label required-star">Wybierz montaż (aktywne, bez przypisanego zlecenia)</label>
                        <select name="installation_id" class="form-select" required>
                            <option value="">— wybierz urządzenie —</option>
                            <?php foreach ($availableInstForOrder as $ai): ?>
                            <option value="<?= $ai['inst_id'] ?>">
                                <?= h($ai['serial_number']) ?>
                                — <?= h($ai['manufacturer_name'] . ' ' . $ai['model_name']) ?>
                                | <?= h($ai['registration']) ?>
                                <?= ($ai['company_name'] ?: $ai['contact_name']) ? '| ' . h($ai['company_name'] ?: $ai['contact_name']) : '' ?>
                                (<?= formatDate($ai['installation_date']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success btn-sm" <?= empty($availableInstForOrder) ? 'disabled' : '' ?>>
                        <i class="fas fa-link me-1"></i>Przypisz do zlecenia
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Order Modal -->
<div class="modal fade" id="editOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= $order['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edytuj zlecenie <?= h($order['order_number']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Data</label>
                            <input type="date" name="date" class="form-control" required value="<?= h($order['date']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="nowe"       <?= $order['status'] === 'nowe'       ? 'selected' : '' ?>>Nowe</option>
                                <option value="w_trakcie"  <?= $order['status'] === 'w_trakcie'  ? 'selected' : '' ?>>W trakcie</option>
                                <option value="zakonczone" <?= $order['status'] === 'zakonczone' ? 'selected' : '' ?>>Zakończone</option>
                                <option value="anulowane"  <?= $order['status'] === 'anulowane'  ? 'selected' : '' ?>>Anulowane</option>
                                <?php if (isAdmin()): ?>
                                <option value="archiwum"   <?= $order['status'] === 'archiwum'   ? 'selected' : '' ?>>Archiwum</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Klient</label>
                            <select name="client_id" class="form-select">
                                <option value="">— brak —</option>
                                <?php foreach ($clients as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $order['client_id'] == $cl['id'] ? 'selected' : '' ?>>
                                    <?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— brak —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $order['technician_id'] == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adres instalacji</label>
                            <input type="text" name="installation_address" class="form-control" value="<?= h($order['installation_address'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="3"><?= h($order['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Zapisz zmiany</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($action === 'demontaze'): ?>
<!-- ── DEMONTAŻE ──────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="action" value="demontaze">
            <div class="col-md-5">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj (nr seryjny, rejestracja, klient...)"
                       value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="orders.php?action=demontaze" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="fas fa-tools text-warning me-1"></i>
        Urządzenia do demontażu (<?= count($disassemblyDevices ?? []) ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Urządzenie</th><th>Pojazd</th><th>Klient</th><th>Technik</th><th>Data montażu</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($disassemblyDevices ?? [] as $dd): ?>
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
                <?php if (empty($disassemblyDevices ?? [])): ?>
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
                    <p class="text-muted small mb-3">Po zakończeniu demontażu urządzenie wróci do stanu magazynowego jako <strong>Sprawne</strong>.</p>
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
                            <select name="device_id" class="form-select" required>
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
                                <option value="<?= $u['id'] ?>" <?= $currentUser['id'] == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
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

<?php elseif ($action === 'protocols'): ?>
<!-- ── PROTOKOŁY MONTAŻY ────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="action" value="protocols">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Szukaj (nr protokołu, rejestracja, nr seryjny...)"
                       value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">Wszystkie typy</option>
                    <option value="PP" <?= ($_GET['type'] ?? '') === 'PP' ? 'selected' : '' ?>>PP — Przekazania</option>
                    <option value="PU" <?= ($_GET['type'] ?? '') === 'PU' ? 'selected' : '' ?>>PU — Uruchomienia</option>
                    <option value="PS" <?= ($_GET['type'] ?? '') === 'PS' ? 'selected' : '' ?>>PS — Serwisowy</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="orders.php?action=protocols" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-clipboard-check me-2 text-info"></i>Protokoły montaży (<?= count($protocols ?? []) ?>)</span>
        <small class="text-muted">PP = Przekazania &nbsp;|&nbsp; PU = Uruchomienia &nbsp;|&nbsp; PS = Serwisowy</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Nr protokołu</th><th>Typ</th><th>Data</th><th>Pojazd</th><th>Urządzenie</th><th>Technik</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php
                $protoTypeLbl   = ['PP' => 'Przekazania', 'PU' => 'Uruchomienia', 'PS' => 'Serwisowy'];
                $protoTypeColor = ['PP' => 'primary', 'PU' => 'success', 'PS' => 'warning'];
                foreach ($protocols ?? [] as $p): ?>
                <tr>
                    <td class="fw-bold"><a href="protocols.php?action=view&id=<?= $p['id'] ?>"><?= h($p['protocol_number']) ?></a></td>
                    <td><span class="badge bg-<?= $protoTypeColor[$p['type']] ?? 'secondary' ?>"><?= $protoTypeLbl[$p['type']] ?? h($p['type']) ?></span></td>
                    <td><?= formatDate($p['date']) ?></td>
                    <td><?= h($p['registration'] ?? '—') ?></td>
                    <td><?= h($p['serial_number'] ?? '—') ?><?php if (!empty($p['model_name'])): ?><br><small class="text-muted"><?= h($p['model_name']) ?></small><?php endif; ?></td>
                    <td><?= h($p['technician_name'] ?? '—') ?></td>
                    <td>
                        <a href="protocols.php?action=view&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info btn-action" title="Podgląd"><i class="fas fa-eye"></i></a>
                        <a href="protocols.php?action=print&id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary btn-action" title="Drukuj"><i class="fas fa-print"></i></a>
                        <a href="protocols.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary btn-action" title="Edytuj"><i class="fas fa-edit"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($protocols ?? [])): ?><tr><td colspan="7" class="text-center text-muted p-3">Brak protokołów montaży.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<!-- ── MODAL NOWE ZLECENIE ─────────────────────────────────────────── -->
<div class="modal fade" id="newOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2 text-success"></i>Nowe zlecenie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="newOrderErr" class="alert alert-danger d-none"></div>
                <form id="newOrderForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="ajax" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Data zlecenia</label>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Technik</label>
                            <select name="technician_id" class="form-select" required>
                                <option value="">— wybierz technika —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == $currentUser['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="modalOrderClientSelect" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($clients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"
                                            data-address="<?= h(trim(($cl['address'] ?? '') . ' ' . ($cl['city'] ?? ''))) ?>">
                                        <?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="modalOrderQuickClientBtn" title="Dodaj nowego klienta"><i class="fas fa-user-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Adres miejsca instalacji</label>
                            <input type="text" name="installation_address" id="modalOrderAddressField" class="form-control" placeholder="Automatycznie z danych klienta lub wpisz ręcznie">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi / opis zlecenia</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Dodatkowe informacje dla technika..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success" id="newOrderSaveBtn"><i class="fas fa-save me-2"></i>Utwórz zlecenie</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick-add client (modal) -->
<div class="modal fade" id="modalOrderQuickClientModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6><button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="modalQCErr"></div>
                <div class="mb-2"><label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label><input type="text" id="modalQCName" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Nazwa firmy</label><input type="text" id="modalQCCompany" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Telefon</label><input type="text" id="modalQCPhone" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">E-mail</label><input type="email" id="modalQCEmail" class="form-control form-control-sm"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="modalQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL NOWY PROTOKÓŁ ─────────────────────────────────────────── -->
<div class="modal fade" id="newProtocolFromOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clipboard-check me-2 text-info"></i>Nowy protokół montażu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="protocols.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Typ protokołu -->
                        <div class="col-md-4">
                            <label class="form-label required-star">Typ protokołu</label>
                            <select name="type" id="npoType" class="form-select" required>
                                <option value="PP">PP — Przekazania</option>
                                <option value="PU">PU — Uruchomienia</option>
                                <option value="PS">PS — Serwisowy</option>
                            </select>
                        </div>
                        <!-- Data -->
                        <div class="col-md-4">
                            <label class="form-label required-star">Data</label>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <!-- Technik -->
                        <div class="col-md-4">
                            <label class="form-label required-star">Technik</label>
                            <select name="technician_id" class="form-select" required>
                                <option value="">— wybierz —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == $currentUser['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Instalacja (PP/PU) -->
                        <div class="col-12" id="npoInstBlock">
                            <label class="form-label">Montaż / pojazd</label>
                            <select name="batch_ref" class="form-select">
                                <option value="">— brak przypisania —</option>
                                <?php if (!empty($modalInstBatches)): ?>
                                <optgroup label="Partie (wiele urządzeń)">
                                    <?php foreach ($modalInstBatches as $b): ?>
                                    <option value="batch:<?= $b['batch_id'] ?>">
                                        Partia #<?= $b['batch_id'] ?> | <?= $b['device_count'] ?> urządzeń | <?= h($b['registrations']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <?php if (!empty($modalInstSingle)): ?>
                                <optgroup label="Pojedyncze instalacje">
                                    <?php foreach ($modalInstSingle as $i): ?>
                                    <option value="inst:<?= $i['id'] ?>">
                                        <?= h($i['registration']) ?> | <?= h($i['serial_number']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        <!-- Podpis klienta -->
                        <div class="col-12">
                            <label class="form-label">Podpis / potwierdzenie klienta</label>
                            <input type="text" name="client_signature" class="form-control" placeholder="Imię, nazwisko lub nr dokumentu">
                        </div>
                        <!-- Uwagi -->
                        <div class="col-12">
                            <label class="form-label">Uwagi</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-info text-white"><i class="fas fa-save me-2"></i>Utwórz protokół</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Quick status change from list ─────────────────────────────────────────
function quickChangeStatus(orderId, newStatus, orderNumber) {
    var label = newStatus === 'zakonczone' ? 'zakończyć' : 'przenieść do archiwum';
    if (!confirm('Czy na pewno chcesz ' + label + ' zlecenie ' + orderNumber + '?')) return;
    var csrf = document.querySelector('#newOrderForm [name=csrf_token]');
    if (!csrf) { window.location.href = 'orders.php'; return; }
    var fd = new FormData();
    fd.append('action', 'change_status');
    fd.append('id', orderId);
    fd.append('new_status', newStatus);
    fd.append('ajax', '1');
    fd.append('csrf_token', csrf.value);
    fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function(data) {
        if (data.error) { alert(data.error); return; }
        window.location.reload();
    }).catch(function() { window.location.reload(); });
}
// ── New Order Modal JS ─────────────────────────────────────────────────────
document.getElementById('modalOrderClientSelect').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var addr = opt ? (opt.getAttribute('data-address') || '') : '';
    var addrField = document.getElementById('modalOrderAddressField');
    if (addrField && addr && !addrField.dataset.manuallyEdited) { addrField.value = addr; }
});
document.getElementById('modalOrderAddressField').addEventListener('input', function() { this.dataset.manuallyEdited = '1'; });

document.getElementById('modalOrderQuickClientBtn').addEventListener('click', function() {
    new bootstrap.Modal(document.getElementById('modalOrderQuickClientModal')).show();
});

document.getElementById('modalQCSaveBtn').addEventListener('click', function() {
    var name = document.getElementById('modalQCName').value.trim();
    var company = document.getElementById('modalQCCompany').value.trim();
    var phone = document.getElementById('modalQCPhone').value.trim();
    var email = document.getElementById('modalQCEmail').value.trim();
    var errEl = document.getElementById('modalQCErr');
    if (!name) { errEl.textContent = 'Imię i nazwisko jest wymagane.'; errEl.classList.remove('d-none'); return; }
    errEl.classList.add('d-none');
    var fd = new FormData();
    fd.append('action', 'quick_add_client'); fd.append('contact_name', name);
    fd.append('company_name', company); fd.append('phone', phone); fd.append('email', email);
    fd.append('csrf_token', document.querySelector('#newOrderForm [name=csrf_token]').value);
    fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function(data) {
        if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
        var sel = document.getElementById('modalOrderClientSelect');
        var opt = new Option(data.label, data.id, true, true);
        sel.add(opt);
        bootstrap.Modal.getInstance(document.getElementById('modalOrderQuickClientModal')).hide();
    }).catch(function() { errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none'); });
});

document.getElementById('newOrderSaveBtn').addEventListener('click', function() {
    var btn = this;
    var form = document.getElementById('newOrderForm');
    var errEl = document.getElementById('newOrderErr');
    var date = form.querySelector('[name=date]').value;
    var tech = form.querySelector('[name=technician_id]').value;
    if (!date) { errEl.textContent = 'Data zlecenia jest wymagana.'; errEl.classList.remove('d-none'); return; }
    if (!tech) { errEl.textContent = 'Wybierz technika.'; errEl.classList.remove('d-none'); return; }
    errEl.classList.add('d-none');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zapisywanie...';
    var fd = new FormData(form);
    fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function(data) {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-2"></i>Utwórz zlecenie';
        if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
        if (data.redirect) { window.location.href = data.redirect; }
    }).catch(function() {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-2"></i>Utwórz zlecenie';
        errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none');
    });
});
// Reset modal form on close
document.getElementById('newOrderModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('newOrderForm').reset();
    document.getElementById('newOrderErr').classList.add('d-none');
    document.getElementById('modalOrderAddressField').dataset.manuallyEdited = '';
    var dateInput = document.querySelector('#newOrderForm [name=date]');
    if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];
});
</script>
<div class="modal fade" id="groupPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>Podgląd grupy – <span id="groupPreviewClientName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="groupPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orderPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="orderPreviewTitle"><i class="fas fa-clipboard-list me-2"></i>Zlecenie</h5>
                <div class="ms-auto d-flex align-items-center gap-2 me-3" id="orderPreviewFullLink"></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orderPreviewBody">
                <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Ładowanie...</p></div>
            </div>
            <div class="modal-footer">
                <a href="#" id="orderPreviewOpenFull" class="btn btn-outline-primary btn-sm" target="_blank"><i class="fas fa-external-link-alt me-1"></i>Pełny widok</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>
<script>
function openGroupPreviewModal(ordersJson, clientLabel) {
    var orders;
    try { orders = JSON.parse(ordersJson); } catch(e) { alert('Błąd parsowania danych grupy'); return; }
    document.getElementById('groupPreviewClientName').textContent = clientLabel;
    var statusLabels = {
        'nowe':'<span class="badge bg-primary">Nowe</span>',
        'w_trakcie':'<span class="badge bg-warning text-dark">W trakcie</span>',
        'zakonczone':'<span class="badge bg-success">Zakończone</span>',
        'anulowane':'<span class="badge bg-danger">Anulowane</span>',
        'archiwum':'<span class="badge bg-secondary">Archiwum</span>'
    };
    var rows = orders.map(function(o) {
        var st = statusLabels[o.status] || ('<span class="badge bg-secondary">'+o.status+'</span>');
        return '<tr>'
            + '<td class="fw-semibold"><a href="#" onclick="closeGroupOpenOrder('+o.id+','+JSON.stringify(o.order_number)+');return false;">'+escHtml(o.order_number)+'</a></td>'
            + '<td>'+escHtml(o.date)+'</td>'
            + '<td>'+escHtml(o.technician_name||'—')+'</td>'
            + '<td><span class="badge '+(o.device_count>0?'bg-success':'bg-secondary')+'">'+o.device_count+'</span></td>'
            + '<td>'+st+'</td>'
            + '<td><button class="btn btn-sm btn-outline-primary btn-action" title="Podgląd" onclick="closeGroupOpenOrder('+o.id+','+JSON.stringify(o.order_number)+')"><i class="fas fa-eye"></i></button></td>'
            + '</tr>';
    }).join('');
    document.getElementById('groupPreviewBody').innerHTML =
        '<table class="table table-hover mb-0">'
        + '<thead><tr><th>Nr zlecenia</th><th>Data</th><th>Technik</th><th>Urządzenia</th><th>Status</th><th>Akcje</th></tr></thead>'
        + '<tbody>'+rows+'</tbody></table>';
    bootstrap.Modal.getInstance(document.getElementById('groupPreviewModal'))?.hide();
    new bootstrap.Modal(document.getElementById('groupPreviewModal')).show();
}
function closeGroupOpenOrder(orderId, orderNumber) {
    var gm = bootstrap.Modal.getInstance(document.getElementById('groupPreviewModal'));
    if (gm) gm.hide();
    setTimeout(function() { openOrderModal(orderId, orderNumber); }, 300);
}
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openOrderModal(orderId, orderNumber) {
    var modal = new bootstrap.Modal(document.getElementById('orderPreviewModal'));
    document.getElementById('orderPreviewTitle').innerHTML = '<i class="fas fa-clipboard-list me-2"></i>Zlecenie ' + orderNumber;
    document.getElementById('orderPreviewOpenFull').href = 'orders.php?action=view&id=' + orderId;
    document.getElementById('orderPreviewBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Ładowanie...</p></div>';
    modal.show();
    fetch('orders.php?action=view&id=' + orderId + '&ajax=1')
        .then(function(r) { return r.text(); })
        .then(function(html) { document.getElementById('orderPreviewBody').innerHTML = html; })
        .catch(function() { document.getElementById('orderPreviewBody').innerHTML = '<p class="text-danger p-3">Błąd ładowania danych zlecenia.</p>'; });
}

function toggleGroupRows(groupId) {
    var rows = document.querySelectorAll('tr[data-group="' + groupId + '"]');
    var icon = document.getElementById('icon-' + groupId);
    var isHidden = rows.length > 0 && rows[0].classList.contains('d-none');
    rows.forEach(function(row) {
        if (isHidden) { row.classList.remove('d-none'); } else { row.classList.add('d-none'); }
    });
    if (icon) {
        if (isHidden) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-up'); }
        else          { icon.classList.remove('fa-chevron-up');   icon.classList.add('fa-chevron-down'); }
    }
}

function openArchiveStatusModal(orderId, orderNumber, currentStatus) {
    document.getElementById('archStatusOrderId').value = orderId;
    document.getElementById('archStatusOrderNumber').textContent = orderNumber;
    var sel = document.getElementById('archStatusSelect');
    if (sel) sel.value = currentStatus;
    new bootstrap.Modal(document.getElementById('archiveStatusModal')).show();
}
</script>

<!-- Modal: Zmiana statusu (Archiwum) -->
<div class="modal fade" id="archiveStatusModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="id" id="archStatusOrderId" value="">
                <div class="modal-header py-2">
                    <h6 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Zmień status</h6>
                    <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Zlecenie: <strong id="archStatusOrderNumber"></strong></p>
                    <label class="form-label">Nowy status</label>
                    <select name="new_status" id="archStatusSelect" class="form-select form-select-sm">
                        <option value="nowe">Nowe</option>
                        <option value="w_trakcie">W trakcie</option>
                        <option value="zakonczone">Zakończone</option>
                        <option value="anulowane">Anulowane</option>
                        <option value="archiwum">Archiwum</option>
                    </select>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
