<?php
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

function fullUrl($path = '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http';
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
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        redirectTo('index.php');
    }
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
    $labels = ['kg' => 'kg', 'half_kg' => 'Half Kg', 'quarter_kg' => 'Quarter Kg', 'pc' => 'pc', 'ctns' => 'ctns'];
    return $labels[$unit] ?? $unit;
}

// Get setting
function getSetting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r ? $r['setting_value'] : '';
}
?>
