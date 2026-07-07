-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-07
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
--
-- Do NOT import database.sql on top of a live database — it starts with
-- DROP TABLE statements and will destroy all existing customers, sales,
-- debts, employees, etc. This file only ALTERs/CREATEs what's needed.
--
-- BEFORE RUNNING: take a backup (Hostinger hPanel > Databases > Backups,
-- or `mysqldump` via SSH) so this is trivially reversible if anything
-- looks wrong afterwards.
--
-- This script is safe to re-run if it's interrupted partway through —
-- each step either checks first or uses IF NOT EXISTS/idempotent MODIFY.

-- ============================================================
-- 1. sales.payment_method — add Lipa/Bank, retire old mpesa/card values
-- ============================================================
ALTER TABLE sales MODIFY payment_method ENUM('cash','mpesa','debt','card','lipa','bank') DEFAULT 'cash';
UPDATE sales SET payment_method='lipa' WHERE payment_method='mpesa';
UPDATE sales SET payment_method='bank' WHERE payment_method='card';
ALTER TABLE sales MODIFY payment_method ENUM('cash','lipa','bank','debt') DEFAULT 'cash';

-- ============================================================
-- 2. customers.category_id -> customers.category (wholesale/retail)
--    Drops the old FK to the product `categories` table (name looked up
--    dynamically since it may differ from what was created originally),
--    nulls out old product-category assignments (they never meant
--    "wholesale/retail" to begin with), then converts the column.
-- ============================================================
SET @fk_name := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='category_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @drop_fk_sql := IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE customers DROP FOREIGN KEY `', @fk_name, '`'), 'SELECT 1');
PREPARE stmt FROM @drop_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='category_id'
);
SET @null_sql := IF(@col_exists > 0, 'UPDATE customers SET category_id = NULL', 'SELECT 1');
PREPARE stmt FROM @null_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @change_sql := IF(@col_exists > 0,
  "ALTER TABLE customers CHANGE COLUMN category_id category ENUM('wholesale','retail') DEFAULT NULL",
  'SELECT 1');
PREPARE stmt FROM @change_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop the now-unused index left behind by the old FK, if present
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='category' AND INDEX_NAME <> 'PRIMARY'
);
SET @drop_idx_sql := IF(@idx_exists > 0,
  (SELECT CONCAT('ALTER TABLE customers DROP INDEX `', INDEX_NAME, '`')
   FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='category' AND INDEX_NAME <> 'PRIMARY'
   LIMIT 1),
  'SELECT 1');
PREPARE stmt FROM @drop_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. customers.status — new column for archive-instead-of-delete
-- ============================================================
SET @status_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='status'
);
SET @add_status_sql := IF(@status_exists = 0,
  "ALTER TABLE customers ADD COLUMN status ENUM('active','inactive') DEFAULT 'active'",
  'SELECT 1');
PREPARE stmt FROM @add_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4. New tasks table (Tasks module)
-- ============================================================
CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  due_date DATE NOT NULL,
  due_time TIME DEFAULT NULL,
  priority ENUM('low','medium','high') DEFAULT 'medium',
  status ENUM('pending','completed') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 5. New payroll table (Payroll module)
-- ============================================================
CREATE TABLE IF NOT EXISTS payroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  period VARCHAR(7) NOT NULL,
  base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','paid') DEFAULT 'pending',
  paid_date DATE DEFAULT NULL,
  notes VARCHAR(500),
  expense_id INT DEFAULT NULL,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_employee_period (employee_id, period),
  FOREIGN KEY (employee_id) REFERENCES employees(id),
  FOREIGN KEY (expense_id) REFERENCES expenses(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ============================================================
-- 6. activity_log.type — widen to allow 'task' and 'payroll' entries
--    (without this, Add Task / Generate Payroll / Mark Paid crash with
--    an uncaught database error right after saving)
-- ============================================================
ALTER TABLE activity_log MODIFY type ENUM('sale','product','customer','expense','debt','po','task','payroll','system') DEFAULT 'system';

-- ============================================================
-- Done. Verify with:
--   SHOW CREATE TABLE sales\G
--   SHOW CREATE TABLE customers\G
--   SHOW CREATE TABLE activity_log\G
--   SHOW TABLES LIKE 'tasks';
--   SHOW TABLES LIKE 'payroll';
-- ============================================================
