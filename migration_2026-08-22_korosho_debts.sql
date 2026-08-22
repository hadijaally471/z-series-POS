-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Korosho Debt tracking (korosho_debts, korosho_debt_payments),
-- widens korosho_sales.payment_method to allow 'debt', and adds
-- outstanding_debt to korosho_customers — same debt-sale flow as the
-- main POS, but fully isolated (its own tables, own customers) so it
-- never mixes with the main `debts`/`customers` totals.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

SET @payment_method_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='korosho_sales' AND COLUMN_NAME='payment_method'
);
SET @widen_payment_method_sql := IF(@payment_method_type NOT LIKE '%debt%',
  "ALTER TABLE korosho_sales MODIFY COLUMN payment_method ENUM('cash','lipa','bank','debt') DEFAULT 'cash'",
  'SELECT 1');
PREPARE stmt FROM @widen_payment_method_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @outstanding_debt_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='korosho_customers' AND COLUMN_NAME='outstanding_debt'
);
SET @add_outstanding_debt_sql := IF(@outstanding_debt_exists = 0,
  'ALTER TABLE korosho_customers ADD COLUMN outstanding_debt DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_purchases',
  'SELECT 1');
PREPARE stmt FROM @add_outstanding_debt_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS korosho_debts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT,
  customer_name VARCHAR(150) NOT NULL,
  sale_id INT,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  description VARCHAR(500),
  debt_date DATE NOT NULL,
  due_date DATE,
  status ENUM('outstanding','partial','cleared') DEFAULT 'outstanding',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES korosho_customers(id),
  FOREIGN KEY (sale_id) REFERENCES korosho_sales(id)
);

CREATE TABLE IF NOT EXISTS korosho_debt_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  debt_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (debt_id) REFERENCES korosho_debts(id)
);
