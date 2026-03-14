-- FleetLink Magazyn - Migration v4
-- Adds device selection, service type and replacement device tracking to PS protocols.
-- Adds batch_id to protocols for PP batch-group linking.
-- Also creates device_history table for "wymieniono na/z" records.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

-- 1. Add service-specific and batch columns to the protocols table
--    (ADD COLUMN IF NOT EXISTS is supported in MySQL 8.0+; safe to re-run)
ALTER TABLE `protocols`
  ADD COLUMN IF NOT EXISTS `service_device_id`      INT UNSIGNED DEFAULT NULL AFTER `service_id`,
  ADD COLUMN IF NOT EXISTS `service_type`            ENUM('przeglad','naprawa','wymiana','aktualizacja','inne') DEFAULT NULL AFTER `service_device_id`,
  ADD COLUMN IF NOT EXISTS `replacement_device_id`   INT UNSIGNED DEFAULT NULL AFTER `service_type`,
  ADD COLUMN IF NOT EXISTS `batch_id`                INT UNSIGNED DEFAULT NULL AFTER `replacement_device_id`;

-- 2. Add foreign key constraints via a stored procedure so the script is
--    idempotent (MySQL does NOT support ADD CONSTRAINT IF NOT EXISTS for FKs).
DROP PROCEDURE IF EXISTS `_fleetlink_migrate_v4`;

DELIMITER $$
CREATE PROCEDURE `_fleetlink_migrate_v4`()
BEGIN
  -- FK: protocols.service_device_id -> devices.id
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'protocols'
      AND CONSTRAINT_NAME = 'fk_protocols_service_device'
  ) THEN
    ALTER TABLE `protocols`
      ADD CONSTRAINT `fk_protocols_service_device`
        FOREIGN KEY (`service_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL;
  END IF;

  -- FK: protocols.replacement_device_id -> devices.id
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'protocols'
      AND CONSTRAINT_NAME = 'fk_protocols_replacement_device'
  ) THEN
    ALTER TABLE `protocols`
      ADD CONSTRAINT `fk_protocols_replacement_device`
        FOREIGN KEY (`replacement_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;

CALL `_fleetlink_migrate_v4`();
DROP PROCEDURE IF EXISTS `_fleetlink_migrate_v4`;

-- 3. Create device_history table (tracks "wymieniono na/z" and general service events)
CREATE TABLE IF NOT EXISTS `device_history` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`         INT UNSIGNED NOT NULL,
  `event_type`        ENUM('wymieniono_na','wymieniono_z','serwis') NOT NULL,
  `related_device_id` INT UNSIGNED DEFAULT NULL,
  `protocol_id`       INT UNSIGNED DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`device_id`)         REFERENCES `devices`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`related_device_id`) REFERENCES `devices`(`id`)   ON DELETE SET NULL,
  FOREIGN KEY (`protocol_id`)       REFERENCES `protocols`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
