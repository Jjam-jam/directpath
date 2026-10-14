<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

$notificationId = (int)($_GET['id'] ?? 0);
$stmt = getDB()->prepare("
    UPDATE leave_notifications
    SET read_at = COALESCE(read_at, NOW())
    WHERE id = ?
      AND (recipient_employee_id = ? OR recipient_role = ?)
");
$stmt->execute([$notificationId, $_SESSION['employee_id'], $_SESSION['role']]);

$destination = $_SESSION['role'] === 'hr_admin'
    ? '../modules/admin/leave.php'
    : '../modules/employee/leave.php';
header('Location: ' . $destination);
exit;
