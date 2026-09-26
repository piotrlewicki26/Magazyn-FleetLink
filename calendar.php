<?php
/**
 * FleetLink System GPS - Calendar View
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

date_default_timezone_set(APP_TIMEZONE);
requireLogin();

$db = getDb();

// Get events for calendar (JSON)
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    $start = sanitize($_GET['start'] ?? date('Y-m-01'));
    $end   = sanitize($_GET['end'] ?? date('Y-m-t'));

    $events = [];

    // Services
    $stmt = $db->prepare("
        SELECT s.id, s.planned_date, s.type, s.status, s.description,
               d.serial_number, m.name as model_name,
               v.registration
        FROM services s
        JOIN devices d ON d.id=s.device_id
        JOIN models m ON m.id=d.model_id
        LEFT JOIN installations inst ON inst.id=s.installation_id
        LEFT JOIN vehicles v ON v.id=inst.vehicle_id
        WHERE s.planned_date BETWEEN ? AND ?
        AND s.status NOT IN ('anulowany')
    ");
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $svc) {
        $color = match($svc['status']) {
            'zaplanowany' => '#fd7e14',
            'w_trakcie'   => '#dc3545',
            'zakończony'  => '#198754',
            default       => '#6c757d',
        };
        $events[] = [
            'id'    => 's_' . $svc['id'],
            'title' => '🔧 ' . ($svc['registration'] ? $svc['registration'] . ' — ' : '') . $svc['model_name'] . ' (' . ucfirst($svc['type']) . ')',
            'start' => $svc['planned_date'],
            'color' => $color,
            'url'   => 'services.php?action=view&id=' . $svc['id'],
            'extendedProps' => [
                'type'   => 'service',
                'desc'   => $svc['description'],
                'serial' => $svc['serial_number'],
                'status' => $svc['status'],
            ],
        ];
    }

    // Installations — group by (date, client) so multiple installs for same customer show as one event
    $stmt = $db->prepare("
        SELECT i.id, i.installation_date, i.status,
               d.serial_number, m.name as model_name,
               v.registration, v.make,
               c.contact_name, c.company_name
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN vehicles v ON v.id=i.vehicle_id
        LEFT JOIN clients c ON c.id=i.client_id
        WHERE i.installation_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start, $end]);
    $installRows = $stmt->fetchAll();

    // Group by date + client key
    $installGroups = [];
    foreach ($installRows as $inst) {
        $clientKey = $inst['company_name'] ?: ($inst['contact_name'] ?: '—');
        $groupKey  = $inst['installation_date'] . '|' . $clientKey;
        if (!isset($installGroups[$groupKey])) {
            $installGroups[$groupKey] = [
                'date'       => $inst['installation_date'],
                'client'     => $clientKey,
                'count'      => 0,
                'first_id'   => $inst['id'],
                'regs'       => [],
            ];
        }
        $installGroups[$groupKey]['count']++;
        $installGroups[$groupKey]['regs'][] = $inst['registration'];
    }

    foreach ($installGroups as $grp) {
        if ($grp['count'] >= 2) {
            $title = '🚗 Montaż: ' . $grp['client'] . ' (' . $grp['count'] . ' urządzeń)';
        } else {
            $title = '🚗 Montaż: ' . $grp['regs'][0] . ' (' . ($grp['client'] !== '—' ? $grp['client'] : 'brak klienta') . ')';
        }
        $events[] = [
            'id'    => 'ig_' . $grp['first_id'],
            'title' => $title,
            'start' => $grp['date'],
            'color' => '#0d6efd',
            'url'   => $grp['count'] >= 2
                ? 'orders.php?action=list'
                : 'orders.php?action=view&id=' . $grp['first_id'],
            'extendedProps' => ['type' => 'installation'],
        ];
    }

    // Work orders (Zlecenia montażowe) — group by client+date so multiple orders for the same customer appear as one event
    try {
        $woStmt = $db->prepare("
            SELECT wo.id, wo.order_number, wo.date, wo.status,
                   wo.installation_address,
                   wo.client_id,
                   c.contact_name, c.company_name,
                   u.name as technician_name,
                   (SELECT COUNT(*) FROM installations i WHERE i.work_order_id=wo.id) as device_count
            FROM work_orders wo
            LEFT JOIN clients c ON c.id=wo.client_id
            LEFT JOIN users u ON u.id=wo.technician_id
            WHERE wo.date BETWEEN ? AND ?
              AND wo.status NOT IN ('anulowane')
        ");
        $woStmt->execute([$start, $end]);
        $woRows = $woStmt->fetchAll();

        // Group by date + client
        $woGroups = [];
        foreach ($woRows as $wo) {
            $clientKey = $wo['company_name'] ?: ($wo['contact_name'] ?: 'brak klienta');
            $groupKey  = $wo['date'] . '|' . $clientKey;
            if (!isset($woGroups[$groupKey])) {
                $woGroups[$groupKey] = [
                    'date'        => $wo['date'],
                    'client'      => $clientKey,
                    'count'       => 0,
                    'device_total'=> 0,
                    'first_id'    => $wo['id'],
                    'first_number'=> $wo['order_number'],
                    'status'      => $wo['status'],
                    'technician'  => $wo['technician_name'] ?? '',
                    'address'     => $wo['installation_address'] ?? '',
                ];
            }
            $woGroups[$groupKey]['count']++;
            $woGroups[$groupKey]['device_total'] += (int)$wo['device_count'];
        }

        $woStatusColors = ['nowe' => '#0d6efd', 'w_trakcie' => '#fd7e14', 'zakonczone' => '#198754'];
        foreach ($woGroups as $grp) {
            if ($grp['count'] >= 2) {
                $title = '📋 Zlecenia: ' . $grp['client'] . ' (' . $grp['count'] . ' zlecenia';
                $title .= $grp['device_total'] > 0 ? ', ' . $grp['device_total'] . ' urządz.)' : ')';
                $url = 'orders.php?action=list';
            } else {
                $devInfo = $grp['device_total'] > 0 ? ' (' . $grp['device_total'] . ' urządz.)' : '';
                $title = '📋 Zlecenie: ' . $grp['first_number'] . ' — ' . $grp['client'] . $devInfo;
                $url = 'orders.php?action=view&id=' . $grp['first_id'];
            }
            $events[] = [
                'id'    => 'wo_' . $grp['first_id'],
                'title' => $title,
                'start' => $grp['date'],
                'color' => $woStatusColors[$grp['status']] ?? '#198754',
                'url'   => $url,
                'extendedProps' => [
                    'type'       => 'order',
                    'technician' => $grp['technician'],
                    'address'    => $grp['address'],
                ],
            ];
        }
    } catch (PDOException $e) { /* work_orders table may not exist yet */ }

    // Uninstallations
    $stmt = $db->prepare("
        SELECT i.id, i.uninstallation_date, d.serial_number, m.name as model_name, v.registration
        FROM installations i
        JOIN devices d ON d.id=i.device_id
        JOIN models m ON m.id=d.model_id
        JOIN vehicles v ON v.id=i.vehicle_id
        WHERE i.uninstallation_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $inst) {
        $events[] = [
            'id'    => 'u_' . $inst['id'],
            'title' => '📤 Demontaż: ' . $inst['registration'] . ' (' . $inst['model_name'] . ')',
            'start' => $inst['uninstallation_date'],
            'color' => '#6f42c1',
            'url'   => 'orders.php?action=demontaze',
            'extendedProps' => ['type' => 'uninstall'],
        ];
    }

    echo json_encode($events);
    exit;
}

