-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Korosho customer tracking (korosho_customers), linked to korosho_sales
--
-- Extends the Korosho module (see migration_2026-08-22_korosho.sql) so a
-- sale can optionally be tied to a named customer, same idea as the main
-- POS's customers. Still fully isolated from the main `customers` table —
-- this is its own customer list, scoped to Korosho sales only.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS korosho_customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20),
  location VARCHAR(200),
  total_purchases DECIMAL(15,2) DEFAULT 0,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

SET @customer_id_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='korosho_sales' AND COLUMN_NAME='customer_id'
);
SET @add_customer_id_sql := IF(@customer_id_exists = 0,
  'ALTER TABLE korosho_sales ADD COLUMN customer_id INT NULL AFTER id, ADD COLUMN customer_name VARCHAR(150) DEFAULT "Walk-in" AFTER customer_id, ADD FOREIGN KEY (customer_id) REFERENCES korosho_customers(id)',
  'SELECT 1');
PREPARE stmt FROM @add_customer_id_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- After running this, cashiers can pick a Korosho customer (or leave
-- Walk-in) when recording a sale in korosho_pos.php.
