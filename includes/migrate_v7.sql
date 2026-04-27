-- FleetLink Magazyn - Migration v7
-- Adds BLE device fields: ble_id, major, minor, mac_address to devices table.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `ble_id`      VARCHAR(100) DEFAULT NULL AFTER `sim_number`,
  ADD COLUMN IF NOT EXISTS `major`       SMALLINT UNSIGNED DEFAULT NULL AFTER `ble_id`,
  ADD COLUMN IF NOT EXISTS `minor`       SMALLINT UNSIGNED DEFAULT NULL AFTER `major`,
  ADD COLUMN IF NOT EXISTS `mac_address` VARCHAR(17) DEFAULT NULL AFTER `minor`;
