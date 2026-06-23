<?php
/**
 * FleetLink Magazyn - Offer Management
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Warsaw');
requireLogin();

$db = getDb();
$action = sanitize($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flashError('Błąd bezpieczeństwa.');
        redirect(getBaseUrl() . 'offers.php');
    }
    $postAction  = sanitize($_POST['action'] ?? '');
    $clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
    $status      = sanitize($_POST['status'] ?? 'robocza');
    $validUntil  = sanitize($_POST['valid_until'] ?? '') ?: null;
    $notes       = sanitize($_POST['notes'] ?? '');
    // VAT: dropdown may say 'custom', then use the custom input
    $vatRawSelect = $_POST['vat_rate'] ?? '23';
    $vatRawCustom = $_POST['vat_rate_custom'] ?? '';
    $vatRate = $vatRawSelect === 'custom'
        ? min(100, max(0, (float)str_replace(',', '.', $vatRawCustom)))
        : min(100, max(0, (float)str_replace(',', '.', $vatRawSelect)));
    $discount    = min(100, max(0, (float)str_replace(',', '.', $_POST['discount'] ?? '0')));
    // payment_terms: may come from select or custom text input
    $paymentTerms = sanitize(trim($_POST['payment_terms'] ?? '14 dni')) ?: '14 dni';
    $deliveryTerms = sanitize($_POST['delivery_terms'] ?? '');
    $items       = $_POST['items'] ?? [];
    $user        = getCurrentUser();

    $validStatuses = ['robocza','wyslana','zaakceptowana','odrzucona','anulowana'];
    if (!in_array($status, $validStatuses)) $status = 'robocza';

    // Calculate totals
    $totalNet = 0;
    $cleanItems = [];
    foreach ($items as $item) {
        $desc      = sanitize($item['description'] ?? '');
        $qty       = (float)str_replace(',', '.', $item['quantity'] ?? '1');
        $unit      = sanitize($item['unit'] ?? 'szt');
        $unitPrice = (float)str_replace(',', '.', $item['unit_price'] ?? '0');
        if (empty($desc) || $qty <= 0) continue;
        $lineNet   = round($qty * $unitPrice, 2);
        $totalNet += $lineNet;
        $cleanItems[] = compact('desc', 'qty', 'unit', 'unitPrice', 'lineNet');
    }
    $discountAmount = round($totalNet * $discount / 100, 2);
    $netAfterDiscount = round($totalNet - $discountAmount, 2);
    $totalGross = round($netAfterDiscount * (1 + $vatRate / 100), 2);

    if ($postAction === 'add') {
        $offerNumber = generateOfferNumber();
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO offers (offer_number, client_id, user_id, status, valid_until, notes, total_net, total_gross, vat_rate, discount, payment_terms, delivery_terms) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$offerNumber, $clientId, $user['id'], $status, $validUntil, $notes, $netAfterDiscount, $totalGross, $vatRate, $discount, $paymentTerms, $deliveryTerms]);
            $offerId = $db->lastInsertId();
            foreach ($cleanItems as $i => $item) {
                $db->prepare("INSERT INTO offer_items (offer_id, description, quantity, unit, unit_price, total_price, sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$offerId, $item['desc'], $item['qty'], $item['unit'], $item['unitPrice'], $item['lineNet'], $i]);
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
            $db->prepare("UPDATE offers SET client_id=?, status=?, valid_until=?, notes=?, total_net=?, total_gross=?, vat_rate=?, discount=?, payment_terms=?, delivery_terms=? WHERE id=?")
               ->execute([$clientId, $status, $validUntil, $notes, $netAfterDiscount, $totalGross, $vatRate, $discount, $paymentTerms, $deliveryTerms, $editId]);
            $db->prepare("DELETE FROM offer_items WHERE offer_id=?")->execute([$editId]);
            foreach ($cleanItems as $i => $item) {
                $db->prepare("INSERT INTO offer_items (offer_id, description, quantity, unit, unit_price, total_price, sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$editId, $item['desc'], $item['qty'], $item['unit'], $item['unitPrice'], $item['lineNet'], $i]);
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

    } elseif ($postAction === 'duplicate') {
        $srcId = (int)($_POST['id'] ?? 0);
        $src = $db->prepare("SELECT * FROM offers WHERE id=?");
        $src->execute([$srcId]);
        $srcOffer = $src->fetch();
        if ($srcOffer) {
            $newNumber = generateOfferNumber();
            $db->prepare("INSERT INTO offers (offer_number, client_id, user_id, status, valid_until, notes, total_net, total_gross, vat_rate, discount, payment_terms, delivery_terms) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$newNumber, $srcOffer['client_id'], $user['id'], 'robocza', null, $srcOffer['notes'], $srcOffer['total_net'], $srcOffer['total_gross'], $srcOffer['vat_rate'], $srcOffer['discount'] ?? 0, $srcOffer['payment_terms'] ?? '14 dni', $srcOffer['delivery_terms'] ?? '']);
            $newId = $db->lastInsertId();
            $srcItems = $db->prepare("SELECT * FROM offer_items WHERE offer_id=? ORDER BY sort_order");
            $srcItems->execute([$srcId]);
            foreach ($srcItems->fetchAll() as $i => $item) {
                $db->prepare("INSERT INTO offer_items (offer_id, description, quantity, unit, unit_price, total_price, sort_order) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$newId, $item['description'], $item['quantity'], $item['unit'], $item['unit_price'], $item['total_price'], $i]);
            }
            flashSuccess("Zduplikowano jako $newNumber.");
            redirect(getBaseUrl() . 'offers.php?action=edit&id=' . $newId);
        }
        redirect(getBaseUrl() . 'offers.php');
    }
}

// Load models for autocomplete/quick-fill
$deviceModels = $db->query("
    SELECT m.id, m.name, m.price_sale, m.price_installation, m.price_service, m.price_subscription,
           mf.name as manufacturer_name
    FROM models m
    JOIN manufacturers mf ON mf.id = m.manufacturer_id
    WHERE m.active = 1
    ORDER BY mf.name, m.name
")->fetchAll();

$offer = null;
$offerItems = [];
if (in_array($action, ['view', 'edit', 'print', 'print_contract']) && $id) {
    $stmt = $db->prepare("
        SELECT o.*, c.contact_name, c.company_name, c.address, c.city, c.postal_code, c.nip,
               c.email as client_email, c.phone as client_phone,
               u.name as user_name, u.email as user_email
        FROM offers o
        LEFT JOIN clients c ON c.id = o.client_id
        JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $offer = $stmt->fetch();
    if (!$offer) { flashError('Oferta nie istnieje.'); redirect(getBaseUrl() . 'offers.php'); }

    $itemStmt = $db->prepare("SELECT * FROM offer_items WHERE offer_id = ? ORDER BY sort_order");
    $itemStmt->execute([$id]);
    $offerItems = $itemStmt->fetchAll();
}

$clients = $db->query("SELECT id, contact_name, company_name FROM clients WHERE active = 1 ORDER BY company_name, contact_name")->fetchAll();

// Company settings
$settings = [];
foreach ($db->query("SELECT `key`, `value` FROM settings")->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

$offers = [];
if ($action === 'list') {
    $filterStatus = sanitize($_GET['status'] ?? '');
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT o.*, c.contact_name, c.company_name, u.name as user_name
            FROM offers o
            LEFT JOIN clients c ON c.id = o.client_id
            JOIN users u ON u.id = o.user_id
            WHERE 1=1";
    $params = [];
    if ($filterStatus) { $sql .= " AND o.status = ?"; $params[] = $filterStatus; }
    if ($search) {
        $sql .= " AND (o.offer_number LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
    }
    $sql .= " ORDER BY o.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll();
}

// Print views
if (($action === 'print' || $action === 'print_contract') && $offer) {
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
    <a href="offers.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Lista ofert</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<!-- ===== LIST VIEW ===== -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="search" name="search" class="form-control form-control-sm"
                       placeholder="Nr oferty, firma, klient..." value="<?= h($_GET['search'] ?? '') ?>">
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
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>Lista ofert <span class="badge bg-secondary"><?= count($offers) ?></span></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nr oferty</th>
                    <th>Klient</th>
                    <th>Status</th>
                    <th>Ważna do</th>
                    <th class="text-end">Netto</th>
                    <th class="text-end">Brutto</th>
                    <th>Wystawił</th>
                    <th>Data</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $o): ?>
                <tr>
                    <td>
                        <a href="offers.php?action=view&id=<?= $o['id'] ?>" class="fw-bold text-decoration-none">
                            <?= h($o['offer_number']) ?>
                        </a>
                    </td>
                    <td><?= h($o['company_name'] ?: ($o['contact_name'] ?? '—')) ?></td>
                    <td><?= getStatusBadge($o['status'], 'offer') ?></td>
                    <td class="<?= ($o['valid_until'] && $o['valid_until'] < date('Y-m-d')) ? 'text-danger fw-semibold' : '' ?>">
                        <?= formatDate($o['valid_until']) ?>
                    </td>
                    <td class="text-end"><?= formatMoney($o['total_net']) ?></td>
                    <td class="text-end fw-bold"><?= formatMoney($o['total_gross']) ?></td>
                    <td class="text-muted small"><?= h($o['user_name']) ?></td>
                    <td class="text-muted small"><?= formatDate($o['created_at']) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="offers.php?action=view&id=<?= $o['id'] ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Podgląd">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="offers.php?action=print&id=<?= $o['id'] ?>" target="_blank"
                               class="btn btn-sm btn-outline-secondary btn-action" title="Drukuj ofertę">
                                <i class="fas fa-print"></i>
                            </a>
                            <a href="offers.php?action=print_contract&id=<?= $o['id'] ?>" target="_blank"
                               class="btn btn-sm btn-outline-info btn-action" title="Drukuj z umową">
                                <i class="fas fa-file-contract"></i>
                            </a>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success btn-action" title="Duplikuj">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger btn-action"
                                        data-confirm="Usuń ofertę <?= h($o['offer_number']) ?>?">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($offers)): ?>
                <tr><td colspan="9" class="text-center text-muted p-4">
                    <i class="fas fa-file-invoice-dollar fa-2x mb-2 d-block opacity-25"></i>
                    Brak ofert. <a href="offers.php?action=add">Utwórz pierwszą ofertę</a>.
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && $offer): ?>
<!-- ===== VIEW OFFER ===== -->
<div class="row g-3">
    <div class="col-lg-8">
        <!-- Offer details card -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-file-invoice-dollar me-2"></i>Oferta <strong><?= h($offer['offer_number']) ?></strong></span>
                <?= getStatusBadge($offer['status'], 'offer') ?>
            </div>
            <div class="card-body">
                <!-- Parties -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 rounded" style="background:#f8f9fa; border-left:3px solid #0d6efd;">
                            <h6 class="text-primary text-uppercase small fw-bold mb-2"><i class="fas fa-building me-1"></i>Wystawca</h6>
                            <div class="fw-semibold"><?= h($settings['company_name'] ?? 'Twoja Firma') ?></div>
                            <?php if ($settings['company_address'] ?? ''): ?>
                            <small class="text-muted"><?= h($settings['company_address']) ?></small><br>
                            <?php endif; ?>
                            <?php if ($settings['company_nip'] ?? ''): ?>
                            <small class="text-muted">NIP: <?= h($settings['company_nip']) ?></small><br>
                            <?php endif; ?>
                            <small class="text-muted"><?= h($offer['user_name']) ?></small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded" style="background:#f8f9fa; border-left:3px solid #198754;">
                            <h6 class="text-success text-uppercase small fw-bold mb-2"><i class="fas fa-user-tie me-1"></i>Odbiorca</h6>
                            <?php if ($offer['client_id']): ?>
                            <div class="fw-semibold"><?= h($offer['company_name'] ?: $offer['contact_name']) ?></div>
                            <?php if ($offer['company_name']): ?>
                            <small class="text-muted"><?= h($offer['contact_name']) ?></small><br>
                            <?php endif; ?>
                            <?php if ($offer['address']): ?>
                            <small class="text-muted"><?= h($offer['address']) ?></small><br>
                            <?php endif; ?>
                            <?php if ($offer['nip']): ?>
                            <small class="text-muted">NIP: <?= h($offer['nip']) ?></small>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <table class="table table-bordered table-sm">
                    <thead class="table-primary">
                        <tr>
                            <th style="width:30px">Lp.</th>
                            <th>Opis / Usługa</th>
                            <th class="text-end" style="width:70px">Ilość</th>
                            <th style="width:50px">J.m.</th>
                            <th class="text-end" style="width:120px">Cena jedn. netto</th>
                            <th class="text-end" style="width:120px">Wartość netto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($offerItems as $i => $item): ?>
                        <tr>
                            <td class="text-center text-muted"><?= $i + 1 ?></td>
                            <td><?= h($item['description']) ?></td>
                            <td class="text-end"><?= h($item['quantity']) ?></td>
                            <td><?= h($item['unit']) ?></td>
                            <td class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                            <td class="text-end fw-semibold"><?= formatMoney($item['total_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($offerItems)): ?>
                        <tr><td colspan="6" class="text-muted text-center p-3">Brak pozycji</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <?php $rawNet = array_sum(array_column($offerItems, 'total_price')); ?>
                        <?php if (($offer['discount'] ?? 0) > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end text-muted">Suma przed rabatem:</td>
                            <td class="text-end text-muted"><?= formatMoney($rawNet) ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end text-danger">Rabat (<?= h($offer['discount']) ?>%):</td>
                            <td class="text-end text-danger">-<?= formatMoney(round($rawNet * $offer['discount'] / 100, 2)) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-light">
                            <td colspan="5" class="text-end fw-semibold">Suma netto:</td>
                            <td class="text-end fw-bold"><?= formatMoney($offer['total_net']) ?></td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="5" class="text-end">VAT (<?= h($offer['vat_rate']) ?>%):</td>
                            <td class="text-end"><?= formatMoney($offer['total_gross'] - $offer['total_net']) ?></td>
                        </tr>
                        <tr class="table-primary">
                            <td colspan="5" class="text-end fw-bold">RAZEM BRUTTO:</td>
                            <td class="text-end fw-bold fs-5"><?= formatMoney($offer['total_gross']) ?></td>
                        </tr>
                    </tfoot>
                </table>

                <?php if ($offer['payment_terms'] ?? ''): ?>
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted"><strong>Termin płatności:</strong> <?= h($offer['payment_terms']) ?></small>
                    </div>
                    <?php if ($offer['delivery_terms'] ?? ''): ?>
                    <div class="col-md-6">
                        <small class="text-muted"><strong>Termin realizacji:</strong> <?= h($offer['delivery_terms']) ?></small>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($offer['notes']): ?>
                <div class="alert alert-light mt-2 mb-0 p-2">
                    <small><i class="fas fa-sticky-note me-1 text-muted"></i><?= nl2br(h($offer['notes'])) ?></small>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <div class="d-flex gap-2 flex-wrap">
                    <a href="offers.php?action=edit&id=<?= $offer['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-edit me-1"></i>Edytuj
                    </a>
                    <a href="offers.php?action=print&id=<?= $offer['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-print me-1"></i>Drukuj ofertę
                    </a>
                    <a href="offers.php?action=print_contract&id=<?= $offer['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-file-contract me-1"></i>Drukuj ofertę + umowę
                    </a>
                    <a href="email.php?offer=<?= $offer['id'] ?>" class="btn btn-sm btn-outline-dark">
                        <i class="fas fa-envelope me-1"></i>Wyślij e-mail
                    </a>
                    <a href="contracts.php?action=add&offer=<?= $offer['id'] ?>" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-file-signature me-1"></i>Utwórz umowę
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Status card -->
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-info-circle me-2"></i>Szczegóły</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Wystawiona:</dt>
                    <dd class="col-7"><?= formatDate($offer['created_at']) ?></dd>
                    <dt class="col-5 text-muted">Ważna do:</dt>
                    <dd class="col-7 <?= ($offer['valid_until'] && $offer['valid_until'] < date('Y-m-d')) ? 'text-danger fw-bold' : '' ?>">
                        <?= formatDate($offer['valid_until']) ?>
                    </dd>
                    <dt class="col-5 text-muted">VAT:</dt>
                    <dd class="col-7"><?= h($offer['vat_rate']) ?>%</dd>
                    <?php if (($offer['discount'] ?? 0) > 0): ?>
                    <dt class="col-5 text-muted">Rabat:</dt>
                    <dd class="col-7 text-danger"><?= h($offer['discount']) ?>%</dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Wystawił:</dt>
                    <dd class="col-7"><?= h($offer['user_name']) ?></dd>
                </dl>
            </div>
        </div>
        <!-- Change status card -->
        <div class="card">
            <div class="card-header"><i class="fas fa-exchange-alt me-2"></i>Zmień status</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= $offer['id'] ?>">
                    <select name="new_status" class="form-select form-select-sm mb-2">
                        <?php foreach (['robocza','wyslana','zaakceptowana','odrzucona','anulowana'] as $s): ?>
                        <option value="<?= $s ?>" <?= $offer['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Zapisz status</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- ===== GENERATOR FORM ===== -->
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-<?= $action === 'add' ? 'plus-circle' : 'edit' ?> me-2 text-primary"></i>
                    <?= $action === 'add' ? 'Generator nowej oferty' : 'Edycja oferty: <strong>' . h($offer['offer_number'] ?? '') . '</strong>' ?>
                </span>
                <?php if (!empty($deviceModels)): ?>
                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modelPickerModal">
                    <i class="fas fa-microchip me-1"></i>Dodaj z katalogu urządzeń
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="offerForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $action ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= $offer['id'] ?>">
                    <?php endif; ?>

                    <!-- Section 1: Header info -->
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                                <i class="fas fa-user-tie me-2"></i>Dane podstawowe
                            </h6>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Klient</label>
                            <select name="client_id" id="clientSelect" class="form-select">
                                <option value="">— wybierz klienta —</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                        <?= ($offer['client_id'] ?? (int)($_GET['client'] ?? 0)) == $c['id'] ? 'selected' : '' ?>>
                                    <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <a href="clients.php?action=add" target="_blank"><i class="fas fa-plus me-1"></i>Dodaj nowego klienta</a>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['robocza','wyslana','zaakceptowana','odrzucona','anulowana'] as $s): ?>
                                <option value="<?= $s ?>" <?= ($offer['status'] ?? 'robocza') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Ważna do</label>
                            <input type="date" name="valid_until" class="form-control"
                                   value="<?= h($offer['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Stawka VAT (%)</label>
                            <select name="vat_rate" id="vatRate" class="form-select">
                                <?php foreach (['0','5','8','23'] as $v): ?>
                                <option value="<?= $v ?>" <?= (string)($offer['vat_rate'] ?? '23') === $v ? 'selected' : '' ?>><?= $v ?>%</option>
                                <?php endforeach; ?>
                                <option value="custom" <?= !in_array((string)($offer['vat_rate'] ?? '23'), ['0','5','8','23']) ? 'selected' : '' ?>>Inna...</option>
                            </select>
                        </div>
                        <div class="col-md-2" id="vatCustomWrap" style="display:<?= !in_array((string)($offer['vat_rate'] ?? '23'), ['0','5','8','23']) ? 'block' : 'none' ?>">
                            <label class="form-label fw-semibold">VAT % (własny)</label>
                            <input type="number" name="vat_rate_custom" id="vatRateCustom" class="form-control"
                                   value="<?= !in_array((string)($offer['vat_rate'] ?? '23'), ['0','5','8','23']) ? h($offer['vat_rate']) : '' ?>"
                                   min="0" max="100" step="1" placeholder="np. 7">
                        </div>
                    </div>

                    <!-- Section 2: Terms -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Termin płatności</label>
                            <select name="payment_terms" class="form-select" id="paymentTermsSelect">
                                <?php $pt = $offer['payment_terms'] ?? '14 dni'; ?>
                                <?php $ptOptions = ['7 dni', '14 dni', '21 dni', '30 dni', 'Przedpłata', 'Gotówka']; ?>
                                <?php foreach ($ptOptions as $opt): ?>
                                <option value="<?= $opt ?>" <?= $pt === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                                <option value="other" <?= !in_array($pt, $ptOptions) ? 'selected' : '' ?>>Inny...</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="paymentTermsCustomWrap" style="display:<?= !in_array($offer['payment_terms'] ?? '14 dni', $ptOptions ?? []) ? 'block' : 'none' ?>">
                            <label class="form-label fw-semibold">Termin płatności (własny)</label>
                            <input type="text" name="payment_terms_custom" id="paymentTermsCustom" class="form-control"
                                   value="<?= !in_array($offer['payment_terms'] ?? '14 dni', $ptOptions ?? []) ? h($offer['payment_terms'] ?? '') : '' ?>"
                                   placeholder="np. 45 dni">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Termin realizacji / dostawy</label>
                            <input type="text" name="delivery_terms" class="form-control"
                                   value="<?= h($offer['delivery_terms'] ?? '') ?>"
                                   placeholder="np. 7 dni roboczych od akceptacji">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Rabat (%)</label>
                            <div class="input-group">
                                <input type="number" name="discount" id="discount" class="form-control"
                                       value="<?= h($offer['discount'] ?? '0') ?>" min="0" max="100" step="0.5">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Items table -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-list-ul me-2"></i>Pozycje oferty
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:30px">#</th>
                                        <th>Opis / Nazwa usługi lub produktu</th>
                                        <th style="width:80px">Ilość</th>
                                        <th style="width:60px">J.m.</th>
                                        <th style="width:130px">Cena jedn. netto</th>
                                        <th style="width:130px">Wartość netto</th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody id="offerItems">
                                    <?php if (!empty($offerItems)): ?>
                                        <?php foreach ($offerItems as $i => $item): ?>
                                        <?= renderOfferItemRow($i, $item) ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?= renderOfferItemRow(0) ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="button" id="addItemBtn" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-plus me-1"></i>Dodaj pozycję
                        </button>
                    </div>

                    <!-- Section 4: Totals -->
                    <div class="row justify-content-end mb-4">
                        <div class="col-md-5 col-lg-4">
                            <div class="card border-primary">
                                <div class="card-header text-primary fw-bold py-2">
                                    <i class="fas fa-calculator me-2"></i>Podsumowanie
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <td class="text-muted">Suma netto (przed rabatem):</td>
                                            <td class="text-end fw-semibold" id="rawNet">0,00 zł</td>
                                        </tr>
                                        <tr id="discountRow" style="display:none">
                                            <td class="text-danger">Rabat (<span id="discountPct">0</span>%):</td>
                                            <td class="text-end text-danger" id="discountAmt">-0,00 zł</td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-semibold">Netto po rabacie:</td>
                                            <td class="text-end fw-bold" id="totalNet">0,00 zł</td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">VAT (<span id="vatPct">23</span>%):</td>
                                            <td class="text-end" id="totalVat">0,00 zł</td>
                                        </tr>
                                        <tr class="table-primary">
                                            <td class="fw-bold">RAZEM BRUTTO:</td>
                                            <td class="text-end fw-bold fs-5" id="totalGross">0,00 zł</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 5: Notes -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-sticky-note me-2"></i>Uwagi i informacje dodatkowe
                        </h6>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Warunki gwarancji, dodatkowe informacje, itp."><?= h($offer['notes'] ?? '') ?></textarea>
                    </div>

                    <!-- Buttons -->
                    <div class="d-flex gap-2 flex-wrap border-top pt-3">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Utwórz ofertę' : 'Zapisz zmiany' ?>
                        </button>
                        <a href="offers.php" class="btn btn-outline-secondary btn-lg">Anuluj</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Device Model Picker Modal -->
<?php if (!empty($deviceModels)): ?>
<div class="modal fade" id="modelPickerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-microchip me-2"></i>Katalog urządzeń</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 border-bottom">
                    <input type="search" id="modelSearch" class="form-control form-control-sm" placeholder="Szukaj modelu...">
                </div>
                <div class="table-responsive" style="max-height:400px; overflow-y:auto;">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Producent</th>
                                <th>Model</th>
                                <th class="text-end">Urządzenie</th>
                                <th class="text-end">Montaż</th>
                                <th class="text-end">Serwis</th>
                                <th class="text-end">Abonament/mc</th>
                                <th>Dodaj</th>
                            </tr>
                        </thead>
                        <tbody id="modelTable">
                            <?php foreach ($deviceModels as $m): ?>
                            <tr class="model-row">
                                <td class="small text-muted"><?= h($m['manufacturer_name']) ?></td>
                                <td class="fw-semibold"><?= h($m['name']) ?></td>
                                <td class="text-end small"><?= $m['price_sale'] > 0 ? formatMoney($m['price_sale']) : '—' ?></td>
                                <td class="text-end small"><?= $m['price_installation'] > 0 ? formatMoney($m['price_installation']) : '—' ?></td>
                                <td class="text-end small"><?= $m['price_service'] > 0 ? formatMoney($m['price_service']) : '—' ?></td>
                                <td class="text-end small"><?= $m['price_subscription'] > 0 ? formatMoney($m['price_subscription']) : '—' ?></td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <?php if ($m['price_sale'] > 0): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary add-model-item"
                                                data-desc="<?= h($m['manufacturer_name'] . ' ' . $m['name']) ?>"
                                                data-price="<?= h($m['price_sale']) ?>"
                                                data-unit="szt">
                                            Urządzenie
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($m['price_installation'] > 0): ?>
                                        <button type="button" class="btn btn-xs btn-outline-success add-model-item"
                                                data-desc="<?= h('Montaż ' . $m['manufacturer_name'] . ' ' . $m['name']) ?>"
                                                data-price="<?= h($m['price_installation']) ?>"
                                                data-unit="szt">
                                            Montaż
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($m['price_subscription'] > 0): ?>
                                        <button type="button" class="btn btn-xs btn-outline-info add-model-item"
                                                data-desc="<?= h('Abonament ' . $m['manufacturer_name'] . ' ' . $m['name']) ?>"
                                                data-price="<?= h($m['price_subscription']) ?>"
                                                data-unit="mc">
                                            Abonament
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Hidden template row for JS cloning -->
<template id="itemRowTemplate">
    <?= renderOfferItemRow('__IDX__') ?>
</template>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Render a single offer item row for the table.
 */
