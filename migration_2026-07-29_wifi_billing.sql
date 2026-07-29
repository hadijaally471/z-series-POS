-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-29
-- Adds: WiFi Bundle Billing module (wifi_customers, wifi_bundle_sales)
--
-- This is a standalone module — fully independent from Billiards, the
-- main `customers` table, Products, and the main `sales` table. It
-- exists purely to record which customer (a neighboring office or any
-- other buyer) bought which internet bundle, for how much, and when it
-- expires. It does not integrate with any router or network hardware;
-- it is a billing/record-keeping ledger only.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- files' header comments for why.
--
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS wifi_customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  location VARCHAR(200),
  contact_phone VARCHAR(30),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wifi_bundle_sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(20) UNIQUE NOT NULL,
  customer_id INT NOT NULL,
  description VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  duration_days INT NOT NULL,
  expires_at DATE NOT NULL,
  payment_method ENUM('cash','lipa','bank') DEFAULT 'cash',
  sold_by INT,
  notes TEXT,
  status ENUM('active','cancelled') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES wifi_customers(id),
  FOREIGN KEY (sold_by) REFERENCES users(id)
);

-- After running this, go to Users in the app and grant the "WiFi Billing"
-- privilege to accounts that should be able to sell bundles.
