# Hostinger Deployment

Use these steps when uploading Z-Series POS to Hostinger.

## 1. Upload Files

Upload the project files to one of these locations:

- Domain root: `public_html/`
- Subfolder: `public_html/zseries-php/`

The app now detects the base URL automatically, so both layouts work.

## 2. Create Database

In Hostinger hPanel:

1. Open MySQL Databases.
2. Create a database and database user.
3. Save the database host, database name, username, and password.
4. Open phpMyAdmin and import `database.sql`.

## 3. Add Production Config

Copy `config.local.example.php` to `config.local.php`, then fill in Hostinger's database values:

```php
define('APP_ENV', 'production');
define('DB_HOST', 'localhost');
define('DB_USER', 'your_hostinger_db_user');
define('DB_PASS', 'your_hostinger_db_password');
define('DB_NAME', 'your_hostinger_db_name');
```

Keep `config.local.php` on the server only and do not upload it to public source control.
Do not use the XAMPP `root` user online.

To enable "Forgot password" on the login page, also add the `SMTP_*` constants
from `config.local.example.php`, pointed at a real mailbox — either a Hostinger
email account (hPanel > Emails) or a Gmail address with an app password. Then
add an email address to each user account that should be able to use it (Users > Edit).

## 4. First Login

Login with the default admin account, then immediately change the password:

- Username: `admin`
- Password: `admin123`

## 5. Test These Pages

- Login/logout
- Dashboard
- POS sale
- Receipt print
- Inventory filters
- Customer/supplier filters
- Debts and debt payments
- Reports

## Security Notes

The `.htaccess` files block directory listing, SQL files, config files, and helper scripts from direct browser access on Apache/LiteSpeed hosting.
