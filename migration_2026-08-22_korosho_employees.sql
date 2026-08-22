-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: korosho_employees (Korosho's own Sales Rep list), and re-points
-- korosho_sales.sales_rep_id from the main `employees` table to this new
-- isolated table — Korosho now stays fully self-contained (own products,
-- customers, sales, AND employees), matching every other part of the
-- module instead of sharing the main payroll employee list.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS korosho_employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Drop whatever the sales_rep_id -> employees FK happens to be named
-- (auto-generated names vary by install) before re-pointing it.
SET @old_fk_name := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'korosho_sales'
    AND COLUMN_NAME = 'sales_rep_id' AND REFERENCED_TABLE_NAME = 'employees'
  LIMIT 1
);
SET @drop_old_fk_sql := IF(@old_fk_name IS NOT NULL,
  CONCAT('ALTER TABLE korosho_sales DROP FOREIGN KEY ', @old_fk_name),
  'SELECT 1');
PREPARE stmt FROM @drop_old_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @new_fk_exists := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'korosho_sales'
    AND COLUMN_NAME = 'sales_rep_id' AND REFERENCED_TABLE_NAME = 'korosho_employees'
);
SET @add_new_fk_sql := IF(@new_fk_exists = 0,
  'ALTER TABLE korosho_sales ADD FOREIGN KEY (sales_rep_id) REFERENCES korosho_employees(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @add_new_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