$activePage = 'calendar';
$pageTitle = 'Kalendarz';

// Data for modals
$calAvailableModels = $db->query("
    SELECT m.id as model_id, m.name as model_name, mf.name as manufacturer_name,
           COUNT(d.id) as available_count
    FROM models m
    JOIN manufacturers mf ON mf.id=m.manufacturer_id
    JOIN devices d ON d.model_id=m.id AND d.status IN ('nowy','sprawny')
    GROUP BY m.id HAVING available_count > 0 ORDER BY mf.name, m.name
")->fetchAll();
$calAvailableDevices = $db->query("
    SELECT d.id, d.serial_number, d.imei, d.sim_number, m.name as model_name, mf.name as manufacturer_name
    FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status IN ('nowy','sprawny') ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();
$calClients = $db->query("SELECT id, contact_name, company_name, address, city, postal_code FROM clients WHERE active=1 ORDER BY company_name, contact_name")->fetchAll();
$calUsers   = $db->query("SELECT id, name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$calAllDevices = $db->query("
    SELECT d.id, d.serial_number, m.name as model_name, mf.name as manufacturer_name,
           COALESCE((SELECT i2.client_id FROM installations i2 WHERE i2.device_id=d.id AND i2.status='aktywna' ORDER BY i2.id DESC LIMIT 1), 0) as client_id,
           (SELECT v.registration FROM installations i3 JOIN vehicles v ON v.id=i3.vehicle_id WHERE i3.device_id=d.id AND i3.status='aktywna' ORDER BY i3.id DESC LIMIT 1) as active_registration
    FROM devices d JOIN models m ON m.id=d.model_id JOIN manufacturers mf ON mf.id=m.manufacturer_id
    WHERE d.status != 'wycofany' ORDER BY mf.name, m.name, d.serial_number
")->fetchAll();
$calActiveInstallations = $db->query("
    SELECT i.id, v.registration, d.serial_number
    FROM installations i JOIN vehicles v ON v.id=i.vehicle_id JOIN devices d ON d.id=i.device_id
    WHERE i.status='aktywna' ORDER BY v.registration
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-calendar-alt me-2 text-primary"></i>Kalendarz serwisów i zleceń</h1>
    <div>
        <button type="button" class="btn btn-sm btn-outline-success me-1" data-bs-toggle="modal" data-bs-target="#calNewOrderModal"><i class="fas fa-plus me-1"></i>Nowe zlecenie</button>
        <button type="button" class="btn btn-sm btn-outline-warning me-1" data-bs-toggle="modal" data-bs-target="#calSvcAddModal"><i class="fas fa-plus me-1"></i>Nowy serwis</button>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-9">
        <div class="card">
            <div class="card-body">
                <div id="calendar"></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card mb-3">
            <div class="card-header small fw-bold">Legenda</div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <span class="badge me-2" style="background:#fd7e14">&#9632;</span>Serwis zaplanowany
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="badge me-2" style="background:#dc3545">&#9632;</span>Serwis w trakcie
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="badge me-2" style="background:#198754">&#9632;</span>Serwis zakończony / Zlecenie zakończone
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="badge me-2" style="background:#0d6efd">&#9632;</span>Zlecenie nowe / Montaż
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="badge me-2" style="background:#fd7e14">&#9632;</span>Zlecenie w trakcie
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge me-2" style="background:#6f42c1">&#9632;</span>Demontaż
                </div>
            </div>
        </div>

        <!-- Upcoming services -->
        <div class="card">
            <div class="card-header small fw-bold"><i class="fas fa-clock me-1"></i>Najbliższe serwisy</div>
            <div class="list-group list-group-flush">
                <?php
                $upcoming = $db->query("
                    SELECT s.id, s.planned_date, s.type, d.serial_number, m.name as model_name, v.registration
                    FROM services s
                    JOIN devices d ON d.id=s.device_id
                    JOIN models m ON m.id=d.model_id
                    LEFT JOIN installations inst ON inst.id=s.installation_id
                    LEFT JOIN vehicles v ON v.id=inst.vehicle_id
                    WHERE s.status IN ('zaplanowany','w_trakcie')
                    AND s.planned_date >= CURDATE()
                    ORDER BY s.planned_date ASC
                    LIMIT 8
                ")->fetchAll();
                if (empty($upcoming)):
                ?>
                <div class="list-group-item text-muted small">Brak zaplanowanych serwisów</div>
                <?php else: foreach ($upcoming as $svc): ?>
                <a href="services.php?action=view&id=<?= $svc['id'] ?>" class="list-group-item list-group-item-action py-2 px-3">
                    <div class="d-flex justify-content-between">
                        <small class="fw-bold"><?= h($svc['registration'] ?: $svc['serial_number']) ?></small>
                        <small class="text-muted"><?= formatDate($svc['planned_date']) ?></small>
                    </div>
                    <small class="text-muted"><?= h($svc['model_name']) ?> — <?= ucfirst($svc['type']) ?></small>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'pl',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listMonth'
        },
        height: 'auto',
        events: {
            url: 'calendar.php',
            extraParams: function() {
                return { json: 1 };
            }
        },
        eventClick: function(info) {
            if (info.event.url) {
                info.jsEvent.preventDefault();
                window.location.href = info.event.url;
            }
        },
        eventDidMount: function(info) {
            if (info.event.extendedProps.desc) {
                info.el.title = info.event.extendedProps.desc;
            }
        },
        buttonText: {
            today: 'Dziś',
            month: 'Miesiąc',
            week: 'Tydzień',
            list: 'Lista',
        }
    });
    calendar.render();
});
</script>

