<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../config/config.php';

$released = payrollReleaseDue(getDB());
echo date('Y-m-d H:i:s') . " - released $released payslip(s)\n";