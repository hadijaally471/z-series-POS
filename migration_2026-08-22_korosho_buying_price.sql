-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Buying Price on Korosho products + snapshots it on each sale item,
-- so Reports can compute real profit (selling price actually charged minus
-- buying price) straight from actual sales — no manual ledger needed.
--
-- Replaces the per-employee manual ledger from migration_2026-08-22_korosho_reps.sql
-- (korosho_reps / korosho_rep_ledger), which is dropped here since it's no
-- longer used — profit now comes from real transactions instead of hand-
-- entered rows.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

SET @buying_price_products_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='korosho_products' AND COLUMN_NAME='buying_price'
);
SET @add_buying_price_products_sql := IF(@buying_price_products_exists = 0,
  'ALTER TABLE korosho_products ADD COLUMN buying_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit',
  'SELECT 1');
PREPARE stmt FROM @add_buying_price_products_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @buying_price_items_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='korosho_sale_items' AND COLUMN_NAME='buying_price'
);
SET @add_buying_price_items_sql := IF(@buying_price_items_exists = 0,
  'ALTER TABLE korosho_sale_items ADD COLUMN buying_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price',
  'SELECT 1');
PREPARE stmt FROM @add_buying_price_items_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS korosho_rep_ledger;
DROP TABLE IF EXISTS korosho_reps;

-- After running this, go to Korosho > Inventory and set each product's
-- Buying Price (Edit product) so profit reporting is accurate going forward.
