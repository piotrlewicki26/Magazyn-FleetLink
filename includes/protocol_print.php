<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Protokół <?= h($protocol['protocol_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #222; }
        .doc-header { display: flex; justify-content: space-between; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #0d6efd; }
        .company h2 { color: #0d6efd; font-size: 16px; }
        .meta h1 { font-size: 18px; }
        .meta p { font-size: 11px; color: #555; }
        .section { margin-bottom: 15px; border: 1px solid #dee2e6; border-radius: 4px; }
        .section-header { background: #f8f9fa; padding: 7px 12px; font-weight: bold; border-bottom: 1px solid #dee2e6; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        .section-body { padding: 10px 12px; }
        table.info { width: 100%; }
        table.info td { padding: 4px 0; }
        table.info td:first-child { color: #777; width: 40%; }
        .signatures { display: flex; justify-content: space-around; margin-top: 40px; }
        .sig-box { text-align: center; width: 200px; }
        .sig-line { border-top: 1px solid #333; padding-top: 5px; margin-top: 30px; }
        .footer-doc { margin-top: 20px; font-size: 9px; color: #999; text-align: center; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div style="padding:25px 30px; max-width:800px; margin:0 auto;">
    <div class="no-print" style="margin-bottom:15px; display:flex; gap:10px;">
        <button onclick="window.print()" style="padding:8px 16px; background:#0d6efd; color:white; border:none; border-radius:4px; cursor:pointer;">🖨️ Drukuj / PDF</button>
        <a href="protocols.php?action=view&id=<?= $protocol['id'] ?>" style="padding:8px 16px; background:#6c757d; color:white; text-decoration:none; border-radius:4px;">← Powrót</a>
    </div>

    <div class="doc-header">
        <div class="company">
            <h2><?= h($settings['company_name'] ?? 'Twoja Firma') ?></h2>
            <?php if ($settings['company_address'] ?? ''): ?><p><?= h($settings['company_address']) ?></p><?php endif; ?>
            <?php if ($settings['company_phone'] ?? ''): ?><p>Tel: <?= h($settings['company_phone']) ?></p><?php endif; ?>
        </div>
        <div class="meta" style="text-align:right">
            <?php
            $titles = ['PP' => 'PROTOKÓŁ PRZEKAZANIA', 'PU' => 'PROTOKÓŁ URUCHOMIENIA', 'PS' => 'PROTOKÓŁ SERWISOWY'];
            ?>
            <h1><?= $titles[$protocol['type']] ?? 'PROTOKÓŁ' ?></h1>
            <p><strong>Nr: <?= h($protocol['protocol_number']) ?></strong></p>
            <p>Data: <?= formatDate($protocol['date']) ?></p>
        </div>
    </div>

    <?php if ($protocol['registration']): ?>
    <div class="section">
        <div class="section-header">Dane pojazdu</div>
        <div class="section-body">
            <table class="info">
                <tr><td>Nr rejestracyjny:</td><td><strong><?= h($protocol['registration']) ?></strong></td></tr>
                <tr><td>Marka / Model:</td><td><?= h($protocol['make'] . ' ' . $protocol['vehicle_model']) ?></td></tr>
                <?php if ($protocol['vin']): ?><tr><td>VIN:</td><td><?= h($protocol['vin']) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($protocol['serial_number']): ?>
    <div class="section">
        <div class="section-header">Dane urządzenia GPS</div>
        <div class="section-body">
            <table class="info">
                <tr><td>Producent / Model:</td><td><strong><?= h($protocol['manufacturer_name'] . ' ' . $protocol['model_name']) ?></strong></td></tr>
                <tr><td>Nr seryjny:</td><td><?= h($protocol['serial_number']) ?></td></tr>
                <?php if ($protocol['imei']): ?><tr><td>IMEI:</td><td><?= h($protocol['imei']) ?></td></tr><?php endif; ?>
                <?php if ($protocol['location_in_vehicle']): ?><tr><td>Miejsce montażu:</td><td><?= h($protocol['location_in_vehicle']) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($protocol['contact_name']): ?>
    <div class="section">
        <div class="section-header">Dane klienta</div>
        <div class="section-body">
            <table class="info">
                <tr><td>Firma:</td><td><strong><?= h($protocol['company_name'] ?: $protocol['contact_name']) ?></strong></td></tr>
                <?php if ($protocol['company_name']): ?><tr><td>Kontakt:</td><td><?= h($protocol['contact_name']) ?></td></tr><?php endif; ?>
                <?php if ($protocol['nip']): ?><tr><td>NIP:</td><td><?= h($protocol['nip']) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($protocol['notes']): ?>
    <div class="section">
        <div class="section-header">Zakres prac / Uwagi</div>
        <div class="section-body" style="white-space:pre-wrap"><?= h($protocol['notes']) ?></div>
    </div>
    <?php endif; ?>

    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">
                <p><strong><?= h($protocol['technician_name'] ?? '') ?></strong></p>
                <p>Technik FleetLink</p>
            </div>
        </div>
        <div class="sig-box">
            <div class="sig-line">
                <?php if ($protocol['client_signature']): ?>
                <p><strong><?= h($protocol['client_signature']) ?></strong></p>
                <?php else: ?><p>&nbsp;</p><?php endif; ?>
                <p>Podpis klienta / odbiorcy</p>
            </div>
        </div>
    </div>

    <div class="footer-doc">
        Wygenerowano przez FleetLink Magazyn <?= date('d.m.Y H:i') ?>
    </div>
</div>
</body>
</html>
