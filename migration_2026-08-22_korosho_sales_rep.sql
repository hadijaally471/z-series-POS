-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: optional sales_rep_id on korosho_sales, so a Korosho sale can be
-- credited to a Sales Rep — same "employees.role='Sales Rep'" list the
-- main POS already uses (Korosho stays isolated for products/customers/
-- sales, but deliberately shares the one employee list rather than
-- duplicating it, per user's choice).
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

SET @sales_rep_id_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='korosho_sales' AND COLUMN_NAME='sales_rep_id'
);
SET @add_sales_rep_id_sql := IF(@sales_rep_id_exists = 0,
  'ALTER TABLE korosho_sales ADD COLUMN sales_rep_id INT NULL AFTER customer_name, ADD FOREIGN KEY (sales_rep_id) REFERENCES employees(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @add_sales_rep_id_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
