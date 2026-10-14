<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('hr_admin');
$employeeId = trim((string)($_GET['employee_id'] ?? ''));
if ($employeeId === '') { http_response_code(400); exit; }
$db = getDB();
$stmt = $db->prepare('SELECT reference_photo_data, reference_photo_mime, reference_photo FROM users WHERE employee_id=?');
$stmt->execute([$employeeId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit; }
if (!empty($row['reference_photo_data'])) {
    header('Content-Type: '.($row['reference_photo_mime'] ?: 'image/jpeg'));
    header('Cache-Control: private, max-age=300');
    echo $row['reference_photo_data']; exit;
}
// Backward compatibility for photos uploaded before migration.
$name = basename((string)$row['reference_photo']);
$path = realpath(__DIR__.'/../../uploads/reference_photos/'.$name);
$root = realpath(__DIR__.'/../../uploads/reference_photos');
if ($path && $root && strpos($path, $root.DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
    $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
    header('Content-Type: '.(in_array($mime,['image/jpeg','image/png'],true)?$mime:'image/jpeg'));
    readfile($path); exit;
}
http_response_code(404);
?>