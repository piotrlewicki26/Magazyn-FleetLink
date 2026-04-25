<?php
/**
 * FleetLink Magazyn - Calendar View
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
                ? 'installations.php?search=' . urlencode($grp['client'])
                : 'installations.php?action=view&id=' . $grp['first_id'],
            'extendedProps' => ['type' => 'installation'],
        ];
    }

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
            'url'   => 'installations.php?action=view&id=' . $inst['id'],
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
    SELECT d.id, d.serial_number, m.name as model_name, mf.name as manufacturer_name
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
    <h1><i class="fas fa-calendar-alt me-2 text-primary"></i>Kalendarz serwisów i montaży</h1>
    <div>
        <button type="button" class="btn btn-sm btn-outline-warning me-1" data-bs-toggle="modal" data-bs-target="#calSvcAddModal"><i class="fas fa-plus me-1"></i>Nowy serwis</button>
        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#calInstAddModal"><i class="fas fa-plus me-1"></i>Nowy montaż</button>
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
                    <span class="badge me-2" style="background:#198754">&#9632;</span>Serwis zakończony
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="badge me-2" style="background:#0d6efd">&#9632;</span>Montaż
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
                                <option value="<?= $d['id'] ?>" data-search="<?= h(strtolower($d['serial_number'] . ' ' . $d['model_name'] . ' ' . $d['manufacturer_name'])) ?>">
                                    <?= h($d['serial_number']) ?> — <?= h($d['manufacturer_name'] . ' ' . $d['model_name']) ?>
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

<!-- Modal: Nowy montaż (kalendarz) — wielourządzeniowy -->
<div class="modal fade" id="calInstAddModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="installations.php" id="calInstAddForm">
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
                            <div id="calInstDevRowsContainer" class="d-flex flex-column gap-2 mb-2">
                                <div class="device-row border rounded p-2 bg-light" data-row-idx="0">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-auto"><span class="row-num badge bg-secondary">1</span></div>
                                        <div class="col-auto">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="clm_auto_0" value="auto" checked>
                                                <label class="btn btn-outline-secondary" for="clm_auto_0"><i class="fas fa-magic me-1"></i>Auto</label>
                                                <input type="radio" class="btn-check" name="device_mode[0]" id="clm_manual_0" value="manual">
                                                <label class="btn btn-outline-primary" for="clm_manual_0"><i class="fas fa-hand-pointer me-1"></i>Ręczny</label>
                                            </div>
                                        </div>
                                        <div class="col col-mode-auto">
                                            <select name="model_id[0]" class="form-select form-select-sm">
                                                <option value="">— wybierz model —</option>
                                                <?php foreach ($calAvailableModels as $m): ?>
                                                <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col col-mode-manual" style="display:none">
                                            <select name="device_id_manual[0]" class="form-select form-select-sm ts-device-cal">
                                                <option value="">— wybierz urządzenie —</option>
                                                <?php
                                                $clGrp0 = '';
                                                foreach ($calAvailableDevices as $dev):
                                                    $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                                                    if ($grp !== $clGrp0) { if ($clGrp0) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $clGrp0 = $grp; }
                                                ?>
                                                <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' ['.h($dev['imei']).']' : '' ?><?= $dev['sim_number'] ? ' ('.h($dev['sim_number']).')' : '' ?></option>
                                                <?php endforeach; if ($clGrp0) echo '</optgroup>'; ?>
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <input type="text" name="vehicle_registration[0]" class="form-control form-control-sm" required placeholder="Nr rej. pojazdu" style="text-transform:uppercase;min-width:130px">
                                        </div>
                                        <div class="col-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" style="display:none" title="Usuń"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="calInstAddRowBtn" class="btn btn-sm btn-outline-success"><i class="fas fa-plus me-1"></i>Dodaj kolejne urządzenie</button>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Klient</label>
                            <div class="input-group">
                                <select name="client_id" id="calInstClientSel" class="form-select">
                                    <option value="">— brak przypisania —</option>
                                    <?php foreach ($calClients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= h(($cl['company_name'] ? $cl['company_name'] . ' — ' : '') . $cl['contact_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-success" id="calInstQCBtn" title="Dodaj nowego klienta"><i class="fas fa-user-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adres instalacji</label>
                            <input type="text" name="installation_address" id="calInstAddrFld" class="form-control" placeholder="Automatycznie z klienta lub wpisz ręcznie">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technik</label>
                            <select name="technician_id" class="form-select">
                                <option value="">— aktualny użytkownik —</option>
                                <?php foreach ($calUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-star">Data montażu</label>
                            <input type="date" name="installation_date" id="calInstDateFld" class="form-control" required value="<?= date('Y-m-d') ?>">
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
                        <div class="col-12"><label class="form-label">Uwagi</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-car me-1"></i>Zarejestruj montaż</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick-add klienta (kalendarz) -->
<div class="modal fade" id="calInstQCModal" tabindex="-1" style="z-index:1090">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-user-plus me-2"></i>Szybko dodaj klienta</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-danger small d-none" id="calInstQCErr"></div>
                <div class="mb-2"><label class="form-label form-label-sm required-star">Imię i nazwisko kontaktu</label><input type="text" id="calInstQCName" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Nazwa firmy</label><input type="text" id="calInstQCCompany" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">Telefon</label><input type="text" id="calInstQCPhone" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label form-label-sm">E-mail</label><input type="email" id="calInstQCEmail" class="form-control form-control-sm"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success btn-sm" id="calInstQCSaveBtn"><i class="fas fa-save me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<template id="calInstDevRowTemplate">
    <div class="device-row border rounded p-2 bg-light" data-row-idx="__IDX__">
        <div class="row g-2 align-items-center">
            <div class="col-auto"><span class="row-num badge bg-secondary">__NUM__</span></div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="clm_auto___IDX__" value="auto" checked>
                    <label class="btn btn-outline-secondary" for="clm_auto___IDX__"><i class="fas fa-magic me-1"></i>Auto</label>
                    <input type="radio" class="btn-check" name="device_mode[__IDX__]" id="clm_manual___IDX__" value="manual">
                    <label class="btn btn-outline-primary" for="clm_manual___IDX__"><i class="fas fa-hand-pointer me-1"></i>Ręczny</label>
                </div>
            </div>
            <div class="col col-mode-auto">
                <select name="model_id[__IDX__]" class="form-select form-select-sm">
                    <option value="">— wybierz model —</option>
                    <?php foreach ($calAvailableModels as $m): ?>
                    <option value="<?= $m['model_id'] ?>"><?= h($m['manufacturer_name'] . ' ' . $m['model_name']) ?> (<?= (int)$m['available_count'] ?> dostępnych)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col col-mode-manual" style="display:none">
                <select name="device_id_manual[__IDX__]" class="form-select form-select-sm ts-device-cal">
                    <option value="">— wybierz urządzenie —</option>
                    <?php
                    $clTplGrp = '';
                    foreach ($calAvailableDevices as $dev):
                        $grp = $dev['manufacturer_name'] . ' ' . $dev['model_name'];
                        if ($grp !== $clTplGrp) { if ($clTplGrp) echo '</optgroup>'; echo '<optgroup label="' . h($grp) . '">'; $clTplGrp = $grp; }
                    ?>
                    <option value="<?= $dev['id'] ?>"><?= h($dev['serial_number']) ?><?= $dev['imei'] ? ' ['.h($dev['imei']).']' : '' ?><?= $dev['sim_number'] ? ' ('.h($dev['sim_number']).')' : '' ?></option>
                    <?php endforeach; if ($clTplGrp) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-auto">
                <input type="text" name="vehicle_registration[__IDX__]" class="form-control form-control-sm" required placeholder="Nr rej. pojazdu" style="text-transform:uppercase;min-width:130px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" title="Usuń"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>
</template>

<script>
window.flCalDevices = <?= json_encode(array_values(array_map(function($d) {
    $t = $d['serial_number'];
    if ($d['imei'])       $t .= ' [' . $d['imei'] . ']';
    if ($d['sim_number']) $t .= ' (' . $d['sim_number'] . ')';
    return ['value' => (string)$d['id'], 'text' => $t];
}, $calAvailableDevices))) ?>;
window.flCalClientAddresses = <?= json_encode(array_reduce($calClients, function($c, $cl) {
    $parts = array_filter([$cl['address'] ?? '', trim(($cl['postal_code'] ?? '') . ' ' . ($cl['city'] ?? ''))]);
    $c[(string)$cl['id']] = implode(', ', $parts);
    return $c;
}, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
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

    // Install modal multi-device
    var container  = document.getElementById('calInstDevRowsContainer');
    var addBtn     = document.getElementById('calInstAddRowBtn');
    var rowCounter = 1;
    if (!container || !addBtn) return;

    function clSyncDropdowns() {
        var rows = Array.from(container.querySelectorAll('.device-row'));
        var rowVals = new Map();
        rows.forEach(function (row) { var sel = row.querySelector('select.ts-device-cal'); if (!sel || !sel.tomselect) return; rowVals.set(row, sel.tomselect.getValue() || ''); });
        rows.forEach(function (row) {
            var sel = row.querySelector('select.ts-device-cal'); if (!sel || !sel.tomselect) return;
            var ts = sel.tomselect, myVal = rowVals.get(row) || '';
            var taken = new Set(); rowVals.forEach(function (v, r) { if (r !== row && v) taken.add(v); });
            (window.flCalDevices || []).forEach(function (dev) {
                if (taken.has(dev.value)) { if (ts.options[dev.value]) ts.removeOption(dev.value); }
                else { if (!ts.options[dev.value]) ts.addOption({value:dev.value,text:dev.text}); }
            });
            ts.refreshOptions(false); if (myVal && ts.options[myVal]) ts.setValue(myVal, true);
        });
    }
    function clInitTS(row) { row.querySelectorAll('select.ts-device-cal').forEach(function(sel){ if(sel.tomselect||typeof TomSelect==='undefined') return; new TomSelect(sel,{placeholder:'— szukaj —',allowEmptyOption:true,maxOptions:null,searchField:['text','value']}); }); }
    function clDestroyTS(row) { row.querySelectorAll('select.ts-device-cal').forEach(function(sel){ if(sel.tomselect) sel.tomselect.destroy(); }); }
    function clApplyMode(row, mode) {
        var ac = row.querySelector('.col-mode-auto'), mc = row.querySelector('.col-mode-manual');
        if (ac) ac.style.display = mode==='auto' ? '' : 'none';
        if (mc) mc.style.display = mode==='manual' ? '' : 'none';
        if (mode==='manual') { clInitTS(row); clSyncDropdowns(); }
    }
    function clUpdateNums() {
        var rows = container.querySelectorAll('.device-row');
        rows.forEach(function(row,i){ var n=row.querySelector('.row-num'); if(n) n.textContent=i+1; var b=row.querySelector('.remove-row-btn'); if(b) b.style.display=rows.length>1?'':'none'; });
    }
    container.addEventListener('change', function(e) {
        if (e.target.type==='radio' && (e.target.name||'').startsWith('device_mode')) clApplyMode(e.target.closest('.device-row'),e.target.value);
        if (e.target.classList.contains('ts-device-cal')||e.target.closest('select.ts-device-cal')) clSyncDropdowns();
    });
    container.addEventListener('click', function(e) {
        var btn = e.target.closest('.remove-row-btn');
        if (btn) { var row=btn.closest('.device-row'); clDestroyTS(row); row.remove(); clUpdateNums(); clSyncDropdowns(); }
    });
    addBtn.addEventListener('click', function () {
        var tpl = document.getElementById('calInstDevRowTemplate'); if (!tpl) return;
        var idx = rowCounter++, clone = tpl.content.cloneNode(true);
        clone.querySelectorAll('[name]').forEach(function(el){el.name=el.name.replace(/__IDX__/g,idx);});
        clone.querySelectorAll('[id]').forEach(function(el){el.id=el.id.replace(/__IDX__/g,idx);});
        clone.querySelectorAll('[for]').forEach(function(el){el.htmlFor=el.htmlFor.replace(/__IDX__/g,idx);});
        container.appendChild(clone); clUpdateNums();
    });
    var instModal = document.getElementById('calInstAddModal');
    if (instModal) {
        instModal.addEventListener('show.bs.modal', function () {
            Array.from(container.querySelectorAll('.device-row')).forEach(function(row,i){ if(i>0){clDestroyTS(row);row.remove();} });
            rowCounter=1;
            var fr = container.querySelector('.device-row');
            if (fr) { var reg=fr.querySelector('input[name="vehicle_registration[0]"]'); if(reg) reg.value=''; var ar=fr.querySelector('input[value="auto"]'); if(ar){ar.checked=true;clApplyMode(fr,'auto');} var ms=fr.querySelector('select[name="model_id[0]"]'); if(ms) ms.value=''; clDestroyTS(fr); }
            document.getElementById('calInstClientSel').value='';
            document.getElementById('calInstAddrFld').value='';
            document.getElementById('calInstDateFld').value=new Date().toISOString().slice(0,10);
            clUpdateNums();
        });
    }
    var cliSel = document.getElementById('calInstClientSel');
    if (cliSel) cliSel.addEventListener('change', function() {
        var v=this.value, addr=document.getElementById('calInstAddrFld');
        if(addr) addr.value=(v&&window.flCalClientAddresses&&window.flCalClientAddresses[v])?window.flCalClientAddresses[v]:'';
    });
    var qcBtn = document.getElementById('calInstQCBtn');
    if (qcBtn) qcBtn.addEventListener('click', function() {
        ['calInstQCName','calInstQCCompany','calInstQCPhone','calInstQCEmail'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
        var err=document.getElementById('calInstQCErr'); if(err) err.classList.add('d-none');
        new bootstrap.Modal(document.getElementById('calInstQCModal')).show();
    });
    var qcSave = document.getElementById('calInstQCSaveBtn');
    if (qcSave) qcSave.addEventListener('click', function() {
        var name=(document.getElementById('calInstQCName').value||'').trim();
        var errEl=document.getElementById('calInstQCErr');
        if (!name){errEl.textContent='Imię i nazwisko kontaktu jest wymagane.';errEl.classList.remove('d-none');return;}
        errEl.classList.add('d-none');
        var fd=new FormData(); fd.append('action','quick_add_client'); fd.append('csrf_token',document.querySelector('#calInstAddForm input[name="csrf_token"]').value); fd.append('contact_name',name); fd.append('company_name',document.getElementById('calInstQCCompany').value); fd.append('phone',document.getElementById('calInstQCPhone').value); fd.append('email',document.getElementById('calInstQCEmail').value);
        fetch('installations.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if(data.error){errEl.textContent=data.error;errEl.classList.remove('d-none');return;}
            var sel=document.getElementById('calInstClientSel'); var opt=document.createElement('option'); opt.value=data.id; opt.textContent=data.label; opt.selected=true; sel.appendChild(opt); sel.dispatchEvent(new Event('change'));
            bootstrap.Modal.getInstance(document.getElementById('calInstQCModal')).hide();
        }).catch(function(){errEl.textContent='Błąd połączenia z serwerem.';errEl.classList.remove('d-none');});
    });
    container.querySelectorAll('.device-row').forEach(function(row){var c=row.querySelector('.btn-check:checked');if(c)clApplyMode(row,c.value);});
    clUpdateNums();
}());
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
