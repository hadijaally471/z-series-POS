# Z-Series POS System
## Installation Guide

### Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher (MariaDB works too)
- Apache or Nginx web server (XAMPP / WAMP / Laragon recommended)

---

## Installation Steps

### Step 1 — Install a Local Server
Download and install **XAMPP** (recommended):
👉 https://www.apachefriends.org/download.html

### Step 2 — Copy Files
Copy the `zseries-php` folder into:
- XAMPP: `C:/xampp/htdocs/zseries-php/`
- Linux: `/var/www/html/zseries-php/`

### Step 3 — Create Database
1. Open your browser and go to: `http://localhost/phpmyadmin`
2. Click **New** to create a new database
3. Name it `zseries_pos` and click Create
4. Click **Import** tab
5. Choose the file `database.sql` from the project folder
6. Click **Go** to import

### Step 4 — Configure Database
Copy `config.local.example.php` to `config.local.php`, then update your local database values there:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Your MySQL username
define('DB_PASS', '');           // Your MySQL password
define('DB_NAME', 'zseries_pos');
```

The app will load `config.local.php` automatically when it exists, so you can keep local credentials out of version control.

### Step 5 — Access the System
Open your browser and go to:
`http://localhost/zseries-php/`

### Default Login
- **Username:** admin
- **Password:** admin123

---

## Folder Structure
```
zseries-php/
├── index.php          — Login page
├── dashboard.php      — Main dashboard
├── pos.php            — Point of Sale
├── inventory.php      — Inventory management
├── customers.php      — Customer management
├── suppliers.php      — Supplier management
├── expenses.php       — Expenses tracking
├── debts.php          — Debt management
├── purchase_orders.php — Purchase orders
├── reports.php        — Business reports
├── receipts.php       — Receipt history & print
├── employees.php      — Employee management
├── activity.php       — System activity log
├── settings.php       — System settings
├── logout.php         — Logout
├── config.php         — Database configuration
├── database.sql       — Database schema + sample data
├── api/
│   └── sales.php      — Sales API endpoint
├── css/
│   └── style.css      — Main stylesheet
├── js/
│   └── main.js        — Main JavaScript
└── includes/
    ├── header.php     — Shared header & sidebar
    └── footer.php     — Shared footer
```

---

## Features
- ✅ Point of Sale (Jumla & Rejareja pricing)
- ✅ Inventory management with low stock alerts
- ✅ Customer management
- ✅ Supplier & Farmer management
- ✅ Expense tracking
- ✅ Debt management with payment recording
- ✅ Purchase Orders
- ✅ Sales reports with charts
- ✅ Receipt printing
- ✅ Employee management
- ✅ Activity log
- ✅ System settings
- ✅ Mobile money payment tracking (M-Pesa, Cash, Debt)

---

## Support
Developed by Kitaa Digital Solutions
© 2026 Z-Series Products
