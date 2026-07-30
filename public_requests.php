<?php
/**
 * FleetLink System GPS - Public requests back office
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();
ensurePublicRequestsTable($db);
$currentUser = getCurrentUser();

function ensureWorkOrdersTableForPublicRequests(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $db->query("SELECT 1 FROM work_orders LIMIT 1");
        return;
    } catch (PDOException $e) {}

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
}

$action = sanitize($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'public_requests.php');
    }

    $postAction = sanitize($_POST['action'] ?? '');
    $requestId = (int)($_POST['id'] ?? 0);

    if (!$requestId) {
        flashError('Nieprawidłowy identyfikator zgłoszenia.');
        redirect(getBaseUrl() . 'public_requests.php');
    }

    if ($postAction === 'save_status') {
        $status = sanitize($_POST['status'] ?? 'nowe');
        $adminNotes = sanitize($_POST['admin_notes'] ?? '');
        $technicianId = (int)($_POST['technician_id'] ?? 0) ?: null;
        $allowedStatuses = ['nowe', 'zweryfikowane', 'w_realizacji', 'zamienione_na_zlecenie', 'odrzucone'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'nowe';
        }

        $db->prepare("UPDATE public_requests SET status = ?, admin_notes = ?, technician_id = ? WHERE id = ?")
            ->execute([$status, $adminNotes ?: null, $technicianId, $requestId]);

        flashSuccess('Zgłoszenie zostało zaktualizowane.');
        redirect(getBaseUrl() . 'public_requests.php?action=view&id=' . $requestId);
    }

    if ($postAction === 'convert_to_order') {
        ensureWorkOrdersTableForPublicRequests($db);

        $requestStmt = $db->prepare("SELECT * FROM public_requests WHERE id = ? LIMIT 1");
        $requestStmt->execute([$requestId]);
        $requestRow = $requestStmt->fetch();

        if (!$requestRow) {
            flashError('Zgłoszenie nie istnieje.');
            redirect(getBaseUrl() . 'public_requests.php');
        }
        if (!empty($requestRow['internal_order_id'])) {
            flashError('To zgłoszenie zostało już zamienione na zlecenie.');
            redirect(getBaseUrl() . 'public_requests.php?action=view&id=' . $requestId);
        }

        $technicianId = (int)($_POST['technician_id'] ?? $requestRow['technician_id'] ?? 0) ?: null;
        if (!$technicianId) {
            $technicianId = (int)$currentUser['id'];
        }

        $vehicleLabelParts = array_filter([
            $requestRow['vehicle_registration'] ?? '',
            $requestRow['vehicle_vin'] ?? '',
            $requestRow['vehicle_details'] ?? '',
        ]);
        $vehicleLabel = $vehicleLabelParts ? implode(' / ', $vehicleLabelParts) : 'Nie podano';

        $db->beginTransaction();
        try {
            $clientId = (int)($requestRow['client_id'] ?? 0);
            if (!$clientId) {
                $contactName = trim(($requestRow['first_name'] ?? '') . ' ' . ($requestRow['last_name'] ?? ''));
                $email = trim((string)($requestRow['email'] ?? ''));
                $phone = trim((string)($requestRow['phone'] ?? ''));
                $companyName = trim((string)($requestRow['company_name'] ?? ''));

                $existingClient = null;
                if ($email !== '') {
                    $clientStmt = $db->prepare("SELECT id FROM clients WHERE email = ? ORDER BY id ASC LIMIT 1");
                    $clientStmt->execute([$email]);
                    $existingClient = $clientStmt->fetch();
                }
                if (!$existingClient && $companyName !== '' && $phone !== '') {
                    $clientStmt = $db->prepare("SELECT id FROM clients WHERE company_name = ? AND phone = ? ORDER BY id ASC LIMIT 1");
                    $clientStmt->execute([$companyName, $phone]);
                    $existingClient = $clientStmt->fetch();
                }

                if ($existingClient) {
                    $clientId = (int)$existingClient['id'];
                } else {
                    $clientInsert = $db->prepare("
                        INSERT INTO clients (company_name, contact_name, email, phone, address, nip, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $clientInsert->execute([
                        $companyName ?: null,
                        $contactName ?: 'Klient publiczny',
                        $email ?: null,
                        $phone ?: null,
                        $requestRow['service_address'] ?: null,
                        $requestRow['nip'] ?: null,
                        'Utworzono z formularza publicznego ' . ($requestRow['request_number'] ?? ''),
                    ]);
                    $clientId = (int)$db->lastInsertId();
                }
            }

            $orderDate = $requestRow['preferred_date'] ?: date('Y-m-d');
            $orderNotes = [];
            $orderNotes[] = 'Źródło: zgłoszenie publiczne ' . ($requestRow['request_number'] ?? '');
            $orderNotes[] = 'Typ: ' . getPublicRequestTypeLabel($requestRow['request_type'] ?? '');
            $orderNotes[] = 'Klient: ' . trim(($requestRow['first_name'] ?? '') . ' ' . ($requestRow['last_name'] ?? ''));
            $orderNotes[] = 'Telefon: ' . ($requestRow['phone'] ?: '—');
            $orderNotes[] = 'E-mail: ' . ($requestRow['email'] ?: '—');
            $orderNotes[] = 'Pojazd: ' . $vehicleLabel;
            $orderNotes[] = 'Opis: ' . ($requestRow['description'] ?: '—');
            if (!empty($requestRow['admin_notes'])) {
                $orderNotes[] = 'Uwagi wewnętrzne: ' . $requestRow['admin_notes'];
            }

            $insertStmt = $db->prepare("
                INSERT INTO work_orders (order_number, date, client_id, installation_address, technician_id, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, 'nowe', ?, ?)
            ");

            $orderNumber = '';
            $newOrderId = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $orderNumber = generateOrderNumber($orderDate);
                try {
                    $insertStmt->execute([
                        $orderNumber,
                        $orderDate,
                        $clientId ?: null,
                        $requestRow['service_address'] ?: null,
                        $technicianId,
                        implode("\n", $orderNotes),
                        $currentUser['id'],
                    ]);
                    $newOrderId = (int)$db->lastInsertId();
                    break;
                } catch (PDOException $e) {
                    $sqlState = $e->getCode();
                    $driverErrorCode = (int)($e->errorInfo[1] ?? 0);
                    // 1062 = MySQL duplicate key, 1555/2067 = SQLite duplicate/unique constraint variants.
                    $isDuplicate = $sqlState === '23000' || in_array($driverErrorCode, [1062, 1555, 2067], true);
                    if (!$isDuplicate || $attempt === 4) {
                        throw $e;
                    }
                }
            }

            $db->prepare("
                UPDATE public_requests
                SET status = 'zamienione_na_zlecenie', technician_id = ?, client_id = ?, internal_order_id = ?, converted_by = ?
                WHERE id = ?
            ")->execute([$technicianId, $clientId ?: null, $newOrderId, $currentUser['id'], $requestId]);

            $db->commit();
            flashSuccess('Utworzono zlecenie ' . $orderNumber . ' na podstawie zgłoszenia.');
            redirect(getBaseUrl() . 'orders.php?action=view&id=' . $newOrderId);
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Nie udało się utworzyć zlecenia na podstawie zgłoszenia.');
            redirect(getBaseUrl() . 'public_requests.php?action=view&id=' . $requestId);
        }
    }

    if ($postAction === 'archive_request') {
        $db->prepare("UPDATE public_requests SET status = 'archiwum', archived = 1 WHERE id = ? AND status = 'zamienione_na_zlecenie'")
            ->execute([$requestId]);
        flashSuccess('Zgłoszenie zostało przeniesione do archiwum.');
        redirect(getBaseUrl() . 'public_requests.php?action=view&id=' . $requestId);
    }
}

$statusFilter = sanitize($_GET['status'] ?? '');
$typeFilter = sanitize($_GET['type'] ?? '');
$search = sanitize($_GET['q'] ?? '');

$activePage = 'public_requests';
$pageTitle = 'Zgłoszenia publiczne';
include __DIR__ . '/includes/header.php';

$users = $db->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll();

if ($action === 'view' && $id > 0) {
    $detailStmt = $db->prepare("
        SELECT pr.*, u.name AS technician_name, c.company_name AS linked_company_name, c.contact_name AS linked_contact_name,
               ub.name AS converted_by_name
        FROM public_requests pr
        LEFT JOIN users u ON u.id = pr.technician_id
        LEFT JOIN clients c ON c.id = pr.client_id
        LEFT JOIN users ub ON ub.id = pr.converted_by
        WHERE pr.id = ?
        LIMIT 1
    ");
    $detailStmt->execute([$id]);
    $request = $detailStmt->fetch();

    if (!$request) {
        echo '<div class="alert alert-danger">Nie znaleziono zgłoszenia.</div>';
        include __DIR__ . '/includes/footer.php';
        exit;
    }
    ?>
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1><i class="fas fa-inbox me-2 text-primary"></i>Zgłoszenie <?= h($request['request_number']) ?></h1>
            <p class="text-muted mb-0"><?= h(getPublicRequestTypeLabel($request['request_type'])) ?> · utworzone <?= h(formatDateTime($request['created_at'])) ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getBaseUrl() ?>public_requests.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Lista zgłoszeń
            </a>
            <?php if (!empty($request['internal_order_id'])): ?>
            <a href="<?= getBaseUrl() ?>orders.php?action=view&id=<?= (int)$request['internal_order_id'] ?>" class="btn btn-outline-primary">
                <i class="fas fa-clipboard-list me-2"></i>Otwórz zlecenie
            </a>
            <?php endif; ?>
            <?php if ($request['status'] === 'zamienione_na_zlecenie'): ?>
            <form method="POST" class="d-inline m-0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="archive_request">
                <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                <button type="submit" class="btn btn-outline-secondary" onclick="return confirm('Przenieść zgłoszenie do archiwum?')">
                    <i class="fas fa-box-archive me-2"></i>Archiwizuj
                </button>
            </form>
            <?php elseif ($request['status'] === 'archiwum'): ?>
            <span class="badge bg-secondary fs-6 align-self-center"><i class="fas fa-box-archive me-1"></i>Zarchiwizowane</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-address-card me-2"></i>Dane klienta</span>
                    <?= getStatusBadge($request['status'], 'public_request') ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Imię i nazwisko</div>
                            <div class="fw-semibold"><?= h(trim($request['first_name'] . ' ' . $request['last_name'])) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Firma</div>
                            <div class="fw-semibold"><?= h($request['company_name']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Telefon</div>
                            <div><?= h($request['phone']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">E-mail</div>
                            <div><a href="mailto:<?= h($request['email']) ?>"><?= h($request['email']) ?></a></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">NIP</div>
                            <div><?= h($request['nip'] ?: '—') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Preferowany termin</div>
                            <div><?= h($request['preferred_date'] ? formatDate($request['preferred_date']) : 'Do ustalenia') ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Adres wykonania usługi</div>
                            <div><?= h($request['service_address']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Rejestracja</div>
                            <div><?= h($request['vehicle_registration'] ?: '—') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">VIN</div>
                            <div><?= h($request['vehicle_vin'] ?: '—') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Opis pojazdu</div>
                            <div><?= h($request['vehicle_details'] ?: '—') ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Opis zgłoszenia</div>
                            <div class="border rounded p-3 bg-light-subtle"><?= nl2br(h($request['description'])) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Powiązany klient</div>
                            <div><?= h(($request['linked_company_name'] ?: $request['linked_contact_name']) ?: '—') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Przypisany technik</div>
                            <div><?= h($request['technician_name'] ?: '—') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-sliders-h me-2"></i>Obsługa zgłoszenia</div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_status">
                        <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['nowe' => 'Nowe', 'zweryfikowane' => 'Zweryfikowane', 'w_realizacji' => 'W realizacji', 'zamienione_na_zlecenie' => 'Zamienione na zlecenie', 'odrzucone' => 'Odrzucone'] as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= $request['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Przypisz technika</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— brak —</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>" <?= (int)($request['technician_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h($user['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Uwagi wewnętrzne</label>
                            <textarea name="admin_notes" class="form-control" rows="5"><?= h($request['admin_notes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save me-2"></i>Zapisz zmiany
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="fas fa-wand-magic-sparkles me-2"></i>Konwersja na zlecenie</div>
                <div class="card-body">
                    <p class="text-muted small">Utwórz wewnętrzne zlecenie na podstawie danych klienta i opisu zgłoszenia.</p>
                    <?php if (!empty($request['internal_order_id'])): ?>
                    <div class="alert alert-success mb-0">
                        To zgłoszenie zostało już zamienione na zlecenie
                        <a href="<?= getBaseUrl() ?>orders.php?action=view&id=<?= (int)$request['internal_order_id'] ?>" class="alert-link">#<?= (int)$request['internal_order_id'] ?></a><?php if (!empty($request['converted_by_name'])): ?> przez <strong><?= h($request['converted_by_name']) ?></strong><?php endif; ?>.
                    </div>
                    <?php else: ?>
                    <form method="POST" class="row g-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="convert_to_order">
                        <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Technik dla zlecenia</label>
                            <select name="technician_id" class="form-select">
                                <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>" <?= (int)($request['technician_id'] ?? $currentUser['id']) === (int)$user['id'] ? 'selected' : '' ?>><?= h($user['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-clipboard-check me-2"></i>Utwórz zlecenie wewnętrzne
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'pr.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== '') {
    $where[] = 'pr.request_type = ?';
    $params[] = $typeFilter;
}
if ($search !== '') {
    $where[] = '(pr.request_number LIKE ? OR pr.company_name LIKE ? OR pr.first_name LIKE ? OR pr.last_name LIKE ? OR pr.email LIKE ? OR pr.phone LIKE ?)';
    for ($i = 0; $i < 6; $i++) {
        $params[] = '%' . $search . '%';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$listStmt = $db->prepare("
    SELECT pr.*, u.name AS technician_name
    FROM public_requests pr
    LEFT JOIN users u ON u.id = pr.technician_id
    $whereSql
    ORDER BY pr.created_at DESC, pr.id DESC
");
$listStmt->execute($params);
$requests = $listStmt->fetchAll();
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h1><i class="fas fa-inbox me-2 text-primary"></i>Zgłoszenia publiczne</h1>
        <p class="text-muted mb-0">Anonimowe zgłoszenia serwisów, montaży i demontaży przesłane z formularza publicznego.</p>
    </div>
    <a href="<?= getBaseUrl() ?>public_request.php" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
        <i class="fas fa-up-right-from-square me-2"></i>Otwórz formularz publiczny
    </a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">— wszystkie —</option>
                    <?php foreach (['nowe' => 'Nowe', 'zweryfikowane' => 'Zweryfikowane', 'w_realizacji' => 'W realizacji', 'zamienione_na_zlecenie' => 'Zamienione na zlecenie', 'odrzucone' => 'Odrzucone'] as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Typ zgłoszenia</label>
                <select name="type" class="form-select">
                    <option value="">— wszystkie —</option>
                    <?php foreach (['serwis' => 'Serwis', 'montaz' => 'Montaż', 'demontaz' => 'Demontaż', 'inna' => 'Inna'] as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Szukaj</label>
                <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Numer, firma, klient, e-mail, telefon">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filtruj</button>
                <a href="<?= getBaseUrl() ?>public_requests.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>Lista zgłoszeń</span>
        <span class="badge bg-primary"><?= count($requests) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Numer</th>
                    <th>Typ</th>
                    <th>Klient</th>
                    <th>Kontakt</th>
                    <th>Status</th>
                    <th>Technik</th>
                    <th>Data</th>
                    <th class="text-end">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                <tr class="<?= !empty($request['archived']) ? 'table-secondary' : '' ?>" style="<?= !empty($request['archived']) ? 'opacity: 0.6' : '' ?>">
                    <td class="fw-semibold"><?= h($request['request_number']) ?></td>
                    <td><?= h(getPublicRequestTypeLabel($request['request_type'])) ?></td>
                    <td>
                        <div><?= h($request['company_name']) ?></div>
                        <div class="text-muted small"><?= h(trim($request['first_name'] . ' ' . $request['last_name'])) ?></div>
                    </td>
                    <td>
                        <div><a href="mailto:<?= h($request['email']) ?>"><?= h($request['email']) ?></a></div>
                        <div class="text-muted small"><?= h($request['phone']) ?></div>
                    </td>
                    <td><?= getStatusBadge($request['status'], 'public_request') ?></td>
                    <td><?= h($request['technician_name'] ?: '—') ?></td>
                    <td><?= h(formatDateTime($request['created_at'])) ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="<?= getBaseUrl() ?>public_requests.php?action=view&id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i>Szczegóły
                            </a>
                            <?php if ($request['status'] === 'zamienione_na_zlecenie'): ?>
                            <form method="POST" class="d-inline m-0">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="archive_request">
                                <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Przenieś do archiwum" onclick="return confirm('Przenieść do archiwum?')">
                                    <i class="fas fa-box-archive"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">Brak zgłoszeń spełniających wybrane kryteria.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