function renderOfferItemRow(int|string $idx, array $item = []): string {
    $desc      = htmlspecialchars($item['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $qty       = htmlspecialchars($item['quantity']    ?? '1', ENT_QUOTES, 'UTF-8');
    $unit      = htmlspecialchars($item['unit']        ?? 'szt', ENT_QUOTES, 'UTF-8');
    $price     = htmlspecialchars($item['unit_price']  ?? '0.00', ENT_QUOTES, 'UTF-8');
    $total     = htmlspecialchars($item['total_price'] ?? '0.00', ENT_QUOTES, 'UTF-8');
    $n         = $idx === '__IDX__' ? '__IDX__' : (int)$idx;

    return <<<HTML
    <tr class="offer-item-row">
        <td class="text-center text-muted align-middle lp-cell"></td>
        <td><input type="text" name="items[{$n}][description]" class="form-control form-control-sm item-desc"
                   value="{$desc}" placeholder="Opis pozycji / usługi" required></td>
        <td><input type="number" name="items[{$n}][quantity]" class="form-control form-control-sm item-qty"
                   value="{$qty}" min="0.01" step="0.01"></td>
        <td><input type="text" name="items[{$n}][unit]" class="form-control form-control-sm item-unit"
                   value="{$unit}" style="width:55px"></td>
        <td><input type="number" name="items[{$n}][unit_price]" class="form-control form-control-sm item-price"
                   value="{$price}" min="0" step="0.01"></td>
        <td><input type="number" name="items[{$n}][total_price]" class="form-control form-control-sm item-total bg-light"
                   value="{$total}" readonly tabindex="-1"></td>
        <td class="text-center align-middle">
            <button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Usuń">
                <i class="fas fa-times"></i>
            </button>
        </td>
    </tr>
    HTML;
}
?>
