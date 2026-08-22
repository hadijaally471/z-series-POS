-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Korosho sales reps (korosho_reps) and their buying/selling ledger
-- (korosho_rep_ledger) — a simple per-rep Date/Kg/Buying Price/Selling
-- Price/Profit book, e.g. for field reps (George, Peter) who buy raw
-- cashew nuts and resell them. Fully isolated from the main `employees`
-- table and from korosho_sales — this is its own record-keeping ledger.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS korosho_reps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS korosho_rep_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rep_id INT NOT NULL,
  entry_date DATE NOT NULL,
  kg DECIMAL(10,2) NOT NULL DEFAULT 0,
  buying_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  recorded_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rep_id) REFERENCES korosho_reps(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);

INSERT INTO korosho_reps (name)
SELECT 'George' WHERE NOT EXISTS (SELECT 1 FROM korosho_reps WHERE name = 'George');

INSERT INTO korosho_reps (name)
SELECT 'Peter' WHERE NOT EXISTS (SELECT 1 FROM korosho_reps WHERE name = 'Peter');

-- Profit (Selling Price − Buying Price) is computed when displayed, not
-- stored, so it can never drift out of sync with the two amounts.
