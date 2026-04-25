<?php
/**
 * FleetLink Magazyn - Generator Ofert GPS
 * Serwuje pełny generator ofert (strona tytułowa, oferta, umowa, urządzenia)
 * po weryfikacji logowania użytkownika.
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

// Preferuj nową wersję pliku generatora; jeśli nie istnieje, użyj starszej
$generatorFile = __DIR__ . '/FleetLink_Generator_Ofert_umowa_19_3.html';
if (!file_exists($generatorFile)) {
    $generatorFile = __DIR__ . '/FleetLink_Generator_Ofert_umowa_18_3.html';
}
if (!file_exists($generatorFile)) {
    flashError('Plik generatora ofert nie istnieje. Skontaktuj się z administratorem.');
    redirect(getBaseUrl() . 'dashboard.php');
}

// Security: restrict sources to known CDNs and same-origin
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn-cgi.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'");
header('Content-Type: text/html; charset=UTF-8');
readfile($generatorFile);
exit;

