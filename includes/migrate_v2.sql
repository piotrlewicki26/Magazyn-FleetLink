-- FleetLink Magazyn - Migration v2
-- Run this script once against an existing database to apply changes
-- introduced in version 2: new device statuses, sale/lease dates, wider SIM field.

SET NAMES utf8mb4;

-- 1. Extend devices status ENUM with 'sprzedany' and 'dzierżawa'
ALTER TABLE `devices`
  MODIFY COLUMN `status`
    ENUM('nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa')
    NOT NULL DEFAULT 'nowy';

-- 2. Widen sim_number to 30 chars (to accommodate full phone numbers)
ALTER TABLE `devices`
  MODIFY COLUMN `sim_number` VARCHAR(30) DEFAULT NULL;

-- 3. Add sale_date (date the device was sold)
ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `sale_date` DATE DEFAULT NULL AFTER `purchase_price`;

-- 4. Add lease_end_date (date until which the device is on lease)
ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `lease_end_date` DATE DEFAULT NULL AFTER `sale_date`;

-- 5. Add batch_id to installations (links all installations created in one batch)
ALTER TABLE `installations`
  ADD COLUMN IF NOT EXISTS `batch_id` INT UNSIGNED DEFAULT NULL AFTER `location_in_vehicle`;

-- 6. Add installation_address to installations (address of the installation site)
ALTER TABLE `installations`
  ADD COLUMN IF NOT EXISTS `installation_address` VARCHAR(200) DEFAULT NULL AFTER `location_in_vehicle`;

-- 7. Create sim_cards table for standalone SIM card management
CREATE TABLE IF NOT EXISTS `sim_cards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone_number` VARCHAR(30) NOT NULL,
  `device_id` INT UNSIGNED DEFAULT NULL,
  `operator` VARCHAR(50) DEFAULT NULL,
  `iccid` VARCHAR(25) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
