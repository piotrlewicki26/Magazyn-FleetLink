<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'print_contract' ? 'Oferta + Umowa ' : 'Oferta ' ?><?= h($offer['offer_number']) ?> — <?= h($settings['company_name'] ?? 'FleetLink') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <style>
:root {
  --navy:#0B2545; --blue:#1A4B8C; --mid:#2E7BCE; --sky:#5BA4F5;
  --ice:#EBF4FF; --green:#1E8449; --mint:#27AE60; --sage:#A9DFBF;
  --foam:#EAFAF1; --slate:#2C3E50; --mist:#7F8C8D; --cloud:#ECF0F1;
  --snow:#F8FBFF; --white:#FFFFFF; --warn:#F39C12;
  --shadow:0 4px 24px rgba(11,37,69,.12);
  --shadow-lg:0 12px 48px rgba(11,37,69,.18);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f0f2f5;color:var(--slate);}

/* ── TOOLBAR (no-print) ── */
.toolbar{background:linear-gradient(135deg,var(--navy),var(--blue));color:#fff;padding:12px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.3);}
.toolbar h6{margin:0;font-size:13px;flex:1;font-family:'Syne',sans-serif;font-weight:700;}
.toolbar a,.toolbar button{padding:8px 16px;border-radius:8px;font-size:12px;cursor:pointer;text-decoration:none;border:none;display:inline-flex;align-items:center;gap:6px;font-weight:600;}
.btn-print{background:linear-gradient(135deg,var(--mint),var(--mid));color:#fff;box-shadow:0 3px 10px rgba(39,174,96,.3);}
.btn-print:hover{box-shadow:0 5px 16px rgba(39,174,96,.4);}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)!important;}
.btn-back:hover{background:rgba(255,255,255,.25);}

.page-wrap{max-width:870px;margin:0 auto;padding:20px 10px 40px;}

/* ═══════════════════════════
   COVER PAGE
═══════════════════════════ */
.cover-card{background:var(--white);border-radius:18px;box-shadow:var(--shadow-lg);overflow:hidden;display:flex;flex-direction:column;margin-bottom:24px;}
.cover-page-inner{display:flex;flex-direction:column;flex:1;}

.cover-topband{
  background:linear-gradient(135deg,var(--navy) 0%,#0E3366 100%);
  padding:22px 44px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;
}
.cover-topband::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:22px 22px;pointer-events:none;}
.cover-logo-area{display:flex;align-items:center;gap:14px;position:relative;}
.cover-logo-img{height:44px;width:auto;display:block;filter:drop-shadow(0 2px 6px rgba(0,120,255,.3));}
.cover-date-area{text-align:right;position:relative;}
.cover-date-label{color:rgba(255,255,255,.45);font-size:9px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;}
.cover-date-value{color:var(--white);font-size:12px;font-weight:700;margin-top:2px;}

.cover-title-section{
  background:linear-gradient(180deg,#0E3366 0%,#0B2545 100%);
  padding:18px 44px 22px;text-align:center;position:relative;
}
.cover-main-title{font-family:'Syne',sans-serif;color:var(--white);font-size:44px;font-weight:800;letter-spacing:-.01em;line-height:1;text-transform:uppercase;}
.cover-main-title em{color:var(--sky);font-style:normal;}
.cover-title-underline{width:110px;height:5px;margin:12px auto 0;background:linear-gradient(90deg,var(--mid),var(--sky));border-radius:3px;}
.cover-tagline{color:rgba(255,255,255,.55);font-size:12px;margin-top:10px;letter-spacing:.04em;}

.cover-img-section{
  background:linear-gradient(180deg,#0B2545 0%,#0D2E5C 100%);
  padding:22px 40px;display:flex;justify-content:center;align-items:center;
  position:relative;overflow:hidden;min-height:120px;
}
.cover-arcs{position:absolute;inset:0;pointer-events:none;}
.cover-arcs svg{width:100%;height:100%;}
.cover-gps-visual{position:relative;z-index:2;display:flex;align-items:center;gap:32px;}
.cover-gps-icon{width:80px;height:80px;background:linear-gradient(135deg,var(--mint),var(--mid));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:36px;box-shadow:0 8px 24px rgba(39,174,96,.4);}
.cover-gps-stats{display:flex;gap:20px;}
.cover-gps-stat{text-align:center;}
.cover-gps-stat-num{font-family:'Syne',sans-serif;color:var(--sage);font-size:22px;font-weight:800;}
.cover-gps-stat-label{color:rgba(255,255,255,.5);font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:2px;}

.cover-client-area{
  padding:18px 44px;display:grid;grid-template-columns:1fr 1fr;gap:18px;
  border-bottom:1px solid var(--cloud);background:var(--snow);
}
.cover-client-box{background:var(--white);border-radius:12px;padding:14px 18px;border:1px solid var(--cloud);}
.cover-client-box h3{font-family:'Syne',sans-serif;color:var(--navy);font-size:11px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.cover-client-box h3::before{content:'';display:block;width:3px;height:13px;background:linear-gradient(to bottom,var(--mint),var(--mid));border-radius:2px;}
.cfield{display:flex;flex-direction:column;gap:2px;margin-bottom:7px;}
.cfield:last-child{margin-bottom:0;}
.cfield-label{color:var(--mist);font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;}
.cfield-value{color:var(--navy);font-size:12px;font-weight:600;min-height:15px;}

.cover-offer-meta{
  padding:14px 44px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px;background:var(--snow);
}
.cover-meta-card{background:linear-gradient(135deg,var(--navy),var(--blue));border-radius:11px;padding:12px 14px;text-align:center;}
.cover-meta-label{color:rgba(255,255,255,.55);font-size:8.5px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px;}
.cover-meta-value{color:var(--white);font-size:13px;font-weight:700;font-family:'Syne',sans-serif;}
.cover-meta-value.green{color:var(--sage);}

.cover-green-stripe{height:4px;background:linear-gradient(90deg,var(--mint),var(--sky));flex-shrink:0;}

.cover-salesperson{
  background:linear-gradient(135deg,#0B2545,var(--blue));
  padding:14px 44px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-shrink:0;
}
.cover-salesperson-left{display:flex;align-items:center;gap:12px;}
.cover-salesperson-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--mint),var(--sky));display:flex;align-items:center;justify-content:center;font-size:18px;border:2px solid rgba(255,255,255,.25);}
.cover-salesperson-label{color:rgba(255,255,255,.45);font-size:8.5px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;}
.cover-salesperson-name{color:var(--white);font-size:13px;font-weight:700;font-family:'Syne',sans-serif;}
.cover-salesperson-role{color:var(--sky);font-size:9px;margin-top:2px;}
.cover-salesperson-right{display:flex;gap:20px;align-items:center;}
.cover-contact-item{text-align:right;}
.cover-contact-label{color:rgba(255,255,255,.4);font-size:8.5px;text-transform:uppercase;letter-spacing:.06em;}
.cover-contact-value{color:var(--white);font-size:10.5px;font-weight:600;margin-top:2px;}

/* ═══════════════════════════
   ABOUT PAGES
═══════════════════════════ */
.about-card{background:var(--white);border-radius:18px;box-shadow:var(--shadow-lg);overflow:hidden;display:flex;flex-direction:column;margin-bottom:24px;}
.about-hero{
  background:linear-gradient(135deg,#0B2545 0%,#1A4B8C 60%,#1A6B3C 100%);
  padding:22px 44px;display:grid;grid-template-columns:1fr auto;align-items:center;gap:28px;
  position:relative;overflow:hidden;
}
.about-hero::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:22px 22px;}
.about-hero-tag{color:var(--sage);font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:8px;}
.about-hero-title{font-family:'Syne',sans-serif;color:var(--white);font-size:20px;font-weight:800;line-height:1.1;margin-bottom:10px;}
.about-hero-title em{color:var(--sage);font-style:normal;}
.about-hero-desc{color:rgba(255,255,255,.72);font-size:11px;line-height:1.55;max-width:480px;}
.about-hero-badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);border-radius:16px;padding:20px 28px;text-align:center;min-width:140px;}
.about-hero-badge-num{font-family:'Syne',sans-serif;color:var(--sage);font-size:24px;font-weight:800;}
.about-hero-badge-label{color:rgba(255,255,255,.65);font-size:11px;margin-top:4px;}

