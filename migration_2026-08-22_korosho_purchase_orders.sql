-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Korosho Purchase Orders (korosho_purchase_orders) — same
-- record-keeping tool as the main Purchase Orders page (order date,
-- expected date, items/total as a free-text line, pending/received/
-- cancelled status), fully isolated from the main `purchase_orders`
-- table and from `suppliers` — supplier name is a plain text field here.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS korosho_purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_number VARCHAR(20) UNIQUE NOT NULL,
  supplier_name VARCHAR(200) NOT NULL,
  items TEXT NOT NULL,
  total_amount DECIMAL(12,2) DEFAULT 0,
  payment_terms VARCHAR(100),
  order_date DATE NOT NULL,
  expected_date DATE,
  status ENUM('pending','received','cancelled') DEFAULT 'pending',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
