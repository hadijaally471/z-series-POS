<?php
require_once 'config.php';
if (isset($_SESSION['user_name'])) {
    logActivity($conn, 'User logged out: ' . $_SESSION['user_name'], 'system');
}
session_destroy();
redirectTo('index.php');
?>
