-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-23
-- Adds: Personal Expenses module (admin-only, private draws)
--
-- This is intentionally NOT linked to the `expenses` table (which feeds
-- Reports' profit calculation) or to `sales`/`billiard_sales`. It's a
-- private ledger of money the owner has drawn out, tagged with which
-- business the cash came from (Uzunguni, Cashewnuts, or Z Series) —
-- purely informational, never subtracted from any revenue figure.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- files' header comments for why.
--
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS personal_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  description VARCHAR(500) NOT NULL,
  source ENUM('uzunguni','cashewnuts','z_series') NOT NULL DEFAULT 'z_series',
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  notes TEXT,
  recorded_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);

-- This page is hard-restricted to role='admin' in personal_expenses.php
-- itself — no privilege checkbox is exposed in Users, so it cannot be
-- delegated to cashier/manager accounts through the app UI.
