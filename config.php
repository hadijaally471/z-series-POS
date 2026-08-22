<?php
// Buffer output so pages can still redirect (header('Location: ...')) after
// having already echoed HTML — needed for the POST/redirect/GET pattern.
ob_start();

// Z-Series POS — Database Configuration
// On hosting, create config.local.php from config.local.example.php.

$local_config = __DIR__ . '/config.local.php';
if (file_exists($local_config)) {
    require_once $local_config;
}

function envValue($key, $default = '') {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

if (!defined('APP_ENV')) define('APP_ENV', envValue('APP_ENV', 'local'));
if (!defined('DB_HOST')) define('DB_HOST', envValue('DB_HOST', 'localhost'));
if (!defined('DB_USER')) define('DB_USER', envValue('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', envValue('DB_PASS', ''));
if (!defined('DB_NAME')) define('DB_NAME', envValue('DB_NAME', 'zseries_pos'));

// Production hardening: hide error details from the browser (log them instead),
// and force HTTPS. Skipped in local dev, which usually has no SSL cert configured.
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    set_exception_handler(function ($e) {
        error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#333">'
           . '<h2>Something went wrong</h2><p>Please try again. If this keeps happening, contact support.</p></body></html>';
    });

    if (PHP_SAPI !== 'cli' && !isHttps()) {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit();
    }
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

function appBaseUrl() {
    static $base = null;
    if ($base !== null) return $base;

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    if (in_array(basename($dir), ['api', 'scripts', 'includes'], true)) {
        $dir = rtrim(dirname($dir), '/');
    }
    $base = ($dir === '' || $dir === '.') ? '' : $dir;
    return $base;
}

function appPath($path = '') {
    $path = ltrim((string)$path, '/');
    return appBaseUrl() . '/' . $path;
}

function isHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function fullUrl($path = '') {
    $scheme = isHttps() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . appPath($path);
}

function redirectTo($path) {
    header('Location: ' . appPath($path));
    exit();
}

// Connect
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    if (APP_ENV === 'local') {
        die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]));
    }
    die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
}
$conn->set_charset('utf8mb4');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Auth check
define('IDLE_TIMEOUT_SECONDS', 180);

function requireLogin() {
    global $conn;
    if (!isset($_SESSION['user_id'])) {
        redirectTo('index.php');
    }
    // Idle timeout — enforced server-side so it holds even if JS is disabled or
    // someone jumps straight to a URL after walking away. The client-side timer
    // in main.js additionally logs out proactively while a page just sits open.
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > IDLE_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        redirectTo('index.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
    // Re-check status/role/privileges against the DB on every request, rather than
    // only at login — otherwise revoking a privilege or deactivating a user doesn't
    // actually take effect until their session happens to end.
    $stmt = $conn->prepare("SELECT name, role, privileges, status FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || $user['status'] !== 'active') {
        session_unset();
        session_destroy();
        redirectTo('index.php');
    }
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_privileges'] = $user['privileges'] ?? '';
}

/* RATE LIMITING (login lockout, forgot-password abuse prevention) */
function tooManyAttempts($conn, $action, $identifier, $maxAttempts, $windowMinutes) {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM rate_limits WHERE action = ? AND identifier = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $stmt->bind_param('ssi', $action, $identifier, $windowMinutes);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['c'] >= $maxAttempts;
}

function recordAttempt($conn, $action, $identifier) {
    $stmt = $conn->prepare("INSERT INTO rate_limits (action, identifier) VALUES (?, ?)");
    $stmt->bind_param('ss', $action, $identifier);
    $stmt->execute();
}

function clearAttempts($conn, $action, $identifier) {
    $stmt = $conn->prepare("DELETE FROM rate_limits WHERE action = ? AND identifier = ?");
    $stmt->bind_param('ss', $action, $identifier);
    $stmt->execute();
}

function csrfToken() {
    return $_SESSION['csrf_token'] ?? '';
}

function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken() {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        die('Invalid request token.');
    }
}

/* INPUT SANITIZATION & VALIDATION HELPERS */
function sanitizeString($s, $maxLen = 1000) {
    $s = trim((string)$s);
    if ($maxLen > 0) $s = mb_substr($s, 0, $maxLen);
    return $s;
}

function sanitizeInt($v) {
    return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int)$v : 0;
}

function sanitizeFloat($v) {
    return is_numeric($v) ? (float)$v : 0.0;
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function requirePost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('Method not allowed');
    }
}

// Privilege checking
function getUserPrivileges() {
    $privStr = $_SESSION['user_privileges'] ?? '';
    if (empty($privStr)) return [];
    return array_filter(array_map('trim', explode(',', $privStr)));
}

function hasPrivilege($privilege) {
    $role = $_SESSION['user_role'] ?? 'cashier';
    // Admins have all privileges
    if ($role === 'admin') return true;
    // Check if user has specific privilege
    $privileges = getUserPrivileges();
    return in_array($privilege, $privileges, true);
}

function requirePrivilege($privilege) {
    if (!hasPrivilege($privilege)) {
        http_response_code(403);
        $dashboard = htmlspecialchars(appPath('dashboard.php'), ENT_QUOTES);
        die('<div style="padding: 40px; text-align: center; font-family: sans-serif; color: #dc3545;"><h2>Access Denied</h2><p>You do not have permission to access this page.</p><a href="' . $dashboard . '" style="color: #0d6efd; text-decoration: none;">Back to Dashboard</a></div>');
    }
}

// Log activity
function logActivity($conn, $action, $type = 'system', $details = '') {
    $user = $_SESSION['user_name'] ?? 'System';
    $uid  = $_SESSION['user_id']   ?? null;
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, user_name, action, details, type) VALUES (?,?,?,?,?)");
    $stmt->bind_param('issss', $uid, $user, $action, $details, $type);
    $stmt->execute();
}

// Format currency
function tzs($amount) {
    return 'TZS ' . number_format($amount, 0, '.', ',');
}

// Human-readable product unit label
function unitLabel($unit) {
    $labels = ['kg' => 'kg', 'half_kg' => 'Half Kg', 'quarter_kg' => 'Quarter Kg', 'pc' => 'pc', 'ctns' => 'ctns', 'half_ctns' => 'Half Carton'];
    return $labels[$unit] ?? $unit;
}

// Product catalog shared by inventory.php and admin_inventory.php
const PRODUCT_CATALOG = [
    'Liquid Soap (1L)',
    'Liquid Soap (1L) (6pc)',
    'Liquid Soap (5L)',
    'Master 23 Special for Tiles (1L)',
    'Master 23 Special for Tiles (1L) (6pc)',
    'Master 23 Special for Tiles (5L)',
    'Master 23 Tiles and Sink Cleaner (1L)',
    'Master 23 Tiles and Sink Cleaner (1L) (6pc)',
    'Master 23 Tiles and Sink Cleaner (5L)',
    'Z Series Tiles and Sink Cleaner (1L)',
    'Z Series Tiles and Sink Cleaner (1L) (6pc)',
    'Z Series Tiles and Sink Cleaner (5L)',
];

// Product catalog for the standalone Korosho module (korosho_inventory.php)
const KOROSHO_PRODUCT_CATALOG = [
    'Cashew Nuts - Baked',
    'Cashew Nuts - Baked (Premium)',
    'Cashew Nuts - Mbichi (Raw)',
    'Cashew Nuts - Mbichi (Raw) (Premium)',
    'Cashew Nuts - Roasted',
    'Cashew Nuts - Roasted (Premium)',
    'Korosho vipande',
];

// Get setting
function getSetting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r ? $r['setting_value'] : '';
}
?>
