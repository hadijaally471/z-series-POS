<?php
require_once 'config.php';
if (isset($_SESSION['user_name'])) {
    logActivity($conn, 'User logged out: ' . $_SESSION['user_name'], 'system');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
redirectTo('index.php' . (isset($_GET['timeout']) ? '?timeout=1' : ''));
?>
