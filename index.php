<?php
/**
 * FleetLink Magazyn - Entry Point
 */
$configFile = __DIR__ . '/includes/config.php';
if (!file_exists($configFile)) {
    header('Location: setup.php');
    exit;
}
header('Location: login.php');
exit;