.about-stats{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--cloud);}
.about-stat{padding:18px 22px;text-align:center;border-right:1px solid var(--cloud);}
.about-stat:last-child{border-right:none;}
.about-stat-num{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;background:linear-gradient(135deg,var(--blue),var(--mint));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.about-stat-label{color:var(--mist);font-size:11px;margin-top:3px;}

.about-body{padding:24px 44px 20px;display:flex;flex-direction:column;gap:20px;flex:1;}
.about-section-title{font-family:'Syne',sans-serif;color:var(--navy);font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.about-section-title::after{content:'';flex:1;height:2px;background:linear-gradient(90deg,var(--ice),transparent);border-radius:2px;}
.about-features{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
.about-feat{background:var(--snow);border-radius:12px;padding:16px;border:1px solid var(--cloud);position:relative;overflow:hidden;}
.about-feat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--mint),var(--mid));}
.feat-icon-wrap{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--blue),var(--mid));display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:8px;}
.feat-icon-wrap.green{background:linear-gradient(135deg,var(--green),var(--mint));}
.about-feat h4{color:var(--navy);font-size:12px;font-weight:700;margin-bottom:5px;}
.about-feat p{color:var(--mist);font-size:11px;line-height:1.55;}
.about-mission{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.mission-box{border-radius:14px;padding:20px;position:relative;overflow:hidden;}
.mission-box.blue{background:linear-gradient(135deg,var(--navy),var(--blue));color:white;}
.mission-box.green{background:linear-gradient(135deg,#0D3D1F,var(--green));color:white;}
.mission-box h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:800;margin-bottom:10px;color:var(--white);}
.mission-box p{color:rgba(255,255,255,.76);font-size:12px;line-height:1.65;}
.mission-icon{position:absolute;bottom:12px;right:12px;font-size:40px;opacity:.15;}
.about-integrations{background:var(--snow);border-radius:14px;padding:18px;border:1px solid var(--cloud);}
.integr-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
.integr-tag{background:var(--white);border:1px solid var(--cloud);border-radius:7px;padding:6px 13px;font-size:11.5px;font-weight:600;color:var(--navy);}

/* ═══════════════════════════
   UNIFIED FOOTER
═══════════════════════════ */
.unified-footer{
  background:linear-gradient(135deg,var(--navy),var(--blue));
  padding:12px 44px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-shrink:0;
}
.unified-footer-brand{display:flex;align-items:center;gap:8px;}
.unified-footer-name{font-family:'Syne',sans-serif;color:var(--white);font-size:14px;font-weight:800;}
.unified-footer-sub{color:var(--sky);font-size:9px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;border-left:1px solid rgba(255,255,255,.2);padding-left:8px;margin-left:4px;}
.unified-footer-center{color:rgba(255,255,255,.45);font-size:10px;flex:1;text-align:center;}
.unified-footer-right{color:rgba(255,255,255,.55);font-size:10px;text-align:right;}

/* ═══════════════════════════
   OFFER ITEMS PAGE
═══════════════════════════ */
.offer-card{background:var(--white);border-radius:18px;box-shadow:var(--shadow-lg);overflow:hidden;display:flex;flex-direction:column;margin-bottom:24px;}
.offer-banner{
  background:linear-gradient(135deg,var(--navy) 0%,#1A5076 100%);
  padding:16px 32px;display:flex;align-items:center;justify-content:space-between;
  position:relative;overflow:hidden;
}
.offer-banner::before{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:20px 20px;pointer-events:none;}
.offer-banner-left{position:relative;}
.offer-banner-logo{height:40px;width:auto;display:block;filter:drop-shadow(0 2px 8px rgba(0,80,204,.5));}
.offer-company{font-family:'Syne',sans-serif;color:var(--white);font-size:18px;font-weight:800;display:flex;align-items:center;gap:10px;position:relative;}
.offer-company-icon{font-size:20px;}
.offer-subtitle{color:rgba(255,255,255,.55);font-size:10px;margin-top:3px;letter-spacing:.04em;}
.offer-meta{display:flex;flex-direction:column;gap:5px;min-width:200px;position:relative;}
.offer-meta-row{display:flex;justify-content:space-between;align-items:center;gap:16px;}
.offer-meta-label{color:rgba(255,255,255,.45);font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
.offer-meta-value{color:var(--white);font-size:11.5px;font-weight:700;}
.offer-green-stripe{height:4px;background:linear-gradient(90deg,var(--mint),var(--sky));}

.table-section{padding:10px 24px 0;}
.section-heading{font-family:'Syne',sans-serif;color:var(--navy);font-size:13px;font-weight:700;padding:10px 0 8px;display:flex;align-items:center;gap:10px;}
.section-heading::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--cloud),transparent);}

.items-table{width:100%;border-collapse:collapse;font-size:12px;}
.items-table thead tr{background:linear-gradient(135deg,var(--navy),var(--blue));}
.items-table thead th{color:var(--white);font-family:'Syne',sans-serif;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:9px 10px;text-align:left;}
.items-table tbody tr{border-bottom:1px solid var(--cloud);transition:background .15s;}
.items-table tbody tr:hover{background:var(--snow);}
.items-table tbody tr:nth-child(even){background:#FAFBFF;}
.items-table tbody td{padding:8px 10px;color:var(--slate);vertical-align:middle;}
.td-num{width:36px;text-align:center;color:var(--mist);font-size:11px;font-weight:700;}
.td-right{text-align:right;}
.td-center{text-align:center;}
.td-bold{font-weight:700;color:var(--navy);}

.summary-section{padding:12px 24px 16px;}
.summary-box{background:var(--navy);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
.summary-box-header{background:linear-gradient(135deg,var(--mint),var(--mid));padding:10px 18px;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;color:var(--white);letter-spacing:.08em;text-transform:uppercase;}
.summary-rows{padding:14px 18px;display:flex;flex-direction:column;gap:10px;}
.summary-row{display:flex;justify-content:space-between;align-items:center;}
.summary-row-label{color:rgba(255,255,255,.6);font-size:12px;}
.summary-row-value{color:var(--white);font-size:13px;font-weight:700;}
.summary-row.total .summary-row-label{color:var(--sage);font-size:13px;font-weight:700;}
.summary-row.total .summary-row-value{color:var(--sage);font-size:15px;font-weight:800;}
.summary-row.discount .summary-row-label{color:#ff8a80;}
.summary-row.discount .summary-row-value{color:#ff8a80;}

.terms-area{padding:0 24px 12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.term-block{background:var(--snow);border-radius:10px;padding:12px 14px;border:1px solid var(--cloud);}
.term-block-label{font-family:'Syne',sans-serif;font-size:9px;font-weight:700;color:var(--mist);text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px;}
.term-block-value{font-size:12px;color:var(--navy);font-weight:600;}

.notes-area{padding:0 24px 16px;}
.notes-box{background:#FFFBEB;border:1px solid #fde68a;border-left:3px solid var(--warn);border-radius:8px;padding:12px 14px;}
.notes-box-label{font-size:9px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px;}
.notes-box-text{font-size:12px;color:#78350F;line-height:1.6;}
.notes-box-footer{font-size:12px;color:#555;line-height:1.6;margin-top:6px;}

/* ═══════════════════════════
   CONTRACT PAGE
═══════════════════════════ */
.contract-card{background:var(--white);border-radius:18px;box-shadow:var(--shadow-lg);overflow:hidden;display:flex;flex-direction:column;margin-bottom:24px;}
.contract-header{background:linear-gradient(135deg,var(--navy),var(--blue));padding:20px 40px;}
.contract-header-title{font-family:'Syne',sans-serif;color:var(--white);font-size:20px;font-weight:800;text-align:center;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;}
.contract-header-sub{color:rgba(255,255,255,.5);font-size:10px;text-align:center;letter-spacing:.08em;}
.contract-header-nr{color:var(--sky);font-size:12px;font-weight:700;text-align:center;margin-top:6px;}
.contract-body{padding:28px 40px;}
.contract-parties{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}
.contract-party{padding:14px 18px;border-radius:10px;}
.contract-party.seller{background:#EBF4FF;border-left:4px solid var(--blue);}
.contract-party.buyer{background:var(--foam);border-left:4px solid var(--mint);}
.contract-party-role{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px;}
.contract-party.seller .contract-party-role{color:var(--blue);}
.contract-party.buyer .contract-party-role{color:var(--green);}
.contract-party-name{font-size:13px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.contract-party-detail{font-size:10.5px;color:var(--slate);line-height:1.6;}
.contract-section{margin-bottom:16px;}
.contract-section h3{font-family:'Syne',sans-serif;font-size:11px;font-weight:800;color:var(--navy);margin-bottom:8px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.06em;}
.contract-section h3::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--cloud),transparent);}
.contract-section p,.contract-section li{font-size:10.5px;color:var(--slate);line-height:1.7;margin-bottom:4px;}
.contract-section ol,.contract-section ul{padding-left:18px;}
.contract-signatures{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:30px;padding-top:20px;border-top:2px solid var(--cloud);}
.contract-sig-block{text-align:center;}
.contract-sig-line{border-bottom:1.5px solid var(--slate);height:40px;margin-bottom:6px;}
.contract-sig-label{font-size:9px;color:var(--mist);font-weight:600;text-transform:uppercase;letter-spacing:.07em;}
.contract-sig-name{font-size:11px;color:var(--navy);font-weight:700;margin-top:4px;}
.contract-notes-box{background:#FFFBEB;border-left:3px solid var(--warn);padding:10px 14px;margin:12px 0;border-radius:0 8px 8px 0;font-size:10.5px;color:#78350F;line-height:1.6;}

/* ═══════════════════════════
   OWU PAGE
═══════════════════════════ */
.owu-card{background:var(--white);border-radius:18px;box-shadow:var(--shadow-lg);overflow:hidden;margin-bottom:24px;}
.owu-body{padding:28px 40px;font-size:10px;line-height:1.6;color:var(--slate);}
.owu-header{text-align:center;margin-bottom:18px;padding-bottom:14px;border-bottom:3px solid var(--blue);}
.owu-header h1{font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;}
.owu-header p{font-size:9.5px;color:var(--mist);}
.owu-section{margin-bottom:10px;}
.owu-section h3{font-family:'Syne',sans-serif;font-size:10px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;padding-bottom:3px;border-bottom:1.5px solid var(--cloud);display:flex;align-items:center;gap:8px;}
.owu-section h3::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--cloud),transparent);}
.owu-section p,.owu-section li{font-size:9.5px;color:var(--slate);line-height:1.6;margin-bottom:3px;}
.owu-section ul{padding-left:14px;margin:3px 0;}
.owu-footer-note{text-align:center;font-size:8px;color:#BDC3C7;margin-top:16px;padding-top:8px;border-top:1px solid var(--cloud);}

/* ═══════════════════════════
   PRINT STYLES
═══════════════════════════ */
@page{size:A4 portrait;margin:0;}
@media print {
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
  body{background:white;margin:0;padding:0;}
  .toolbar{display:none!important;}
  .page-wrap{padding:0;max-width:none;}

  /* Cover = 1 A4 page */
  .cover-card{
    border-radius:0!important;box-shadow:none!important;
    width:210mm;height:297mm;overflow:hidden;
    page-break-after:always;break-after:page;
    display:flex;flex-direction:column;
    margin-bottom:0;
  }
  .cover-page-inner{height:100%;display:flex;flex-direction:column;}
  .cover-topband{padding:14mm 13mm;}
  .cover-title-section{padding:10mm 13mm;}
  .cover-main-title{font-size:36pt!important;}
  .cover-img-section{padding:10mm 13mm;flex:1;}
  .cover-client-area{padding:8mm 13mm;grid-template-columns:1fr 1fr;}
  .cover-offer-meta{padding:5mm 13mm;grid-template-columns:repeat(4,1fr);}
  .cover-salesperson{padding:6mm 13mm;}

  /* About = each card = 1 A4 page */
  .about-card{
    border-radius:0!important;box-shadow:none!important;
    width:210mm;height:297mm;overflow:hidden;
    page-break-after:always;break-after:page;
    display:flex;flex-direction:column;
    margin-bottom:0;
  }
  .about-hero{padding:12mm 13mm;}
  .about-body{padding:6mm 13mm 4mm;}
  .unified-footer{padding:6px 13mm;}

  /* Offer = natural flow */
  .offer-card{
    border-radius:0!important;box-shadow:none!important;
    width:210mm;
    page-break-after:always;break-after:page;
    margin-bottom:0;
  }
  .offer-banner{padding:8mm 13mm!important;}
  .table-section{padding:3mm 13mm 0!important;}
  .summary-section{padding:3mm 13mm!important;}
  .terms-area{padding:0 13mm 4mm!important;}
  .notes-area{padding:0 13mm 6mm!important;}
  .unified-footer{padding:6px 13mm!important;}
  .items-table{font-size:9pt!important;}
  .items-table td,.items-table th{padding:5px 7px!important;}

  /* Contract = natural flow */
  .contract-card{
    border-radius:0!important;box-shadow:none!important;
    width:210mm;
    page-break-after:always;break-after:page;
    margin-bottom:0;
  }
  .contract-body{padding:8mm 13mm;}
  .contract-header{padding:10mm 13mm;}

  /* OWU = 1 compact page */
  .owu-card{
    border-radius:0!important;box-shadow:none!important;
    width:210mm;height:297mm;overflow:hidden;
    page-break-after:always;break-after:page;
    margin-bottom:0;
  }
  .owu-body{padding:9mm 13mm;font-size:7pt!important;line-height:1.35!important;}
  .owu-header h1{font-size:8.5pt!important;}
  .owu-header{margin-bottom:3mm!important;padding-bottom:3mm!important;}
  .owu-section{margin-bottom:2mm!important;}
  .owu-section h3{font-size:7pt!important;margin-bottom:1mm!important;}
  .owu-section p,.owu-section li{font-size:6.5pt!important;line-height:1.35!important;}
}
    </style>
</head>
<body>
<?php
// Prepare variables
$companyName    = $settings['company_name']    ?? 'FleetLink – System GPS';
$companyAddr    = $settings['company_address'] ?? '';
$companyCity    = $settings['company_city']    ?? '';
$companyFullAddr = trim($companyAddr . ($companyCity ? ', ' . $companyCity : ''), ', ');
$companyNip     = $settings['company_nip']     ?? '';
$companyPhone   = $settings['company_phone']   ?? '';
$companyEmail   = $settings['company_email']   ?? '';
$companyWww     = $settings['company_website'] ?? $settings['company_www'] ?? 'www.fleetlink.pl';
$offerFooter    = $settings['offer_footer']    ?? '';

$clientName     = $offer['company_name'] ?: ($offer['contact_name'] ?? '');
$clientContact  = ($offer['company_name'] && $offer['contact_name']) ? $offer['contact_name'] : '';
$clientAddr     = trim(($offer['address'] ?? '') . (($offer['postal_code'] ?? '') || ($offer['city'] ?? '') ? ', ' . trim(($offer['postal_code'] ?? '') . ' ' . ($offer['city'] ?? '')) : ''), ', ');
$clientNip      = $offer['nip']          ?? '';
$clientEmail    = $offer['client_email'] ?? '';
$clientPhone    = $offer['client_phone'] ?? '';

$rawNet = array_sum(array_column($offerItems, 'total_price'));
$discountPct = (float)($offer['discount'] ?? 0);
$discountAmt = round($rawNet * $discountPct / 100, 2);
$vatAmt = round($offer['total_gross'] - $offer['total_net'], 2);

// FleetLink logo SVG (base64)
$logoB64 = 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0ODAgMTYwIiB3aWR0aD0iNDgwIiBoZWlnaHQ9IjE2MCI+CiAgPGRlZnM+CiAgICA8bGluZWFyR3JhZGllbnQgaWQ9Im1hcmtlckZpbGwiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMzAlIiB5Mj0iMTAwJSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCUiIHN0b3AtY29sb3I9IiMzOENGRkYiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSI1NSUiIHN0b3AtY29sb3I9IiMwQThGRkYiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxMDAlIiBzdG9wLWNvbG9yPSIjMDA1MENDIi8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJmbGVldEdyYWQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMCUiIHkyPSIxMDAlIj4KICAgICAgPHN0b3Agb2Zmc2V0PSIwJSIgc3RvcC1jb2xvcj0iI0ZGRkZGRiIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjEwMCUiIHN0b3AtY29sb3I9IiNDOERDRjAiLz4KICAgIDwvbGluZWFyR3JhZGllbnQ+CiAgICA8bGluZWFyR3JhZGllbnQgaWQ9ImxpbmtHcmFkIiB4MT0iMCUiIHkxPSIwJSIgeDI9IjAlIiB5Mj0iMTAwJSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCUiIHN0b3AtY29sb3I9IiM1QkUwRkYiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxMDAlIiBzdG9wLWNvbG9yPSIjMEE4RkZGIi8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogICAgPGZpbHRlciBpZD0idGV4dFNoYWRvdyIgeD0iLTEwJSIgeT0iLTEwJSIgd2lkdGg9IjEzMCUiIGhlaWdodD0iMTMwJSI+CiAgICAgIDxmZURyb3BTaGFkb3cgZHg9IjAiIGR5PSIyIiBzdGREZXZpYXRpb249IjgiIGZsb29kLWNvbG9yPSIjMEE4RkZGIiBmbG9vZC1vcGFjaXR5PSIwLjI1Ii8+CiAgICA8L2ZpbHRlcj4KICA8L2RlZnM+CiAgPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoNTQsIDc2KSI+CiAgICA8cGF0aCBkPSJNMCwtNTYgQzI2LC01NiA0NCwtMzYgNDQsLTE0IEM0NCwxMiAyMCw0MiAwLDYyIEMtMjAsNDIgLTQ0LDEyIC00NCwtMTQgQy00NCwtMzYgLTI2LC01NiAwLC01NiBaIiBmaWxsPSJ1cmwoI21hcmtlckZpbGwpIi8+CiAgICA8Y2lyY2xlIGN4PSIwIiBjeT0iLTE0IiByPSIxOCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjRkZGRkZGIiBzdHJva2Utd2lkdGg9IjIuNSIgb3BhY2l0eT0iMC45Ii8+CiAgICA8Y2lyY2xlIGN4PSIwIiBjeT0iLTE0IiByPSI4IiBmaWxsPSIjRkZGRkZGIi8+CiAgICA8Y2lyY2xlIGN4PSIwIiBjeT0iLTE0IiByPSIzLjUiIGZpbGw9IiMwQThGRkYiLz4KICA8L2c+CiAgPHRleHQgeD0iMTE2IiB5PSI3OCIgZm9udC1mYW1pbHk9IidTZWdvZSBVSScsIEhlbHZldGljYSwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSI2NCIgZm9udC13ZWlnaHQ9IjgwMCIgbGV0dGVyLXNwYWNpbmc9Ii0zIiBmaWx0ZXI9InVybCgjdGV4dFNoYWRvdykiPjx0c3BhbiBmaWxsPSJ1cmwoI2ZsZWV0R3JhZCkiPkZsZWV0PC90c3Bhbj48dHNwYW4gZmlsbD0idXJsKCNsaW5rR3JhZCkiPkxpbms8L3RzcGFuPjwvdGV4dD4KICA8cmVjdCB4PSIxMTYiIHk9Ijg3IiB3aWR0aD0iMzA4IiBoZWlnaHQ9IjIuNSIgcng9IjEuMjUiIGZpbGw9InVybCgjbGlua0dyYWQpIiBvcGFjaXR5PSIwLjU1Ii8+CiAgPHRleHQgeD0iMjQwIiB5PSIxMjgiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZvbnQtZmFtaWx5PSInU2Vnb2UgVUknLCBIZWx2ZXRpY2EsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMTkiIGZvbnQtd2VpZ2h0PSI2MDAiIGxldHRlci1zcGFjaW5nPSI5IiBmaWxsPSIjNkJCRkRFIiBvcGFjaXR5PSIwLjkiPlNZU1RFTSBHUFM8L3RleHQ+Cjwvc3ZnPgo=';
?>

<!-- ═════ TOOLBAR (screen only) ═════ -->
<div class="toolbar">
    <h6>📄 <?= $action === 'print_contract' ? 'Oferta + Umowa: ' : 'Oferta: ' ?> <?= h($offer['offer_number']) ?></h6>
    <button onclick="window.print()" class="btn-print">🖨️ Drukuj / Zapisz PDF</button>
    <a href="offers.php?action=view&id=<?= $offer['id'] ?>" class="btn-back">← Powrót</a>
    <?php if ($action === 'print'): ?>
    <a href="offers.php?action=print_contract&id=<?= $offer['id'] ?>" class="btn-back" style="background:rgba(39,174,96,.3);">📝 + Umowa</a>
    <?php endif; ?>
</div>

<div class="page-wrap">

<!-- ═══════════════════════════════════════════════════════
     PAGE 1: COVER
═══════════════════════════════════════════════════════ -->
<div class="cover-card">
  <div class="cover-page-inner">

    <!-- TOP BAND -->
    <div class="cover-topband">
      <div class="cover-logo-area">
        <img src="data:image/svg+xml;base64,<?= $logoB64 ?>" alt="FleetLink" class="cover-logo-img">
      </div>
      <div class="cover-date-area">
        <div class="cover-date-label">Data wystawienia</div>
        <div class="cover-date-value"><?= formatDate($offer['created_at']) ?></div>
        <?php if ($offer['valid_until']): ?>
        <div class="cover-date-label" style="margin-top:5px">Ważna do</div>
        <div class="cover-date-value"><?= formatDate($offer['valid_until']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TITLE -->
    <div class="cover-title-section">
      <div class="cover-main-title">OFERTA <em>BIZNESOWA</em></div>
      <div class="cover-title-underline"></div>
      <div class="cover-tagline">Kompleksowy system lokalizacji i zarządzania flotą pojazdów</div>
    </div>

    <!-- VISUAL SECTION -->
    <div class="cover-img-section">
      <div class="cover-arcs">
        <svg viewBox="0 0 900 240" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
          <path d="M -80 240 Q 180 -30 540 140" fill="none" stroke="rgba(46,123,206,.28)" stroke-width="85" stroke-linecap="round"/>
          <path d="M 320 -10 Q 650 140 960 50" fill="none" stroke="rgba(39,174,96,.2)" stroke-width="65" stroke-linecap="round"/>
          <path d="M -30 60 Q 170 200 420 70" fill="none" stroke="rgba(91,164,245,.16)" stroke-width="48" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="cover-gps-visual">
        <div class="cover-gps-icon">📡</div>
        <div class="cover-gps-stats">
          <div class="cover-gps-stat">
            <div class="cover-gps-stat-num">+40%</div>
            <div class="cover-gps-stat-label">Bezpieczeństwo</div>
          </div>
          <div class="cover-gps-stat">
            <div class="cover-gps-stat-num">−30%</div>
            <div class="cover-gps-stat-label">Wypadki</div>
          </div>
          <div class="cover-gps-stat">
            <div class="cover-gps-stat-num">−15k</div>
            <div class="cover-gps-stat-label">L paliwa/rok</div>
          </div>
          <div class="cover-gps-stat">
            <div class="cover-gps-stat-num">24/7</div>
            <div class="cover-gps-stat-label">Monitoring</div>
          </div>
        </div>
      </div>
    </div>

    <!-- CLIENT / VENDOR -->
    <div class="cover-client-area">
      <div class="cover-client-box">
        <h3>Wystawca oferty</h3>
        <div class="cfield">
          <div class="cfield-label">Firma</div>
          <div class="cfield-value"><?= h($companyName) ?></div>
        </div>
        <?php if ($companyFullAddr): ?>
        <div class="cfield">
          <div class="cfield-label">Adres</div>
          <div class="cfield-value"><?= h($companyFullAddr) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($companyEmail || $companyPhone): ?>
        <div class="cfield">
          <div class="cfield-label">E-mail / Telefon</div>
          <div class="cfield-value"><?= h(implode(' · ', array_filter([$companyEmail, $companyPhone]))) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($companyNip): ?>
        <div class="cfield">
          <div class="cfield-label">NIP</div>
          <div class="cfield-value"><?= h($companyNip) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($companyWww): ?>
        <div class="cfield">
          <div class="cfield-label">Strona WWW</div>
          <div class="cfield-value"><?= h($companyWww) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <div class="cover-client-box">
        <h3>Klient</h3>
        <?php if ($offer['client_id']): ?>
        <div class="cfield">
          <div class="cfield-label">Firma</div>
          <div class="cfield-value"><?= h($clientName ?: '—') ?></div>
        </div>
        <?php if ($clientNip): ?>
        <div class="cfield">
          <div class="cfield-label">NIP</div>
          <div class="cfield-value"><?= h($clientNip) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($clientAddr): ?>
        <div class="cfield">
          <div class="cfield-label">Adres</div>
          <div class="cfield-value"><?= h($clientAddr) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($clientContact): ?>
        <div class="cfield">
          <div class="cfield-label">Osoba kontaktowa</div>
          <div class="cfield-value"><?= h($clientContact) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($clientEmail || $clientPhone): ?>
        <div class="cfield">
          <div class="cfield-label">E-mail / Telefon</div>
          <div class="cfield-value"><?= h(implode(' · ', array_filter([$clientEmail, $clientPhone]))) ?></div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="cfield"><div class="cfield-label">Firma</div><div class="cfield-value">—</div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- META CARDS -->
    <div class="cover-offer-meta">
      <div class="cover-meta-card">
        <div class="cover-meta-label">Numer oferty</div>
        <div class="cover-meta-value green"><?= h($offer['offer_number']) ?></div>
      </div>
      <div class="cover-meta-card">
        <div class="cover-meta-label">Data wystawienia</div>
        <div class="cover-meta-value"><?= formatDate($offer['created_at']) ?></div>
      </div>
      <div class="cover-meta-card">
        <div class="cover-meta-label">Ważna do</div>
        <div class="cover-meta-value"><?= $offer['valid_until'] ? formatDate($offer['valid_until']) : '—' ?></div>
      </div>
      <div class="cover-meta-card">
        <div class="cover-meta-label">Wartość brutto</div>
        <div class="cover-meta-value green"><?= formatMoney($offer['total_gross']) ?></div>
      </div>
    </div>

    <!-- GREEN STRIPE -->
    <div class="cover-green-stripe"></div>

    <!-- SALESPERSON -->
    <div class="cover-salesperson">
      <div class="cover-salesperson-left">
        <div class="cover-salesperson-avatar">👤</div>
        <div>
          <div class="cover-salesperson-label">Opiekun handlowy</div>
          <div class="cover-salesperson-name"><?= h($offer['user_name']) ?></div>
          <div class="cover-salesperson-role">Specjalista ds. sprzedaży · <?= h($companyName) ?></div>
        </div>
      </div>
      <div class="cover-salesperson-right">
        <?php if ($companyPhone): ?>
        <div class="cover-contact-item">
          <div class="cover-contact-label">Telefon</div>
          <div class="cover-contact-value"><?= h($companyPhone) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($companyEmail): ?>
        <div class="cover-contact-item">
          <div class="cover-contact-label">E-mail</div>
          <div class="cover-contact-value"><?= h($companyEmail) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div><!-- /cover-card -->

<!-- ═══════════════════════════════════════════════════════
     PAGE 2: O NAS (About) — Part 1
═══════════════════════════════════════════════════════ -->
<div class="about-card">
  <div class="about-hero">
    <div>
      <div class="about-hero-tag">✦ Kim jesteśmy</div>
      <div class="about-hero-title">FleetLink – Twój<br><em>wybór nr 1</em> w zarządzaniu flotą</div>
      <div class="about-hero-desc">Zaawansowana ewidencja pojazdów i kierowców. Wszystkie dane flotowe zgromadzone w jednym miejscu. Proste zarządzanie gospodarką techniczną – nie umknie Ci żaden ważny termin. Obniżenie kosztów i lepsza wydajność floty są zaledwie kilka kliknięć stąd.</div>
    </div>
    <div class="about-hero-badge">
      <div class="about-hero-badge-num">24/7</div>
      <div class="about-hero-badge-label">Monitoring<br>i wsparcie</div>
    </div>
  </div>
  <div class="about-stats">
    <div class="about-stat"><div class="about-stat-num">+40%</div><div class="about-stat-label">Wzrost bezpieczeństwa</div></div>
    <div class="about-stat"><div class="about-stat-num">−30%</div><div class="about-stat-label">Mniej wypadków</div></div>
    <div class="about-stat"><div class="about-stat-num">−15k</div><div class="about-stat-label">Litrów paliwa rocznie</div></div>
    <div class="about-stat"><div class="about-stat-num">24/7</div><div class="about-stat-label">Monitoring i wsparcie</div></div>
  </div>
  <div class="about-body">
    <div>
      <div class="about-section-title">Nasza misja i wartości</div>
      <div class="about-mission">
        <div class="mission-box blue">
          <h3>🎯 Misja</h3>
          <p>Dostarczamy inteligentne systemy monitoringu GPS, które umożliwiają efektywne zarządzanie flotą. Wspieramy firmy transportowe w optymalizacji kosztów, zwiększeniu bezpieczeństwa i poprawie wydajności – niezależnie od wielkości przedsiębiorstwa.</p>
          <div class="mission-icon">🚛</div>
        </div>
        <div class="mission-box green">
          <h3>🌱 Zrównoważony rozwój</h3>
          <p>Wprowadzamy zrównoważoną jazdę dzięki raportowaniu emisji CO₂ i analizie zachowań kierowców. Moduł Eco-Driving pozwala zmniejszyć zużycie paliwa o kilkanaście procent rocznie.</p>
          <div class="mission-icon">🌿</div>
        </div>
      </div>
    </div>
    <div>
      <div class="about-section-title">Co oferujemy</div>
      <div class="about-features">
        <div class="about-feat"><div class="feat-icon-wrap">📡</div><h4>Śledzenie GPS na żywo</h4><p>Lokalizacja pojazdów w czasie rzeczywistym, historia tras i archiwizacja danych.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap">📊</div><h4>Business Intelligence</h4><p>Dashboardy, automatyczna wysyłka raportów, eksport do Excel/PDF/HTML.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap green">⛽</div><h4>Kontrola paliwa</h4><p>Monitorowanie tankowań, ubytków i spalania. Obsługa kart paliwowych.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap">🔗</div><h4>e-TOLL i SENT-GEO</h4><p>Pełna integracja z polskim systemem opłat drogowych i monitorowaniem SENT.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap green">📋</div><h4>Zdalny odczyt tachografu</h4><p>Automatyczne pobieranie plików DDD, raporty potencjalnych kar, czas pracy.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap">🛡️</div><h4>Bezpieczeństwo i kamery</h4><p>Telematyka wideo, alerty AI i ochrona prawna dzięki nagraniom z trasy.</p></div>
      </div>
    </div>
  </div>
  <div class="unified-footer">
    <div class="unified-footer-brand">
      <span class="unified-footer-name">FleetLink</span>
      <span class="unified-footer-sub">System GPS</span>
    </div>
    <div class="unified-footer-center">O nas – strona 1/2</div>
    <div class="unified-footer-right"><?= h($companyEmail) ?><?= ($companyEmail && $companyPhone) ? ' · ' : '' ?><?= h($companyPhone) ?></div>
  </div>
</div><!-- /about-card 1 -->

<!-- ═══════════════════════════════════════════════════════
     PAGE 3: O NAS (About) — Part 2
═══════════════════════════════════════════════════════ -->
<div class="about-card">
  <div class="about-hero" style="padding:18px 44px;">
    <div>
      <div class="about-hero-tag">✦ Rozwiązania i branże</div>
      <div class="about-hero-title" style="font-size:18px">Kompleksowe <em>zarządzanie</em> flotą dla każdej branży</div>
      <div class="about-hero-desc">Nasze rozwiązania pomagają zmaksymalizować wydajność floty i znaleźć możliwości oszczędzania zasobów – od transportu ciężkiego po rolnictwo i budownictwo.</div>
    </div>
  </div>
  <div class="about-body">
    <div>
      <div class="about-section-title">Więcej funkcji systemu</div>
      <div class="about-features">
        <div class="about-feat"><div class="feat-icon-wrap green">🗺️</div><h4>Planowanie tras i zadań</h4><p>Mapa zadań na żywo, elastyczne planowanie, nawigacja krok po kroku.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap">📱</div><h4>Aplikacja mobilna</h4><p>Pełny dostęp ze smartfona. Web + Mobile – zarządzaj flotą z dowolnego miejsca.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap green">❄️</div><h4>Monitoring chłodni</h4><p>Zdalne monitorowanie temperatury w naczepach z alertami w czasie rzeczywistym.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap">🌿</div><h4>Eco-Driving</h4><p>Analiza stylu jazdy, raportowanie emisji CO₂ i optymalizacja zużycia paliwa.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap green">🔑</div><h4>CarSharing</h4><p>Zarządzanie dostępem do pojazdów, rezerwacje i historia użytkowania.</p></div>
        <div class="about-feat"><div class="feat-icon-wrap">🔧</div><h4>Inspekcje pojazdów</h4><p>Cyfrowe checklisty, zarządzanie usterkami i przypomnienia o przeglądach.</p></div>
      </div>
    </div>
    <div>
      <div class="about-section-title">Obsługiwane branże</div>
      <div class="about-features">
        <div class="about-feat" style="display:flex;align-items:center;gap:10px;padding:12px 14px"><span style="font-size:20px">🚛</span><div><h4 style="font-size:11px">Transport i logistyka</h4><p style="font-size:10px">Nadzór nad kierowcami, zgodność z przepisami</p></div></div>
        <div class="about-feat" style="display:flex;align-items:center;gap:10px;padding:12px 14px"><span style="font-size:20px">🚜</span><div><h4 style="font-size:11px">Rolnictwo</h4><p style="font-size:10px">Śledzenie maszyn rolniczych i ciągników</p></div></div>
        <div class="about-feat" style="display:flex;align-items:center;gap:10px;padding:12px 14px"><span style="font-size:20px">📦</span><div><h4 style="font-size:11px">Kurierzy i dostawy</h4><p style="font-size:10px">Terminowe dostawy, optymalizacja tras</p></div></div>
        <div class="about-feat" style="display:flex;align-items:center;gap:10px;padding:12px 14px"><span style="font-size:20px">🏗️</span><div><h4 style="font-size:11px">Budownictwo</h4><p style="font-size:10px">Zarządzanie sprzętem i projektami</p></div></div>
        <div class="about-feat" style="display:flex;align-items:center;gap:10px;padding:12px 14px"><span style="font-size:20px">🗑️</span><div><h4 style="font-size:11px">Gospodarka odpadami</h4><p style="font-size:10px">Monitoring tras, zrównoważony rozwój</p></div></div>
        <div class="about-feat" style="display:flex;align-items:center;gap:10px;padding:12px 14px"><span style="font-size:20px">🚌</span><div><h4 style="font-size:11px">Transport pasażerski</h4><p style="font-size:10px">Bezpieczeństwo i zgodność z przepisami</p></div></div>
      </div>
    </div>
    <div class="about-integrations">
      <div class="about-section-title" style="margin-bottom:0">Integracje z systemami zewnętrznymi</div>
      <div class="integr-list">
        <div class="integr-tag">Projekt44</div><div class="integr-tag">FireTMS</div>
        <div class="integr-tag">Timocom</div><div class="integr-tag">Cargobull</div>
        <div class="integr-tag">e-TOLL (GDDKiA)</div><div class="integr-tag">SENT-GEO (KAS)</div>
        <div class="integr-tag">Giełdy transportowe</div><div class="integr-tag">Karty paliwowe</div>
        <div class="integr-tag">CO3</div><div class="integr-tag">LKW Walter</div>
        <div class="integr-tag">Thermo King</div><div class="integr-tag">HU-GO (Węgry)</div>
      </div>
    </div>
  </div>
  <div class="unified-footer">
    <div class="unified-footer-brand">
      <span class="unified-footer-name">FleetLink</span>
      <span class="unified-footer-sub">System GPS</span>
    </div>
    <div class="unified-footer-center">O nas – strona 2/2</div>
    <div class="unified-footer-right"><?= h($companyEmail) ?><?= ($companyEmail && $companyPhone) ? ' · ' : '' ?><?= h($companyPhone) ?></div>
  </div>
</div><!-- /about-card 2 -->

<!-- ═══════════════════════════════════════════════════════
     PAGE 4: OFFER ITEMS
═══════════════════════════════════════════════════════ -->
<div class="offer-card">
  <!-- Banner -->
  <div class="offer-banner">
    <div class="offer-banner-left">
      <div class="offer-company">
        <span class="offer-company-icon">📋</span>
        <?= h($companyName) ?>
      </div>
      <div class="offer-subtitle">Oferta komercyjna · System GPS FleetLink</div>
    </div>
    <div class="offer-meta">
      <div class="offer-meta-row">
        <span class="offer-meta-label">Nr oferty</span>
        <span class="offer-meta-value"><?= h($offer['offer_number']) ?></span>
      </div>
      <div class="offer-meta-row">
        <span class="offer-meta-label">Data</span>
        <span class="offer-meta-value"><?= formatDate($offer['created_at']) ?></span>
      </div>
      <?php if ($offer['valid_until']): ?>
      <div class="offer-meta-row">
        <span class="offer-meta-label">Ważna do</span>
        <span class="offer-meta-value"><?= formatDate($offer['valid_until']) ?></span>
      </div>
      <?php endif; ?>
      <div class="offer-meta-row">
        <span class="offer-meta-label">Klient</span>
        <span class="offer-meta-value"><?= h($clientName ?: '—') ?></span>
      </div>
    </div>
  </div>
  <div class="offer-green-stripe"></div>

  <!-- Items Table -->
  <div class="table-section">
    <div class="section-heading">Pozycje oferty</div>
    <table class="items-table">
      <thead>
        <tr>
          <th class="td-num">Lp.</th>
          <th>Opis / Nazwa usługi lub produktu</th>
          <th style="width:65px;text-align:right">Ilość</th>
          <th style="width:50px;text-align:center">J.m.</th>
          <th style="width:120px;text-align:right">Cena jedn. netto</th>
          <th style="width:120px;text-align:right">Wartość netto</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($offerItems as $i => $item): ?>
        <tr>
          <td class="td-num td-center"><?= $i + 1 ?></td>
          <td><?= h($item['description']) ?></td>
          <td class="td-right"><?= h(rtrim(rtrim(number_format((float)$item['quantity'], 2, ',', ''), '0'), ',')) ?></td>
          <td class="td-center"><?= h($item['unit']) ?></td>
          <td class="td-right"><?= formatMoney($item['unit_price']) ?></td>
          <td class="td-right td-bold"><?= formatMoney($item['total_price']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($offerItems)): ?>
        <tr>
          <td colspan="6" style="text-align:center;color:#aaa;padding:20px;font-style:italic;">— brak pozycji —</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Summary -->
  <div class="summary-section">
    <div style="display:flex;justify-content:flex-end;">
      <div style="width:320px;">
        <div class="summary-box">
          <div class="summary-box-header">💰 Podsumowanie finansowe</div>
          <div class="summary-rows">
            <?php if ($discountPct > 0): ?>
            <div class="summary-row">
              <span class="summary-row-label">Suma przed rabatem:</span>
              <span class="summary-row-value"><?= formatMoney($rawNet) ?></span>
            </div>
            <div class="summary-row discount">
              <span class="summary-row-label">Rabat (<?= number_format($discountPct, 2, ',', '') ?>%):</span>
              <span class="summary-row-value">−<?= formatMoney($discountAmt) ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row">
              <span class="summary-row-label">Suma netto:</span>
              <span class="summary-row-value"><?= formatMoney($offer['total_net']) ?></span>
            </div>
            <div class="summary-row">
              <span class="summary-row-label">VAT (<?= h($offer['vat_rate']) ?>%):</span>
              <span class="summary-row-value"><?= formatMoney($vatAmt) ?></span>
            </div>
            <div class="summary-row total">
              <span class="summary-row-label">RAZEM BRUTTO:</span>
              <span class="summary-row-value"><?= formatMoney($offer['total_gross']) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment / Delivery Terms -->
  <?php if (($offer['payment_terms'] ?? '') || ($offer['delivery_terms'] ?? '')): ?>
  <div class="terms-area">
    <?php if ($offer['payment_terms'] ?? ''): ?>
    <div class="term-block">
      <div class="term-block-label">Termin płatności</div>
      <div class="term-block-value"><?= h($offer['payment_terms']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($offer['delivery_terms'] ?? ''): ?>
    <div class="term-block">
      <div class="term-block-label">Termin realizacji</div>
      <div class="term-block-value"><?= h($offer['delivery_terms']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Notes -->
  <?php if ($offer['notes'] || $offerFooter): ?>
  <div class="notes-area">
    <div class="notes-box">
      <div class="notes-box-label">📝 Uwagi</div>
      <?php if ($offer['notes']): ?>
      <div class="notes-box-text"><?= nl2br(h($offer['notes'])) ?></div>
      <?php endif; ?>
      <?php if ($offerFooter): ?>
      <div class="notes-box-footer"><?= nl2br(h($offerFooter)) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="unified-footer">
    <div class="unified-footer-brand">
      <span class="unified-footer-name">FleetLink</span>
      <span class="unified-footer-sub">System GPS</span>
    </div>
    <div class="unified-footer-center">Oferta handlowa · <?= h($offer['offer_number']) ?></div>
    <div class="unified-footer-right"><?= h($companyEmail) ?><?= ($companyEmail && $companyPhone) ? ' · ' : '' ?><?= h($companyPhone) ?></div>
  </div>
</div><!-- /offer-card -->

<?php if ($action === 'print_contract'): ?>
<?php
$contractDate = date('d.m.Y');
$contractNum  = 'UM/' . date('Y/m') . '/' . ltrim(preg_replace('/.*\/(\d+)$/', '$1', $offer['offer_number']), '0');
$sellerName   = $companyName;
$sellerAddr   = $companyFullAddr;
$sellerNip    = $companyNip;
$sellerEmail  = $companyEmail;
$buyerName    = $clientName;
$buyerAddr    = $clientAddr;
$buyerNip     = $clientNip;
$buyerEmail   = $clientEmail;
?>
<!-- ═══════════════════════════════════════════════════════
     PAGE 5: UMOWA (Contract)
═══════════════════════════════════════════════════════ -->
<div class="contract-card">
  <div class="contract-header">
    <div class="contract-header-title">UMOWA</div>
    <div class="contract-header-sub">Umowa o świadczenie usług systemu GPS FleetLink</div>
    <div class="contract-header-nr">
      Nr <?= h($contractNum) ?> &nbsp;|&nbsp;
      <?= $companyCity ? h($companyCity) . ', ' : '' ?><?= $contractDate ?>
      &nbsp;|&nbsp; powiązana z ofertą <?= h($offer['offer_number']) ?>
    </div>
  </div>
  <div class="contract-body">

    <!-- Parties -->
    <div class="contract-parties">
      <div class="contract-party seller">
        <div class="contract-party-role">Zleceniobiorca / Wykonawca</div>
        <div class="contract-party-name"><?= h($sellerName) ?></div>
        <div class="contract-party-detail">
          <?php if ($sellerAddr): ?><?= h($sellerAddr) ?><br><?php endif; ?>
          <?php if ($sellerNip): ?>NIP: <?= h($sellerNip) ?><br><?php endif; ?>
          <?php if ($sellerEmail): ?>E-mail: <?= h($sellerEmail) ?><br><?php endif; ?>
          Reprezentowana przez: <strong><?= h($offer['user_name']) ?></strong><br>
          zwana dalej <strong>„Wykonawcą"</strong>
        </div>
      </div>
      <div class="contract-party buyer">
        <div class="contract-party-role">Zleceniodawca / Klient</div>
        <?php if ($buyerName): ?>
        <div class="contract-party-name"><?= h($buyerName) ?></div>
        <div class="contract-party-detail">
          <?php if ($buyerAddr): ?><?= h($buyerAddr) ?><br><?php endif; ?>
          <?php if ($buyerNip): ?>NIP: <?= h($buyerNip) ?><br><?php endif; ?>
          <?php if ($buyerEmail): ?>E-mail: <?= h($buyerEmail) ?><br><?php endif; ?>
          zwany dalej <strong>„Klientem"</strong>
        </div>
        <?php else: ?>
        <div class="contract-party-detail">—</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- §1 Subject -->
    <div class="contract-section">
      <h3>§1 — Przedmiot umowy</h3>
      <p>1. Wykonawca zobowiązuje się do dostarczenia i uruchomienia systemu lokalizacji GPS pojazdów, na warunkach określonych w ofercie nr <strong><?= h($offer['offer_number']) ?></strong> z dnia <?= formatDate($offer['created_at']) ?>, stanowiącej integralną część niniejszej umowy.</p>
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
      <p>1. Łączne wynagrodzenie Wykonawcy wynosi <strong><?= formatMoney($offer['total_gross']) ?> brutto</strong> (<?= formatMoney($offer['total_net']) ?> netto + VAT <?= h($offer['vat_rate']) ?>%).</p>
      <?php if ($discountPct > 0): ?>
      <p>2. Powyższa kwota uwzględnia rabat w wysokości <?= number_format($discountPct, 2, ',', '') ?>%.</p>
      <?php endif; ?>
      <p><?= $discountPct > 0 ? '3' : '2' ?>. Płatność zostanie zrealizowana w terminie: <strong><?= h($offer['payment_terms'] ?? '14 dni') ?></strong> od daty wystawienia faktury VAT.</p>
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

    <!-- §5 Subscription -->
    <div class="contract-section">
      <h3>§5 — Przetwarzanie danych i abonament</h3>
      <ol>
        <li>Dane lokalizacyjne są przechowywane na serwerach Wykonawcy lub wskazanego operatora platformy GPS.</li>
        <li>Klient zobowiązuje się do terminowego opłacania abonamentu za dostęp do platformy telematycznej, zgodnie z cennikiem obowiązującym w dniu zawarcia umowy.</li>
        <li>W przypadku braku płatności abonamentowej przez 30 dni Wykonawca ma prawo zawiesić dostęp do platformy.</li>
      </ol>
    </div>

    <!-- §6 RODO -->
    <div class="contract-section">
      <h3>§6 — Poufność i RODO</h3>
      <ol>
        <li>Strony zobowiązują się do zachowania poufności wszelkich informacji uzyskanych w trakcie realizacji umowy.</li>
        <li>Dane osobowe przetwarzane są zgodnie z Rozporządzeniem (UE) 2016/679 (RODO).</li>
        <li>Administratorem danych przekazanych przez Klienta jest Wykonawca.</li>
      </ol>
    </div>

    <!-- §7 Final -->
    <div class="contract-section">
      <h3>§7 — Postanowienia końcowe</h3>
      <ol>
        <li>Wszelkie zmiany niniejszej umowy wymagają formy pisemnej pod rygorem nieważności.</li>
        <li>W sprawach nieuregulowanych niniejszą umową mają zastosowanie przepisy Kodeksu Cywilnego.</li>
        <li>Ewentualne spory rozstrzygał będzie sąd właściwy dla siedziby Wykonawcy.</li>
        <li>Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron.</li>
      </ol>
    </div>

    <?php if ($offer['notes']): ?>
    <div class="contract-notes-box">
      <strong>Dodatkowe ustalenia:</strong><br>
      <?= nl2br(h($offer['notes'])) ?>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="contract-signatures">
      <div class="contract-sig-block">
        <div class="contract-sig-line"></div>
        <div class="contract-sig-label">Wykonawca</div>
        <div class="contract-sig-name"><?= h($sellerName) ?></div>
      </div>
      <div class="contract-sig-block">
        <div class="contract-sig-line"></div>
        <div class="contract-sig-label">Klient</div>
        <div class="contract-sig-name"><?= h($buyerName ?: '..............................') ?></div>
      </div>
    </div>

  </div>
  <div class="unified-footer">
    <div class="unified-footer-brand">
      <span class="unified-footer-name">FleetLink</span>
      <span class="unified-footer-sub">System GPS</span>
    </div>
    <div class="unified-footer-center">Umowa Nr <?= h($contractNum) ?></div>
    <div class="unified-footer-right">Wygenerowano: <?= date('d.m.Y H:i') ?></div>
  </div>
</div><!-- /contract-card -->

<!-- ═══════════════════════════════════════════════════════
     PAGE 6: OWU (General Terms & Conditions)
═══════════════════════════════════════════════════════ -->
<div class="owu-card">
  <div class="owu-body">
    <div class="owu-header">
      <h1>Ogólne Warunki Umów (OWU)</h1>
      <p><?= h($companyName) ?> · Wersja obowiązująca od 01.01.2025</p>
    </div>

    <div class="owu-section">
      <h3>§1 Definicje</h3>
      <ul>
        <li><strong>Wykonawca</strong> – <?= h($companyName) ?>, dostawca systemu GPS FleetLink.</li>
        <li><strong>Klient</strong> – podmiot zamawiający usługi na podstawie umowy.</li>
        <li><strong>Platforma</strong> – system telematyczny FleetLink dostępny przez przeglądarkę i aplikację mobilną.</li>
        <li><strong>Urządzenie</strong> – lokalizator GPS dostarczony przez Wykonawcę.</li>
        <li><strong>Abonament</strong> – miesięczna opłata za dostęp do Platformy i przechowywanie danych.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§2 Zawarcie umowy i warunki ogólne</h3>
      <ul>
        <li>Umowa zostaje zawarta z chwilą podpisania przez obie Strony i obowiązuje przez okres wskazany w umowie głównej.</li>
        <li>Klient zobowiązuje się do podania prawdziwych danych niezbędnych do realizacji usługi.</li>
        <li>Wykonawca zastrzega prawo do zmiany OWU z zachowaniem 30-dniowego okresu wypowiedzenia.</li>
        <li>Klient może rozwiązać umowę z zachowaniem 30-dniowego okresu wypowiedzenia, chyba że umowa stanowi inaczej.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§3 Montaż i uruchomienie urządzeń</h3>
      <ul>
        <li>Montaż urządzeń GPS wykonywany jest przez autoryzowanych techników Wykonawcy lub jego partnerów.</li>
        <li>Klient zobowiązuje się udostępnić pojazdy w uzgodnionym terminie.</li>
        <li>Po montażu sporządzany jest protokół odbioru podpisany przez obie Strony.</li>
        <li>Ingerencja osób nieuprawnionych w urządzenia skutkuje utratą gwarancji.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§4 Dostęp do platformy i dane</h3>
      <ul>
        <li>Dostęp do Platformy przyznawany jest po podpisaniu umowy i uruchomieniu urządzeń.</li>
        <li>Dane lokalizacyjne archiwizowane są przez minimum 365 dni.</li>
        <li>Klient jest odpowiedzialny za bezpieczeństwo swoich danych dostępowych (login, hasło).</li>
        <li>Wykonawca zapewnia dostępność Platformy na poziomie 99,5% (SLA) rocznie.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§5 Płatności i abonament</h3>
      <ul>
        <li>Faktury abonamentowe wystawiane są z góry, na początku każdego okresu rozliczeniowego.</li>
        <li>Opóźnienie w płatności powyżej 30 dni uprawnia Wykonawcę do zawieszenia usług.</li>
        <li>Opóźnienie powyżej 60 dni uprawnia Wykonawcę do rozwiązania umowy ze skutkiem natychmiastowym.</li>
        <li>Do zaległych należności naliczane są odsetki ustawowe za opóźnienie.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§6 Odpowiedzialność i siła wyższa</h3>
      <ul>
        <li>Wykonawca nie ponosi odpowiedzialności za szkody wynikłe z niesprawności sieci GSM/GPS/GNSS.</li>
        <li>Odpowiedzialność Wykonawcy ograniczona jest do wysokości opłat abonamentowych uiszczonych w danym roku.</li>
        <li>Wykonawca nie odpowiada za szkody wynikłe z siły wyższej (awaria infrastruktury, klęski żywiołowe, cyberataki).</li>
        <li>Klient ponosi odpowiedzialność za szkody wyrządzone przez nieprawidłowe użytkowanie urządzeń.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§7 Ochrona danych osobowych (RODO)</h3>
      <ul>
        <li>Administratorem danych jest Wykonawca w rozumieniu art. 4 ust. 7 RODO.</li>
        <li>Dane przetwarzane są wyłącznie w celu realizacji umowy i świadczenia usług telematycznych.</li>
        <li>Klient jako pracodawca zobowiązany jest do poinformowania pracowników o monitorowaniu pojazdów.</li>
        <li>Szczegółowa polityka prywatności dostępna jest na stronie www Wykonawcy.</li>
      </ul>
    </div>

    <div class="owu-section">
      <h3>§8 Postanowienia końcowe</h3>
      <ul>
        <li>Prawem właściwym jest prawo polskie. Sądem właściwym jest sąd siedziby Wykonawcy.</li>
        <li>Nieważność jednego postanowienia OWU nie wpływa na ważność pozostałych.</li>
        <li>OWU stanowią integralną część każdej umowy zawartej z Wykonawcą.</li>
      </ul>
    </div>

    <div class="owu-footer-note">
      <?= h($companyName) ?> · <?= h($companyFullAddr) ?> · <?= h($companyEmail) ?> · <?= h($companyPhone) ?> ·
      Wygenerowano: <?= date('d.m.Y H:i') ?>
    </div>
  </div>
</div><!-- /owu-card -->
<?php endif; ?>

</div><!-- /page-wrap -->
</body>
</html>
