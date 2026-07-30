-- FleetLink Magazyn - Migration v5
-- Adds replacement_device_id to services table (for "wymiana" service type).
-- Adds service_id to device_history table so wymiana entries from services are tracked.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

-- 1. Add replacement_device_id to the services table
ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `replacement_device_id` INT UNSIGNED DEFAULT NULL AFTER `type`;

-- 2. Add service_id to the device_history table
ALTER TABLE `device_history`
  ADD COLUMN IF NOT EXISTS `service_id` INT UNSIGNED DEFAULT NULL AFTER `protocol_id`;

-- 3. Add FK constraints via a stored procedure (idempotent)
DROP PROCEDURE IF EXISTS `_fleetlink_migrate_v5`;

DELIMITER $$
CREATE PROCEDURE `_fleetlink_migrate_v5`()
BEGIN
  -- FK: services.replacement_device_id -> devices.id
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'services'
      AND CONSTRAINT_NAME = 'fk_services_replacement_device'
  ) THEN
    ALTER TABLE `services`
      ADD CONSTRAINT `fk_services_replacement_device`
        FOREIGN KEY (`replacement_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL;
  END IF;

  -- FK: device_history.service_id -> services.id
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'device_history'
      AND CONSTRAINT_NAME = 'fk_device_history_service'
  ) THEN
    ALTER TABLE `device_history`
      ADD CONSTRAINT `fk_device_history_service`
        FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;

CALL `_fleetlink_migrate_v5`();
DROP PROCEDURE IF EXISTS `_fleetlink_migrate_v5`;
