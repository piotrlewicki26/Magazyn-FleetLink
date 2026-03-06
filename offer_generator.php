<?php
/**
 * FleetLink Magazyn - GPS Offer Generator
 * Auth-gated wrapper that serves the standalone FleetLink Generator HTML tool.
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$currentUser = getCurrentUser();
$userName = htmlspecialchars($currentUser['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$createOfferUrl = htmlspecialchars(getBaseUrl() . 'offers.php?action=add', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$backUrl      = htmlspecialchars(getBaseUrl() . 'offers.php',    ENT_QUOTES | ENT_HTML5, 'UTF-8');
$dashboardUrl = htmlspecialchars(getBaseUrl() . 'dashboard.php', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$logoutUrl    = htmlspecialchars(getBaseUrl() . 'logout.php',    ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Read the self-contained generator HTML
$generatorFile = __DIR__ . '/FleetLink_Generator_Ofert_umowa_18_3.html';
$generatorHtml = @file_get_contents($generatorFile);

if ($generatorHtml === false) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">'
        . '<title>Błąd — FleetLink Magazyn</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">'
        . '</head><body class="p-4"><div class="alert alert-danger">'
        . '<strong>Błąd:</strong> Nie można wczytać pliku generatora ofert.'
        . ' Skontaktuj się z administratorem.</div>'
        . '<a href="' . $backUrl . '" class="btn btn-secondary">← Wróć do ofert</a>'
        . '</body></html>';
    exit;
}

// Height of the injected app bar in pixels
$navBarHeightPx = 38;

// Build a slim, fixed navigation bar to inject into the page.
// It sits at the bottom so it never hides the generator's own sidebar/topbar.
$navBar = '<div id="fl-app-bar" style="'
    . 'position:fixed;bottom:0;left:0;right:0;z-index:2147483647;'
    . 'background:rgba(8,24,54,.96);backdrop-filter:blur(8px);'
    . 'border-top:1px solid rgba(91,164,245,.25);'
    . 'padding:7px 16px;display:flex;align-items:center;gap:14px;'
    . 'font-family:\'DM Sans\',\'Segoe UI\',Arial,sans-serif;font-size:12px;">'
    . '<a href="' . $backUrl . '" style="color:#5BA4F5;text-decoration:none;display:flex;align-items:center;gap:6px;font-weight:600;">'
    .   '<span style="font-size:15px;">&#8592;</span> Wróć do Ofert'
    . '</a>'
    . '<span style="color:rgba(255,255,255,.2);margin:0 4px;">|</span>'
    . '<a href="' . $createOfferUrl . '" style="'
    .   'background:linear-gradient(135deg,#27AE60,#2E7BCE);color:#fff;text-decoration:none;'
    .   'display:flex;align-items:center;gap:5px;font-weight:700;'
    .   'padding:4px 12px;border-radius:8px;font-size:11px;">'
    .   '&#43; Nowa oferta w systemie'
    . '</a>'
    . '<span style="color:rgba(255,255,255,.2);margin:0 4px;">|</span>'
    . '<a href="' . $dashboardUrl . '" style="color:rgba(255,255,255,.5);text-decoration:none;">Panel główny</a>'
    . '<span style="flex:1;"></span>'
    . '<span style="color:rgba(255,255,255,.35);">&#128100; ' . $userName . '</span>'
    . '<a href="' . $logoutUrl . '" style="color:rgba(255,100,100,.7);text-decoration:none;font-size:11px;">Wyloguj</a>'
    . '</div>'
    // Add bottom padding so generator content is never hidden behind the bar
    . '<style>body>.app-shell{padding-bottom:' . $navBarHeightPx . 'px!important;'
    .   'min-height:calc(100vh - ' . $navBarHeightPx . 'px)!important;}</style>';

// Inject the nav bar just before </body> (case-insensitive to handle any casing)
$modified = str_ireplace('</body>', $navBar . '</body>', $generatorHtml, $count);

// Fall back to appending the bar if the closing tag wasn't found
$generatorHtml = ($count > 0) ? $modified : ($generatorHtml . $navBar);

header('Content-Type: text/html; charset=UTF-8');
echo $generatorHtml;
exit;
