<?php
// Streams a leave proof file after checking who is asking.
// Allowed: the employee who filed it, HR Admin, CEO (view-only). Nobody else.
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

if (!in_array($_SESSION['role'], ['employee', 'hr_admin', 'ceo'], true)) { http_response_code(403); exit('Access denied.'); }

$id   = (int)($_GET['id'] ?? 0);
$kind = ($_GET['kind'] ?? '') === 'document' ? 'document' : 'attachment';
$columns = $kind === 'document'
    ? 'employee_id, document_path AS attachment_path, document_name AS attachment_name, document_mime AS attachment_mime'
    : 'employee_id, attachment_path, attachment_name, attachment_mime';
$stmt = getDB()->prepare("SELECT $columns FROM leave_requests WHERE id = ?");
$stmt->execute([$id]);
$lr = $stmt->fetch();

if (!$lr || empty($lr['attachment_path'])) { http_response_code(404); exit('No attachment.'); }
if ($_SESSION['role'] === 'employee' && $lr['employee_id'] !== $_SESSION['employee_id']) { http_response_code(403); exit('Access denied.'); }

$file = realpath(LEAVE_ATTACH_PATH . basename($lr['attachment_path']));
$base = realpath(LEAVE_ATTACH_PATH);
if (!$file || !$base || strpos($file, $base) !== 0 || !is_file($file)) { http_response_code(404); exit('File missing.'); }

header('Content-Type: ' . ($lr['attachment_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $lr['attachment_name'] ?: basename($file)) . '"');
readfile($file);
