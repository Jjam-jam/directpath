<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('payroll_manager');
header('Location: ../admin/payroll.php');
exit;
