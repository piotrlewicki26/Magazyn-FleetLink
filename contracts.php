<?php
/**
 * FleetLink System GPS - Contracts Management
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
$preOfferId = (int)($_GET['offer'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { flashError('Błąd bezpieczeństwa.'); redirect(getBaseUrl() . 'contracts.php'); }
    $postAction  = sanitize($_POST['action'] ?? '');
    $offerId     = (int)($_POST['offer_id'] ?? 0) ?: null;
    $clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
    $type        = sanitize($_POST['type'] ?? 'montaz');
    $startDate   = sanitize($_POST['start_date'] ?? '') ?: null;
    $endDate     = sanitize($_POST['end_date'] ?? '') ?: null;
    $value       = str_replace(',', '.', $_POST['value'] ?? '0');
    $content     = sanitize($_POST['content'] ?? '');
    $status      = sanitize($_POST['status'] ?? 'aktywna');

    $validTypes    = ['montaz','serwis','subskrypcja','inne'];
    $validStatuses = ['aktywna','zakonczona','anulowana'];
    if (!in_array($type, $validTypes)) $type = 'montaz';
    if (!in_array($status, $validStatuses)) $status = 'aktywna';

    if ($postAction === 'add') {
        $contractNum = 'UM/' . date('Y') . '/' . sprintf('%04d', ($db->query("SELECT COUNT(*) FROM contracts WHERE YEAR(created_at)=" . date('Y'))->fetchColumn() + 1));
        $db->prepare("INSERT INTO contracts (offer_id, client_id, contract_number, type, start_date, end_date, value, content, status) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$offerId, $clientId, $contractNum, $type, $startDate, $endDate, $value, $content, $status]);
        flashSuccess("Umowa $contractNum została utworzona.");
        redirect(getBaseUrl() . 'contracts.php');

    } elseif ($postAction === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE contracts SET offer_id=?, client_id=?, type=?, start_date=?, end_date=?, value=?, content=?, status=? WHERE id=?")
           ->execute([$offerId, $clientId, $type, $startDate, $endDate, $value, $content, $status, $editId]);
        flashSuccess('Umowa zaktualizowana.');
        redirect(getBaseUrl() . 'contracts.php');

    } elseif ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM contracts WHERE id=?")->execute([$delId]);
        flashSuccess('Umowa usunięta.');
        redirect(getBaseUrl() . 'contracts.php');
    }
}

$contract = null;
if (in_array($action, ['edit','view']) && $id) {
    $stmt = $db->prepare("SELECT c.*, cl.contact_name, cl.company_name, o.offer_number FROM contracts c LEFT JOIN clients cl ON cl.id=c.client_id LEFT JOIN offers o ON o.id=c.offer_id WHERE c.id=?");
    $stmt->execute([$id]);
    $contract = $stmt->fetch();
    if (!$contract) { flashError('Umowa nie istnieje.'); redirect(getBaseUrl() . 'contracts.php'); }
}

$clients = $db->query("SELECT id, contact_name, company_name FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
$allOffers = $db->query("SELECT id, offer_number FROM offers ORDER BY created_at DESC LIMIT 50")->fetchAll();

// Pre-fill from offer
$preOffer = null;
if ($preOfferId) {
    $stmt = $db->prepare("SELECT o.*, c.contact_name, c.company_name FROM offers o LEFT JOIN clients c ON c.id=o.client_id WHERE o.id=?");
    $stmt->execute([$preOfferId]);
    $preOffer = $stmt->fetch();
}

// Get company settings for contract template
$settings = [];
foreach ($db->query("SELECT `key`, `value` FROM settings")->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

$contracts = [];
if ($action === 'list') {
    $contracts = $db->query("
        SELECT c.*, cl.contact_name, cl.company_name, o.offer_number
        FROM contracts c
        LEFT JOIN clients cl ON cl.id=c.client_id
        LEFT JOIN offers o ON o.id=c.offer_id
        ORDER BY c.created_at DESC
    ")->fetchAll();
}

$activePage = 'offers';
$pageTitle = 'Umowy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-file-signature me-2 text-primary"></i>Umowy</h1>
    <?php if ($action === 'list'): ?>
    <a href="contracts.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Nowa umowa</a>
    <?php else: ?>
    <a href="contracts.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Powrót</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">Umowy (<?= count($contracts) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Nr umowy</th><th>Typ</th><th>Klient</th><th>Powiązana oferta</th><th>Od</th><th>Do</th><th>Wartość</th><th>Status</th><th>Akcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($contracts as $c): ?>
                <tr>
                    <td class="fw-bold"><?= h($c['contract_number']) ?></td>
                    <td><?= h(ucfirst($c['type'])) ?></td>
                    <td><?= h($c['company_name'] ?: $c['contact_name'] ?? '—') ?></td>
                    <td><?= $c['offer_number'] ? '<a href="offers.php?action=view&id=' . $c['offer_id'] . '">' . h($c['offer_number']) . '</a>' : '—' ?></td>
                    <td><?= formatDate($c['start_date']) ?></td>
                    <td class="<?= $c['end_date'] && $c['end_date'] < date('Y-m-d') ? 'text-danger' : '' ?>"><?= formatDate($c['end_date']) ?></td>
                    <td><?= formatMoney($c['value']) ?></td>
                    <td><?= getStatusBadge($c['status'], 'installation') ?></td>
                    <td>
                        <a href="contracts.php?action=view&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info btn-action"><i class="fas fa-eye"></i></a>
                        <a href="contracts.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-action" data-confirm="Usuń umowę <?= h($c['contract_number']) ?>?"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($contracts)): ?><tr><td colspan="9" class="text-center text-muted p-3">Brak umów.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'view' && $contract): ?>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Szczegóły umowy</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted">Nr umowy</th><td class="fw-bold"><?= h($contract['contract_number']) ?></td></tr>
                    <tr><th class="text-muted">Typ</th><td><?= h(ucfirst($contract['type'])) ?></td></tr>
                    <tr><th class="text-muted">Klient</th><td><?= h($contract['company_name'] ?: $contract['contact_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Oferta</th><td><?= $contract['offer_number'] ? h($contract['offer_number']) : '—' ?></td></tr>
                    <tr><th class="text-muted">Obowiązuje od</th><td><?= formatDate($contract['start_date']) ?></td></tr>
                    <tr><th class="text-muted">Obowiązuje do</th><td><?= formatDate($contract['end_date']) ?></td></tr>
                    <tr><th class="text-muted">Wartość</th><td class="fw-bold"><?= formatMoney($contract['value']) ?></td></tr>
                    <tr><th class="text-muted">Status</th><td><?= getStatusBadge($contract['status'], 'installation') ?></td></tr>
                </table>
            </div>
            <div class="card-footer d-flex gap-2">
                <a href="contracts.php?action=edit&id=<?= $contract['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Edytuj</a>
                <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i>Drukuj</button>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Treść umowy</div>
            <div class="card-body">
                <?php if ($contract['content']): ?>
                <div style="white-space: pre-wrap; font-family: serif; font-size:13px;"><?= h($contract['content']) ?></div>
                <?php else: ?>
                <p class="text-muted">Brak treści umowy. <a href="contracts.php?action=edit&id=<?= $contract['id'] ?>">Dodaj treść.</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card" style="max-width:800px">
    <div class="card-header"><i class="fas fa-file-signature me-2"></i><?= $action === 'add' ? 'Nowa umowa' : 'Edytuj umowę' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $action ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $contract['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Powiązana oferta</label>
                    <select name="offer_id" class="form-select">
                        <option value="">— brak —</option>
                        <?php foreach ($allOffers as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($contract['offer_id'] ?? $preOfferId) == $o['id'] ? 'selected' : '' ?>><?= h($o['offer_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Klient</label>
                    <select name="client_id" class="form-select">
                        <option value="">— wybierz —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"
                                <?= ($contract['client_id'] ?? $preOffer['client_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                            <?= h(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['contact_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Typ umowy</label>
                    <select name="type" class="form-select">
                        <option value="montaz" <?= ($contract['type'] ?? 'montaz') === 'montaz' ? 'selected' : '' ?>>Montaż GPS</option>
                        <option value="serwis" <?= ($contract['type'] ?? '') === 'serwis' ? 'selected' : '' ?>>Serwis</option>
                        <option value="subskrypcja" <?= ($contract['type'] ?? '') === 'subskrypcja' ? 'selected' : '' ?>>Subskrypcja</option>
                        <option value="inne" <?= ($contract['type'] ?? '') === 'inne' ? 'selected' : '' ?>>Inne</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Wartość umowy</label>
                    <div class="input-group">
                        <input type="number" name="value" class="form-control" value="<?= h($contract['value'] ?? $preOffer['total_gross'] ?? '0') ?>" min="0" step="0.01">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="aktywna" <?= ($contract['status'] ?? 'aktywna') === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
                        <option value="zakonczona" <?= ($contract['status'] ?? '') === 'zakonczona' ? 'selected' : '' ?>>Zakończona</option>
                        <option value="anulowana" <?= ($contract['status'] ?? '') === 'anulowana' ? 'selected' : '' ?>>Anulowana</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Data rozpoczęcia</label>
                    <input type="date" name="start_date" class="form-control" value="<?= h($contract['start_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Data zakończenia</label>
                    <input type="date" name="end_date" class="form-control" value="<?= h($contract['end_date'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Treść umowy</label>
                    <textarea name="content" class="form-control" rows="12"><?= h($contract['content'] ?? ($settings['contract_template'] ?? '')) ?></textarea>
                    <small class="text-muted">Możesz wpisać pełną treść umowy lub używać szablonu z ustawień.</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Utwórz umowę' : 'Zapisz zmiany' ?></button>
                    <a href="contracts.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
