-- FleetLink Magazyn - Migration v9
-- Adds ecan_device_id to installations (optional paired ECAN device).
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

-- Add ecan_device_id column to installations
ALTER TABLE `installations`
  ADD COLUMN IF NOT EXISTS `ecan_device_id` INT UNSIGNED DEFAULT NULL AFTER `device_id`;

-- Add FK for ecan_device_id (ignore error if already exists)
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'installations'
   AND CONSTRAINT_NAME = 'fk_inst_ecan_device') = 0,
  'ALTER TABLE `installations` ADD CONSTRAINT `fk_inst_ecan_device` FOREIGN KEY (`ecan_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL',
  'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
