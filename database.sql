-- Z-Series Products POS System
-- Database Schema
-- Created: May 2026

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS debt_payments;
DROP TABLE IF EXISTS debts;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

-- USERS
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(150),
  role ENUM('admin','cashier','manager') DEFAULT 'cashier',
  phone VARCHAR(20),
  privileges TEXT,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- PASSWORD RESETS
CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- RATE LIMITS (login lockout, forgot-password abuse prevention)
CREATE TABLE rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(50) NOT NULL,
  identifier VARCHAR(150) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action_identifier (action, identifier)
);

-- CATEGORIES
CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SUPPLIERS
CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  type ENUM('farmer','tile_supplier','materials','other') DEFAULT 'other',
  phone VARCHAR(20),
  location VARCHAR(200),
  products_supplied TEXT,
  total_purchased DECIMAL(15,2) DEFAULT 0,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- EMPLOYEES (must come before sales)
CREATE TABLE employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  role VARCHAR(100),
  phone VARCHAR(20),
  salary DECIMAL(10,2),
  nida VARCHAR(50),
  start_date DATE,
  status ENUM('active','inactive','on_leave') DEFAULT 'active',
  office ENUM('Cashewnut Sales','Home office','Pool Master','Z series sales','Driver','Others','Guard','Accountant','Manager') DEFAULT 'Cashewnut Sales',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- PRODUCTS
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  category_id INT,
  supplier_id INT,
  rejareja_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  jumla_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT DEFAULT 0,
  low_stock_threshold INT DEFAULT 10,
  unit VARCHAR(30) DEFAULT 'pcs',
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

-- CUSTOMERS
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20),
  category ENUM('wholesale','retail') DEFAULT NULL,
  location VARCHAR(200),
  total_purchases DECIMAL(15,2) DEFAULT 0,
  outstanding_debt DECIMAL(12,2) DEFAULT 0,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SALES
CREATE TABLE sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(20) UNIQUE NOT NULL,
  customer_id INT,
  customer_name VARCHAR(150) DEFAULT 'Walk-in',
  customer_city VARCHAR(200),
  price_type ENUM('rejareja','jumla') DEFAULT 'rejareja',
  subtotal DECIMAL(12,2) DEFAULT 0,
  discount DECIMAL(12,2) DEFAULT 0,
  total DECIMAL(12,2) DEFAULT 0,
  payment_method ENUM('cash','lipa','bank','debt') DEFAULT 'cash',
  cashier_id INT,
  sales_rep_id INT,
  status ENUM('completed','cancelled','refunded') DEFAULT 'completed',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (cashier_id) REFERENCES users(id),
  FOREIGN KEY (sales_rep_id) REFERENCES employees(id) ON DELETE SET NULL
);

-- SALE ITEMS
CREATE TABLE sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(200),
  qty INT DEFAULT 1,
  unit_price DECIMAL(12,2),
  total DECIMAL(12,2),
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
);

-- EXPENSES
CREATE TABLE expenses (
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
  FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- DEBTS
CREATE TABLE debts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT,
  customer_name VARCHAR(150),
  sale_id INT,
  amount DECIMAL(12,2) NOT NULL,
  amount_paid DECIMAL(12,2) DEFAULT 0,
  balance DECIMAL(12,2),
  description TEXT,
  debt_date DATE,
  due_date DATE,
  status ENUM('outstanding','partial','cleared') DEFAULT 'outstanding',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (sale_id) REFERENCES sales(id)
);

-- DEBT PAYMENTS
CREATE TABLE debt_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  debt_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash','mpesa') DEFAULT 'cash',
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (debt_id) REFERENCES debts(id)
);

-- PURCHASE ORDERS
CREATE TABLE purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_number VARCHAR(20) UNIQUE NOT NULL,
  supplier_id INT,
  supplier_name VARCHAR(150),
  items TEXT,
  total_amount DECIMAL(12,2),
  payment_terms VARCHAR(100),
  order_date DATE,
  expected_date DATE,
  status ENUM('pending','received','cancelled') DEFAULT 'pending',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

-- TASKS
CREATE TABLE tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  due_date DATE NOT NULL,
  due_time TIME DEFAULT NULL,
  priority ENUM('low','medium','high') DEFAULT 'medium',
  status ENUM('pending','completed') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- PAYROLL
