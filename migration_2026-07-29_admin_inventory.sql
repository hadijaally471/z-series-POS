-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-29
-- Adds: Private Inventory module (admin-only stock pool)
--
-- This holds stock the owner has produced/received but has not yet
-- released to the employee-visible inventory (`products`). It shares
-- the product catalog and `categories` table with `products`, but keeps
-- an independent `stock` count — moving stock from here to `products`
-- happens explicitly via the "Transfer to Inventory" action in
-- admin_inventory.php, never automatically.
--
-- This page is hard-restricted to role='admin' in admin_inventory.php
-- itself — no privilege checkbox is exposed in Users, so it cannot be
-- delegated to cashier/manager accounts through the app UI (same
-- pattern as personal_expenses.php).
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- files' header comments for why.
--
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS admin_inventory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  category_id INT,
  rejareja_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  jumla_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT DEFAULT 0,
  low_stock_threshold INT DEFAULT 10,
  unit VARCHAR(30) DEFAULT 'pcs',
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id)
);
