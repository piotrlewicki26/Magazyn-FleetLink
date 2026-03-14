<?php
/**
 * FleetLink Magazyn - GPS Offer Generator
 * The offer generator has been removed. Redirect to dashboard.
 */
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

flashError('Generator ofert GPS został wyłączony. Skontaktuj się z administratorem.');
redirect(getBaseUrl() . 'dashboard.php');