CREATE TABLE payroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  period VARCHAR(7) NOT NULL,
  base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','paid') DEFAULT 'pending',
  paid_date DATE DEFAULT NULL,
  notes VARCHAR(500),
  expense_id INT DEFAULT NULL,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_employee_period (employee_id, period),
  FOREIGN KEY (employee_id) REFERENCES employees(id),
  FOREIGN KEY (expense_id) REFERENCES expenses(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ACTIVITY LOG
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  user_name VARCHAR(100),
  action VARCHAR(255),
  details TEXT,
  type ENUM('sale','product','customer','expense','debt','po','task','payroll','system') DEFAULT 'system',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SETTINGS
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================
-- DEFAULT DATA
-- =====================

-- Admin user (password: admin123)
INSERT INTO users (name, username, password, role, phone, privileges) VALUES
('Administrator', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '+255 712 000 000', 'dashboard,pos,inventory,customers,suppliers,expenses,debts,purchase_orders,reports,receipts,employees,activity,settings,users');

-- Categories
INSERT INTO categories (name) VALUES ('Tiles'), ('Soap/Cleaner'), ('Cashew Nuts');

-- Suppliers
INSERT INTO suppliers (name, type, phone, location, products_supplied) VALUES
('Mzee Hamisi Juma', 'farmer', '+255 712 100 200', 'Kilosa, Morogoro', 'Cashew Nuts Grade A, B'),
('Bi Zuwena Hassan', 'farmer', '+255 723 200 300', 'Kibaha, Pwani', 'Cashew Nuts Grade B'),
('Rashidi Farms', 'farmer', '+255 734 300 400', 'Newala, Mtwara', 'Cashew Nuts Grade A'),
('Dar Tile Suppliers', 'tile_supplier', '+255 745 400 500', 'Dar es Salaam', 'Floor Tiles, Wall Tiles, Mosaic'),
('Chem Raw Materials', 'materials', '+255 756 500 600', 'Dar es Salaam', 'Chemicals for soap production');

-- Products
INSERT INTO products (name, category_id, supplier_id, rejareja_price, jumla_price, stock, unit, low_stock_threshold) VALUES
('Floor Tile 60x60 (White)', 1, 4, 12000, 9500, 8, 'pcs', 20),
('Wall Tile 30x60 (Grey)', 1, 4, 8500, 6800, 45, 'pcs', 20),
('Mosaic Tile 20x20', 1, 4, 5500, 4200, 0, 'pcs', 15),
('Sink Cleaner 500ml', 2, 5, 3500, 2800, 5, 'pcs', 15),
('Sink Cleaner 1L', 2, 5, 6000, 4800, 32, 'pcs', 10),
('Sink Cleaner 5L', 2, 5, 24000, 19500, 12, 'pcs', 5),
('Cashew Nuts Grade A (1kg)', 3, 1, 18000, 15000, 2, 'kg', 10),
('Cashew Nuts Grade B (1kg)', 3, 2, 14000, 11500, 18, 'kg', 10),
('Cashew Nuts Grade A (5kg)', 3, 1, 85000, 70000, 6, 'bag', 3);

-- Customers
INSERT INTO customers (name, phone, location, total_purchases, outstanding_debt) VALUES
('Hassan Ally', '+255 712 111 222', 'Kariakoo, DSM', 2450000, 450000),
('Fatuma Said', '+255 723 333 444', 'Kinondoni, DSM', 385000, 0),
('Amina Shop', '+255 734 555 666', 'Ilala, DSM', 1200000, 350000),
('Juma Rashidi', '+255 745 777 888', 'Temeke, DSM', 145000, 45000),
('Zuwena Stores', '+255 756 999 000', 'Msasani, DSM', 3800000, 0);

-- Employees
INSERT INTO employees (name, role, phone, salary, start_date, status) VALUES
('Admin User', 'Administrator', '+255 712 000 001', 600000, '2025-01-01', 'active'),
('Amina Rashid', 'Cashier', '+255 723 000 002', 350000, '2025-03-01', 'active'),
('Bakari Hamisi', 'Factory Worker', '+255 734 000 003', 280000, '2025-06-01', 'active'),
('Zuwena Ali', 'Factory Worker', '+255 745 000 004', 280000, '2025-06-01', 'on_leave'),
('Omari Hassan', 'Driver', '+255 756 000 005', 320000, '2025-09-01', 'active');

-- Settings
INSERT INTO settings (setting_key, setting_value) VALUES
('business_name', 'Z-Series Products'),
('business_phone', '+255 712 000 000'),
('business_address', 'Arusha, Tanzania'),
('business_email', 'info@zseries.co.tz'),
('currency', 'TZS'),
('low_stock_threshold', '10'),
('tax_rate', '0'),
('receipt_footer', 'Thank you for your business!'),
('tin_number', '123-456-789');

-- Expenses
INSERT INTO expenses (description, category, amount, expense_date) VALUES
('Cashew transport — Morogoro to DSM', 'transport', 85000, CURDATE()),
('Electricity bill — Factory', 'utilities', 240000, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('Factory worker salaries — April', 'staff', 1200000, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
('Raw chemicals for soap production', 'raw_materials', 450000, DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
('Shop rent — May 2026', 'rent', 350000, DATE_SUB(CURDATE(), INTERVAL 6 DAY));

-- Debts
INSERT INTO debts (customer_id, customer_name, amount, balance, description, debt_date, due_date, status) VALUES
(1, 'Hassan Ally', 450000, 450000, 'Tiles purchase on credit', DATE_SUB(CURDATE(), INTERVAL 22 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'outstanding'),
(3, 'Amina Shop', 350000, 350000, 'Cashew nuts bulk order', DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_ADD(CURDATE(), INTERVAL 21 DAY), 'outstanding'),
(4, 'Juma Rashidi', 45000, 45000, 'Sink cleaner products', DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_ADD(CURDATE(), INTERVAL 24 DAY), 'outstanding');

-- Purchase Orders
INSERT INTO purchase_orders (po_number, supplier_id, supplier_name, items, total_amount, payment_terms, order_date, expected_date, status) VALUES
('PO-0008', 1, 'Mzee Hamisi Juma', 'Cashew Nuts Grade A — 50kg', 850000, 'Cash on Delivery', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'pending'),
('PO-0007', 4, 'Dar Tile Suppliers', 'Floor Tile 60x60 × 200pcs', 1900000, '50% Advance', DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'pending'),
('PO-0006', 5, 'Chem Raw Materials', 'Chemicals for soap production', 450000, 'Cash on Delivery', DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'received');

-- Activity Log
INSERT INTO activity_log (user_name, action, type) VALUES
('Admin', 'System initialized — Z-Series POS installed', 'system'),
('Admin', 'Products added to inventory', 'product'),
('Admin', 'Customers imported', 'customer'),
('Admin', 'Suppliers configured', 'system');
