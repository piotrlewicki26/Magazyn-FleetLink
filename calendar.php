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
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-calendar-alt me-2 text-primary"></i>Kalendarz serwisów i montaży</h1>
    <div>
        <a href="services.php?action=add" class="btn btn-sm btn-outline-warning me-1"><i class="fas fa-plus me-1"></i>Nowy serwis</a>
        <a href="installations.php?action=add" class="btn btn-sm btn-outline-success"><i class="fas fa-plus me-1"></i>Nowy montaż</a>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
