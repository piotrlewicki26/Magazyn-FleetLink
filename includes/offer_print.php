<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'print_contract' ? 'Oferta + Umowa ' : 'Oferta ' ?><?= h($offer['offer_number']) ?> — <?= h($settings['company_name'] ?? 'FleetLink') ?></title>
    <style>
        /* ===== Base ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1a1a1a; background: #f0f2f5; }
        .page-wrap { max-width: 820px; margin: 0 auto; padding: 10px; }
        .document { background: #fff; padding: 35px 40px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.12); }

        /* ===== Toolbar (no-print) ===== */
        .toolbar { background: #1a1a2e; color: #fff; padding: 12px 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 0; position: sticky; top: 0; z-index: 100; }
        .toolbar h6 { margin: 0; font-size: 13px; flex: 1; }
        .toolbar a, .toolbar button { padding: 7px 16px; border-radius: 4px; font-size: 12px; cursor: pointer; text-decoration: none; border: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
        .btn-print { background: #0d6efd; color: #fff; }
        .btn-back  { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3) !important; }

        /* ===== Document header ===== */
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; padding-bottom: 18px; border-bottom: 3px solid #0d6efd; }
        .company-info h2 { color: #0d6efd; font-size: 17px; font-weight: 700; margin-bottom: 5px; }
        .company-info p { margin: 2px 0; font-size: 10.5px; color: #555; }
        .doc-meta { text-align: right; }
        .doc-meta h1 { font-size: 26px; font-weight: 800; color: #0d6efd; letter-spacing: 2px; margin-bottom: 6px; }
        .doc-meta table { margin-left: auto; }
        .doc-meta td { padding: 1px 0 1px 10px; font-size: 10.5px; color: #555; }
        .doc-meta td:first-child { color: #888; font-size: 10px; }

        /* ===== Parties ===== */
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 22px; }
        .party { padding: 14px; border-radius: 6px; }
        .party-seller { background: #e8f0fe; border-left: 4px solid #0d6efd; }
        .party-buyer  { background: #e6f4ea; border-left: 4px solid #198754; }
        .party h4 { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-bottom: 7px; }
        .party-seller h4 { color: #0d6efd; }
        .party-buyer  h4 { color: #198754; }
        .party p { margin: 2px 0; font-size: 10.5px; }
        .party .party-name { font-size: 13px; font-weight: 700; margin-bottom: 3px; }

        /* ===== Items table ===== */
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10.5px; }
        table.items thead th { background: #0d6efd; color: #fff; padding: 7px 8px; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.5px; }
        table.items tbody td { border-bottom: 1px solid #e9ecef; padding: 6px 8px; vertical-align: middle; }
        table.items tbody tr:last-child td { border-bottom: none; }
        table.items tbody tr:nth-child(even) { background: #f8f9fa; }
        table.items .text-right { text-align: right; }
        table.items .text-center { text-align: center; }
        table.items .lp { color: #888; font-size: 10px; width: 28px; }

        /* ===== Totals ===== */
        .totals-wrap { display: flex; justify-content: flex-end; margin-top: 5px; margin-bottom: 18px; }
        .totals-box { width: 320px; border: 1px solid #dee2e6; border-radius: 6px; overflow: hidden; font-size: 11px; }
        .totals-box table { width: 100%; border-collapse: collapse; }
        .totals-box td { padding: 5px 12px; }
        .totals-box tr:not(:last-child) { border-bottom: 1px solid #e9ecef; }
        .totals-box .label { color: #555; }
        .totals-box .value { text-align: right; font-weight: 600; }
        .totals-box .total-row { background: #0d6efd; color: #fff; }
        .totals-box .total-row .label, .totals-box .total-row .value { color: #fff; font-size: 13px; font-weight: 700; }
        .totals-box .discount-row { color: #dc3545; }

        /* ===== Terms & notes ===== */
        .terms-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; font-size: 10.5px; }
        .term-block { background: #f8f9fa; padding: 10px 12px; border-radius: 5px; }
        .term-block .term-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6c757d; font-weight: 700; margin-bottom: 4px; }
        .notes-block { background: #fffbea; border: 1px solid #fde68a; border-radius: 5px; padding: 10px 12px; font-size: 10.5px; margin-bottom: 18px; }
        .notes-block .term-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #92400e; font-weight: 700; margin-bottom: 4px; }

        /* ===== Signatures ===== */
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; }
        .sig-box { text-align: center; }
        .sig-line { border-top: 1px solid #666; padding-top: 6px; font-size: 10px; color: #555; margin-top: 35px; }

        /* ===== Footer ===== */
        .doc-footer { margin-top: 25px; border-top: 1px solid #e9ecef; padding-top: 8px; font-size: 9px; color: #aaa; text-align: center; }

        /* ===== Contract section ===== */
        .contract-doc { background: #fff; padding: 35px 40px; box-shadow: 0 2px 12px rgba(0,0,0,0.12); }
        .contract-title { text-align: center; margin-bottom: 30px; }
        .contract-title h1 { font-size: 20px; font-weight: 800; letter-spacing: 2px; color: #1a1a1a; margin-bottom: 4px; }
        .contract-title p { font-size: 11px; color: #666; }
        .contract-section { margin-bottom: 20px; }
        .contract-section h3 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #0d6efd; border-bottom: 1px solid #e9ecef; padding-bottom: 5px; margin-bottom: 10px; }
        .contract-section p, .contract-section li { font-size: 10.5px; line-height: 1.65; margin-bottom: 5px; }
        .contract-section ol, .contract-section ul { padding-left: 18px; }
        .contract-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .contract-party { padding: 12px; border-radius: 6px; font-size: 10.5px; }
        .contract-party.seller { background: #e8f0fe; border-left: 4px solid #0d6efd; }
        .contract-party.buyer  { background: #e6f4ea; border-left: 4px solid #198754; }
        .contract-party .cp-role { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 6px; }
        .contract-party.seller .cp-role { color: #0d6efd; }
        .contract-party.buyer  .cp-role { color: #198754; }
        .contract-party p { margin: 2px 0; }
        .contract-party .cp-name { font-size: 13px; font-weight: 700; margin-bottom: 2px; }
        .contract-sigs { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; margin-top: 55px; }

        /* ===== Page break ===== */
        .page-break { page-break-before: always; }

        /* ===== Print ===== */
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page-wrap { padding: 0; }
            .document, .contract-doc { box-shadow: none; margin: 0; padding: 20mm 18mm; }
            .page-break { page-break-before: always; }
        }
    </style>
</head>
<body>
<!-- Toolbar -->
<div class="toolbar no-print">
    <h6>📄 <?= $action === 'print_contract' ? 'Oferta + Umowa: ' : 'Oferta: ' ?> <?= h($offer['offer_number']) ?></h6>
    <button onclick="window.print()" class="btn-print">🖨️ Drukuj / Zapisz PDF</button>
    <a href="offers.php?action=view&id=<?= $offer['id'] ?>" class="btn-back">← Powrót</a>
    <?php if ($action === 'print'): ?>
    <a href="offers.php?action=print_contract&id=<?= $offer['id'] ?>" class="btn-back" style="background:rgba(25,135,84,0.3);">📝 + Umowa</a>
    <?php endif; ?>
</div>

<div class="page-wrap">
<!-- ============================================================
     OFFER DOCUMENT
     ============================================================ -->
<div class="document">

    <!-- Header -->
    <div class="doc-header">
        <div class="company-info">
            <h2><?= h($settings['company_name'] ?? 'FleetLink') ?></h2>
            <?php if ($settings['company_address'] ?? ''): ?><p><?= h($settings['company_address']) ?></p><?php endif; ?>
            <?php if ($settings['company_city'] ?? ''): ?><p><?= h($settings['company_city']) ?></p><?php endif; ?>
            <?php if ($settings['company_phone'] ?? ''): ?><p>📞 <?= h($settings['company_phone']) ?></p><?php endif; ?>
            <?php if ($settings['company_email'] ?? ''): ?><p>✉ <?= h($settings['company_email']) ?></p><?php endif; ?>
            <?php if ($settings['company_nip'] ?? ''): ?><p>NIP: <?= h($settings['company_nip']) ?></p><?php endif; ?>
            <?php if ($settings['company_regon'] ?? ''): ?><p>REGON: <?= h($settings['company_regon']) ?></p><?php endif; ?>
        </div>
        <div class="doc-meta">
            <h1>OFERTA</h1>
            <table>
                <tr><td>Numer:</td><td><strong><?= h($offer['offer_number']) ?></strong></td></tr>
                <tr><td>Data wystawienia:</td><td><?= formatDate($offer['created_at']) ?></td></tr>
                <?php if ($offer['valid_until']): ?>
                <tr><td>Ważna do:</td><td><strong><?= formatDate($offer['valid_until']) ?></strong></td></tr>
                <?php endif; ?>
                <tr><td>Status:</td><td><?= h(mb_strtoupper($offer['status'])) ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Parties -->
    <div class="parties">
        <div class="party party-seller">
            <h4>Wystawca / Sprzedawca</h4>
            <p class="party-name"><?= h($settings['company_name'] ?? '') ?></p>
            <?php if ($settings['company_address'] ?? ''): ?><p><?= h($settings['company_address']) ?></p><?php endif; ?>
            <?php if ($settings['company_city'] ?? ''): ?><p><?= h($settings['company_city']) ?></p><?php endif; ?>
            <?php if ($settings['company_nip'] ?? ''): ?><p>NIP: <?= h($settings['company_nip']) ?></p><?php endif; ?>
            <?php if ($settings['company_phone'] ?? ''): ?><p>Tel: <?= h($settings['company_phone']) ?></p><?php endif; ?>
            <p style="margin-top:5px;color:#555;">Opiekun: <strong><?= h($offer['user_name']) ?></strong></p>
        </div>
        <div class="party party-buyer">
            <h4>Odbiorca / Kupujący</h4>
            <?php if ($offer['client_id']): ?>
            <p class="party-name"><?= h($offer['company_name'] ?: $offer['contact_name']) ?></p>
            <?php if ($offer['company_name']): ?><p><?= h($offer['contact_name']) ?></p><?php endif; ?>
            <?php if ($offer['address']): ?><p><?= h($offer['address']) ?></p><?php endif; ?>
            <?php if ($offer['postal_code'] || $offer['city']): ?>
            <p><?= h(trim(($offer['postal_code'] ?? '') . ' ' . ($offer['city'] ?? ''))) ?></p>
            <?php endif; ?>
            <?php if ($offer['nip']): ?><p>NIP: <?= h($offer['nip']) ?></p><?php endif; ?>
            <?php if ($offer['client_email'] ?? ''): ?><p>E-mail: <?= h($offer['client_email']) ?></p><?php endif; ?>
            <?php if ($offer['client_phone'] ?? ''): ?><p>Tel: <?= h($offer['client_phone']) ?></p><?php endif; ?>
            <?php else: ?><p style="color:#888;">—</p><?php endif; ?>
        </div>
    </div>

    <!-- Items table -->
    <table class="items">
        <thead>
            <tr>
                <th class="lp">Lp.</th>
                <th>Opis / Nazwa usługi lub produktu</th>
                <th class="text-right" style="width:65px">Ilość</th>
                <th class="text-center" style="width:45px">J.m.</th>
                <th class="text-right" style="width:120px">Cena jedn. netto</th>
                <th class="text-right" style="width:120px">Wartość netto</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($offerItems as $i => $item): ?>
            <tr>
                <td class="lp text-center"><?= $i + 1 ?></td>
                <td><?= h($item['description']) ?></td>
                <td class="text-right"><?= h(rtrim(rtrim(number_format((float)$item['quantity'], 2, ',', ''), '0'), ',')) ?></td>
                <td class="text-center"><?= h($item['unit']) ?></td>
                <td class="text-right"><?= formatMoney($item['unit_price']) ?></td>
                <td class="text-right"><strong><?= formatMoney($item['total_price']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($offerItems)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:15px;">— brak pozycji —</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <?php
    $rawNet = array_sum(array_column($offerItems, 'total_price'));
    $discountPct = (float)($offer['discount'] ?? 0);
    $discountAmt = round($rawNet * $discountPct / 100, 2);
    $vatAmt = round($offer['total_gross'] - $offer['total_net'], 2);
    ?>
    <div class="totals-wrap">
        <div class="totals-box">
            <table>
                <?php if ($discountPct > 0): ?>
                <tr>
                    <td class="label">Suma przed rabatem:</td>
                    <td class="value"><?= formatMoney($rawNet) ?></td>
                </tr>
                <tr class="discount-row">
                    <td class="label">Rabat (<?= number_format($discountPct, 2, ',', '') ?>%):</td>
                    <td class="value">-<?= formatMoney($discountAmt) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">Suma netto:</td>
                    <td class="value"><?= formatMoney($offer['total_net']) ?></td>
                </tr>
                <tr>
                    <td class="label">VAT (<?= h($offer['vat_rate']) ?>%):</td>
                    <td class="value"><?= formatMoney($vatAmt) ?></td>
                </tr>
                <tr class="total-row">
                    <td class="label">RAZEM BRUTTO:</td>
                    <td class="value"><?= formatMoney($offer['total_gross']) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Payment / delivery terms -->
    <?php if (($offer['payment_terms'] ?? '') || ($offer['delivery_terms'] ?? '')): ?>
    <div class="terms-row">
        <?php if ($offer['payment_terms'] ?? ''): ?>
        <div class="term-block">
            <div class="term-label">Termin płatności</div>
            <?= h($offer['payment_terms']) ?>
        </div>
        <?php endif; ?>
        <?php if ($offer['delivery_terms'] ?? ''): ?>
        <div class="term-block">
            <div class="term-label">Termin realizacji</div>
            <?= h($offer['delivery_terms']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php $footerText = $settings['offer_footer'] ?? ''; ?>
    <?php if ($offer['notes'] || $footerText): ?>
    <div class="notes-block">
        <div class="term-label">Uwagi</div>
        <?php if ($offer['notes']): ?><p><?= nl2br(h($offer['notes'])) ?></p><?php endif; ?>
        <?php if ($footerText): ?><p style="margin-top:4px;color:#555;"><?= nl2br(h($footerText)) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">Wystawił: <?= h($offer['user_name']) ?></div>
        </div>
        <div class="sig-box">
            <div class="sig-line">Zaakceptował / Pieczęć i podpis klienta</div>
        </div>
    </div>

    <div class="doc-footer">
        Wygenerowano przez FleetLink Magazyn &mdash; <?= date('d.m.Y H:i') ?> &mdash; <?= h($settings['company_name'] ?? '') ?>
    </div>
</div><!-- /document -->

<?php if ($action === 'print_contract'): ?>
<!-- ============================================================
     CONTRACT DOCUMENT (separate page)
     ============================================================ -->
<div class="page-break"></div>
<div class="contract-doc">
    <?php
    $contractDate = date('d.m.Y');
    $contractCity = $settings['company_city'] ?? '';
    $contractNum  = 'UM/' . date('Y/m') . '/' . ltrim(preg_replace('/.*\/(\d+)$/', '$1', $offer['offer_number']), '0');
    $sellerName   = $settings['company_name']    ?? '';
    $sellerAddr   = trim(($settings['company_address'] ?? '') . ', ' . ($settings['company_city'] ?? ''), ', ');
    $sellerNip    = $settings['company_nip']     ?? '';
    $sellerEmail  = $settings['company_email']   ?? '';
    $buyerName    = $offer['company_name'] ?: ($offer['contact_name'] ?? '');
    $buyerAddr    = trim(($offer['address'] ?? '') . ', ' . trim(($offer['postal_code'] ?? '') . ' ' . ($offer['city'] ?? '')), ', ');
    $buyerNip     = $offer['nip'] ?? '';
    $buyerEmail   = $offer['client_email'] ?? '';
    ?>

    <!-- Contract title -->
    <div class="contract-title">
        <h1>UMOWA</h1>
        <p>Nr <?= h($contractNum) ?> &nbsp;|&nbsp; <?= $contractCity ? h($contractCity) . ', ' : '' ?><?= $contractDate ?></p>
        <p style="font-size:10px;color:#888;margin-top:4px;">powiązana z ofertą <?= h($offer['offer_number']) ?></p>
    </div>

    <!-- Parties -->
    <div class="contract-parties">
        <div class="contract-party seller">
            <div class="cp-role">Zleceniobiorca / Wykonawca</div>
            <p class="cp-name"><?= h($sellerName) ?></p>
            <?php if ($sellerAddr): ?><p><?= h($sellerAddr) ?></p><?php endif; ?>
            <?php if ($sellerNip): ?><p>NIP: <?= h($sellerNip) ?></p><?php endif; ?>
            <?php if ($sellerEmail): ?><p>E-mail: <?= h($sellerEmail) ?></p><?php endif; ?>
            <p style="margin-top:5px;">Reprezentowana przez: <strong><?= h($offer['user_name']) ?></strong></p>
            <p>zwana dalej <strong>„Wykonawcą"</strong></p>
        </div>
        <div class="contract-party buyer">
            <div class="cp-role">Zleceniodawca / Klient</div>
            <?php if ($buyerName): ?>
            <p class="cp-name"><?= h($buyerName) ?></p>
            <?php if ($buyerAddr): ?><p><?= h($buyerAddr) ?></p><?php endif; ?>
            <?php if ($buyerNip): ?><p>NIP: <?= h($buyerNip) ?></p><?php endif; ?>
            <?php if ($buyerEmail): ?><p>E-mail: <?= h($buyerEmail) ?></p><?php endif; ?>
            <?php else: ?><p style="color:#888;">—</p><?php endif; ?>
            <p style="margin-top:5px;">zwany dalej <strong>„Klientem"</strong></p>
        </div>
    </div>

    <!-- §1 Subject -->
    <div class="contract-section">
        <h3>§1 — Przedmiot umowy</h3>
        <p>1. Wykonawca zobowiązuje się do dostarczenia i uruchomienia systemu lokalizacji GPS pojazdów, na warunkach określonych
           w ofercie nr <strong><?= h($offer['offer_number']) ?></strong> z dnia <?= formatDate($offer['created_at']) ?>,
           stanowiącej integralną część niniejszej umowy.</p>
        <p>2. Zakres prac obejmuje w szczególności:</p>
        <ol>
            <?php foreach ($offerItems as $item): ?>
            <li><?= h($item['description']) ?> — <?= h(rtrim(rtrim(number_format((float)$item['quantity'], 2, ',', ''), '0'), ',')) ?> <?= h($item['unit']) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>

    <!-- §2 Value -->
    <div class="contract-section">
        <h3>§2 — Wartość umowy i warunki płatności</h3>
        <p>1. Łączne wynagrodzenie Wykonawcy wynosi <strong><?= formatMoney($offer['total_gross']) ?> brutto</strong>
           (<?= formatMoney($offer['total_net']) ?> netto + VAT <?= h($offer['vat_rate']) ?>%).</p>
        <?php if ($discountPct > 0): ?>
        <p>2. Powyższa kwota uwzględnia rabat w wysokości <?= number_format($discountPct, 2, ',', '') ?>%.</p>
        <?php endif; ?>
        <p><?= $discountPct > 0 ? '3' : '2' ?>. Płatność zostanie zrealizowana w terminie: <strong><?= h($offer['payment_terms'] ?? '14 dni') ?></strong>
           od daty wystawienia faktury VAT.</p>
        <p><?= $discountPct > 0 ? '4' : '3' ?>. Faktura VAT zostanie wystawiona po podpisaniu protokołu odbioru.</p>
    </div>

    <!-- §3 Delivery -->
    <div class="contract-section">
        <h3>§3 — Termin realizacji</h3>
        <?php if ($offer['delivery_terms'] ?? ''): ?>
        <p>1. Wykonawca zobowiązuje się zrealizować przedmiot umowy w terminie: <strong><?= h($offer['delivery_terms']) ?></strong>.</p>
        <?php else: ?>
        <p>1. Strony ustalą termin realizacji odrębnie po podpisaniu niniejszej umowy.</p>
        <?php endif; ?>
        <p>2. Za datę realizacji uznaje się dzień podpisania protokołu odbioru przez Klienta.</p>
    </div>

    <!-- §4 Warranty -->
    <div class="contract-section">
        <h3>§4 — Gwarancja i serwis</h3>
        <ol>
            <li>Wykonawca udziela 24-miesięcznej gwarancji na zamontowane urządzenia lokalizacyjne.</li>
            <li>Gwarancja obejmuje wady fabryczne i usterki powstałe z winy Wykonawcy.</li>
            <li>Gwarancja nie obejmuje uszkodzeń mechanicznych, przepięć elektrycznych oraz zdarzeń losowych.</li>
            <li>Zgłoszenia gwarancyjne należy kierować na adres e-mail: <?= h($sellerEmail ?: '...') ?>.</li>
        </ol>
    </div>

    <!-- §5 Data / Subscription -->
    <div class="contract-section">
        <h3>§5 — Przetwarzanie danych i abonament</h3>
        <ol>
            <li>Dane lokalizacyjne są przechowywane na serwerach Wykonawcy lub wskazanego operatora platformy GPS.</li>
            <li>Klient zobowiązuje się do terminowego opłacania abonamentu za dostęp do platformy telematycznej,
                zgodnie z cennikiem obowiązującym w dniu zawarcia umowy.</li>
            <li>W przypadku braku płatności abonamentowej przez 30 dni Wykonawca ma prawo zawiesić dostęp do platformy.</li>
        </ol>
    </div>

    <!-- §6 Confidentiality -->
    <div class="contract-section">
        <h3>§6 — Poufność i RODO</h3>
        <ol>
            <li>Strony zobowiązują się do zachowania poufności wszelkich informacji uzyskanych w trakcie realizacji umowy.</li>
            <li>Dane osobowe przetwarzane są zgodnie z Rozporządzeniem (UE) 2016/679 (RODO).</li>
            <li>Administratorem danych przekazanych przez Klienta jest Wykonawca.</li>
        </ol>
    </div>

    <!-- §7 General -->
    <div class="contract-section">
        <h3>§7 — Postanowienia końcowe</h3>
        <ol>
            <li>Wszelkie zmiany niniejszej umowy wymagają formy pisemnej pod rygorem nieważności.</li>
            <li>W sprawach nieuregulowanych niniejszą umową mają zastosowanie przepisy Kodeksu Cywilnego.</li>
            <li>Ewentualne spory rozstrzygał będzie sąd właściwy dla siedziby Wykonawcy.</li>
            <li>Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron.</li>
        </ol>
    </div>

    <!-- Notes -->
    <?php if ($offer['notes']): ?>
    <div class="notes-block">
        <div class="term-label">Dodatkowe ustalenia</div>
        <p><?= nl2br(h($offer['notes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="contract-sigs">
        <div class="sig-box">
            <div class="sig-line">
                Wykonawca<br>
                <strong><?= h($sellerName) ?></strong>
            </div>
        </div>
        <div class="sig-box">
            <div class="sig-line">
                Klient<br>
                <strong><?= h($buyerName ?: '..............................') ?></strong>
            </div>
        </div>
    </div>

    <div class="doc-footer">
        Wygenerowano przez FleetLink Magazyn &mdash; <?= date('d.m.Y H:i') ?> &mdash; <?= h($settings['company_name'] ?? '') ?>
    </div>
</div><!-- /contract-doc -->
<?php endif; ?>

</div><!-- /page-wrap -->
</body>
</html>

