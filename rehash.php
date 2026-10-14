<?php
require_once 'config/config.php';
$db = getDB();

$newPass = password_hash('sadmin123', PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE users SET password=? WHERE employee_id='SA001'");
$stmt->execute([$newPass]);

echo "Password reset done.";
?>