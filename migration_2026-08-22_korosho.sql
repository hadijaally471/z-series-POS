-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Korosho module (korosho_products, korosho_sales, korosho_sale_items)
--
-- This is a separate, self-contained sales module for cashew/korosho
-- products — its own product catalog and its own stock, sold through its
-- own cart/checkout. Revenue lives entirely in these tables so it never
-- mixes with the main `products`/`sales`/`sale_items` tables shown in POS
-- and Reports (same isolation pattern as Billiards and WiFi Billing).
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- files' header comments for why.
--
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS korosho_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  unit VARCHAR(30) DEFAULT 'kg',
  rejareja_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  jumla_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT DEFAULT 0,
  low_stock_threshold INT DEFAULT 10,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS korosho_sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(20) UNIQUE NOT NULL,
  price_type ENUM('rejareja','jumla') DEFAULT 'rejareja',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method ENUM('cash','lipa','bank') DEFAULT 'cash',
  cashier_id INT,
  status ENUM('completed','cancelled') DEFAULT 'completed',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cashier_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS korosho_sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(200),
  qty INT DEFAULT 1,
  unit_price DECIMAL(12,2),
  total DECIMAL(12,2),
  FOREIGN KEY (sale_id) REFERENCES korosho_sales(id),
  FOREIGN KEY (product_id) REFERENCES korosho_products(id)
);

INSERT INTO korosho_products (name, unit, rejareja_price, jumla_price, stock, low_stock_threshold)
SELECT 'Cashew Nuts - Baked', 'kg', 18000, 15000, 0, 10
WHERE NOT EXISTS (SELECT 1 FROM korosho_products WHERE name = 'Cashew Nuts - Baked');

INSERT INTO korosho_products (name, unit, rejareja_price, jumla_price, stock, low_stock_threshold)
SELECT 'Cashew Nuts - Mbichi (Raw)', 'kg', 14000, 11500, 0, 10
WHERE NOT EXISTS (SELECT 1 FROM korosho_products WHERE name = 'Cashew Nuts - Mbichi (Raw)');

INSERT INTO korosho_products (name, unit, rejareja_price, jumla_price, stock, low_stock_threshold)
SELECT 'Cashew Nuts - Roasted', 'kg', 20000, 17000, 0, 10
WHERE NOT EXISTS (SELECT 1 FROM korosho_products WHERE name = 'Cashew Nuts - Roasted');

INSERT INTO korosho_products (name, unit, rejareja_price, jumla_price, stock, low_stock_threshold)
SELECT 'Korosho vipande', 'kg', 12000, 9500, 0, 10
WHERE NOT EXISTS (SELECT 1 FROM korosho_products WHERE name = 'Korosho vipande');

-- After running this, go to Users in the app and grant the "Korosho"
-- privilege to accounts that should sell/view korosho products.
