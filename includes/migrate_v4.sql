-- FleetLink Magazyn - Migration v4
-- Adds device selection, service type and replacement device tracking to PS protocols.
-- Also creates device_history table for "wymieniono na/z" records.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

-- 1. Add service-specific columns to the protocols table
ALTER TABLE `protocols`
  ADD COLUMN IF NOT EXISTS `service_device_id`      INT UNSIGNED DEFAULT NULL AFTER `service_id`,
  ADD COLUMN IF NOT EXISTS `service_type`            ENUM('przeglad','naprawa','wymiana','aktualizacja','inne') DEFAULT NULL AFTER `service_device_id`,
  ADD COLUMN IF NOT EXISTS `replacement_device_id`   INT UNSIGNED DEFAULT NULL AFTER `service_type`;

ALTER TABLE `protocols`
  ADD CONSTRAINT IF NOT EXISTS `fk_protocols_service_device`
    FOREIGN KEY (`service_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT IF NOT EXISTS `fk_protocols_replacement_device`
    FOREIGN KEY (`replacement_device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL;

-- 2. Create device_history table (tracks "wymieniono na/z" and general service events)
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