<!-- Modal: Nowy serwis (kalendarz) -->
<div class="modal fade" id="calSvcAddModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="services.php" id="calSvcAddForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wrench me-2 text-warning"></i>Nowy serwis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-star">Urządzenie GPS</label>
                            <input type="text" id="calSvcDevSearch" class="form-control form-control-sm mb-1" placeholder="Szukaj urządzenia (nr seryjny, model…)" autocomplete="off">
                            <select name="device_id" id="calSvcDevSelect" class="form-select" required size="4" style="height:auto">
                                <option value="">— wybierz urządzenie —</option>
                                <?php
                                $calSvcGrp = '';
                                foreach ($calAllDevices as $d):
                                    $grp = $d['manufacturer_name'] . ' ' . $d['model_name'];
                                    if ($grp !== $calSvcGrp) { if ($calSvcGrp) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $calSvcGrp = $grp; }
                                ?>
                                <option value="<?= $d['id'] ?>" data-search="<?= h(strtolower($d['serial_number'] . ' ' . $d['model_name'] . ' ' . $d['manufacturer_name'] . ' ' . ($d['active_registration'] ?? ''))) ?>">
                                    <?= h($d['serial_number']) ?> — <?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?><?= $d['active_registration'] ? ' [' . h($d['active_registration']) . ']' : '' ?>
                                </option>
                                <?php endforeach; if ($calSvcGrp) echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Powiązany montaż (aktywny)</label>
                            <select name="installation_id" class="form-select">
                                <option value="">— brak —</option>
                                <?php foreach ($calActiveInstallations as $inst): ?>
                                <option value="<?= $inst['id'] ?>"><?= h($inst['registration'] . ' — ' . $inst['serial_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Typ serwisu</label>
                            <select name="type" class="form-select">
                                <option value="przeglad" selected>Przegląd</option>
                                <option value="naprawa">Naprawa</option>
                                <option value="wymiana">Wymiana</option>
                                <option value="aktualizacja">Aktualizacja firmware</option>
                                <option value="inne">Inne</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="zaplanowany" selected>Zaplanowany</option>
                                <option value="w_trakcie">W trakcie</option>
                                <option value="zakończony">Zakończony</option>
                                <option value="anulowany">Anulowany</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data zaplanowana</label>
                            <input type="date" name="planned_date" id="calSvcPlannedDate" class="form-control" required>
                        </div>
                        <?php if (!empty($calUsers)): ?>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($calUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12"><label class="form-label">Opis / Problem</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-warning btn-sm text-white"><i class="fas fa-save me-1"></i>Zarejestruj serwis</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Modal: Nowe zlecenie (Kalendarz) -->
<div class="modal fade" id="calNewOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2 text-success"></i>Nowe zlecenie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="calNewOrderErr" class="alert alert-danger d-none"></div>
                <form id="calNewOrderForm">
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
                                <?php foreach ($calUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == getCurrentUser()['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="calOrderClientSelect" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($calClients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"
                                            data-address="<?= h(trim(($cl['address'] ?? '') . ' ' . ($cl['city'] ?? ''))) ?>">
                                        <?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="calOrderQuickClientBtn" title="Dodaj nowego klienta"><i class="fas fa-user-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Adres miejsca instalacji</label>
                            <input type="text" name="installation_address" id="calOrderAddressField" class="form-control" placeholder="Automatycznie z danych klienta lub wpisz ręcznie">
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
                <button type="button" class="btn btn-success" id="calNewOrderSaveBtn"><i class="fas fa-save me-2"></i>Utwórz zlecenie</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick-add klienta (Kalendarz - Nowe zlecenie) -->
<div class="modal fade" id="calOrderQuickClientModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6><button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="calOrdQCErr"></div>
                <div class="mb-2"><label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label><input type="text" id="calOrdQCName" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Nazwa firmy</label><input type="text" id="calOrdQCCompany" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Telefon</label><input type="text" id="calOrdQCPhone" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">E-mail</label><input type="email" id="calOrdQCEmail" class="form-control form-control-sm"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="calOrdQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // Service modal search
    var svcSearch = document.getElementById('calSvcDevSearch');
    var calSvcModal = document.getElementById('calSvcAddModal');
    if (calSvcModal) {
        calSvcModal.addEventListener('show.bs.modal', function () {
            if (svcSearch) svcSearch.value = '';
            var sel = document.getElementById('calSvcDevSelect'); if (sel) { sel.value = ''; document.querySelectorAll('#calSvcDevSelect option').forEach(function(o){o.style.display='';}); }
            var dt = document.getElementById('calSvcPlannedDate'); if (dt) dt.value = new Date().toISOString().slice(0, 10);
        });
    }
    if (svcSearch) svcSearch.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('#calSvcDevSelect option').forEach(function (o) {
            if (!o.value) { o.style.display = ''; return; }
            o.style.display = (!q || (o.dataset.search||'').includes(q)) ? '' : 'none';
        });
    });

    // New order modal
    var calOrderClientSel = document.getElementById('calOrderClientSelect');
    var calOrderAddrFld   = document.getElementById('calOrderAddressField');
    if (calOrderClientSel) {
        calOrderClientSel.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            var addr = opt ? (opt.getAttribute('data-address') || '') : '';
            if (calOrderAddrFld && addr && !calOrderAddrFld.dataset.manuallyEdited) { calOrderAddrFld.value = addr; }
        });
    }
    if (calOrderAddrFld) {
        calOrderAddrFld.addEventListener('input', function () { this.dataset.manuallyEdited = '1'; });
    }

    var qcOrderBtn = document.getElementById('calOrderQuickClientBtn');
    if (qcOrderBtn) qcOrderBtn.addEventListener('click', function () {
        new bootstrap.Modal(document.getElementById('calOrderQuickClientModal')).show();
    });
    var qcOrderSave = document.getElementById('calOrdQCSaveBtn');
    if (qcOrderSave) qcOrderSave.addEventListener('click', function () {
        var name = (document.getElementById('calOrdQCName').value || '').trim();
        var company = document.getElementById('calOrdQCCompany').value.trim();
        var phone = document.getElementById('calOrdQCPhone').value.trim();
        var email = document.getElementById('calOrdQCEmail').value.trim();
        var errEl = document.getElementById('calOrdQCErr');
        if (!name) { errEl.textContent = 'Imię i nazwisko jest wymagane.'; errEl.classList.remove('d-none'); return; }
        errEl.classList.add('d-none');
        var fd = new FormData();
        fd.append('action', 'quick_add_client'); fd.append('contact_name', name);
        fd.append('company_name', company); fd.append('phone', phone); fd.append('email', email);
        fd.append('csrf_token', document.querySelector('#calNewOrderForm [name=csrf_token]').value);
        fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function (data) {
            if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
            var sel = document.getElementById('calOrderClientSelect');
            var opt = new Option(data.label, data.id, true, true);
            sel.add(opt); sel.dispatchEvent(new Event('change'));
            bootstrap.Modal.getInstance(document.getElementById('calOrderQuickClientModal')).hide();
            ['calOrdQCName','calOrdQCCompany','calOrdQCPhone','calOrdQCEmail'].forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; });
        }).catch(function () { errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none'); });
    });

    var saveOrderBtn = document.getElementById('calNewOrderSaveBtn');
    if (saveOrderBtn) saveOrderBtn.addEventListener('click', function () {
        var btn = this;
        var form = document.getElementById('calNewOrderForm');
        var errEl = document.getElementById('calNewOrderErr');
        var date = form.querySelector('[name=date]').value;
        var tech = form.querySelector('[name=technician_id]').value;
        if (!date) { errEl.textContent = 'Data zlecenia jest wymagana.'; errEl.classList.remove('d-none'); return; }
        if (!tech)  { errEl.textContent = 'Wybierz technika.'; errEl.classList.remove('d-none'); return; }
        errEl.classList.add('d-none');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zapisywanie...';
        var fd = new FormData(form);
        fetch('orders.php', { method: 'POST', body: fd }).then(r => r.json()).then(function (data) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-2"></i>Utwórz zlecenie';
            if (data.error) { errEl.textContent = data.error; errEl.classList.remove('d-none'); return; }
            if (data.redirect) { window.location.href = data.redirect; }
        }).catch(function () {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-2"></i>Utwórz zlecenie';
            errEl.textContent = 'Błąd połączenia.'; errEl.classList.remove('d-none');
        });
    });

    var calNOM = document.getElementById('calNewOrderModal');
    if (calNOM) calNOM.addEventListener('hidden.bs.modal', function () {
        document.getElementById('calNewOrderForm').reset();
        document.getElementById('calNewOrderErr').classList.add('d-none');
        if (calOrderAddrFld) calOrderAddrFld.dataset.manuallyEdited = '';
        var di = document.querySelector('#calNewOrderForm [name=date]');
        if (di) di.value = new Date().toISOString().split('T')[0];
    });
}());
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
