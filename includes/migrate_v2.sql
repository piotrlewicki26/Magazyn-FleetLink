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
