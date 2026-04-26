-- FleetLink System GPS - Migration v6
-- Adds 'do_demontazu' status to devices.status ENUM.
-- Run this script once against an existing database to apply the changes.

SET NAMES utf8mb4;

-- 1. Modify devices.status ENUM to include 'do_demontazu'
ALTER TABLE `devices`
  MODIFY COLUMN `status` ENUM('nowy','sprawny','w_serwisie','uszkodzony','zamontowany','wycofany','sprzedany','dzierżawa','do_demontazu') NOT NULL DEFAULT 'nowy';
