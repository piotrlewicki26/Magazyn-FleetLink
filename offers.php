<?php
/**
 * FleetLink Magazyn - Offer Management
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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'offers.php'); }
    $postAction  = sanitize($_POST['action'] ?? '');
    $clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
    $status      = sanitize($_POST['status'] ?? 'robocza');
    $validUntil  = sanitize($_POST['valid_until'] ?? '') ?: null;
    $notes       = sanitize($_POST['notes'] ?? '');
    $vatRate     = min(100, max(0, (float)str_replace(',', '.', $_POST['vat_rate'] ?? '23')));
    $items       = $_POST['items'] ?? [];
    $user        = getCurrentUser();

    $validStatuses = ['robocza','wyslana','zaakceptowana','odrzucona','anulowana'];
    if (!in_array($status, $validStatuses)) $status = 'robocza';

    // Calculate totals
    $totalNet = 0;
    $cleanItems = [];
    foreach ($items as $item) {
        $desc     = sanitize($item['description'] ?? '');
        $qty      = (float)str_replace(',', '.', $item['quantity'] ?? '1');
        $unit     = sanitize($item['unit'] ?? 'szt');
        $unitPrice = (float)str_replace(',', '.', $item['unit_price'] ?? '0');
        if (empty($desc) || $qty <= 0) continue;
        $total = round($qty * $unitPrice, 2);
        $totalNet += $total;
        $cleanItems[] = compact('desc','qty','unit','unitPrice','total');
    }
    $totalGross = round($totalNet * (1 + $vatRate / 100), 2);

    if ($postAction === 'add') {
        $offerNumber = generateOfferNumber();
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO offers (offer_number, client_id, user_id, status, valid_until, notes, total_net, total_gross, vat_rate) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$offerNumber, $clientId, $user['id'], $status, $validUntil, $notes, $totalNet, $totalGross, $vatRate]);
            $offerId = $db->lastInsertId();
            foreach ($cleanItems as $i => $item) {
                $db->prepare("INSERT INTO offer_items (offer_id, description, quantity, unit, unit_price, total_price, sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$offerId, $item['desc'], $item['qty'], $item['unit'], $item['unitPrice'], $item['total'], $i]);
            }
            $db->commit();
            flashSuccess("Oferta $offerNumber została utworzona.");
            redirect(getBaseUrl() . 'offers.php?action=view&id=' . $offerId);
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd podczas zapisu: ' . $e->getMessage());
            redirect(getBaseUrl() . 'offers.php?action=add');
        }

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE offers SET client_id=?, status=?, valid_until=?, notes=?, total_net=?, total_gross=?, vat_rate=? WHERE id=?")
               ->execute([$clientId, $status, $validUntil, $notes, $totalNet, $totalGross, $vatRate, $editId]);
            $db->prepare("DELETE FROM offer_items WHERE offer_id=?")->execute([$editId]);
            foreach ($cleanItems as $i => $item) {
                $db->prepare("INSERT INTO offer_items (offer_id, description, quantity, unit, unit_price, total_price, sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$editId, $item['desc'], $item['qty'], $item['unit'], $item['unitPrice'], $item['total'], $i]);
            }
            $db->commit();
            flashSuccess('Oferta zaktualizowana.');
        } catch (Exception $e) {
            $db->rollBack();
            flashError('Błąd: ' . $e->getMessage());
        }
        redirect(getBaseUrl() . 'offers.php?action=view&id=' . $editId);

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM offers WHERE id=?")->execute([$delId]);
        flashSuccess('Oferta usunięta.');
        redirect(getBaseUrl() . 'offers.php');

    } elseif ($postAction === 'status') {
        $editId = (int)($_POST['id'] ?? 0);
        $newStatus = sanitize($_POST['new_status'] ?? '');
        if ($editId && in_array($newStatus, $validStatuses)) {
            $db->prepare("UPDATE offers SET status=? WHERE id=?")->execute([$newStatus, $editId]);
            flashSuccess('Status oferty zmieniony.');
        }
        redirect(getBaseUrl() . 'offers.php?action=view&id=' . $editId);
    }
}

$offer = null;
$offerItems = [];
if (in_array($action, ['view','edit','print']) && $id) {
    $stmt = $db->prepare("
        SELECT o.*, c.contact_name, c.company_name, c.address, c.city, c.postal_code, c.nip,
               c.email as client_email, c.phone as client_phone,
               u.name as user_name, u.email as user_email
        FROM offers o
        LEFT JOIN clients c ON c.id=o.client_id
        JOIN users u ON u.id=o.user_id
        WHERE o.id=?
    ");
    $stmt->execute([$id]);
    $offer = $stmt->fetch();
    if (!$offer) { flashError('Oferta nie istnieje.'); redirect(getBaseUrl() . 'offers.php'); }

    $itemStmt = $db->prepare("SELECT * FROM offer_items WHERE offer_id=? ORDER BY sort_order");
    $itemStmt->execute([$id]);
    $offerItems = $itemStmt->fetchAll();
}

$clients = $db->query("SELECT id, contact_name, company_name FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();

// Company settings for print header
$settings = [];
$settingsRows = $db->query("SELECT `key`, `value` FROM settings")->fetchAll();
foreach ($settingsRows as $row) $settings[$row['key']] = $row['value'];

$offers = [];
if ($action === 'list') {
    $filterStatus = sanitize($_GET['status'] ?? '');
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT o.*, c.contact_name, c.company_name, u.name as user_name
            FROM offers o
            LEFT JOIN clients c ON c.id=o.client_id
            JOIN users u ON u.id=o.user_id
            WHERE 1=1";
    $params = [];
    if ($filterStatus) { $sql .= " AND o.status=?"; $params[] = $filterStatus; }
    if ($search) { $sql .= " AND (o.offer_number LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
    $sql .= " ORDER BY o.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll();
}

// Print view
if ($action === 'print' && $offer) {
    include __DIR__ . '/includes/offer_print.php';
    exit;
}

$activePage = 'offers';
$pageTitle = 'Oferty';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Oferty</h1>
    <?php if ($action === 'list'): ?>
    <a href="offers.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowa oferta</a>
    <?php else: ?>
    <a href="offers.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm" placeholder="Nr oferty, klient..." value="<?= h($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Wszystkie statusy</option>
                    <?php foreach (['robocza','wyslana','zaakceptowana','odrzucona','anulowana'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtruj</button>
                <a href="offers.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">Oferty (<?= count($offers) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Nr oferty</th><th>Klient</th><th>Status</th><th>Ważna do</th><th>Netto</th><th>Brutto</th><th>Wystawił</th><th>Data</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $o): ?>
                <tr>
                    <td><a href="offers.php?action=view&id=<?= $o['id'] ?>" class="fw-bold"><?= h($o['offer_number']) ?></a></td>
                    <td><?= h($o['company_name'] ?: $o['contact_name'] ?? '—') ?></td>
                    <td><?= getStatusBadge($o['status'], 'offer') ?></td>
                    <td class="<?= $o['valid_until'] && $o['valid_until'] < date('Y-m-d') ? 'text-danger' : '' ?>">
                        <?= formatDate($o['valid_until']) ?>
                    </td>
                    <td><?= formatMoney($o['total_net']) ?></td>
                    <td class="fw-bold"><?= formatMoney($o['total_gross']) ?></td>
                    <td><?= h($o['user_name']) ?></td>
                    <td class="text-muted small"><?= formatDate($o['created_at']) ?></td>
                    <td>
                        <a href="offers.php?action=view&id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="offers.php?action=print&id=<?= $o['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary btn-action" title="Drukuj/PDF"><i class="fas fa-print"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $o['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                    data-confirm="Usuń ofertę <?= h($o['offer_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($offers)): ?><tr><td colspan="9" class="text-center text-muted p-3">Brak ofert.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && $offer): ?>
<div class="row g-3">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Oferta <strong><?= h($offer['offer_number']) ?></strong></span>
                <?= getStatusBadge($offer['status'], 'offer') ?>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6 class="text-muted">Wystawił</h6>
                        <p class="mb-0"><?= h($offer['user_name']) ?></p>
                        <small class="text-muted"><?= formatDateTime($offer['created_at']) ?></small>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Klient</h6>
                        <p class="mb-0 fw-bold"><?= h($offer['company_name'] ?: $offer['contact_name'] ?? '—') ?></p>
                        <?php if ($offer['company_name']): ?><p class="mb-0 small text-muted"><?= h($offer['contact_name']) ?></p><?php endif; ?>
                        <small class="text-muted"><?= h($offer['city'] ?? '') ?></small>
                    </div>
                </div>

                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr><th>Opis</th><th class="text-end">Ilość</th><th>J.m.</th><th class="text-end">Cena jedn.</th><th class="text-end">Wartość</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($offerItems as $item): ?>
                        <tr>
                            <td><?= h($item['description']) ?></td>
                            <td class="text-end"><?= h($item['quantity']) ?></td>
                            <td><?= h($item['unit']) ?></td>
                            <td class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                            <td class="text-end fw-bold"><?= formatMoney($item['total_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($offerItems)): ?>
                        <tr><td colspan="5" class="text-muted text-center">Brak pozycji</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-end">Suma netto:</td><td class="text-end fw-bold"><?= formatMoney($offer['total_net']) ?></td></tr>
                        <tr><td colspan="4" class="text-end">VAT (<?= h($offer['vat_rate']) ?>%):</td><td class="text-end"><?= formatMoney($offer['total_gross'] - $offer['total_net']) ?></td></tr>
                        <tr class="table-primary"><td colspan="4" class="text-end fw-bold">RAZEM brutto:</td><td class="text-end fw-bold fs-5"><?= formatMoney($offer['total_gross']) ?></td></tr>
                    </tfoot>
                </table>

                <?php if ($offer['notes']): ?>
                <div class="alert alert-light"><small><?= h($offer['notes']) ?></small></div>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <a href="offers.php?action=edit&id=<?= $offer['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <a href="offers.php?action=print&id=<?= $offer['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i>Drukuj/PDF</a>
                <a href="email.php?offer=<?= $offer['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-envelope me-1"></i>Wyślij e-mail</a>
                <a href="contracts.php?action=add&offer=<?= $offer['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-file-signature me-1"></i>Utwórz umowę</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Zmień status</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= $offer['id'] ?>">
                    <select name="new_status" class="form-select mb-2">
                        <?php foreach (['robocza','wyslana','zaakceptowana','odrzucona','anulowana'] as $s): ?>
                        <option value="<?= $s ?>" <?= $offer['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Zmień status</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card">
    <div class="card-header"><i class="fas fa-<?= $action === 'add' ? 'plus' : 'edit' ?> me-2"></i><?= $action === 'add' ? 'Nowa oferta' : 'Edytuj ofertę: ' . h($offer['offer_number'] ?? '') ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $offer['id'] ?>"><?php endif; ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Klient</label>
                    <select name="client_id" class="form-select">
                        <option value="">— wybierz klienta —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($offer['client_id'] ?? (int)($_GET['client'] ?? 0)) == $c['id'] ? 'selected' : '' ?>>
                            <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['robocza','wyslana','zaakceptowana','odrzucona','anulowana'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($offer['status'] ?? 'robocza') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ważna do</label>
                    <input type="date" name="valid_until" class="form-control" value="<?= h($offer['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Stawka VAT (%)</label>
                    <input type="number" name="vat_rate" id="vatRate" class="form-control" value="<?= h($offer['vat_rate'] ?? '23') ?>" min="0" max="100" step="1">
                </div>
            </div>

            <h6 class="fw-bold mb-2">Pozycje oferty</h6>
            <div class="row g-1 mb-1 text-muted small fw-bold">
                <div class="col-md-5">Opis</div>
                <div class="col-md-1">Ilość</div>
                <div class="col-md-1">J.m.</div>
                <div class="col-md-2">Cena jedn. (netto)</div>
                <div class="col-md-2">Wartość</div>
                <div class="col-md-1"></div>
            </div>
            <div id="offerItems">
                <?php if (!empty($offerItems)): ?>
                    <?php foreach ($offerItems as $i => $item): ?>
                    <div class="offer-item-row row g-2 mb-2 align-items-center">
                        <div class="col-md-5"><input type="text" name="items[<?= $i ?>][description]" class="form-control form-control-sm" value="<?= h($item['description']) ?>" required></div>
                        <div class="col-md-1"><input type="number" name="items[<?= $i ?>][quantity]" class="form-control form-control-sm item-qty" value="<?= h($item['quantity']) ?>" min="0.01" step="0.01"></div>
                        <div class="col-md-1"><input type="text" name="items[<?= $i ?>][unit]" class="form-control form-control-sm" value="<?= h($item['unit']) ?>"></div>
                        <div class="col-md-2"><input type="number" name="items[<?= $i ?>][unit_price]" class="form-control form-control-sm item-price" value="<?= h($item['unit_price']) ?>" min="0" step="0.01"></div>
                        <div class="col-md-2"><input type="number" name="items[<?= $i ?>][total_price]" class="form-control form-control-sm item-total" value="<?= h($item['total_price']) ?>" readonly></div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-item w-100"><i class="fas fa-times"></i></button></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="offer-item-row row g-2 mb-2 align-items-center">
                    <div class="col-md-5"><input type="text" name="items[0][description]" class="form-control form-control-sm" placeholder="Opis pozycji" required></div>
                    <div class="col-md-1"><input type="number" name="items[0][quantity]" class="form-control form-control-sm item-qty" value="1" min="0.01" step="0.01"></div>
                    <div class="col-md-1"><input type="text" name="items[0][unit]" class="form-control form-control-sm" value="szt"></div>
                    <div class="col-md-2"><input type="number" name="items[0][unit_price]" class="form-control form-control-sm item-price" value="0.00" min="0" step="0.01"></div>
                    <div class="col-md-2"><input type="number" name="items[0][total_price]" class="form-control form-control-sm item-total" value="0.00" readonly></div>
                    <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-item w-100"><i class="fas fa-times"></i></button></div>
                </div>
                <?php endif; ?>
            </div>
            <button type="button" id="addItemBtn" class="btn btn-sm btn-outline-success mb-3">
                <i class="fas fa-plus me-1"></i>Dodaj pozycję
            </button>

            <div class="row justify-content-end">
                <div class="col-md-4">
                    <table class="table table-sm text-end">
                        <tr><td>Netto:</td><td class="fw-bold" id="totalNet">0,00 zł</td></tr>
                        <tr><td>Brutto:</td><td class="fw-bold text-primary fs-5" id="totalGross">0,00 zł</td></tr>
                    </table>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Uwagi</label>
                <textarea name="notes" class="form-control" rows="3"><?= h($offer['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Utwórz ofertę' : 'Zapisz zmiany' ?></button>
            <a href="offers.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
