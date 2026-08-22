-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-08-22
-- Adds: Korosho Expenses (korosho_expenses) — same expense tracking as
-- the main Expenses page (description, category, amount, date, optional
-- employee), fully isolated from the main `expenses` table. "Belongs To"
-- links to korosho_employees, not the main employees table.
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS korosho_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  category ENUM('transport','utilities','staff','raw_materials','rent','maintenance','other') DEFAULT 'other',
  employee_id INT,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  recorded_by INT,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  FOREIGN KEY (employee_id) REFERENCES korosho_employees(id)
);
