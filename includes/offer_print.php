<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Oferta <?= h($offer['offer_number']) ?> — <?= h($settings['company_name'] ?? 'FleetLink') ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0d6efd; }
        .company-info h2 { color: #0d6efd; font-size: 18px; }
        .company-info p { margin: 2px 0; font-size: 11px; color: #555; }
        .doc-meta { text-align: right; }
        .doc-meta h1 { font-size: 22px; color: #333; margin-bottom: 5px; }
        .doc-meta p { font-size: 11px; color: #555; }
        .parties { display: flex; gap: 30px; margin-bottom: 20px; }
        .party { flex: 1; background: #f8f9fa; padding: 12px; border-radius: 5px; }
        .party h4 { color: #0d6efd; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .party p { margin: 2px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.items th { background: #0d6efd; color: white; padding: 7px; text-align: left; font-size: 10px; }
        table.items td { border-bottom: 1px solid #dee2e6; padding: 6px 7px; }
        table.items tr:last-child td { border-bottom: none; }
        table.items .text-end { text-align: right; }
        .totals { float: right; width: 280px; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 5px 8px; }
        .totals .total-row { background: #0d6efd; color: white; font-weight: bold; font-size: 13px; }
        .notes { margin-top: 20px; padding: 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px; font-size: 10px; }
        .signatures { display: flex; justify-content: space-between; margin-top: 50px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #aaa; width: 200px; margin: 0 auto 5px; padding-top: 5px; }
        .footer { margin-top: 30px; border-top: 1px solid #dee2e6; padding-top: 10px; font-size: 9px; color: #999; text-align: center; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div style="padding: 25px 30px; max-width: 800px; margin: 0 auto;">
    <!-- Print Button -->
    <div class="no-print" style="margin-bottom:15px; display:flex; gap:10px;">
        <button onclick="window.print()" style="padding:8px 16px; background:#0d6efd; color:white; border:none; border-radius:4px; cursor:pointer;">
            🖨️ Drukuj / Zapisz jako PDF
        </button>
        <a href="offers.php?action=view&id=<?= $offer['id'] ?>" style="padding:8px 16px; background:#6c757d; color:white; text-decoration:none; border-radius:4px;">
            ← Powrót
        </a>
    </div>

    <!-- Header -->
    <div class="doc-header">
        <div class="company-info">
            <h2><?= h($settings['company_name'] ?? 'Twoja Firma') ?></h2>
            <?php if ($settings['company_address'] ?? ''): ?><p><?= h($settings['company_address']) ?></p><?php endif; ?>
            <?php if ($settings['company_city'] ?? ''): ?><p><?= h($settings['company_city']) ?></p><?php endif; ?>
            <?php if ($settings['company_phone'] ?? ''): ?><p>Tel: <?= h($settings['company_phone']) ?></p><?php endif; ?>
            <?php if ($settings['company_email'] ?? ''): ?><p>E-mail: <?= h($settings['company_email']) ?></p><?php endif; ?>
            <?php if ($settings['company_nip'] ?? ''): ?><p>NIP: <?= h($settings['company_nip']) ?></p><?php endif; ?>
        </div>
        <div class="doc-meta">
            <h1>OFERTA</h1>
            <p><strong>Nr: <?= h($offer['offer_number']) ?></strong></p>
            <p>Data wystawienia: <?= formatDate($offer['created_at']) ?></p>
            <?php if ($offer['valid_until']): ?><p>Ważna do: <strong><?= formatDate($offer['valid_until']) ?></strong></p><?php endif; ?>
            <p>Status: <?= h(ucfirst($offer['status'])) ?></p>
        </div>
    </div>

    <!-- Parties -->
    <div class="parties">
        <div class="party">
            <h4>Wystawca</h4>
            <p><strong><?= h($settings['company_name'] ?? '') ?></strong></p>
            <?php if ($settings['company_address'] ?? ''): ?><p><?= h($settings['company_address']) ?></p><?php endif; ?>
            <?php if ($settings['company_nip'] ?? ''): ?><p>NIP: <?= h($settings['company_nip']) ?></p><?php endif; ?>
            <p><?= h($offer['user_name']) ?></p>
        </div>
        <div class="party">
            <h4>Odbiorca</h4>
            <?php if ($offer['client_id']): ?>
            <p><strong><?= h($offer['company_name'] ?: $offer['contact_name']) ?></strong></p>
            <?php if ($offer['company_name']): ?><p><?= h($offer['contact_name']) ?></p><?php endif; ?>
            <?php if ($offer['address']): ?><p><?= h($offer['address']) ?></p><?php endif; ?>
            <?php if ($offer['postal_code'] || $offer['city']): ?><p><?= h(trim($offer['postal_code'] . ' ' . $offer['city'])) ?></p><?php endif; ?>
            <?php if ($offer['nip']): ?><p>NIP: <?= h($offer['nip']) ?></p><?php endif; ?>
            <?php else: ?><p>—</p><?php endif; ?>
        </div>
    </div>

    <!-- Items Table -->
    <table class="items">
        <thead>
            <tr>
                <th>Lp.</th>
                <th>Opis / Nazwa</th>
                <th class="text-end">Ilość</th>
                <th>J.m.</th>
                <th class="text-end">Cena jedn. netto</th>
                <th class="text-end">Wartość netto</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($offerItems as $i => $item): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= h($item['description']) ?></td>
                <td class="text-end"><?= h($item['quantity']) ?></td>
                <td><?= h($item['unit']) ?></td>
                <td class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                <td class="text-end"><strong><?= formatMoney($item['total_price']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals">
        <table>
            <tr>
                <td>Suma netto:</td>
                <td class="text-end"><strong><?= formatMoney($offer['total_net']) ?></strong></td>
            </tr>
            <tr>
                <td>VAT (<?= h($offer['vat_rate']) ?>%):</td>
                <td class="text-end"><?= formatMoney($offer['total_gross'] - $offer['total_net']) ?></td>
            </tr>
            <tr class="total-row">
                <td>RAZEM BRUTTO:</td>
                <td class="text-end"><?= formatMoney($offer['total_gross']) ?></td>
            </tr>
        </table>
    </div>
    <div style="clear:both"></div>

    <!-- Notes -->
    <?php if ($offer['notes'] || ($settings['offer_footer'] ?? '')): ?>
    <div class="notes">
        <?php if ($offer['notes']): ?><p><?= h($offer['notes']) ?></p><?php endif; ?>
        <?php if ($settings['offer_footer'] ?? ''): ?><p><?= h($settings['offer_footer']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="signatures">
        <div class="signature-box">
            <div class="signature-line">&nbsp;</div>
            <p>Wystawił: <?= h($offer['user_name']) ?></p>
        </div>
        <div class="signature-box">
            <div class="signature-line">&nbsp;</div>
            <p>Zaakceptował klient</p>
        </div>
    </div>

    <div class="footer">
        Wygenerowano przez FleetLink System GPS &mdash; <?= h($settings['company_name'] ?? '') ?> &mdash; <?= date('d.m.Y H:i') ?> &mdash; <a href="https://www.fleetlink.pl" style="color:inherit;text-decoration:none">www.fleetlink.pl</a>
    </div>
</div>
</body>
</html>
