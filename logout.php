<?php
// Logout function 
session_start();
require_once 'config/config.php';

// Log the logout action before destroying session
if (isset($_SESSION['employee_id'])) {
    try {
        $db   = getDB();
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, user_name, action, module, details, ip_address) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $_SESSION['employee_id'],
            $_SESSION['name'] ?? 'Unknown',
            'Logout',
            'Auth',
            'User logged out',
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
    } catch (Exception $e) {
        // silent fail
    }
}

// Clear remember token from DB
if (isset($_COOKIE['remember_token'])) {
    try {
        $db = getDB();
        $db->prepare("UPDATE users SET remember_token=NULL WHERE remember_token=?")->execute([$_COOKIE['remember_token']]);
    } catch (Exception $e) {}
    setcookie('remember_token', '', time() - 3600, '/');
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header('Location: ' . (defined('SITE_URL') ? SITE_URL : '') . '/index.php');
exit;
