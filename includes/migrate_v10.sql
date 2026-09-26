-- FleetLink Magazyn - Migration v10
-- Adds `archived` flag and `converted_by` (user id) to public_requests.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

ALTER TABLE `public_requests`
  ADD COLUMN IF NOT EXISTS `archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `internal_order_id`;

ALTER TABLE `public_requests`
  ADD COLUMN IF NOT EXISTS `converted_by` INT UNSIGNED DEFAULT NULL AFTER `archived`;
