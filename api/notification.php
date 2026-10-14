<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT id, target_url FROM notifications
    WHERE id = ? AND (recipient_employee_id = ? OR recipient_role = ?)");
$stmt->execute([$id, $_SESSION['employee_id'], $_SESSION['role']]);
$notification = $stmt->fetch();

if (!$notification) {
    header('Location: ../modules/' . ($_SESSION['role'] === 'employee' ? 'employee' : 'admin') . '/dashboard.php');
    exit;
}

$target = (string)$notification['target_url'];
if (!preg_match('~^modules/(employee|admin|ceo)/[a-zA-Z0-9_/-]+\.php(?:\?[a-zA-Z0-9_=&%-]*)?$~', $target)
    || str_contains($target, '..')) {
    $target = 'modules/' . ($_SESSION['role'] === 'employee' ? 'employee' : 'admin') . '/dashboard.php';
}
$db->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ?')->execute([$id]);
header('Location: ../' . $target);
exit;
