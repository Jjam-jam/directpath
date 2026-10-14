<?php
// Streams a loan SOA/statement after checking who is asking.
// Allowed: the employee who submitted it, HR Admin and Payroll Manager (reviewers). Nobody else.
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

if (!in_array($_SESSION['role'], ['employee', 'hr_admin', 'payroll_manager'], true)) { http_response_code(403); exit('Access denied.'); }

$id = (int)($_GET['id'] ?? 0);
$st = getDB()->prepare("SELECT employee_id, doc_path, doc_name, doc_mime FROM loan_disclosures WHERE id = ?");
$st->execute([$id]);
$d = $st->fetch();
if (!$d || empty($d['doc_path'])) { http_response_code(404); exit('No document.'); }
if ($_SESSION['role'] === 'employee' && $d['employee_id'] !== $_SESSION['employee_id']) { http_response_code(403); exit('Access denied.'); }

$file = realpath(LOAN_DOC_PATH . basename($d['doc_path']));
$base = realpath(LOAN_DOC_PATH);
if (!$file || !$base || strpos($file, $base) !== 0 || !is_file($file)) { http_response_code(404); exit('File missing.'); }

header('Content-Type: ' . ($d['doc_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $d['doc_name'] ?: basename($file)) . '"');
readfile($file);
