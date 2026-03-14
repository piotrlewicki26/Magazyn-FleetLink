<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Protokół <?= h($protocol['protocol_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1a1a2e; background: #fff; }
        .pp-doc { max-width: 860px; margin: 0 auto; padding: 28px 32px; }
        /* Header */
        .pp-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 16px; margin-bottom: 20px; border-bottom: 3px solid #2563eb; }
        .pp-company-name { font-size: 1.35rem; font-weight: 800; color: #1a1a2e; letter-spacing: -0.5px; }
        .pp-company-name span { color: #2563eb; }
        .pp-company-sub { font-size: 0.78rem; color: #666; margin-top: 3px; line-height: 1.6; }
        .pp-title { font-size: 1.15rem; font-weight: 700; color: #2563eb; letter-spacing: 1px; text-transform: uppercase; text-align: right; }
        .pp-meta { font-size: 0.8rem; color: #555; margin-top: 4px; text-align: right; line-height: 1.7; }
        /* Info grid */
        .pp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
        .pp-box { background: #f8faff; border: 1px solid #dce8ff; border-radius: 7px; padding: 11px 14px; }
        .pp-box .lbl { font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.9px; color: #2563eb; margin-bottom: 4px; }
        .pp-box .val { font-size: 0.88rem; font-weight: 600; color: #1a1a2e; }
        .pp-box .sub { font-size: 0.75rem; color: #666; margin-top: 3px; line-height: 1.5; }
        /* Section */
        .pp-section-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #2563eb; margin: 18px 0 7px; display: flex; align-items: center; gap: 6px; }
        .pp-section-label::after { content: ''; flex: 1; height: 1px; background: #dce8ff; }
        /* Detail table */
        .pp-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.83rem; }
        .pp-table th { text-align: left; padding: 7px 10px; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: #888; border-bottom: 1px solid #dce8ff; width: 38%; font-weight: 700; }
        .pp-table td { padding: 7px 10px; border-bottom: 1px solid #f0f4ff; color: #1a1a2e; }
        /* Notes block */
        .pp-notes { font-size: 0.85rem; color: #333; padding: 10px 14px; background: #f8faff; border-radius: 6px; border: 1px solid #dce8ff; white-space: pre-wrap; line-height: 1.6; }
        /* Signatures */
        .pp-sig-row { display: flex; gap: 40px; margin-top: 48px; }
        .pp-sig-box { flex: 1; text-align: center; }
        .pp-sig-line { border-top: 2px solid #1a1a2e; padding-top: 6px; margin-top: 56px; font-size: 0.76rem; color: #444; line-height: 1.6; }
        /* Footer */
        .pp-footer { text-align: center; font-size: 0.69rem; color: #aaa; margin-top: 28px; padding-top: 12px; border-top: 1px solid #dce8ff; }
        /* No-print controls */
        .no-print-bar { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; margin-bottom: 20px; background: #f8faff; border: 1px solid #dce8ff; border-radius: 8px; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; font-size: 11px; }
            .pp-doc { padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>
<div class="pp-doc">
    <!-- Print controls (hidden on actual print) -->
    <div class="no-print-bar no-print" style="margin-bottom:20px">
        <span style="font-weight:600;color:#2563eb"><i class="fas fa-file-alt" style="margin-right:6px"></i>Podgląd wydruku — <?= h($protocol['protocol_number']) ?></span>
        <div style="display:flex;gap:8px">
            <button onclick="window.print()" style="padding:7px 18px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px">🖨️ Drukuj / PDF</button>
            <a href="protocols.php?action=view&id=<?= $protocol['id'] ?>" style="padding:7px 18px;background:#6c757d;color:#fff;text-decoration:none;border-radius:6px;font-size:13px">← Powrót</a>
        </div>
    </div>

    <!-- Document header -->
    <div class="pp-header">
        <div>
            <?php if ($settings['company_name'] ?? ''): ?>
            <div class="pp-company-name"><?= h($settings['company_name']) ?></div>
            <div class="pp-company-sub">
                <?php if ($settings['company_address'] ?? ''): ?><?= h($settings['company_address']) ?><br><?php endif; ?>
                <?php if ($settings['company_phone'] ?? ''): ?>Tel: <?= h($settings['company_phone']) ?><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="pp-company-name">Fleet<span>Link</span></div>
            <div class="pp-company-sub">System zarządzania urządzeniami GPS</div>
            <?php endif; ?>
        </div>
        <div>
            <?php
            $titles = ['PP' => 'Protokół przekazania', 'PU' => 'Protokół uruchomienia', 'PS' => 'Protokół serwisowy'];
            ?>
            <div class="pp-title"><?= $titles[$protocol['type']] ?? 'Protokół' ?></div>
            <div class="pp-meta">
                Nr: <strong><?= h($protocol['protocol_number']) ?></strong><br>
                Data: <strong><?= formatDate($protocol['date']) ?></strong><br>
                Technik: <strong><?= h($protocol['technician_name'] ?? '—') ?></strong>
            </div>
        </div>
    </div>

    <!-- Info boxes -->
    <div class="pp-grid">
        <?php if ($protocol['registration'] || $protocol['contact_name']): ?>
        <div class="pp-box">
            <div class="lbl">Klient</div>
            <div class="val"><?= h($protocol['company_name'] ?: ($protocol['contact_name'] ?: '—')) ?></div>
            <?php if ($protocol['company_name'] && $protocol['contact_name']): ?>
            <div class="sub"><?= h($protocol['contact_name']) ?></div>
            <?php endif; ?>
            <?php if ($protocol['nip'] ?? ''): ?><div class="sub">NIP: <?= h($protocol['nip']) ?></div><?php endif; ?>
        </div>
        <div class="pp-box">
            <div class="lbl">Pojazd</div>
            <div class="val"><?= h($protocol['registration'] ?? '—') ?></div>
            <?php if (($protocol['make'] ?? '') || ($protocol['vehicle_model'] ?? '')): ?>
            <div class="sub"><?= h(trim($protocol['make'] . ' ' . ($protocol['vehicle_model'] ?? ''))) ?></div>
            <?php endif; ?>
            <?php if ($protocol['vin'] ?? ''): ?><div class="sub">VIN: <?= h($protocol['vin']) ?></div><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="pp-box">
            <div class="lbl">Typ protokołu</div>
            <div class="val"><?= $titles[$protocol['type']] ?? h($protocol['type']) ?></div>
        </div>
        <div class="pp-box">
            <div class="lbl">Data</div>
            <div class="val"><?= formatDate($protocol['date']) ?></div>
        </div>
        <?php endif; ?>
        <div class="pp-box">
            <div class="lbl">Technik</div>
            <div class="val"><?= h($protocol['technician_name'] ?? '—') ?></div>
            <div class="sub">Data: <?= formatDate($protocol['date']) ?></div>
        </div>
    </div>

    <!-- Device section -->
    <?php if ($protocol['serial_number'] || $protocol['model_name']): ?>
    <div class="pp-section-label">Urządzenie GPS</div>
    <table class="pp-table">
        <?php if ($protocol['manufacturer_name'] || $protocol['model_name']): ?>
        <tr><th>Producent / Model</th><td><strong><?= h(trim(($protocol['manufacturer_name'] ?? '') . ' ' . ($protocol['model_name'] ?? ''))) ?></strong></td></tr>
        <?php endif; ?>
        <?php if ($protocol['serial_number']): ?>
        <tr><th>Nr seryjny</th><td><?= h($protocol['serial_number']) ?></td></tr>
        <?php endif; ?>
        <?php if ($protocol['imei'] ?? ''): ?>
        <tr><th>IMEI</th><td><?= h($protocol['imei']) ?></td></tr>
        <?php endif; ?>
        <?php if ($protocol['location_in_vehicle'] ?? ''): ?>
        <tr><th>Miejsce montażu</th><td><?= h($protocol['location_in_vehicle']) ?></td></tr>
        <?php endif; ?>
        <?php if ($protocol['installation_date'] ?? ''): ?>
        <tr><th>Data instalacji</th><td><?= formatDate($protocol['installation_date']) ?></td></tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <!-- Notes / Work scope -->
    <?php if ($protocol['notes']): ?>
    <div class="pp-section-label">Zakres prac / Uwagi</div>
    <div class="pp-notes"><?= h($protocol['notes']) ?></div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="pp-sig-row">
        <div class="pp-sig-box">
            <div class="pp-sig-line">
                <strong><?= h($protocol['technician_name'] ?? '') ?></strong><br>
                Podpis technika
            </div>
        </div>
        <div class="pp-sig-box">
            <div class="pp-sig-line">
                <?php if ($protocol['client_signature']): ?>
                <strong><?= h($protocol['client_signature']) ?></strong><br>
                <?php else: ?><br><?php endif; ?>
                Podpis klienta / odbiorcy
            </div>
        </div>
    </div>

    <div class="pp-footer">
        Wygenerowano przez <?= h($settings['company_name'] ?? 'FleetLink Magazyn') ?> &mdash; <?= date('d.m.Y H:i') ?>
    </div>
</div>
</body>
</html>
