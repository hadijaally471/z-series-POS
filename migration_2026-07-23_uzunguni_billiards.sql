-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-23
-- Adds: Uzunguni Billiards Arena module (billiard_branches, billiard_sales)
--
-- This is a separate business from Z-Series Products (tiles/soap/cashew
-- nuts) — its revenue lives in its own tables so it never mixes with the
-- `sales` table totals shown in Reports.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- files' header comments for why.
--
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS billiard_branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  location VARCHAR(200),
  token_rate DECIMAL(10,2) NOT NULL DEFAULT 500,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS billiard_sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(20) UNIQUE NOT NULL,
  branch_id INT NOT NULL,
  tokens INT NOT NULL,
  rate_per_token DECIMAL(10,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash','lipa','bank') DEFAULT 'cash',
  cashier_id INT,
  notes TEXT,
  status ENUM('completed','cancelled') DEFAULT 'completed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES billiard_branches(id),
  FOREIGN KEY (cashier_id) REFERENCES users(id)
);

INSERT INTO billiard_branches (name, location, token_rate)
SELECT 'Uzunguni A', 'Uzunguni', 500
WHERE NOT EXISTS (SELECT 1 FROM billiard_branches WHERE name = 'Uzunguni A');

INSERT INTO billiard_branches (name, location, token_rate)
SELECT 'Uzunguni B', 'Uzunguni', 500
WHERE NOT EXISTS (SELECT 1 FROM billiard_branches WHERE name = 'Uzunguni B');

-- After running this, go to Users in the app and grant the "Uzunguni Billiards"
-- privilege to accounts that should record/view billiards sales.
