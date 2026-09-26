-- FleetLink Magazyn - Migration v3
-- Accessories (Akcesoria) module
-- Run this script once against an existing database to add the accessories tables.

SET NAMES utf8mb4;

-- Accessories (consumable items stored in the warehouse)
CREATE TABLE IF NOT EXISTS `accessories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `quantity_initial` INT NOT NULL DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History of accessory issues from the warehouse
CREATE TABLE IF NOT EXISTS `accessory_issues` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `accessory_id` INT UNSIGNED NOT NULL,
  `installation_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`accessory_id`) REFERENCES `accessories`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`installation_id`) REFERENCES `installations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
