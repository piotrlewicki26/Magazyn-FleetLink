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

$generatorFile = __DIR__ . '/FleetLink_Generator_Ofert_umowa_18_3.html';
if (!file_exists($generatorFile)) {
    flashError('Plik generatora ofert nie istnieje. Skontaktuj się z administratorem.');
    redirect(getBaseUrl() . 'dashboard.php');
}

// Serve the HTML generator file directly — it's a full standalone page
header('Content-Type: text/html; charset=UTF-8');
readfile($generatorFile);
exit;

