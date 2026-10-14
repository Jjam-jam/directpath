<?php
// ============================================================
// Directpath HRMS — Leave API
// ============================================================
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$db     = getDB();
$empId  = $_SESSION['employee_id'];

// ── GET LEAVE BALANCE ─────────────────────────────────────────
if ($action === 'get_balance') {
    $year = (int)($input['year'] ?? date('Y'));
    $leaveBalances = getLeaveBalance($empId, $year);
    $balance = [];
    foreach ($leaveBalances as $type => $leaveBalance) {
        $balance[] = [
            'type'      => $type,
            'total'     => $leaveBalance['total'],
            'used'      => $leaveBalance['used'],
            'pending'   => $leaveBalance['pending'],
            'remaining' => $leaveBalance['remaining'],
            'available' => $leaveBalance['available'],
        ];
    }

    echo json_encode(['success' => true, 'year' => $year, 'balance' => $balance]);
    exit;
}

// ── GET PENDING LEAVES (Admin) ────────────────────────────────
if ($action === 'get_pending') {
    apiRequireRole('hr_admin');

    $stmt = $db->query("
    SELECT 
        lr.*,
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        e.department,
        e.position
    FROM leave_requests lr
    JOIN users e ON lr.employee_id = e.employee_id
    WHERE lr.status IN ('pending', 'documents_submitted')
    ORDER BY lr.urgent DESC, lr.created_at ASC
");

    $leaves = $stmt->fetchAll();

    echo json_encode(['success' => true, 'leaves' => $leaves]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
