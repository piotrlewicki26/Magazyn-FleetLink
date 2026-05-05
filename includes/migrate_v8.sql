-- FleetLink Magazyn - Migration v8
-- Adds work_orders table (Zlecenia) and work_order_id to installations.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

-- Work orders table (Zlecenia montażowe)
CREATE TABLE IF NOT EXISTS `work_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(30) NOT NULL UNIQUE,
  `date` DATE NOT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `installation_address` VARCHAR(255) DEFAULT NULL,
  `technician_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('nowe','w_trakcie','zakonczone','anulowane') NOT NULL DEFAULT 'nowe',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add work_order_id column to installations
ALTER TABLE `installations`
  ADD COLUMN IF NOT EXISTS `work_order_id` INT UNSIGNED DEFAULT NULL AFTER `batch_id`;

-- Add FK for work_order_id (ignore error if already exists)
-- Use a stored procedure to add it safely
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'installations'
   AND CONSTRAINT_NAME = 'fk_inst_work_order') = 0,
  'ALTER TABLE `installations` ADD CONSTRAINT `fk_inst_work_order` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders`(`id`) ON DELETE SET NULL',
  'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
